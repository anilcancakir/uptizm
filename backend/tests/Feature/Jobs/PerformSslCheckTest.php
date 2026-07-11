<?php

namespace Tests\Feature\Jobs;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\PerformSslCheck;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Support\Monitoring\HostGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\SslCertificate\Exceptions\CouldNotDownloadCertificate;
use Spatie\SslCertificate\SslCertificate;
use Tests\TestCase;

/**
 * Locks the SSL-expiry monitoring path of {@see PerformSslCheck}: a near-expiry
 * certificate opens exactly one incident, a HostGuard-blocked target is refused
 * before any connect, a download failure records an error without opening an
 * incident, and a re-run never stacks a duplicate SSL incident.
 *
 * The network-hitting certificate fetch is overridden per test via the
 * `fetchCertificate` seam, so no test opens a live TLS connection.
 */
class PerformSslCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_near_expiry_cert_opens_exactly_one_ssl_incident(): void
    {
        $monitor = $this->makeMonitor(url: 'https://example.com/', thresholdDays: 14);
        $certificate = $this->fabricateCertificate(daysUntilExpiration: 5);

        $this->jobReturning($monitor->id, $certificate)->handle(new HostGuard);

        // 1. Exactly one SSL incident is opened for the monitor.
        $incidents = Incident::query()->where('primary_monitor_id', $monitor->id)->get();
        $this->assertCount(1, $incidents);
        $this->assertSame(SignalSource::UserThreshold, $incidents->first()->signal_source);
        $this->assertStringContainsString('SSL cert expires', $incidents->first()->title);

        // 2. The expiry is persisted and no error is recorded.
        $fresh = $monitor->fresh();
        $this->assertNotNull($fresh->ssl_expires_at);
        $this->assertNotNull($fresh->ssl_last_checked_at);
        $this->assertNull($fresh->ssl_last_error);
    }

    public function test_blocked_host_is_refused_without_connecting(): void
    {
        $monitor = $this->makeMonitor(url: 'https://10.0.0.5/', thresholdDays: 14);

        // The seam throws if reached: a blocked host must return before any fetch.
        $job = new class($monitor->id) extends PerformSslCheck
        {
            protected function fetchCertificate(string $hostPort): SslCertificate
            {
                throw new RuntimeException('The job must not connect to a blocked host.');
            }
        };

        $job->handle(new HostGuard);

        $fresh = $monitor->fresh();
        $this->assertNotNull($fresh->ssl_last_error);
        $this->assertSame(0, Incident::query()->where('primary_monitor_id', $monitor->id)->count());
    }

    public function test_download_failure_records_error_and_completes(): void
    {
        $monitor = $this->makeMonitor(url: 'https://example.com/', thresholdDays: 14);

        $job = new class($monitor->id) extends PerformSslCheck
        {
            protected function fetchCertificate(string $hostPort): SslCertificate
            {
                throw CouldNotDownloadCertificate::hostDoesNotExist($hostPort);
            }
        };

        // Completes without propagating the download failure.
        $job->handle(new HostGuard);

        $fresh = $monitor->fresh();
        $this->assertNotNull($fresh->ssl_last_error);
        $this->assertNotNull($fresh->ssl_last_checked_at);
        $this->assertSame(0, Incident::query()->where('primary_monitor_id', $monitor->id)->count());
    }

    public function test_second_run_does_not_open_a_duplicate_ssl_incident(): void
    {
        $monitor = $this->makeMonitor(url: 'https://example.com/', thresholdDays: 14);
        $certificate = $this->fabricateCertificate(daysUntilExpiration: 5);

        // 1. First run opens the single SSL incident.
        $this->jobReturning($monitor->id, $certificate)->handle(new HostGuard);

        // 2. Second run with the same near-expiry cert must not stack a duplicate.
        $this->jobReturning($monitor->id, $certificate)->handle(new HostGuard);

        $this->assertSame(1, Incident::query()->where('primary_monitor_id', $monitor->id)->count());
    }

    /**
     * Build a job whose certificate fetch returns the given fabricated cert,
     * bypassing the live TLS handshake.
     */
    protected function jobReturning(string $monitorId, SslCertificate $certificate): PerformSslCheck
    {
        return new class($monitorId, $certificate) extends PerformSslCheck
        {
            public function __construct(
                string $monitorId,
                private readonly SslCertificate $certificate,
            ) {
                parent::__construct($monitorId);
            }

            protected function fetchCertificate(string $hostPort): SslCertificate
            {
                return $this->certificate;
            }
        };
    }

    /**
     * Fabricate an in-memory certificate expiring in the given number of days,
     * so {@see SslCertificate::daysUntilExpirationDate()} lands deterministically
     * inside the alert window without a network read.
     */
    protected function fabricateCertificate(int $daysUntilExpiration): SslCertificate
    {
        return SslCertificate::createFromArray([
            'rawCertificateFields' => [
                'subject' => ['CN' => 'example.com'],
                'issuer' => ['CN' => 'Test CA'],
                'validFrom_time_t' => now()->subDays(80)->timestamp,
                'validTo_time_t' => now()->addDays($daysUntilExpiration)->addHours(6)->timestamp,
            ],
            'fingerprint' => 'aa:bb',
            'fingerprintSha256' => 'cc:dd',
            'remoteAddress' => '93.184.216.34',
            'publicKeyDetail' => [],
        ]);
    }

    /**
     * Persist a team-owned monitor with SSL tracking enabled.
     */
    protected function makeMonitor(string $url, int $thresholdDays): Monitor
    {
        $user = User::query()->create([
            'name' => 'SSL Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'SSL Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => $url,
            'check_interval_sec' => 60,
            'ssl_tracking' => true,
            'ssl_alert_threshold_days' => $thresholdDays,
            'last_status' => MonitorStatus::Up,
        ]);
    }
}
