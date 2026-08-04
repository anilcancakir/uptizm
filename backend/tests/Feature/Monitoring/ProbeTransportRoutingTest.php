<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Jobs\PerformMonitorCheck;
use App\Jobs\ProcessCheckResult;
use App\Jobs\ScheduleMonitorChecks;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\LocalProbeEngine;
use App\Services\Monitoring\ProbeTransport;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\CheckResult;
use App\Support\Services\SystemTeam;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Jobs\CheckJobTest;
use Tests\TestCase;

/**
 * Which network a probe leaves through is a boundary, not a preference.
 *
 * The internal system team's catalog monitors are probed from THIS server,
 * egressing through the third-party proxy pool. Every other monitor keeps going
 * through the Cloudflare worker. The worker's isolation from our own network is
 * structural: it runs somewhere else entirely. Probing a CUSTOMER's target from
 * here would put this VPS, which also runs the app, PostgreSQL, Redis and
 * sibling sites, on the wire as the origin of a request nobody here authored.
 *
 * So the routing is asserted in both directions with recording doubles, and the
 * invariant is asserted a second time at the engine's own entry point: the
 * decision and the guard live in different classes on purpose, so a careless
 * caller (or an edit to the decision) cannot move a customer monitor onto our
 * egress without the guard also being deleted.
 *
 * The doubles are bound on the CONCRETE classes and the job is dispatched
 * THROUGH the container, which is the only arrangement in which a missing
 * `ProbeTransport` binding surfaces at all: the existing positional
 * `handle()` call sites in {@see CheckJobTest} hand both
 * arguments in by hand and never ask the container for anything, so they stay
 * green while every check in production throws.
 */
class ProbeTransportRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_system_teams_monitor_is_probed_by_the_local_engine(): void
    {
        $monitor = $this->systemMonitor();
        $relay = $this->recordingRelay();
        $engine = $this->recordingEngine();

        Bus::fake([
            ProcessCheckResult::class,
        ]);

        PerformMonitorCheck::dispatch($monitor, 'us-east');

        $this->assertSame([(string) $monitor->id], $engine->seen);
        $this->assertSame([], $relay->seen);

        // The seam swap changes WHICH object is called and nothing about what
        // happens to the result, so the handoff downstream must still happen.
        Bus::assertDispatched(ProcessCheckResult::class);
    }

    public function test_a_customer_monitor_is_probed_by_the_relay(): void
    {
        $monitor = $this->customerMonitor();
        $relay = $this->recordingRelay();
        $engine = $this->recordingEngine();

        Bus::fake([
            ProcessCheckResult::class,
        ]);

        PerformMonitorCheck::dispatch($monitor, 'us-east');

        $this->assertSame([(string) $monitor->id], $relay->seen);
        $this->assertSame([], $engine->seen);

        Bus::assertDispatched(ProcessCheckResult::class);
    }

    public function test_the_local_engine_refuses_a_monitor_the_system_team_does_not_own(): void
    {
        $monitor = $this->customerMonitor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not be probed from this server');

        app(LocalProbeEngine::class)->dispatch($monitor, 'us-east');
    }

    public function test_the_engines_refusal_survives_a_subclass_that_replaces_the_probe(): void
    {
        // The guard sits in `dispatch()`, ABOVE the probe seam, so replacing the
        // probe (a double here, a future subclass in production) cannot skip it.
        // This is what makes the second assertion independent of the first rather
        // than a restatement of it.
        $engine = $this->recordingEngine();
        $monitor = $this->customerMonitor();

        try {
            $engine->dispatch($monitor, 'us-east');

            $this->fail('The engine probed a monitor the system team does not own.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('may not be probed from this server', $e->getMessage());
        }

        $this->assertSame([], $engine->seen);
    }

    public function test_the_transport_contract_is_bound_to_the_relay_by_default(): void
    {
        // Unbound, `Container::call()` throws BindingResolutionException on every
        // single check in production while the whole suite stays green. The
        // default is the relay, so a caller that has no opinion keeps the
        // behaviour it had before the interface existed.
        $this->assertInstanceOf(RelayClient::class, app(ProbeTransport::class));
    }

    public function test_the_scheduler_eager_loads_the_team_the_routing_predicate_reads(): void
    {
        // The predicate reads `$monitor->team->is_system` once per check, so the
        // fan-out query has to carry the relation or a busy tick pays one extra
        // SELECT per due monitor.
        Bus::fake([
            PerformMonitorCheck::class,
        ]);

        $this->customerMonitor(['next_check_at' => now()->subMinute()]);
        $this->customerMonitor(['next_check_at' => now()->subMinute()]);

        $teamQueries = [];

        DB::listen(function ($query) use (&$teamQueries): void {
            if (str_contains($query->sql, '"teams"')) {
                $teamQueries[] = $query;
            }
        });

        (new ScheduleMonitorChecks)->handle();

        // One batched load for both monitors. Zero means the relation is not
        // eager-loaded at all; two means it is loaded per monitor.
        $this->assertCount(
            1,
            $teamQueries,
            'The due-monitor query did not eager-load the team in a single batch.'
        );
        $this->assertCount(2, $teamQueries[0]->bindings);
    }

    /**
     * A {@see RelayClient} double that records the monitors handed to it and
     * performs no HTTP call, bound on the concrete class so the container still
     * resolves the {@see ProbeTransport} binding to reach it.
     */
    protected function recordingRelay(): RelayClient
    {
        $relay = new class extends RelayClient
        {
            /**
             * @var list<string>
             */
            public array $seen = [];

            public function __construct() {}

            public function dispatch(Monitor $monitor, string $region): CheckResult
            {
                $this->seen[] = (string) $monitor->id;

                return ProbeTransportRoutingTest::reading($monitor, $region);
            }
        };

        $this->app->instance(RelayClient::class, $relay);

        return $relay;
    }

    /**
     * A {@see LocalProbeEngine} double overriding only the probe seam, so the
     * engine's own system-team guard still runs for real.
     */
    protected function recordingEngine(): LocalProbeEngine
    {
        $engine = new class extends LocalProbeEngine
        {
            /**
             * @var list<string>
             */
            public array $seen = [];

            public function __construct() {}

            protected function probe(Monitor $monitor, string $region): CheckResult
            {
                $this->seen[] = (string) $monitor->id;

                return ProbeTransportRoutingTest::reading($monitor, $region);
            }
        };

        $this->app->instance(LocalProbeEngine::class, $engine);

        return $engine;
    }

    /**
     * One successful HTTP reading, the minimum both doubles have to return for
     * the handoff below the seam to run unchanged.
     */
    public static function reading(Monitor $monitor, string $region): CheckResult
    {
        return new CheckResult(
            monitorId: (string) $monitor->id,
            region: $region,
            checkedAt: new DateTimeImmutable,
            status: MonitorStatus::Up,
            statusCode: 200,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: (string) Str::uuid(),
        );
    }

    /**
     * A monitor owned by the one internal team the local engine may probe for,
     * resolved through the production resolver because `is_system` is not
     * fillable.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function systemMonitor(array $attributes = []): Monitor
    {
        return $this->monitorFor(SystemTeam::resolve(), $attributes);
    }

    /**
     * A monitor on an ordinary tenant team, each call on a team of its own so a
     * multi-monitor tick spans more than one team.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function customerMonitor(array $attributes = []): Monitor
    {
        $user = User::factory()->create();

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        return $this->monitorFor($team, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function monitorFor(Team $team, array $attributes): Monitor
    {
        return Monitor::query()->create(array_merge([
            'team_id' => $team->id,
            'name' => 'Catalog probe',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'regions' => ['us-east'],
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ], $attributes));
    }
}
