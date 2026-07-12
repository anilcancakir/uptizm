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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        $this->assertStringContainsString('budget', strtolower((string) $response->json('data.answer')));
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
