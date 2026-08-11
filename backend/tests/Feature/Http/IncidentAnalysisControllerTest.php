<?php

namespace Tests\Feature\Http;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\FakeIncidentAnalysisGateway;
use App\Services\Ai\IncidentAnalysisGateway;
use App\Services\Ai\IncidentAnalysisPayload;
use App\Services\Ai\IncidentAnalysisResult;
use App\Services\Ai\NonConformingAnalysisException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers GET /api/v1/incidents/{incident}/analysis: the post-incident RCA
 * summary backend.
 *
 * Every test binds the deterministic {@see FakeIncidentAnalysisGateway} (no
 * Anthropic call) except the budget test, which proves the real gateway is
 * never reached when the team is over its daily cap.
 */
class IncidentAnalysisControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_returns_a_deterministic_rca_from_the_fake_gateway(): void
    {
        $this->app->bind(IncidentAnalysisGateway::class, FakeIncidentAnalysisGateway::class);
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.summary',
            'Deterministic RCA stub: elevated response time on the affected monitor correlates with the incident window.',
        );
        $response->assertJsonPath('data.confidence', 'medium');
        $response->assertJsonPath(
            'data.contributing_factors.0',
            'Response time exceeded the configured threshold during the incident window.',
        );
        $response->assertJsonPath('data.stripped_citations', []);

        // Nothing degraded on the LLM path, so the reason is null. The key is
        // still PRESENT (the structure assertion below pins that): the client
        // reads null as "the model answered" and an absent key as a hole.
        $response->assertJsonPath('data.degrade_reason', null);
    }

    public function test_analysis_emits_the_enriched_evidence_and_actions_shape(): void
    {
        $this->app->bind(IncidentAnalysisGateway::class, FakeIncidentAnalysisGateway::class);
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'summary',
                'confidence',
                'contributing_factors',
                'stripped_citations',
                'degrade_reason',
                'evidence_for' => [
                    ['label', 'detail', 'source'],
                ],
                'evidence_against' => [
                    ['label', 'detail', 'source'],
                ],
                'suggested_actions' => [
                    ['title', 'rationale'],
                ],
            ],
        ]);

        // Every emitted evidence source is one of the three enum members: the
        // honest-AI-boundary never leaks a free-text or fabricated source.
        foreach ($response->json('data.evidence_for') as $evidence) {
            $this->assertContains($evidence['source'], ['timeline', 'check', 'monitor']);
        }
        foreach ($response->json('data.evidence_against') as $evidence) {
            $this->assertContains($evidence['source'], ['timeline', 'check', 'monitor']);
        }
    }

    public function test_analysis_degrades_to_the_fallback_shape_on_non_conforming_output(): void
    {
        // A gateway that always rejects models the case where the LLM returned
        // malformed nesting twice (past the single retry). The endpoint must
        // degrade to the deterministic baseline with the identical empty-array
        // wire shape, never a 500.
        $this->app->instance(IncidentAnalysisGateway::class, new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                throw new NonConformingAnalysisException('Malformed nested structured output.');
            }
        });

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonPath('data.confidence', 'low');
        $response->assertJsonPath('data.degrade_reason', 'output_untrusted');
        $this->assertSame([], $response->json('data.evidence_for'));
        $this->assertSame([], $response->json('data.evidence_against'));
        $this->assertSame([], $response->json('data.suggested_actions'));
    }

    public function test_analysis_degrades_to_the_fallback_shape_when_the_ai_service_is_unreachable(): void
    {
        // A gateway whose transport fails (an AI outage, a timeout, or a
        // missing/invalid key) degrades to the SAME deterministic baseline as
        // the over-budget and non-conforming paths: a logged 200 with the
        // identical empty-array wire shape, never a 500 that would blank the
        // incident detail screen.
        Log::spy();
        $this->app->instance(IncidentAnalysisGateway::class, new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                throw new ConnectionException('cURL error 7: connection refused.');
            }
        });

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonPath('data.confidence', 'low');
        // The reason is a VALUE, not a substring of a sentence: this assertion
        // used to read 'unavailable' out of the summary prose, which is exactly
        // the coupling the reason code removes.
        $response->assertJsonPath('data.degrade_reason', 'service_unreachable');
        $this->assertSame([], $response->json('data.evidence_for'));
        $this->assertSame([], $response->json('data.evidence_against'));
        $this->assertSame([], $response->json('data.suggested_actions'));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_analysis_degrades_when_the_provider_answers_with_an_error_body(): void
    {
        // `Laravel\Ai\Exceptions\AiException` extends Exception, so neither the
        // app's own NonConformingAnalysisException nor the Guzzle pair catches
        // it, and OpenRouter raises a PLAIN one for an error body delivered
        // in-band on HTTP 200: the most ordinary provider bad day there is used
        // to 500 this endpoint. To a caller a provider that answered with an
        // error body is a provider that did not answer, so it degrades under
        // the same reason as an unreachable service.
        Log::spy();
        $this->app->instance(IncidentAnalysisGateway::class, new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                throw new AiException('OpenRouter Error: [rate_limited] Provider returned an error.');
            }
        });

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonPath('data.confidence', 'low');
        $response->assertJsonPath('data.degrade_reason', 'service_unreachable');
        $this->assertSame([], $response->json('data.evidence_for'));

        // The provider chose that message, so it is not copied into our logs:
        // the log line names the incident and nothing the provider authored.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context === ['incident_id' => (string) $incident->id],
        );
    }

    public function test_degraded_summary_carries_only_the_objective_core(): void
    {
        // The summary is a machine-readable baseline for a direct API consumer,
        // not display copy: the client composes its own localized sentence from
        // the reason code. So it carries the incident's severity and lifecycle
        // and NO English reason clause to leak onto a Turkish screen.
        $this->app->instance(IncidentAnalysisGateway::class, new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                throw new NonConformingAnalysisException('Malformed nested structured output.');
            }
        });

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $summary = (string) $response->json('data.summary');
        $this->assertStringContainsString(IncidentSeverity::Critical->value, $summary);
        $this->assertStringContainsString(IncidentStatus::Detected->value, $summary);
        $this->assertStringNotContainsString('Deterministic baseline', $summary);
        $this->assertStringNotContainsString('(', $summary);
    }

    public function test_analysis_folds_the_timeline_and_recent_checks_into_the_payload(): void
    {
        $spy = new class implements IncidentAnalysisGateway
        {
            public ?IncidentAnalysisPayload $captured = null;

            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                $this->captured = $payload;

                return (new FakeIncidentAnalysisGateway)->analyze($payload);
            }
        };
        $this->app->instance(IncidentAnalysisGateway::class, $spy);

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $incident->updates()->create([
            'actor' => 'system',
            'author' => 'Threshold Engine',
            'status' => IncidentStatus::Detected->value,
            'message' => 'Latency crossed the critical bound.',
            'is_public' => true,
            'autonomous' => true,
            'display_at' => now(),
        ]);
        MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east',
            'checked_at' => now(),
            'status' => MonitorStatus::Down->value,
            'status_code' => 503,
            'response_ms' => 4000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);

        $payload = $spy->captured;
        $this->assertNotNull($payload);
        $this->assertSame((string) $incident->id, $payload->incidentId);
        $this->assertSame('Threshold Engine', $payload->timeline[0]['author']);
        $this->assertSame('us-east', $payload->checks[0]['region']);
        $this->assertContains((string) $monitor->id, $payload->knownMonitorIds);
    }

    public function test_analysis_404s_a_team_that_does_not_own_the_incident(): void
    {
        $this->app->bind(IncidentAnalysisGateway::class, FakeIncidentAnalysisGateway::class);
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        [, $otherUser] = $this->makeMonitor();

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(404);
    }

    public function test_analysis_over_budget_degrades_without_calling_the_llm(): void
    {
        // A zero daily cap forces every run over budget. Binding a gateway that
        // throws proves the LLM is never reached: a 200 with a low-confidence
        // deterministic summary means the budget guard short-circuited.
        config(['ai.budget.daily_per_team' => 0]);
        $this->app->instance(IncidentAnalysisGateway::class, new class implements IncidentAnalysisGateway
        {
            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                throw new RuntimeException('The LLM must not be called when over budget.');
            }
        });

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(200);
        $response->assertJsonPath('data.confidence', 'low');
        // As above: the reason left the prose and became a field.
        $response->assertJsonPath('data.degrade_reason', 'budget_exhausted');

        // The over-budget path emits the SAME enriched keys as the LLM path,
        // as empty arrays, so the client renders no hole.
        $this->assertSame([], $response->json('data.evidence_for'));
        $this->assertSame([], $response->json('data.evidence_against'));
        $this->assertSame([], $response->json('data.suggested_actions'));
    }

    public function test_analysis_requires_authentication(): void
    {
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $response->assertStatus(401);
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Incident Analysis Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Incident Analysis Team',
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
            'started_at' => now()->subMinutes(5),
        ]);
    }
}
