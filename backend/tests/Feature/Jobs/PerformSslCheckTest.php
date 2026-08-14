<?php

namespace Tests\Feature\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\PerformSslCheck;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
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

        // 2. The structured half, and the one rule this creator is most likely to
        //    break: it bypasses the shared creation seam and builds its own
        //    `Incident::create`, so all three title columns have to be spread in
        //    by hand. The persisted key is BARE: the `_one` / `_other` pair is a
        //    catalogue detail the composer resolves from `days`, and a suffixed
        //    key here would be a wire value the Flutter enum has no member for,
        //    which falls the operator app back to English forever.
        $incident = $incidents->first();
        $this->assertSame('API Uptime SSL cert expires in 5 days', $incident->title);
        $this->assertSame(IncidentTitle::SSL_EXPIRING, $incident->title_key);
        // Canonicalizing, not identical: PostgreSQL's `jsonb` sorts object keys
        // while SQLite keeps the written order, so only the parameter SET is
        // portable. The per-engine CI job is what surfaced the difference.
        $this->assertEqualsCanonicalizing(
            ['monitor' => 'API Uptime', 'days' => 5],
            $incident->title_params,
        );

        // 3. The expiry is persisted and no error is recorded.
        $fresh = $monitor->fresh();
        $this->assertNotNull($fresh->ssl_expires_at);
        $this->assertNotNull($fresh->ssl_last_checked_at);
        $this->assertNull($fresh->ssl_last_error);
    }

    /**
     * The single-day case, which is the one English sentence this PR deliberately
     * changes: the writer this replaced said "expires in 1 days". The count picks
     * the `_one` catalogue entry while the PERSISTED key stays bare, so the same
     * stored value covers both counts and the client mirrors the choice from the
     * same `days` parameter.
     */
    public function test_a_one_day_cert_composes_the_singular_sentence_from_the_same_bare_key(): void
    {
        $monitor = $this->makeMonitor(url: 'https://example.com/', thresholdDays: 14);

        $this->jobReturning($monitor->id, $this->fabricateCertificate(daysUntilExpiration: 1))
            ->handle(new HostGuard);

        $incident = Incident::query()->where('primary_monitor_id', $monitor->id)->sole();

        $this->assertSame('API Uptime SSL cert expires in 1 day', $incident->title);
        $this->assertSame(IncidentTitle::SSL_EXPIRING, $incident->title_key);
        $this->assertEqualsCanonicalizing(
            ['monitor' => 'API Uptime', 'days' => 1],
            $incident->title_params,
        );
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
     * A renewed certificate closes the incident its expiry opened.
     *
     * Nothing did this before, and the consequence was worse than a stale row:
     * {@see PerformSslCheck::hasActiveSslIncident()} suppresses a second open
     * while one is active, so the first expiry warning a monitor ever got also
     * silenced its SSL lane for good. The next real expiry, a year later, would
     * have paged nobody.
     */
    public function test_a_renewed_certificate_closes_the_open_ssl_incident(): void
    {
        $monitor = $this->makeMonitor(url: 'https://example.com/', thresholdDays: 14);

        // 1. Five days left: the incident opens.
        $this->jobReturning($monitor->id, $this->fabricateCertificate(daysUntilExpiration: 5))
            ->handle(new HostGuard);

        $opened = Incident::query()->where('primary_monitor_id', $monitor->id)->sole();
        $this->assertTrue($opened->lifecycle->isActive());

        // 2. Renewed to ninety days, comfortably outside the alert window.
        $this->jobReturning($monitor->id, $this->fabricateCertificate(daysUntilExpiration: 90))
            ->handle(new HostGuard);

        $resolved = Incident::query()->where('primary_monitor_id', $monitor->id)->sole();
        $this->assertSame(IncidentStatus::Resolved, $resolved->lifecycle);
        $this->assertNotNull($resolved->resolved_at);

        // 3. Narrated on the public timeline, like every other auto-resolve.
        $this->assertSame(1, $resolved->updates()->count());
    }

    /**
     * And the lane is open again afterwards, which is the half that makes the
     * close worth having: a monitor whose certificate expired once must still
     * be able to warn about the next one.
     */
    public function test_the_ssl_lane_pages_again_after_a_renewal(): void
    {
        $monitor = $this->makeMonitor(url: 'https://example.com/', thresholdDays: 14);

        $this->jobReturning($monitor->id, $this->fabricateCertificate(daysUntilExpiration: 5))
            ->handle(new HostGuard);
        $this->jobReturning($monitor->id, $this->fabricateCertificate(daysUntilExpiration: 90))
            ->handle(new HostGuard);
        $this->jobReturning($monitor->id, $this->fabricateCertificate(daysUntilExpiration: 3))
            ->handle(new HostGuard);

        $incidents = Incident::query()->where('primary_monitor_id', $monitor->id)->get();
        $this->assertCount(2, $incidents, 'the second expiry earns its own incident');
        $this->assertCount(1, $incidents->filter(fn (Incident $i): bool => $i->lifecycle->isActive()));
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
