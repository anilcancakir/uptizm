<?php

namespace Tests\Feature\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\NotificationChannelSeverity;
use App\Enums\SignalSource;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
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
 * Locks the team-channel fan-out that {@see IncidentDispatcher::dispatch()}
 * performs alongside the existing person-channel page: it loads the incident
 * team's ENABLED {@see NotificationChannel} rows, filters each by its severity
 * band (all -> every incident; critical -> critical incidents only), and
 * throttles per channel so a flapping monitor or a correlated multi-monitor
 * burst collapses to a single send per channel per cooldown window.
 */
class IncidentChannelDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_critical_incident_notifies_both_all_and_critical_channels(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor();

        $slackAll = $this->channel($team, NotificationChannelSeverity::All);
        $webhookCritical = $this->channel($team, NotificationChannelSeverity::Critical);

        $incident = $this->makeIncident($monitor, IncidentSeverity::Critical);

        $this->dispatch($monitor, $incident);

        Notification::assertSentTo($slackAll, IncidentOpened::class);
        Notification::assertSentTo($webhookCritical, IncidentOpened::class);
    }

    public function test_a_non_critical_incident_notifies_only_severity_all_channels(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor();

        $slackAll = $this->channel($team, NotificationChannelSeverity::All);
        $webhookCritical = $this->channel($team, NotificationChannelSeverity::Critical);

        $incident = $this->makeIncident($monitor, IncidentSeverity::Warn);

        $this->dispatch($monitor, $incident);

        Notification::assertSentTo($slackAll, IncidentOpened::class);
        Notification::assertNotSentTo($webhookCritical, IncidentOpened::class);
    }

    public function test_disabled_channels_are_never_notified(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor();

        $disabled = $this->channel($team, NotificationChannelSeverity::All, enabled: false);

        $incident = $this->makeIncident($monitor, IncidentSeverity::Critical);

        $this->dispatch($monitor, $incident);

        Notification::assertNotSentTo($disabled, IncidentOpened::class);
    }

    public function test_a_burst_on_the_same_channel_is_throttled_to_one_send(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor();

        $slackAll = $this->channel($team, NotificationChannelSeverity::All);

        $incident = $this->makeIncident($monitor, IncidentSeverity::Critical);

        // A flapping/correlated burst dispatches the same open twice in quick
        // succession; the per-channel throttle must collapse it to one send.
        $this->dispatch($monitor, $incident);
        $this->dispatch($monitor, $incident);

        Notification::assertSentToTimes($slackAll, IncidentOpened::class, 1);
    }

    public function test_a_resolve_is_not_throttled_by_its_own_open_within_the_window(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor();

        $slackAll = $this->channel($team, NotificationChannelSeverity::All);
        $incident = $this->makeIncident($monitor, IncidentSeverity::Critical);

        // Open then resolve the same incident within the throttle window: the
        // resolve must NOT be suppressed by the open (the throttle key is scoped
        // per lifecycle event, not per channel alone).
        $dispatcher = $this->app->make(IncidentDispatcher::class);
        $dispatcher->dispatch($monitor, [
            'opened' => $incident,
            'resolved' => null,
            'status_change' => null,
        ]);
        $dispatcher->dispatch($monitor, [
            'opened' => null,
            'resolved' => $incident,
            'status_change' => null,
        ]);

        Notification::assertSentToTimes($slackAll, IncidentOpened::class, 1);
        Notification::assertSentToTimes($slackAll, IncidentResolved::class, 1);
    }

    /**
     * Invoke the dispatcher for a freshly opened incident.
     */
    protected function dispatch(Monitor $monitor, Incident $incident): void
    {
        $this->app->make(IncidentDispatcher::class)->dispatch($monitor, [
            'opened' => $incident,
            'resolved' => null,
            'status_change' => null,
        ]);
    }

    /**
     * Silence the broadcast + status-page-cache side effects so the test only
     * exercises the notification fan-out.
     */
    protected function fakeSideEffects(): MockInterface
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);

        $spy = Mockery::spy(StatusPageCache::class);
        $this->app->instance(StatusPageCache::class, $spy);

        return $spy;
    }

    /**
     * Persist a team-scoped notification channel.
     */
    protected function channel(Team $team, NotificationChannelSeverity $severity, bool $enabled = true): NotificationChannel
    {
        return NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'severity' => $severity,
            'is_enabled' => $enabled,
        ]);
    }

    /**
     * Create a monitor owned by a team with a single notifiable member.
     *
     * @return array{0: Monitor, 1: Team}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Channel Dispatch Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Channel Dispatch Team',
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
            'alert_on_down' => true,
            'alert_on_recover' => true,
        ]);

        return [$monitor, $team];
    }

    /**
     * Persist an opened incident for the monitor at the given severity.
     */
    protected function makeIncident(Monitor $monitor, IncidentSeverity $severity): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => $severity,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }
}
