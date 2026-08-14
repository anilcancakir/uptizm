<?php

namespace Tests\Feature\Http;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AssistantGateway;
use App\Services\Ai\AssistantPayload;
use App\Services\Ai\AssistantResult;
use App\Services\Ai\FakeAssistantGateway;
use App\Services\Ai\NonConformingAnalysisException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers POST /api/v1/assistant: the grounded floating-assistant backend that
 * answers questions about the current team's monitors/incidents.
 *
 * Every test binds the deterministic {@see FakeAssistantGateway} (no
 * Anthropic call) except the budget test, which proves the real gateway is
 * never reached when the team is over its daily cap.
 */
class AssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_returns_a_deterministic_answer_from_the_fake_gateway(): void
    {
        $this->app->bind(AssistantGateway::class, FakeAssistantGateway::class);
        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.answer',
            'Deterministic assistant stub: ask a follow-up question and I will answer from your team\'s current monitors and incidents.',
        );
        $response->assertJsonPath('data.confidence', 'medium');
        $response->assertJsonPath('data.stripped_citations', []);
    }

    public function test_assistant_folds_the_team_monitors_and_incidents_into_the_payload(): void
    {
        $spy = new class implements AssistantGateway
        {
            public ?AssistantPayload $captured = null;

            public function answer(AssistantPayload $payload): AssistantResult
            {
                $this->captured = $payload;

                return (new FakeAssistantGateway)->answer($payload);
            }
        };
        $this->app->instance(AssistantGateway::class, $spy);

        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'What is currently down?',
            ]);

        $response->assertStatus(200);

        $payload = $spy->captured;
        $this->assertNotNull($payload);
        $this->assertSame((string) $monitor->team_id, $payload->teamId);
        $this->assertSame('What is currently down?', $payload->question);
        $this->assertContains((string) $monitor->id, $payload->knownMonitorIds);
        $this->assertContains((string) $incident->id, $payload->knownIncidentIds);
    }

    public function test_assistant_over_budget_degrades_without_calling_the_llm(): void
    {
        // A zero daily cap forces every run over budget. Binding a gateway
        // that throws proves the LLM is never reached: a 200 with a
        // low-confidence deterministic answer means the budget guard
        // short-circuited.
        config(['ai.budget.daily_per_team' => 0]);
        $this->app->instance(AssistantGateway::class, new class implements AssistantGateway
        {
            public function answer(AssistantPayload $payload): AssistantResult
            {
                throw new RuntimeException('The LLM must not be called when over budget.');
            }
        });

        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.confidence', 'low');
        // The REASON rather than a word in the sentence. This used to assert the
        // string contained "budget", which pinned English copy as though it were
        // the contract and would have gone red the moment the sentence was
        // localized. `degrade_reason` is the fact; the sentence is presentation.
        $response->assertJsonPath('data.degrade_reason', 'budget_exhausted');
    }

    public function test_the_over_budget_sentence_is_written_in_the_callers_language(): void
    {
        // It was a hardcoded English literal, and the client renders `answer`
        // straight into the chat bubble, so a Turkish operator over budget read
        // an English sentence attributed to Uptizm AI.
        config(['ai.budget.daily_per_team' => 0]);
        [, $user] = $this->makeMonitor();
        $user->forceFill(['locale' => 'tr'])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'Hangi izleyiciler yavaş?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.degrade_reason', 'budget_exhausted');
        $this->assertSame(
            __('assistant.degraded_budget', [], 'tr'),
            $response->json('data.answer'),
        );
        $this->assertNotSame(
            __('assistant.degraded_budget', [], 'en'),
            $response->json('data.answer'),
            'the two locales must actually differ, or this asserts nothing',
        );
    }

    public function test_a_real_answer_carries_no_degrade_reason(): void
    {
        // The other side of the flag: without this, always-degraded would pass.
        $this->app->bind(AssistantGateway::class, FakeAssistantGateway::class);
        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.degrade_reason', null);
    }

    public function test_a_transport_failure_answers_service_unavailable_rather_than_500(): void
    {
        // Reproduced by accident against the live provider:
        // `cURL error 28: Connection timed out after 10001 milliseconds`. Nothing
        // caught it, so it left as a 500 whose body is Laravel's own English
        // "Server Error", and the client puts the backend's message straight into
        // its toast. `IncidentAnalysisService` has caught this pair since the day
        // it shipped; this endpoint never did.
        $this->app->instance(AssistantGateway::class, new class implements AssistantGateway
        {
            public function answer(AssistantPayload $payload): AssistantResult
            {
                throw new ConnectionException('cURL error 28: Connection timed out');
            }
        });

        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(503);
        $this->assertSame(__('assistant.unavailable'), $response->json('message'));
    }

    public function test_a_provider_error_body_answers_service_unavailable(): void
    {
        // The branch that is easy to leave out and is the most ordinary provider
        // bad day there is: `laravel/ai` raises a PLAIN `AiException` for an
        // error body delivered IN-BAND with HTTP 200, so it descends from neither
        // this app's exceptions nor the Guzzle pair above.
        $this->app->instance(AssistantGateway::class, new class implements AssistantGateway
        {
            public function answer(AssistantPayload $payload): AssistantResult
            {
                throw new AiException('rate limited');
            }
        });

        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(503);
    }

    public function test_untrusted_output_answers_service_unavailable(): void
    {
        // The gateway rejects non-conforming structured output after one retry.
        // It threw a bare `RuntimeException`, which a caller can only catch by
        // catching every runtime error, so it now throws the typed exception the
        // analysis gateway already uses.
        $this->app->instance(AssistantGateway::class, new class implements AssistantGateway
        {
            public function answer(AssistantPayload $payload): AssistantResult
            {
                throw new NonConformingAnalysisException('untrusted');
            }
        });

        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(503);
    }

    public function test_a_failure_is_logged_with_what_failed(): void
    {
        // A 503 the operator can read is half of it; the other half is an ops
        // line naming the failure, which a bare 500 never produced. Same shape as
        // `IncidentAnalysisService`: the class, not the provider's own message.
        Log::spy();

        $this->app->instance(AssistantGateway::class, new class implements AssistantGateway
        {
            public function answer(AssistantPayload $payload): AssistantResult
            {
                throw new ConnectionException('cURL error 28: Connection timed out');
            }
        });

        [, $user] = $this->makeMonitor();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/assistant', [
            'question' => 'How many monitors are down right now?',
        ]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'assistant')
                && ($context['failure'] ?? null) === 'ConnectionException',
        );
    }

    public function test_assistant_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/assistant', [
            'question' => 'How many monitors are down right now?',
        ]);

        $response->assertStatus(401);
    }

    public function test_assistant_requires_a_current_team(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'How many monitors are down right now?',
            ]);

        $response->assertStatus(403);
    }

    public function test_assistant_validates_the_question(): void
    {
        $this->app->bind(AssistantGateway::class, FakeAssistantGateway::class);
        [, $user] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => '',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('question');
    }

    public function test_assistant_is_scoped_to_the_current_team_only(): void
    {
        $spy = new class implements AssistantGateway
        {
            public ?AssistantPayload $captured = null;

            public function answer(AssistantPayload $payload): AssistantResult
            {
                $this->captured = $payload;

                return (new FakeAssistantGateway)->answer($payload);
            }
        };
        $this->app->instance(AssistantGateway::class, $spy);

        [$monitor, $user] = $this->makeMonitor();
        [$otherMonitor] = $this->makeMonitor();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistant', [
                'question' => 'What monitors do I have?',
            ]);

        $response->assertStatus(200);

        $payload = $spy->captured;
        $this->assertNotNull($payload);
        $this->assertContains((string) $monitor->id, $payload->knownMonitorIds);
        $this->assertNotContains((string) $otherMonitor->id, $payload->knownMonitorIds);
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Assistant Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Assistant Team',
            // The AI assistant is an analysis-tier (Pro+) feature.
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
