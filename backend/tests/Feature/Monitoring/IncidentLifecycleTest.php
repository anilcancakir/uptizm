<?php

namespace Tests\Feature\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\PublishAiIncidentUpdate;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\IncidentWriteService;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the incident lifecycle wiring in {@see CheckPersistenceService}: a
 * threshold open dispatches {@see IncidentOpened} to the team, a recovery
 * auto-resolves the active down incident and dispatches
 * {@see IncidentResolved}, both gated on the monitor's alert flags and both
 * scoped so a recovered site never clears an unrelated (SSL / metric-breach)
 * incident.
 */
class IncidentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_crossing_the_fail_threshold_opens_an_incident_and_pages_the_team(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $service = $this->service();

        // 1. Two consecutive down checks cross the threshold and open exactly
        //    one active down incident.
        $this->drivePastThreshold($service, $monitor);

        $incident = Incident::query()->sole();
        $this->assertNull($incident->trigger_metric_key);
        $this->assertTrue($incident->lifecycle->isActive());

        // 2. The team's users are paged with IncidentOpened.
        Notification::assertSentTo($user, IncidentOpened::class);
    }

    public function test_a_recovery_resolves_the_active_incident_and_notifies_the_team(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $service = $this->service();

        $this->drivePastThreshold($service, $monitor);
        $incident = Incident::query()->sole();

        // 1. An up check with a cleared streak recovers the monitor and
        //    auto-resolves the active down incident.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-up', status: MonitorStatus::Up));

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);

        // 2. The recovery leaves a system-authored note on the timeline.
        $update = $incident->updates()->sole();
        $this->assertSame('system', $update->actor);
        $this->assertSame(IncidentStatus::Resolved, $update->status);

        // 3. The team is notified of the recovery.
        Notification::assertSentTo($user, IncidentResolved::class);
    }

    public function test_a_repeated_up_check_does_not_resolve_or_notify_twice(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $service = $this->service();

        $this->drivePastThreshold($service, $monitor);
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-up-1', status: MonitorStatus::Up));

        // A second up check finds no active down incident: no resolve, and
        // exactly one recovery notification total.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-up-2', status: MonitorStatus::Up));

        Notification::assertSentToTimes($user, IncidentResolved::class, 1);
    }

    public function test_alert_on_down_false_still_opens_the_incident_but_sends_no_page(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor(alertOnDown: false);
        $service = $this->service();

        // 1. The gate is the alert flag, not the open path: the incident still
        //    opens on the threshold crossing.
        $this->drivePastThreshold($service, $monitor);

        $this->assertSame(1, Incident::query()->count());

        // 2. But no IncidentOpened is dispatched while the flag is off.
        Notification::assertNotSentTo($user, IncidentOpened::class);
    }

    public function test_a_recovery_does_not_resolve_an_unrelated_ssl_incident(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $service = $this->service();

        // 1. An active SSL incident (trigger_metric_key = 'ssl') is out of the
        //    down-recovery scope: an up probe does not fix an expiring cert.
        $sslIncident = $this->makeIncident($monitor, triggerMetricKey: 'ssl');

        // 2. A healthy check recovers the monitor but must leave the SSL
        //    incident active and dispatch no recovery notification.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-up', status: MonitorStatus::Up));

        $this->assertTrue($sslIncident->refresh()->lifecycle->isActive());
        Notification::assertNotSentTo($user, IncidentResolved::class);
    }

    /**
     * Resolve the service with its real collaborators from the container.
     */
    public function test_resolving_a_still_broken_monitor_reopens_instead_of_opening_a_second(): void
    {
        // Measured on the running system: a manual resolve leaves
        // `consecutive_fails` where it was and clears the only other gate, so the
        // NEXT check opened a brand new incident. Clicking resolve ten times on a
        // monitor that is still down therefore produced ten incidents, and with
        // autonomous updates on, twenty model calls and ten customer-facing posts.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $service = $this->service();

        $this->drivePastThreshold($service, $monitor);
        $first = Incident::query()->sole();

        // The operator resolves it by hand while the monitor is still failing.
        $this->app->make(IncidentWriteService::class)->resolve($first, author: 'Operator');

        // The next check arrives, still down.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-3', status: MonitorStatus::Down));

        $this->assertSame(
            1,
            Incident::query()->count(),
            'a monitor that never recovered is one outage, not two',
        );
        $this->assertTrue(
            Incident::query()->sole()->lifecycle->isActive(),
            'and it is open again, so a mistaken resolve does not silence a real outage',
        );
    }

    public function test_a_failure_after_the_window_is_a_new_incident(): void
    {
        // The other half. The window answers "is this the same outage", so past
        // it a fresh failure is a fresh episode and gets its own incident, its
        // own timeline and its own postmortem.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $service = $this->service();

        $this->drivePastThreshold($service, $monitor);
        $first = Incident::query()->sole();
        $this->app->make(IncidentWriteService::class)->resolve($first, author: 'Operator');

        // Far enough past the reopen window that this is a different outage.
        $first->forceFill(['resolved_at' => now()->subDay()])->save();

        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-4', status: MonitorStatus::Down));

        $this->assertSame(2, Incident::query()->count());
    }

    public function test_a_recovery_inside_the_window_makes_the_next_failure_a_new_incident(): void
    {
        // The second condition, and the one a window alone would get wrong: a
        // monitor that actually came back and broke again is two outages even
        // minutes apart, and the auto-resolve already closed the first one on
        // its own terms.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $service = $this->service();

        $this->drivePastThreshold($service, $monitor);
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'up-1', status: MonitorStatus::Up));

        $this->assertSame(1, Incident::query()->count());
        $this->assertNotNull(Incident::query()->sole()->resolved_at, 'the recovery closed it');

        // Down again, twice, inside the reopen window.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-5', status: MonitorStatus::Down));
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-6', status: MonitorStatus::Down));

        $this->assertSame(
            2,
            Incident::query()->count(),
            'it recovered in between, so this is a second outage and not a reopen',
        );
    }

    public function test_an_automated_open_queues_the_autonomous_update(): void
    {
        // The hook started beside `IncidentWriteService::dispatchOpened()`, which
        // only a manual create and a reopen reach. The automated open goes
        // through `CheckPersistenceService` -> `ThresholdEvaluator::evaluate()`
        // -> `IncidentDispatcher`, so it queued nothing for the threshold and
        // metric opens, which is to say for almost every incident. Live, an
        // operator turned the switch on, an incident opened, and nothing ever
        // ran.
        Notification::fake();
        Queue::fake();
        [$monitor] = $this->makeMonitor();
        $monitor->forceFill(['ai_auto_updates' => true])->save();

        $this->drivePastThreshold($this->service(), $monitor->fresh());

        Queue::assertPushed(PublishAiIncidentUpdate::class);
    }

    public function test_a_monitor_that_did_not_allow_it_queues_nothing(): void
    {
        Notification::fake();
        Queue::fake();
        [$monitor] = $this->makeMonitor();

        $this->drivePastThreshold($this->service(), $monitor);

        Queue::assertNotPushed(PublishAiIncidentUpdate::class);
    }

    protected function service(): CheckPersistenceService
    {
        return $this->app->make(CheckPersistenceService::class);
    }

    /**
     * Push two distinct down checks through persist so the monitor crosses its
     * `incident_threshold` (2) and opens a down incident.
     */
    protected function drivePastThreshold(CheckPersistenceService $service, Monitor $monitor): void
    {
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-1', status: MonitorStatus::Down));
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'down-2', status: MonitorStatus::Down));
    }

    /**
     * Create a monitor owned by a team whose single member is notifiable, so
     * `incident->team->users` resolves to a non-empty recipient set.
     *
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(bool $alertOnDown = true): array
    {
        $user = User::query()->create([
            'name' => 'Lifecycle Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Lifecycle Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            'alert_on_down' => $alertOnDown,
        ]);

        return [$monitor, $user];
    }

    /**
     * Persist a bare down incident tagged with the given trigger metric key so
     * the scoping guard can be exercised (null = down, 'ssl' = certificate).
     */
    protected function makeIncident(Monitor $monitor, ?string $triggerMetricKey): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Certificate expiring',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'trigger_metric_key' => $triggerMetricKey,
            'started_at' => now(),
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
}
