<?php

namespace Tests\Feature\Ai;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\GenerateWeeklyDigest;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\TeamDigest;
use App\Models\User;
use App\Services\Ai\DigestGateway;
use App\Services\Ai\DigestPayload;
use App\Services\Ai\DigestResult;
use App\Services\Ai\FakeDigestGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the weekly-digest job: it composes a team's week (uptime + incidents)
 * into a fenced {@see DigestPayload}, spends the per-team AI budget at the
 * spend point, degrades to a deterministic digest over budget or on gateway
 * failure, and persists exactly one team-scoped {@see TeamDigest} row.
 *
 * The LLM is always faked (no real Anthropic call in CI).
 */
class GenerateWeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_within_budget_persists_an_llm_digest(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);
        [$team, $monitor] = $this->makeTeam();
        $this->makeIncident($monitor);

        GenerateWeeklyDigest::dispatchSync((string) $team->id);

        $digest = TeamDigest::query()->sole();
        $this->assertSame($team->id, $digest->team_id);
        $this->assertSame(1, $digest->incident_count);
        $this->assertStringContainsString('Deterministic digest stub', $digest->summary);
        $this->assertSame('medium', $digest->confidence->value);
        $this->assertNotEmpty($digest->highlights);
        $this->assertNotNull($digest->generated_at);
    }

    public function test_over_budget_persists_a_deterministic_digest(): void
    {
        // A zero daily cap forces every run over budget: the LLM is never
        // called yet the digest must still be persisted.
        config(['ai.budget.daily_per_team' => 0]);
        [$team, $monitor] = $this->makeTeam();
        $this->makeIncident($monitor);
        $this->app->instance(DigestGateway::class, new class implements DigestGateway
        {
            public function summarize(DigestPayload $payload): DigestResult
            {
                throw new RuntimeException('The LLM must not be called when over budget.');
            }
        });

        GenerateWeeklyDigest::dispatchSync((string) $team->id);

        $digest = TeamDigest::query()->sole();
        $this->assertSame('low', $digest->confidence->value);
        $this->assertStringContainsString('budget', strtolower($digest->summary));
    }

    public function test_gateway_failure_persists_a_deterministic_digest(): void
    {
        [$team, $monitor] = $this->makeTeam();
        $this->makeIncident($monitor);
        $this->app->instance(DigestGateway::class, new class implements DigestGateway
        {
            public function summarize(DigestPayload $payload): DigestResult
            {
                throw new RuntimeException('Simulated provider outage.');
            }
        });

        GenerateWeeklyDigest::dispatchSync((string) $team->id);

        $digest = TeamDigest::query()->sole();
        $this->assertSame('low', $digest->confidence->value);
        $this->assertNotEmpty($digest->summary);
    }

    public function test_digest_folds_the_teams_own_incidents_and_uptime_into_the_payload(): void
    {
        $spy = new class implements DigestGateway
        {
            public ?DigestPayload $captured = null;

            public function summarize(DigestPayload $payload): DigestResult
            {
                $this->captured = $payload;

                return (new FakeDigestGateway)->summarize($payload);
            }
        };
        $this->app->instance(DigestGateway::class, $spy);

        [$team, $monitor] = $this->makeTeam();
        $incident = $this->makeIncident($monitor);
        DB::table('monitor_daily_uptime')->insert([
            'id' => (string) Str::uuid(),
            'monitor_id' => $monitor->id,
            'team_id' => $team->id,
            'date' => now()->subDay()->format('Y-m-d'),
            'uptime_percent' => 97.5,
            'total_checks' => 100,
            'failed_checks' => 2,
            'worst_status' => 'partial_outage',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        GenerateWeeklyDigest::dispatchSync((string) $team->id);

        $payload = $spy->captured;
        $this->assertNotNull($payload);
        $this->assertSame((string) $team->id, $payload->teamId);
        $this->assertSame(1, $payload->incidentCount);
        $this->assertSame((string) $incident->id, $payload->incidents[0]['incident_id']);
        $this->assertContains((string) $incident->id, $payload->knownIncidentIds);
        $this->assertContains((string) $monitor->id, $payload->knownMonitorIds);
        $this->assertEqualsWithDelta(97.5, $payload->uptimePercent, 0.01);
    }

    public function test_digest_is_team_scoped(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);
        [$teamA, $monitorA] = $this->makeTeam();
        [$teamB, $monitorB] = $this->makeTeam();
        $this->makeIncident($monitorA);
        $this->makeIncident($monitorB);
        $this->makeIncident($monitorB);

        GenerateWeeklyDigest::dispatchSync((string) $teamA->id);

        $digest = TeamDigest::query()->sole();
        $this->assertSame($teamA->id, $digest->team_id);
        $this->assertSame(1, $digest->incident_count);
    }

    public function test_unknown_team_persists_nothing(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);

        GenerateWeeklyDigest::dispatchSync((string) Str::uuid());

        $this->assertSame(0, TeamDigest::query()->count());
    }

    /**
     * @return array{0: Team, 1: Monitor}
     */
    protected function makeTeam(): array
    {
        $user = User::query()->create([
            'name' => 'Digest Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Digest Team',
        ]);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$team, $monitor];
    }

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
            'started_at' => now()->subDays(2),
        ]);
    }
}
