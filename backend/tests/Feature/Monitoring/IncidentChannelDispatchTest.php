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
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\IncidentDispatcher;
use App\Services\StatusPages\StatusPageCache;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
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

    /**
     * The point of the whole escalation path, stated as a channel assertion: a
     * critical-only channel gets NOTHING while the incident sits at warn, and it
     * is told the moment the incident is raised to critical. Without the raise,
     * that channel stayed silent for the entire outage.
     */
    public function test_an_escalation_reaches_the_critical_only_channel(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor();

        $slackAll = $this->channel($team, NotificationChannelSeverity::All);
        $webhookCritical = $this->channel($team, NotificationChannelSeverity::Critical);

        // 1. The warn open: the critical-only channel is correctly not told.
        $incident = $this->makeIncident($monitor, IncidentSeverity::Warn);
        $this->dispatch($monitor, $incident);
        Notification::assertNotSentTo($webhookCritical, IncidentOpened::class);

        // 2. The metric goes critical, so the evaluator raises the SAME incident
        //    and reports it in the escalated slot.
        $incident->update(['severity' => IncidentSeverity::Critical]);
        $this->dispatchEscalated($monitor, $incident->refresh());

        // 3. Both channels hear about it, and as an escalation rather than as a
        //    second open: the operator has been watching this incident.
        Notification::assertSentTo($webhookCritical, IncidentEscalated::class);
        Notification::assertSentTo($slackAll, IncidentEscalated::class);
        Notification::assertNotSentTo($webhookCritical, IncidentOpened::class);
    }

    /**
     * The three things the review caught, each stated as an assertion rather than
     * left to the reader: the webhook payload names the real event, the public
     * status page is invalidated, and the notification is registered so the
     * send-time gate consults the operator's preference at all.
     */
    public function test_an_escalation_names_itself_correctly_to_integrations(): void
    {
        [$monitor, $team] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor, IncidentSeverity::Critical);

        $payload = (new IncidentEscalated($incident))->toWebhook($this->channel($team, NotificationChannelSeverity::All));

        $this->assertSame(
            'incident.escalated',
            $payload['event'],
            'a hardcoded incident.opened would be a lie in a machine-read field',
        );
        $this->assertSame('critical', $payload['severity']);
    }

    public function test_an_escalation_invalidates_the_public_status_page(): void
    {
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor, IncidentSeverity::Critical);

        // An escalation rewrites the incident's title and severity, and the
        // assembler puts that title into the public read model, so a cached page
        // would otherwise serve the state the incident has moved on from.
        $this->mock(StatusPageCache::class, function (MockInterface $mock) use ($monitor): void {
            $mock->shouldReceive('invalidateForMonitors')->once()->with([$monitor->id]);
        });

        $this->app->make(IncidentDispatcher::class)->dispatch($monitor, [
            'opened' => null,
            'resolved' => null,
            'escalated' => $incident,
            'status_change' => null,
        ]);
    }

    public function test_the_escalation_notification_is_registered_for_preferences(): void
    {
        // An unregistered class is FAIL-OPEN in GateNotificationChannels, so
        // without this entry the escalation shipped ungated: a member who had
        // turned push off would still be pushed. The slug the registry derives
        // has to match the token the notification uses for its preference rows.
        $this->assertTrue(NotificationPreferenceRegistry::has(IncidentEscalated::class));
        $this->assertSame(
            'incident_escalated',
            NotificationPreferenceRegistry::resolveSlug(IncidentEscalated::class),
        );
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
     * Drive the dispatcher with an ESCALATED incident, the slot the evaluator
     * fills when a louder breach lands on an already-open incident.
     */
    protected function dispatchEscalated(Monitor $monitor, Incident $incident): void
    {
        $this->app->make(IncidentDispatcher::class)->dispatch($monitor, [
            'opened' => null,
            'resolved' => null,
            'escalated' => $incident,
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
