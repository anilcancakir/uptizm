<?php

namespace Tests\Feature\Http;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MonitorCheckController;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckAggregateService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Services\Monitoring\CheckAggregateServiceTest;

/**
 * Locks the dashboard + monitor-check read endpoints: {@see DashboardController::stats()}
 * must fold monitor status counts and the average response time for the
 * caller's current team, {@see MonitorCheckController::uptime()} and
 * {@see MonitorCheckController::responseTimes()} must delegate to
 * {@see CheckAggregateService}, and {@see DashboardController::aiInbox()}
 * must always return an empty envelope (AI triage is deferred), never a 404.
 *
 * No `api/v1/dashboard/*` or `api/v1/monitors/{monitor}/checks/*` routes
 * exist yet (routes land in a later step), so these tests invoke the
 * controller actions directly with a request carrying an authenticated user,
 * matching the pattern used by {@see CheckAggregateServiceTest}.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_counts_monitors_by_status_and_averages_response_time(): void
    {
        $team = $this->makeTeam();

        $this->makeMonitor($team, MonitorStatus::Up, responseMs: 100);
        $this->makeMonitor($team, MonitorStatus::Up, responseMs: 200);
        $this->makeMonitor($team, MonitorStatus::Down, responseMs: null);
        $this->makeMonitor($team, MonitorStatus::Degraded, responseMs: 300);

        $this->makeIncident($team, IncidentStatus::Investigating);
        $this->makeIncident($team, IncidentStatus::Resolved);

        $response = (new DashboardController)->stats($this->requestFor($team));
        $data = $response->getData(true)['data'];

        $this->assertSame(2, $data['monitors_up']);
        $this->assertSame(1, $data['monitors_down']);
        $this->assertSame(1, $data['monitors_degraded']);
        $this->assertSame(0, $data['monitors_paused']);
        $this->assertSame(0, $data['monitors_pending']);
        $this->assertSame(4, $data['monitors_total']);
        $this->assertSame(200, $data['avg_response_ms']);
        $this->assertSame(1, $data['open_incidents']);
    }

    public function test_stats_counts_a_monitor_awaiting_its_first_check(): void
    {
        $team = $this->makeTeam();

        // A monitor created moments ago has no last_status, so it lands in none
        // of the four status buckets. Without its own count plus a real total, a
        // client summing those buckets reads this team as having zero monitors.
        $this->makeMonitor($team, MonitorStatus::Up, responseMs: 120);
        $this->makePendingMonitor($team);
        $this->makePendingMonitor($team);

        $response = (new DashboardController)->stats($this->requestFor($team));
        $data = $response->getData(true)['data'];

        $this->assertSame(1, $data['monitors_up']);
        $this->assertSame(0, $data['monitors_down']);
        $this->assertSame(2, $data['monitors_pending']);
        $this->assertSame(3, $data['monitors_total']);
    }

    public function test_stats_never_leaks_another_teams_monitors(): void
    {
        $team = $this->makeTeam();
        $otherTeam = $this->makeTeam();

        $this->makeMonitor($otherTeam, MonitorStatus::Down, responseMs: 500);

        $response = (new DashboardController)->stats($this->requestFor($team));
        $data = $response->getData(true)['data'];

        $this->assertSame(0, $data['monitors_down']);
    }

    public function test_stats_reports_rolling_24h_uptime_and_its_delta_from_checks(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, MonitorStatus::Up, responseMs: 100);

        // Last 24h: 3 up, 1 down, 1 paused -> 3 / (3 + 1) = 75.00% (paused is
        // excluded, matching the daily-uptime service definition).
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($monitor, MonitorStatus::Down, responseMs: 0);
        $this->makeCheck($monitor, MonitorStatus::Paused, responseMs: 0);

        // Prior 24h (~30h ago): all up -> 100.00%, so the delta is 75 - 100.
        $priorAt = now()->subHours(30);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100, at: $priorAt);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100, at: $priorAt);

        $response = (new DashboardController)->stats($this->requestFor($team));
        $data = $response->getData(true)['data'];

        $this->assertEqualsWithDelta(75.0, $data['uptime_24h'], 0.001);
        $this->assertEqualsWithDelta(-25.0, $data['uptime_24h_delta'], 0.001);
    }

    public function test_stats_uptime_is_null_without_checks_and_ignores_other_teams(): void
    {
        $team = $this->makeTeam();
        $this->makeMonitor($team, MonitorStatus::Up, responseMs: 100);

        // Another team's fresh check must not bleed into this team's uptime.
        $otherTeam = $this->makeTeam();
        $otherMonitor = $this->makeMonitor($otherTeam, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($otherMonitor, MonitorStatus::Up, responseMs: 100);

        $response = (new DashboardController)->stats($this->requestFor($team));
        $data = $response->getData(true)['data'];

        $this->assertNull($data['uptime_24h']);
        $this->assertNull($data['uptime_24h_delta']);
    }

    public function test_active_incidents_excludes_resolved_ones(): void
    {
        $team = $this->makeTeam();

        $this->makeIncident($team, IncidentStatus::Investigating);
        $this->makeIncident($team, IncidentStatus::Resolved);

        $response = (new DashboardController)->activeIncidents($this->requestFor($team));
        $data = $response->response()->getData(true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame('investigating', $data[0]['lifecycle']);
    }

    public function test_monitors_snapshot_returns_the_teams_monitors_with_last_status(): void
    {
        $team = $this->makeTeam();
        $this->makeMonitor($team, MonitorStatus::Up, responseMs: 120);

        $response = (new DashboardController)->monitorsSnapshot($this->requestFor($team));
        $data = $response->response()->getData(true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame('up', $data[0]['last_status']);
    }

    public function test_ai_inbox_returns_an_empty_envelope_not_a_404(): void
    {
        $team = $this->makeTeam();

        $response = (new DashboardController)->aiInbox($this->requestFor($team));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['data']);
    }

    public function test_uptime_summary_reports_the_range_and_ratio(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, MonitorStatus::Up, responseMs: 100);

        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 120);
        $this->makeCheck($monitor, MonitorStatus::Down, responseMs: 0);

        $controller = new MonitorCheckController(new CheckAggregateService);
        $request = $this->requestFor($team, ['range' => '24h']);

        $response = $controller->uptime($request, $monitor);
        $data = $response->getData(true)['data'];

        $this->assertSame('24h', $data['range']);
        $this->assertSame(3, $data['total']);
        $this->assertEqualsWithDelta(0.6667, $data['uptime_ratio'], 0.001);
    }

    public function test_response_times_returns_bucketed_dots(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, MonitorStatus::Up, responseMs: 100);

        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 200);

        $controller = new MonitorCheckController(new CheckAggregateService);
        $request = $this->requestFor($team, ['range' => '24h']);

        // A JsonResponse now, not a resource collection: the endpoint stopped
        // hydrating a synthetic MonitorCheck per bucket, which was ~85% of the
        // request on the default 24h range of a busy monitor. So `getData()`
        // directly instead of `->response()->getData()`. The wire shape itself is
        // pinned key for key by MonitorResponseTimesControllerTest.
        $payload = $controller->responseTimes($request, $monitor)->getData(true);

        $this->assertGreaterThan(0, count($payload['data']));
        $this->assertArrayHasKey('response_ms', $payload['data'][0]);
    }

    /**
     * Builds a request carrying an authenticated user whose current team is
     * `$team`, mirroring what Sanctum would resolve for a real HTTP call.
     */
    protected function requestFor(Team $team, array $query = []): Request
    {
        $user = User::query()->create([
            'name' => 'Dashboard Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $request = Request::create('/', 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Team Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Dashboard Team',
        ]);
    }

    protected function makeMonitor(Team $team, MonitorStatus $lastStatus, ?int $responseMs): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime '.Str::random(6),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'last_status' => $lastStatus,
            'last_response_ms' => $responseMs,
        ]);
    }

    /**
     * A monitor that has never been checked: no last_status, no timing.
     */
    protected function makePendingMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Pending '.Str::random(6),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
    }

    protected function makeCheck(Monitor $monitor, MonitorStatus $status, int $responseMs, ?CarbonInterface $at = null): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'id' => (string) Str::orderedUuid(),
            'checked_at' => $at ?? now(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east-1',
            'status' => $status,
            'response_ms' => $responseMs,
        ]);
    }

    protected function makeIncident(Team $team, IncidentStatus $lifecycle): Incident
    {
        return Incident::query()->create([
            'team_id' => $team->id,
            'title' => 'Incident '.Str::random(6),
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'started_at' => now(),
        ]);
    }
}
