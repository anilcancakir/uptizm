<?php

namespace Tests\Feature\Services;

use App\Enums\AiMode;
use App\Enums\EscalationTargetType;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Jobs\DispatchEscalationStep;
use App\Jobs\SweepAiSuggestions;
use App\Jobs\TriageAnomalyCandidate;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\NotificationChannel;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\FakeAnomalyTriageGateway;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\IncidentDispatcher;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Services\SystemTeam;
use Carbon\CarbonInterface;
use Database\Seeders\SystemTeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Locks the internal team that owns the public service catalog's monitors: its
 * ownership shape, the silence that shape buys, and its exemption from plan
 * accounting.
 *
 * Most of what matters here is an ABSENCE (no membership pivot, no page, no AI
 * selection, no quota effect), and an absence asserted on its own proves nothing
 * about the query that found it. So every absence in this file is paired with a
 * mirror control on an ordinary team, which must show the same query returning
 * the row it is supposed to find. A broken probe then fails the control instead
 * of certifying the absence.
 */
class SystemTeamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fastest cadence the plan catalog grants any tier (Enterprise's
     * `check_interval_sec`), which is what "the platform floor" means for
     * {@see PlanGate::minCheckIntervalSec()}.
     *
     * A literal on purpose: the implementation derives it from the catalog, so a
     * catalog edit must fail this assertion rather than silently redefine the
     * floor on both sides at once.
     */
    private const PLATFORM_FLOOR_SEC = 5;

    protected function setUp(): void
    {
        parent::setUp();

        // The fleet-sweep test resolves the AI boundary from the container; bind
        // the deterministic fake so no run can reach the real gateway.
        $this->app->bind(AnomalyTriageGateway::class, FakeAnomalyTriageGateway::class);
    }

    public function test_resolve_is_idempotent_and_creates_exactly_one_team(): void
    {
        $first = SystemTeam::resolve();
        $second = SystemTeam::resolve();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Team::query()->where('is_system', true)->count());
        $this->assertSame(1, Team::query()->count());
    }

    public function test_the_owner_fk_is_set_and_the_membership_pivot_is_empty(): void
    {
        $team = SystemTeam::resolve();
        $control = $this->makeCustomerTeam(withMember: true);

        // 1. The NOT NULL owner FK points at the dedicated user, not at a human.
        $this->assertNotNull($team->user_id);
        $this->assertSame(
            mb_strtolower((string) config('uptizm.system_team_email')),
            $team->owner->email,
        );
        $this->assertNull($team->owner->email_verified_at);

        // 2. No `team_user` row exists for it, so `users` is empty. The control
        //    proves the query and the relation both find a member when there is
        //    one: without it, an empty result could just mean a broken read.
        $this->assertSame(0, DB::table('team_user')->where('team_id', $team->id)->count());
        $this->assertTrue($team->users->isEmpty());

        $this->assertSame(1, DB::table('team_user')->where('team_id', $control->id)->count());
        $this->assertCount(1, $control->users);
    }

    public function test_the_system_user_is_absent_from_the_staff_allowlist(): void
    {
        // A NON-empty allowlist, because the default is empty and "not in an
        // empty list" is true of every address ever written. The control entry
        // proves the membership check can find an address at all.
        config(['uptizm.staff_emails' => ['ops@uptizm.com']]);

        $email = SystemTeam::resolve()->owner->email;

        $this->assertContains('ops@uptizm.com', config('uptizm.staff_emails'));
        $this->assertNotContains($email, config('uptizm.staff_emails'));
    }

    public function test_the_system_team_carries_no_alerting_configuration(): void
    {
        $team = SystemTeam::resolve();
        $control = $this->makeCustomerTeam();

        NotificationChannel::factory()->slack()->create(['team_id' => $control->id]);
        $this->makeDefaultPolicy($control);
        OnCallSchedule::factory()->create(['team_id' => $control->id]);

        foreach ([NotificationChannel::class, EscalationPolicy::class, OnCallSchedule::class] as $model) {
            $this->assertSame(0, $model::query()->where('team_id', $team->id)->count());
            $this->assertSame(1, $model::query()->where('team_id', $control->id)->count());
        }
    }

    public function test_opening_an_incident_on_a_service_monitor_pages_nobody(): void
    {
        Notification::fake();
        Queue::fake();
        $this->silenceSideEffects();

        $monitor = $this->makeServiceMonitor();

        $this->dispatchOpen($monitor, $this->makeIncident($monitor));

        // No person-notification (the users relation is empty) and no escalation
        // step queued. The ladder is the load-bearing one: `escalate()` is
        // UNGATED by `alert_on_down`, so only the absent policy stops it.
        Notification::assertNothingSent();
        Queue::assertNotPushed(DispatchEscalationStep::class);
    }

    public function test_a_send_to_the_system_teams_membership_reaches_nobody(): void
    {
        // The test above passes on FOUR independent layers at once (alert flags
        // off, no members, no policy, no channels), so it cannot speak for any
        // one of them: attaching a member to the system team leaves it green,
        // because `alert_on_down` already blocked that path. This isolates the
        // membership layer by evaluating `IncidentDispatcher.php:96`'s own
        // expression, `$incident->team->users`, and proves the send is a silent
        // no-op rather than a throw on an empty relation.
        Notification::fake();

        $service = $this->makeServiceMonitor();
        $incident = $this->makeIncident($service);

        $control = $this->makeCustomerTeam(withMember: true);
        $controlIncident = $this->makeIncident($this->makeMonitor($control));

        Notification::send($incident->team->users, new IncidentOpened($incident));
        Notification::assertNothingSent();

        Notification::send($controlIncident->team->users, new IncidentOpened($controlIncident));
        Notification::assertSentTo($control->owner, IncidentOpened::class);
    }

    public function test_the_same_dispatch_pages_an_ordinary_team(): void
    {
        // The mirror control for the test above: same dispatcher, same call
        // shape, a team that HAS a member and a policy. If this fails, the
        // silence proven above is the harness, not the ownership shape.
        Notification::fake();
        Queue::fake();
        $this->silenceSideEffects();

        $team = $this->makeCustomerTeam(withMember: true);
        $this->makeDefaultPolicy($team);
        $monitor = $this->makeMonitor($team, ['alert_on_down' => true]);

        $this->dispatchOpen($monitor, $this->makeIncident($monitor));

        Notification::assertSentTo($team->owner, IncidentOpened::class);
        Queue::assertPushed(DispatchEscalationStep::class);
    }

    public function test_plan_gate_reports_the_system_team_as_unlimited(): void
    {
        $gate = new PlanGate;
        $team = SystemTeam::resolve();
        $control = $this->makeCustomerTeam();

        // 1. `limits()` is the single short-circuit point: every count-style
        //    accessor derives from it, so all four report unlimited at once.
        $limits = $gate->limits($team);
        foreach (['monitors', 'status_pages', 'responders', 'subscribers'] as $key) {
            $this->assertNull($limits[$key], "Expected an unlimited `{$key}` cap.");
        }

        $this->assertNull($gate->monitorLimit($team));
        $this->assertNull($gate->statusPageLimit($team));
        $this->assertNull($gate->responderLimit($team));
        $this->assertNull($gate->subscriberLimit($team));

        // 2. The control team still reads the Free tier's caps, so the exemption
        //    is scoped to the flag and did not disable plan gating outright.
        $this->assertSame(1, $gate->monitorLimit($control));
        $this->assertSame(1, $gate->statusPageLimit($control));
        $this->assertSame(1, $gate->responderLimit($control));
        $this->assertSame(100, $gate->subscriberLimit($control));
    }

    public function test_the_accessors_that_do_not_count_keep_their_own_direction(): void
    {
        $gate = new PlanGate;
        $team = SystemTeam::resolve();
        $control = $this->makeCustomerTeam();

        // A FLOOR, so the exemption is the LOWEST interval, never a big number.
        $this->assertSame(self::PLATFORM_FLOOR_SEC, $gate->minCheckIntervalSec($team));
        $this->assertSame(180, $gate->minCheckIntervalSec($control));

        // A CAP, so the exemption is every region the relay supports.
        $this->assertSame(count(MonitorRegion::cases()), $gate->maxRegionsPerMonitor($team));
        $this->assertSame(1, $gate->maxRegionsPerMonitor($control));

        // Not a quota at all: it stays `off`, matching the AiMode every service
        // monitor is pinned to. An AI level above `off` would contradict the pin.
        $this->assertSame(AiMode::Off->value, $gate->aiLevel($team));
        $this->assertSame('inbox', $gate->aiLevel($control));
    }

    public function test_the_boolean_entitlements_stay_at_the_plan_value(): void
    {
        $gate = new PlanGate;
        $team = SystemTeam::resolve();

        // On its own (plan-less, so Free) every customer-feature boolean is off,
        // and the exemption does not force any of them true.
        $this->assertFalse($gate->allowsWhiteLabel($team));
        $this->assertFalse($gate->allowsPrivatePages($team));
        $this->assertFalse($gate->allowsSso($team));

        // Given a plan that grants them, they follow the plan: proof the three
        // read through to the catalog rather than being hardcoded false, while
        // the counts stay unlimited.
        $team->forceFill(['plan' => 'business'])->save();

        $this->assertTrue($gate->allowsWhiteLabel($team));
        $this->assertTrue($gate->allowsPrivatePages($team));
        $this->assertTrue($gate->allowsSso($team));
        $this->assertNull($gate->monitorLimit($team));
        $this->assertSame(AiMode::Off->value, $gate->aiLevel($team));
    }

    public function test_a_customer_teams_monitor_count_ignores_service_monitors(): void
    {
        $gate = new PlanGate;
        $customer = $this->makeCustomerTeam();
        $this->makeMonitor($customer);

        $this->makeServiceMonitor();
        $this->makeServiceMonitor();

        // The count is by `team_id`, so the customer is unaffected; counting the
        // system team's own two monitors proves the query is not simply empty.
        $this->assertSame(1, $gate->monitorsUsed($customer));
        $this->assertSame(2, $gate->monitorsUsed(SystemTeam::resolve()));
    }

    public function test_the_ai_fleet_sweep_never_selects_a_service_monitor(): void
    {
        Queue::fake();

        // Both monitors carry an identical anomalous window, so the only thing
        // separating them is `ai_mode`. The suggest-mode customer monitor is the
        // mirror control: it MUST be selected, or the sweep found nothing at all
        // and the system team's absence from the fan-out proves nothing.
        $service = $this->makeServiceMonitor();
        $customer = $this->makeMonitor($this->makeCustomerTeam(), ['ai_mode' => AiMode::Suggest]);

        $this->seedAnomalousWindow($service);
        $this->seedAnomalousWindow($customer);

        $this->app->call([new SweepAiSuggestions, 'handle']);

        Queue::assertPushed(
            TriageAnomalyCandidate::class,
            fn (TriageAnomalyCandidate $job): bool => $job->monitorId === (string) $customer->id,
        );
        Queue::assertNotPushed(
            TriageAnomalyCandidate::class,
            fn (TriageAnomalyCandidate $job): bool => $job->monitorId === (string) $service->id,
        );
    }

    public function test_the_seeder_provisions_the_team_against_an_empty_users_table(): void
    {
        // The production case: `teams.user_id` is a NOT NULL cascading FK, so a
        // seeder that expected to borrow an existing account would fail here.
        $this->assertSame(0, User::query()->count());

        $this->seed(SystemTeamSeeder::class);

        $team = Team::query()->where('is_system', true)->sole();
        $this->assertNotNull($team->user_id);
        $this->assertSame(
            mb_strtolower((string) config('uptizm.system_team_email')),
            User::query()->sole()->email,
        );
    }

    public function test_running_the_seeder_twice_creates_one_row(): void
    {
        $this->seed(SystemTeamSeeder::class);
        $this->seed(SystemTeamSeeder::class);

        $this->assertSame(1, Team::query()->where('is_system', true)->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_is_system_defaults_to_false_and_is_true_for_exactly_one_row(): void
    {
        $customer = $this->makeCustomerTeam();
        $this->makeCustomerTeam();
        SystemTeam::resolve();

        $this->assertFalse($customer->fresh()->is_system);
        $this->assertSame(1, Team::query()->where('is_system', true)->count());
        $this->assertSame(2, Team::query()->where('is_system', false)->count());
    }

    /**
     * A monitor owned by the system team, in the shape the catalog creates them:
     * AI off and down-alerts off.
     */
    protected function makeServiceMonitor(): Monitor
    {
        return $this->makeMonitor(SystemTeam::resolve(), [
            'ai_mode' => AiMode::Off,
            'alert_on_down' => false,
            'alert_on_recover' => false,
        ]);
    }

    /**
     * An ordinary customer team, optionally with its owner attached as a member
     * (the `team_user` pivot row the paging paths read).
     */
    protected function makeCustomerTeam(bool $withMember = false): Team
    {
        $user = User::query()->create([
            'name' => 'Customer Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Customer Ops',
        ]);

        if ($withMember) {
            $team->users()->attach($user->id, ['role' => 'admin']);
        }

        return $team;
    }

    /**
     * A monitor for the given team.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(Team $team, array $overrides = []): Monitor
    {
        return Monitor::query()->create(array_merge([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'last_status' => MonitorStatus::Up,
        ], $overrides));
    }

    /**
     * An opened incident for the monitor, shaped like a threshold open.
     */
    protected function makeIncident(Monitor $monitor): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }

    /**
     * Drive the shared off-lock dispatch for a freshly opened incident.
     */
    protected function dispatchOpen(Monitor $monitor, Incident $incident): void
    {
        $this->app->make(IncidentDispatcher::class)->dispatch($monitor, [
            'opened' => $incident,
            'resolved' => null,
            'status_change' => null,
        ]);
    }

    /**
     * Give the team a default escalation policy with one on-call step, so the
     * ladder has something to queue.
     */
    protected function makeDefaultPolicy(Team $team): EscalationPolicy
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
     * Silence the broadcast and status-page-cache side effects so a dispatch
     * test only exercises the paging paths.
     */
    protected function silenceSideEffects(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);

        $this->app->instance(StatusPageCache::class, Mockery::spy(StatusPageCache::class));
    }

    /**
     * Seed a window past the detector's cold-start gate (>= 100 checks over
     * >= 1800s) ending in a sustained spike, so the pure statistical detector
     * returns a real candidate. Mirrors `SweepAiSuggestionsTest`.
     */
    protected function seedAnomalousWindow(Monitor $monitor): void
    {
        $start = now()->subMinutes(120);

        for ($i = 0; $i < 110; $i++) {
            $this->makeCheck($monitor, $start->copy()->addMinutes($i), 200 + (($i % 5) - 2) * 10);
        }

        for ($i = 110; $i < 120; $i++) {
            $this->makeCheck($monitor, $start->copy()->addMinutes($i), 2500);
        }
    }

    /**
     * Record one probe check at a fixed time with the given response_ms.
     */
    protected function makeCheck(Monitor $monitor, CarbonInterface $checkedAt, int $responseMs): void
    {
        MonitorCheck::query()->create([
            'id' => (string) Str::uuid(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'global',
            'checked_at' => $checkedAt,
            'status' => MonitorStatus::Up->value,
            'status_code' => 200,
            'response_ms' => $responseMs,
        ]);
    }
}
