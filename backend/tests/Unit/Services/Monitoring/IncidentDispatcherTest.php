<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\IncidentDispatcher;
use App\Services\StatusPages\StatusPageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Locks the off-lock side-effect contract extracted from
 * {@see CheckPersistenceService} into
 * {@see IncidentDispatcher}: an open pages the team (gated on `alert_on_down`),
 * a resolve clears the page (gated on `alert_on_recover`), both lifecycle
 * transitions broadcast UNCONDITIONALLY on the alert flags, a health flip
 * broadcasts {@see MonitorStatusChanged}, and any lifecycle change busts the
 * status-page cache. The dispatcher fires exactly what the persistence path
 * used to fire in-line, in the same order.
 */
class IncidentDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_pages_the_team_broadcasts_and_invalidates_cache(): void
    {
        Notification::fake();
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $cache = $this->spyCache();

        $this->dispatcher()->dispatch($monitor, [
            'opened' => $incident,
            'resolved' => null,
            'status_change' => null,
        ]);

        Notification::assertSentTo($user, IncidentOpened::class);
        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'opened'
                && $event->incident->id === $incident->id,
        );
        $cache->shouldHaveReceived('invalidateForMonitors')->once()->with([$monitor->id]);
    }

    public function test_a_resolve_notifies_the_team_broadcasts_and_invalidates_cache(): void
    {
        Notification::fake();
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Resolved);
        $cache = $this->spyCache();

        $this->dispatcher()->dispatch($monitor, [
            'opened' => null,
            'resolved' => $incident,
            'status_change' => null,
        ]);

        Notification::assertSentTo($user, IncidentResolved::class);
        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'resolved'
                && $event->incident->id === $incident->id,
        );
        $cache->shouldHaveReceived('invalidateForMonitors')->once()->with([$monitor->id]);
    }

    public function test_alert_flags_gate_the_page_but_not_the_broadcast(): void
    {
        Notification::fake();
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        [$monitor, $user] = $this->makeMonitor(alertOnDown: false);
        $incident = $this->makeIncident($monitor);
        $this->spyCache();

        $this->dispatcher()->dispatch($monitor, [
            'opened' => $incident,
            'resolved' => null,
            'status_change' => null,
        ]);

        // The page is suppressed while the down-alert flag is off.
        Notification::assertNotSentTo($user, IncidentOpened::class);

        // But the live-dashboard broadcast fires regardless of the alert gate.
        Event::assertDispatched(IncidentBroadcast::class);
    }

    public function test_a_status_change_broadcasts_monitor_status_changed(): void
    {
        Notification::fake();
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        [$monitor] = $this->makeMonitor();
        $this->spyCache();

        $this->dispatcher()->dispatch($monitor, [
            'opened' => null,
            'resolved' => null,
            'status_change' => [
                'from' => MonitorStatus::Up,
                'to' => MonitorStatus::Down,
            ],
        ]);

        Event::assertDispatched(
            MonitorStatusChanged::class,
            fn (MonitorStatusChanged $event): bool => $event->from === MonitorStatus::Up
                && $event->to === MonitorStatus::Down
                && $event->monitor->id === $monitor->id,
        );
    }

    public function test_no_lifecycle_change_skips_the_cache_invalidation(): void
    {
        Notification::fake();
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);
        [$monitor] = $this->makeMonitor();
        $cache = $this->spyCache();

        // A bare status flip with no incident open/resolve must not bust the
        // status-page cache: only a lifecycle change mutates a component status.
        $this->dispatcher()->dispatch($monitor, [
            'opened' => null,
            'resolved' => null,
            'status_change' => [
                'from' => MonitorStatus::Up,
                'to' => MonitorStatus::Down,
            ],
        ]);

        $cache->shouldNotHaveReceived('invalidateForMonitors');
    }

    /**
     * Resolve the dispatcher with its real collaborators from the container.
     */
    protected function dispatcher(): IncidentDispatcher
    {
        return $this->app->make(IncidentDispatcher::class);
    }

    /**
     * Bind a Mockery spy for the status-page cache so cache-invalidation calls
     * can be asserted without touching the real cache store.
     */
    protected function spyCache(): MockInterface
    {
        $spy = Mockery::spy(StatusPageCache::class);
        $this->app->instance(StatusPageCache::class, $spy);

        return $spy;
    }

    /**
     * Create a monitor owned by a team whose single member is notifiable so
     * `incident->team->users` resolves to a non-empty recipient set.
     *
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(bool $alertOnDown = true, bool $alertOnRecover = true): array
    {
        $user = User::query()->create([
            'name' => 'Dispatcher Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Dispatcher Team',
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
            'alert_on_recover' => $alertOnRecover,
        ]);

        return [$monitor, $user];
    }

    /**
     * Persist a down incident for the monitor in the given lifecycle state.
     */
    protected function makeIncident(Monitor $monitor, IncidentStatus $lifecycle = IncidentStatus::Detected): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }
}
