<?php

namespace Tests\Feature\Http;

use App\Enums\AiConfidence;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\AiAnalysisFeedback;
use App\Models\AiIncidentAnalysis;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\IncidentAnalysisGateway;
use App\Services\Ai\IncidentAnalysisPayload;
use App\Services\Ai\IncidentAnalysisResult;
use App\Services\Ai\NonConformingAnalysisException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the analysis STORE and the Helpful / Not helpful vote:
 * `GET /incidents/{incident}/analysis` reading through
 * `ai_incident_analyses`, and
 * `POST /incidents/{incident}/analysis/feedback` writing to
 * `ai_analysis_feedback`.
 *
 * Every test binds a counting gateway, because the thing under test is HOW
 * MANY TIMES the model is asked. A fake that only returns a fixed answer would
 * make every assertion here pass whether the store worked or not.
 */
class AnalysisFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_read_of_unchanged_evidence_does_not_ask_the_model_again(): void
    {
        $gateway = $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");
        $second = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame(1, $gateway->calls, 'The second read should have been served from the store.');
        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'Both reads should name the same stored analysis.',
        );
        $this->assertSame(1, AiIncidentAnalysis::query()->count());
    }

    public function test_a_check_that_changes_the_evidence_buys_a_new_analysis(): void
    {
        $gateway = $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        // A different status code is a different picture, not a new tick of the
        // same one.
        $this->makeCheck($monitor, MonitorStatus::Down, 500, 3900);

        $second = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $this->assertSame(2, $gateway->calls);
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, AiIncidentAnalysis::query()->count());
    }

    public function test_another_tick_of_the_same_failure_does_not_buy_a_new_analysis(): void
    {
        $gateway = $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        // Same region, same status, same code, same latency: the clock moved and
        // nothing else did. This is the case the whole store exists for, because
        // an OPEN incident is the one people keep refreshing.
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $this->assertSame(1, $gateway->calls);
    }

    public function test_refresh_asks_the_model_again_even_when_the_evidence_is_unchanged(): void
    {
        $gateway = $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis?refresh=1");

        $this->assertSame(2, $gateway->calls, 'Retry is the one path that spends on purpose.');
        // The evidence did not change, so the re-ask replaces the text on the
        // same fingerprint rather than leaving two rows an operator could rate
        // independently.
        $this->assertSame(1, AiIncidentAnalysis::query()->count());
    }

    public function test_the_read_carries_the_analysis_id_and_this_readers_vote(): void
    {
        $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['id', 'feedback']]);
        $this->assertNotNull($response->json('data.id'));
        $this->assertNull($response->json('data.feedback'), 'Nobody has voted yet.');
    }

    public function test_a_vote_is_recorded_and_reflected_back(): void
    {
        $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $analysisId = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis")
            ->json('data.id');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/analysis/feedback", [
                'analysis_id' => $analysisId,
                'helpful' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.feedback', true);
        $response->assertJsonPath('data.id', $analysisId);
        $this->assertDatabaseHas('ai_analysis_feedback', [
            'analysis_id' => $analysisId,
            'user_id' => $user->id,
            'helpful' => true,
        ]);
    }

    public function test_changing_a_vote_updates_the_row_rather_than_adding_one(): void
    {
        $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $analysisId = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis")
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/analysis/feedback", [
                'analysis_id' => $analysisId,
                'helpful' => true,
            ]);
        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/analysis/feedback", [
                'analysis_id' => $analysisId,
                'helpful' => false,
            ]);

        $second->assertJsonPath('data.feedback', false);
        $this->assertSame(1, AiAnalysisFeedback::query()->count());
    }

    public function test_a_refresh_that_answers_differently_drops_the_vote_it_invalidated(): void
    {
        // The counting gateway numbers its answers, so the retry returns text
        // the operator has not read. Their rating was about the old words.
        $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $analysisId = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis")
            ->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/analysis/feedback", [
                'analysis_id' => $analysisId,
                'helpful' => false,
            ]);
        $this->assertSame(1, AiAnalysisFeedback::query()->count());

        $refreshed = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis?refresh=1");

        $refreshed->assertJsonPath('data.id', $analysisId);
        $refreshed->assertJsonPath('data.feedback', null);
        $this->assertSame(
            0,
            AiAnalysisFeedback::query()->count(),
            'A rating must not survive the text it was about.',
        );
    }

    public function test_a_refresh_that_answers_identically_keeps_the_vote(): void
    {
        // Nothing the operator read has changed, so their rating still stands.
        $this->app->instance(IncidentAnalysisGateway::class, new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                return new IncidentAnalysisResult(
                    summary: 'The same answer every time.',
                    confidence: AiConfidence::Medium,
                    contributingFactors: [],
                );
            }
        });
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $analysisId = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis")
            ->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/analysis/feedback", [
                'analysis_id' => $analysisId,
                'helpful' => true,
            ]);

        $refreshed = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis?refresh=1");

        $refreshed->assertJsonPath('data.feedback', true);
        $this->assertSame(1, AiAnalysisFeedback::query()->count());
    }

    public function test_a_vote_on_another_teams_analysis_is_masked_as_404(): void
    {
        $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $analysisId = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis")
            ->json('data.id');

        [$otherMonitor, $otherUser] = $this->makeMonitor();
        $otherIncident = $this->makeIncident($otherMonitor);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/v1/incidents/{$otherIncident->id}/analysis/feedback", [
                'analysis_id' => $analysisId,
                'helpful' => false,
            ]);

        $response->assertStatus(404);
        $this->assertSame(0, AiAnalysisFeedback::query()->count());
    }

    public function test_a_degraded_analysis_is_not_stored_and_offers_nothing_to_rate(): void
    {
        // A gateway that always degrades: the service catches this and answers
        // from its deterministic baseline.
        $this->app->bind(IncidentAnalysisGateway::class, fn () => new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                throw new NonConformingAnalysisException('Untrusted output.');
            }
        });

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, MonitorStatus::Down, 503, 4100);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonPath('data.degrade_reason', 'output_untrusted');
        $response->assertJsonPath('data.id', null);
        $response->assertJsonPath('data.feedback', null);
        $this->assertSame(0, AiIncidentAnalysis::query()->count());
    }

    /**
     * Bind a gateway that counts how many times it was asked.
     */
    protected function bindCountingGateway(): object
    {
        $gateway = new class implements IncidentAnalysisGateway
        {
            public int $calls = 0;

            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                $this->calls++;

                return new IncidentAnalysisResult(
                    summary: 'Counting stub answer number '.$this->calls.'.',
                    confidence: AiConfidence::Medium,
                    contributingFactors: [],
                );
            }
        };

        $this->app->instance(IncidentAnalysisGateway::class, $gateway);

        return $gateway;
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Analysis Feedback Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Analysis Feedback Team',
            // AI incident analysis is an analysis-tier (Pro+) feature.
            'plan' => 'pro',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$monitor, $user];
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
            'started_at' => now()->subMinutes(30),
        ]);
    }

    protected function makeCheck(Monitor $monitor, MonitorStatus $status, int $code, int $ms): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'region' => 'eu-central',
            'status' => $status,
            'status_code' => $code,
            'response_ms' => $ms,
            'checked_at' => now(),
        ]);
    }
}
