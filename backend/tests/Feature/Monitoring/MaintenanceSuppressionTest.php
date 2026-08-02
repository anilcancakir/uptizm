<?php

namespace Tests\Feature\Monitoring;

use App\Enums\EscalationTargetType;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Jobs\DispatchEscalationStep;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\OnCall\EscalationDispatcher;
use App\Support\Monitoring\CheckResult;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Pins alert suppression during a planned maintenance window, and the sweep
 * that keeps the public page honest across a window's boundaries.
 *
 * The dangerous failure here is not the obvious one. Suppressing too little
 * wakes an operator for work they scheduled themselves; suppressing too widely
 * silences a REAL outage while a window happens to be open. So the scope is
 * asserted from both sides: the window's own attached monitors are muted, and
 * every monitor outside that set (and the same monitor outside those times)
 * still pages. The incident is opened either way; only the paging is withheld,
 * and every withheld page leaves a log line naming both ids.
 */
class MaintenanceSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_down_result_inside_an_open_window_opens_the_incident_without_paging(): void
    {
        Notification::fake();
        Queue::fake();

        [$team, $user] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeEscalationLadder($team);
        $this->makeWindow($team, $page, [$monitor], now()->subMinutes(10), now()->addMinutes(50));

        $this->drivePersist($monitor, MonitorStatus::Down);

        // The incident still opens: only the page is withheld, so the outage is
        // recorded and the public status page still turns red.
        $this->assertSame(1, Incident::query()->count());

        Notification::assertNotSentTo($user, IncidentOpened::class);
        Queue::assertNotPushed(DispatchEscalationStep::class);
    }

    public function test_the_same_monitor_pages_normally_outside_the_window(): void
    {
        Notification::fake();
        Queue::fake();

        [$team, $user] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeEscalationLadder($team);

        // The very same monitor and the very same window, an hour before it opens.
        $this->makeWindow($team, $page, [$monitor], now()->addHours(2), now()->addHours(3));

        $this->drivePersist($monitor, MonitorStatus::Down);

        $this->assertSame(1, Incident::query()->count());

        Notification::assertSentTo($user, IncidentOpened::class);
        Queue::assertPushed(DispatchEscalationStep::class);
    }

    /**
     * THE CASE THAT PROVES THE SCOPE IS NARROW.
     *
     * A window is open on one monitor while a second monitor of the same team,
     * on the same status page, goes genuinely down. Suppressing by team or by
     * page passes every other test in this file and fails here, and its failure
     * mode in production is an unpaged outage.
     */
    public function test_a_monitor_not_attached_to_the_window_pages_normally_during_it(): void
    {
        Notification::fake();
        Queue::fake();

        [$team, $user] = $this->makeTeam();
        $underMaintenance = $this->makeMonitor($team, 'API');
        $unrelated = $this->makeMonitor($team, 'Checkout');
        $page = $this->makePage($team, 'ops', $underMaintenance, $unrelated);
        $this->makeEscalationLadder($team);
        $this->makeWindow($team, $page, [$underMaintenance], now()->subMinutes(10), now()->addMinutes(50));

        $this->drivePersist($unrelated, MonitorStatus::Down);

        Notification::assertSentTo($user, IncidentOpened::class);
        Queue::assertPushed(DispatchEscalationStep::class);
    }

    /**
     * THE CASE THE QUEUE-TIME ASSERTIONS COULD NOT REACH.
     *
     * `escalate()` only ENQUEUES delayed jobs, so every other ladder assertion
     * in this file (`Queue::assertNotPushed(DispatchEscalationStep::class)`)
     * answers for queue time and says nothing about the step that fires minutes
     * later. The gap that hid in it: an incident opens with no window anywhere,
     * the ladder is queued, the operator then schedules the window, and the
     * pending step fires INSIDE it. That is the most natural operator sequence
     * there is, and it paged the on-call straight through planned work.
     *
     * So this one runs the step for real, at fire time, and asserts silence.
     */
    public function test_a_window_opened_after_the_incident_withholds_a_pending_escalation_step(): void
    {
        [$team, $user] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $step = $this->makeUserEscalationStep($team, $user);

        // 1. The incident opens with no window in existence: the ladder queues.
        Notification::fake();
        Queue::fake();
        $this->drivePersist($monitor, MonitorStatus::Down);

        Queue::assertPushed(DispatchEscalationStep::class);
        $incident = Incident::query()->latest('created_at')->firstOrFail();

        // 2. Only NOW does the operator schedule the window, open right now.
        $this->makeWindow($team, $page, [$monitor], now()->subMinute(), now()->addMinutes(59));

        // 3. Re-fake so the open's own page (already asserted elsewhere) does not
        //    count, then fire the pending step the way the queue would.
        Notification::fake();
        app(EscalationDispatcher::class)->pageStep((string) $incident->id, (string) $step->id);

        Notification::assertNothingSent();
    }

    /**
     * The mirror of the case above: a step whose window has already CLOSED by
     * the time it fires must page. The guard reads the clock at fire time, so
     * it has to let this one through, and asserting only the silent direction
     * would pass just as well against a guard that never pages at all.
     */
    public function test_a_step_firing_after_its_window_closed_still_pages(): void
    {
        [$team, $user] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $step = $this->makeUserEscalationStep($team, $user);

        Notification::fake();
        Queue::fake();
        $this->drivePersist($monitor, MonitorStatus::Down);
        $incident = Incident::query()->latest('created_at')->firstOrFail();

        // A window that ended an hour ago suppresses nothing.
        $this->makeWindow($team, $page, [$monitor], now()->subHours(2), now()->subHour());

        Notification::fake();
        app(EscalationDispatcher::class)->pageStep((string) $incident->id, (string) $step->id);

        Notification::assertSentTo($user, IncidentOpened::class);
    }

    /**
     * Scope, at fire time: an incident carrying one monitor under planned work
     * AND one genuinely down monitor is a real outage and must still page.
     * "Every attached monitor is covered" is the rule; "any" would mute this.
     */
    public function test_a_step_pages_when_only_some_of_the_incident_monitors_are_covered(): void
    {
        [$team, $user] = $this->makeTeam();
        $underMaintenance = $this->makeMonitor($team, 'API');
        $unrelated = $this->makeMonitor($team, 'Checkout');
        $page = $this->makePage($team, 'ops', $underMaintenance, $unrelated);
        $step = $this->makeUserEscalationStep($team, $user);

        Notification::fake();
        Queue::fake();
        $this->drivePersist($underMaintenance, MonitorStatus::Down);
        $incident = Incident::query()->latest('created_at')->firstOrFail();

        // Correlate a second, unplanned monitor onto the same incident, with the
        // same pivot payload ThresholdEvaluator writes (both component-status
        // columns are NOT NULL).
        $incident->monitors()->attach($unrelated->id, [
            'component_status_at_start' => MonitorStatus::Down->value,
            'component_status_current' => MonitorStatus::Down->value,
        ]);

        $this->makeWindow($team, $page, [$underMaintenance], now()->subMinute(), now()->addMinutes(59));

        Notification::fake();
        app(EscalationDispatcher::class)->pageStep((string) $incident->id, (string) $step->id);

        Notification::assertSentTo($user, IncidentOpened::class);
    }

    public function test_a_window_that_does_not_suppress_alerts_still_pages(): void
    {
        Notification::fake();

        [$team, $user] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeWindow(
            $team,
            $page,
            [$monitor],
            now()->subMinutes(10),
            now()->addMinutes(50),
            suppressAlerts: false,
        );

        $this->drivePersist($monitor, MonitorStatus::Down);

        Notification::assertSentTo($user, IncidentOpened::class);
    }

    public function test_the_suppression_writes_a_log_line_naming_the_monitor_and_the_window(): void
    {
        Notification::fake();

        [$team] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $window = $this->makeWindow($team, $page, [$monitor], now()->subMinutes(10), now()->addMinutes(50));

        Log::spy();

        $this->drivePersist($monitor, MonitorStatus::Down);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $context['monitor_id'] === $monitor->getKey()
                && $context['scheduled_maintenance_id'] === $window->getKey())
            ->once();
    }

    public function test_a_recovery_inside_an_open_window_is_withheld_too(): void
    {
        Notification::fake();

        [$team, $user] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeWindow($team, $page, [$monitor], now()->subMinutes(10), now()->addMinutes(50));

        $this->drivePersist($monitor, MonitorStatus::Down);
        $this->drivePersist($monitor, MonitorStatus::Up);

        // A recovery for a page nobody ever received is noise, not relief.
        Notification::assertNothingSentTo($user);
    }

    /**
     * Suppression withholds the PAGE and nothing else. The live dashboard still
     * learns about the incident and the public page still drops its cached read
     * model, so an operator watching the status page during planned work sees the
     * component go red. An early return in the dispatcher would silently take
     * both of these with it.
     */
    public function test_a_suppressed_open_still_broadcasts_and_still_busts_the_public_page_cache(): void
    {
        Notification::fake();
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);

        [$team] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeWindow($team, $page, [$monitor], now()->subMinutes(10), now()->addMinutes(50));

        Cache::put('status-page:ops', ['stale'], 60);

        $this->drivePersist($monitor, MonitorStatus::Down);

        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'opened',
        );
        $this->assertFalse(Cache::has('status-page:ops'));
    }

    public function test_the_boundary_sweep_busts_the_cache_of_a_page_whose_window_just_opened(): void
    {
        [$team] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeWindow($team, $page, [$monitor], now()->subSeconds(30), now()->addHour());

        Cache::put('status-page:ops', ['stale'], 60);

        BustStatusPageCacheForMaintenanceBoundaries::dispatchSync();

        $this->assertFalse(Cache::has('status-page:ops'));
    }

    public function test_the_boundary_sweep_busts_the_cache_of_a_page_whose_window_just_closed(): void
    {
        [$team] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeWindow($team, $page, [$monitor], now()->subHour(), now()->subSeconds(30));

        Cache::put('status-page:ops', ['stale'], 60);

        BustStatusPageCacheForMaintenanceBoundaries::dispatchSync();

        $this->assertFalse(Cache::has('status-page:ops'));
    }

    public function test_the_boundary_sweep_leaves_pages_away_from_a_boundary_cached(): void
    {
        [$team] = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');
        $midWindow = $this->makePage($team, 'mid-window', $monitor);
        $this->makeWindow($team, $midWindow, [$monitor], now()->subHours(3), now()->addHours(3));

        $other = $this->makeMonitor($team, 'Checkout');
        $noWindow = $this->makePage($team, 'no-window', $other);

        Cache::put('status-page:mid-window', ['fresh'], 60);
        Cache::put('status-page:no-window', ['fresh'], 60);

        BustStatusPageCacheForMaintenanceBoundaries::dispatchSync();

        $this->assertTrue(Cache::has('status-page:mid-window'));
        $this->assertTrue(Cache::has('status-page:no-window'));
        $this->assertSame('no-window', $noWindow->slug);
    }

    /**
     * The sweep is registered beside the five existing scheduled jobs, with the
     * same guards. Found by the JOB the entry dispatches rather than by its
     * description, so renaming the entry cannot make this vacuous.
     */
    public function test_the_boundary_sweep_is_scheduled_beside_the_existing_jobs(): void
    {
        $event = $this->scheduledBoundaryEvent();

        $this->assertTrue(
            $event->onOneServer,
            'The sweep is scheduled without onOneServer(), so every web host dispatches its own.'
        );
        $this->assertTrue(
            $event->withoutOverlapping,
            'The sweep is scheduled without withoutOverlapping(), so a slow sweep is re-entered.'
        );
        $this->assertSame(
            '* * * * *',
            $event->expression,
            'A boundary is a minute-grained event; a coarser cadence leaves the public page stale.'
        );
    }

    /**
     * The scheduled entry whose closure dispatches the boundary sweep.
     */
    protected function scheduledBoundaryEvent(): ScheduledEvent
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty(
            $events,
            'The scheduler holds no events at all, so routes/console.php was never loaded and '
            .'every assertion about the entry below would pass over an empty list.'
        );

        foreach ($events as $event) {
            if ($this->scheduledJob($event) instanceof BustStatusPageCacheForMaintenanceBoundaries) {
                return $event;
            }
        }

        $this->fail('No scheduled entry dispatches '.BustStatusPageCacheForMaintenanceBoundaries::class.'.');
    }

    /**
     * The job instance a `Schedule::job()` entry closes over, or null when the
     * event is not one.
     */
    protected function scheduledJob(ScheduledEvent $event): ?object
    {
        if (! $event instanceof CallbackEvent) {
            return null;
        }

        $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);

        if (! $callback instanceof Closure) {
            return null;
        }

        $job = (new ReflectionFunction($callback))->getClosureUsedVariables()['job'] ?? null;

        return is_object($job) ? $job : null;
    }

    /**
     * Drive one probe outcome through the real persistence path so the incident
     * lifecycle transition (and its off-lock dispatch) fires end to end.
     */
    protected function drivePersist(Monitor $monitor, MonitorStatus $status): void
    {
        $result = new CheckResult(
            monitorId: $monitor->id,
            region: 'us-east',
            checkedAt: new DateTimeImmutable,
            status: $status,
            statusCode: $status === MonitorStatus::Down ? 500 : 200,
            responseMs: 120,
            errorMessage: $status === MonitorStatus::Down ? 'boom' : null,
            timingDnsMs: 0,
            timingConnectMs: 0,
            timingTlsMs: 0,
            timingTtfbMs: 0,
            timingDownloadMs: 0,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: (string) Str::uuid(),
        );

        app(CheckPersistenceService::class)->persist($monitor->fresh(), $result);
    }

    /**
     * Creates a maintenance window on the page, attached to the given monitors.
     *
     * @param  array<int, Monitor>  $monitors  The window's own affected components.
     */
    protected function makeWindow(
        Team $team,
        StatusPage $page,
        array $monitors,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        bool $suppressAlerts = true,
    ): ScheduledMaintenance {
        $window = ScheduledMaintenance::query()->create([
            'team_id' => $team->id,
            'status_page_id' => $page->id,
            'title' => 'Database failover rehearsal',
            'description' => 'Planned work; the component is expected to be unavailable.',
            'suppress_alerts' => $suppressAlerts,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $window->monitors()->attach(array_map(fn (Monitor $monitor): string => (string) $monitor->id, $monitors));

        return $window;
    }

    /**
     * Creates a team escalation ladder so a suppressed open can be told apart
     * from a team that simply has nobody to page.
     */
    protected function makeEscalationLadder(Team $team): EscalationPolicy
    {
        $policy = EscalationPolicy::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary On-Call Policy',
        ]);

        EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        return $policy;
    }

    /**
     * A one-step ladder that pages a NAMED USER rather than the on-call
     * rotation, so firing a step needs no schedule or rotation fixture: the
     * fire-time cases care about whether the step pages at all, not about who
     * it resolves to.
     */
    protected function makeUserEscalationStep(Team $team, User $user): EscalationStep
    {
        $policy = EscalationPolicy::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary On-Call Policy',
        ]);

        return EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 5,
            'target_type' => EscalationTargetType::User,
            'target_id' => $user->id,
        ]);
    }

    /**
     * Creates a public status page showing the given monitors.
     */
    protected function makePage(Team $team, string $slug, Monitor ...$monitors): StatusPage
    {
        $page = StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
        ]);

        foreach ($monitors as $order => $monitor) {
            $page->monitors()->attach([$monitor->id => ['display_order' => $order]]);
        }

        return $page;
    }

    /**
     * Creates a persisted team whose single member is notifiable.
     *
     * @return array{0: Team, 1: User}
     */
    protected function makeTeam(): array
    {
        $user = User::query()->create([
            'name' => 'Maintenance Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Maintenance Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        return [$team, $user];
    }

    /**
     * Creates a monitor that opens an incident on its first down result.
     */
    protected function makeMonitor(Team $team, string $name): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => MonitorType::Http,
            'url' => 'https://example.com/'.Str::slug($name),
            'check_interval_sec' => 60,
            'incident_threshold' => 1,
            'consecutive_fails' => 0,
            'alert_on_down' => true,
            'alert_on_recover' => true,
            'show_on_status_page' => true,
            'last_status' => MonitorStatus::Up,
        ]);
    }
}
