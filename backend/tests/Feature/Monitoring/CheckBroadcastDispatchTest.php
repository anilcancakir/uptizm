<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Jobs\PerformSslCheck;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\HostGuard;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\SslCertificate\SslCertificate;
use Tests\TestCase;

/**
 * Locks the real-time broadcast dispatch wired into the monitoring pipeline:
 * incident opens/resolves push {@see IncidentBroadcast} and monitor health
 * flips push {@see MonitorStatusChanged}, both on the team's private channel.
 *
 * The concurrency-critical guarantee lives here too: the prior status is read
 * INSIDE the per-monitor lock, so two serialized regions of the same monitor
 * observe a single transition and never double-broadcast an up->down flip.
 * Paused transitions and the first-ever (null prior) status are suppressed so
 * a config action or initial seeding never floods the live dashboard.
 */
class CheckBroadcastDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_an_incident_broadcasts_incident_opened_on_the_team_channel(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: MonitorStatus::Up, incidentThreshold: 1);
        $service = $this->service();

        // A single down check crosses the threshold and opens the incident.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-1', status: MonitorStatus::Down));

        $incident = Incident::query()->sole();
        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'opened'
                && $event->incident->id === $incident->id,
        );
    }

    public function test_a_recovery_broadcasts_incident_resolved_on_the_team_channel(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: MonitorStatus::Up, incidentThreshold: 1);
        $service = $this->service();

        // Open the incident, then recover it with a healthy check.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-1', status: MonitorStatus::Down));
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'up-1', status: MonitorStatus::Up));

        $incident = Incident::query()->sole();
        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'resolved'
                && $event->incident->id === $incident->id,
        );
    }

    public function test_an_up_to_down_flip_broadcasts_one_monitor_status_changed(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: MonitorStatus::Up, incidentThreshold: 10);
        $service = $this->service();

        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-1', status: MonitorStatus::Down));

        Event::assertDispatched(
            MonitorStatusChanged::class,
            fn (MonitorStatusChanged $event): bool => $event->from === MonitorStatus::Up
                && $event->to === MonitorStatus::Down
                && $event->monitor->id === $monitor->id,
        );
        Event::assertDispatchedTimes(MonitorStatusChanged::class, 1);
    }

    public function test_a_same_status_check_broadcasts_no_monitor_status_changed(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: MonitorStatus::Up, incidentThreshold: 10);
        $service = $this->service();

        // A healthy check on an already-up monitor is not a transition.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'up-1', status: MonitorStatus::Up));

        Event::assertNotDispatched(MonitorStatusChanged::class);
    }

    public function test_the_first_ever_check_suppresses_the_null_prior_transition(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: null, incidentThreshold: 10);
        $service = $this->service();

        // The first status a brand-new monitor records has no prior to flip
        // from; reconcile-on-nav picks it up, not a live badge broadcast.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-1', status: MonitorStatus::Down));

        Event::assertNotDispatched(MonitorStatusChanged::class);
    }

    public function test_two_regions_of_the_same_flip_broadcast_the_transition_only_once(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: MonitorStatus::Up, incidentThreshold: 10);
        $service = $this->service();

        // Region 1 records the up->down flip and broadcasts it. Region 2,
        // serialized after region 1's committed denorm UPDATE, reads down as the
        // in-lock prior status: down->down is not a transition, so it stays
        // silent. Exactly one MonitorStatusChanged survives.
        $service->persist($monitor, new CheckResult(
            monitorId: (string) $monitor->id,
            region: 'us-east-1',
            checkedAt: new DateTimeImmutable,
            status: MonitorStatus::Down,
            statusCode: 503,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: 'region-1',
        ));
        $service->persist($monitor, new CheckResult(
            monitorId: (string) $monitor->id,
            region: 'eu-west-1',
            checkedAt: new DateTimeImmutable,
            status: MonitorStatus::Down,
            statusCode: 503,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: 'region-2',
        ));

        Event::assertDispatchedTimes(MonitorStatusChanged::class, 1);
    }

    public function test_an_ssl_incident_open_broadcasts_incident_opened(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        Notification::fake();
        $monitor = $this->makeMonitor(lastStatus: MonitorStatus::Up, incidentThreshold: 10);
        $monitor->forceFill([
            'ssl_tracking' => true,
            'ssl_alert_threshold_days' => 14,
        ])->save();
        $certificate = $this->fabricateCertificate(daysUntilExpiration: 5);

        $this->jobReturning($monitor->id, $certificate)->handle(new HostGuard);

        $incident = Incident::query()->where('primary_monitor_id', $monitor->id)->sole();
        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'opened'
                && $event->incident->id === $incident->id,
        );
    }

    /**
     * Resolve the persistence service with its real collaborators.
     */
    protected function service(): CheckPersistenceService
    {
        return $this->app->make(CheckPersistenceService::class);
    }

    /**
     * Persist a team-owned monitor seeded with the given prior health and
     * incident threshold.
     */
    protected function makeMonitor(?MonitorStatus $lastStatus, int $incidentThreshold): Monitor
    {
        $user = User::query()->create([
            'name' => 'Broadcast Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Broadcast Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => $incidentThreshold,
            'consecutive_fails' => 0,
            'last_status' => $lastStatus,
        ]);
    }

    /**
     * Build a CheckResult carrying the given status for a direct persist call.
     */
    protected function makeResult(Monitor $monitor, string $probeRunId, MonitorStatus $status): CheckResult
    {
        return new CheckResult(
            monitorId: (string) $monitor->id,
            region: 'us-east-1',
            checkedAt: new DateTimeImmutable,
            status: $status,
            statusCode: $status === MonitorStatus::Up ? 200 : 503,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: $probeRunId,
        );
    }

    /**
     * Build an SSL job whose certificate fetch returns the given fabricated
     * cert, bypassing the live TLS handshake.
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
     * Fabricate an in-memory certificate expiring in the given number of days
     * so the SSL job lands deterministically inside the alert window.
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
}
