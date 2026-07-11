<?php

namespace Tests\Feature\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\IncidentSeverity;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\Controllers\Api\V1\AiSuggestionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the AI suggestion inbox surface: the team-scoped pending inbox shaped
 * into the Flutter {@see DashboardController::aiInbox()}
 * contract, plus the idempotent accept/dismiss endpoints on
 * {@see AiSuggestionController}.
 *
 * The inbox must emit `monitors[]` + `primary_monitor_id` (so the Flutter
 * decoder resolves the monitor name) and the `ai` sub-object (confidence +
 * tldr), never the raw evidence. Accept must open exactly one Incident even
 * when replayed, and a cross-team suggestion must 404, not leak.
 */
class AiInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_returns_the_shaped_pending_suggestion(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, MonitorStatus::Down);
        $suggestion = $this->makeSuggestion($team, $monitor, [
            'confidence' => AiConfidence::High,
            'recommendation' => 'Checkout latency tripled over the last window.',
        ]);

        $response = $this->getJson('/api/v1/dashboard/ai-inbox');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $suggestion->id);
        $response->assertJsonPath('data.0.ai_owned', true);
        $response->assertJsonPath('data.0.signal_source', SignalSource::AiAnomaly->value);
        $response->assertJsonPath('data.0.primary_monitor_id', $monitor->id);
        $response->assertJsonPath('data.0.monitors.0.monitor_id', $monitor->id);
        $response->assertJsonPath('data.0.monitors.0.name', $monitor->name);
        $response->assertJsonPath('data.0.monitors.0.component_status_current', MonitorStatus::Down->value);
        $response->assertJsonPath('data.0.ai.confidence', AiConfidence::High->value);
        $response->assertJsonPath('data.0.ai.tldr', 'Checkout latency tripled over the last window.');
        $response->assertJsonPath('data.0.ai.trigger', 'anomaly');
    }

    public function test_inbox_never_emits_the_raw_evidence(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, MonitorStatus::Down);
        $this->makeSuggestion($team, $monitor, [
            'evidence' => [
                'observed' => 1200.0,
                'baseline' => 200.0,
                'secret_marker' => 'must-never-leak-to-client',
            ],
        ]);

        $response = $this->getJson('/api/v1/dashboard/ai-inbox');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.evidence');
        $this->assertStringNotContainsString('must-never-leak-to-client', $response->getContent());
    }

    public function test_inbox_excludes_expired_and_non_pending_suggestions(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, MonitorStatus::Down);

        $this->makeSuggestion($team, $monitor);
        $this->makeSuggestion($team, $monitor, ['expires_at' => now()->subMinute()]);
        $this->makeSuggestion($team, $monitor, ['status' => AiSuggestionStatus::Dismissed]);
        $this->makeSuggestion($team, $monitor, ['status' => AiSuggestionStatus::Accepted]);

        $response = $this->getJson('/api/v1/dashboard/ai-inbox');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_inbox_never_returns_another_teams_suggestions(): void
    {
        $team = $this->actingAsTeamMember();
        $otherTeam = $this->makeTeam();
        $otherMonitor = $this->makeMonitor($otherTeam, MonitorStatus::Down);
        $this->makeSuggestion($otherTeam, $otherMonitor);

        $response = $this->getJson('/api/v1/dashboard/ai-inbox');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_accept_opens_exactly_one_incident(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, MonitorStatus::Down);
        $suggestion = $this->makeSuggestion($team, $monitor, ['severity' => IncidentSeverity::Critical->value]);

        $response = $this->postJson("/api/v1/ai-suggestions/{$suggestion->id}/accept");

        $response->assertOk();
        $this->assertSame(1, Incident::query()->count());

        $incident = Incident::query()->firstOrFail();
        $response->assertJsonPath('data.incident_id', $incident->id);
        $this->assertSame($team->id, $incident->team_id);
        $this->assertSame($monitor->id, $incident->primary_monitor_id);
        $this->assertSame(SignalSource::AiAnomaly, $incident->signal_source);
        $this->assertTrue($incident->ai_owned);
        $this->assertTrue($incident->monitors()->where('monitors.id', $monitor->id)->exists());

        $suggestion->refresh();
        $this->assertSame(AiSuggestionStatus::Accepted, $suggestion->status);
        $this->assertSame($incident->id, $suggestion->accepted_incident_id);
    }

    public function test_double_accept_is_idempotent_and_returns_the_same_incident(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, MonitorStatus::Down);
        $suggestion = $this->makeSuggestion($team, $monitor);

        $first = $this->postJson("/api/v1/ai-suggestions/{$suggestion->id}/accept");
        $second = $this->postJson("/api/v1/ai-suggestions/{$suggestion->id}/accept");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(
            $first->json('data.incident_id'),
            $second->json('data.incident_id'),
        );
    }

    public function test_dismiss_marks_the_suggestion_dismissed(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, MonitorStatus::Down);
        $suggestion = $this->makeSuggestion($team, $monitor);

        $response = $this->postJson("/api/v1/ai-suggestions/{$suggestion->id}/dismiss");

        $response->assertOk();
        $suggestion->refresh();
        $this->assertSame(AiSuggestionStatus::Dismissed, $suggestion->status);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_accept_of_another_teams_suggestion_404s(): void
    {
        $this->actingAsTeamMember();
        $otherTeam = $this->makeTeam();
        $otherMonitor = $this->makeMonitor($otherTeam, MonitorStatus::Down);
        $suggestion = $this->makeSuggestion($otherTeam, $otherMonitor);

        $response = $this->postJson("/api/v1/ai-suggestions/{$suggestion->id}/accept");

        $response->assertNotFound();
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_dismiss_of_another_teams_suggestion_404s(): void
    {
        $this->actingAsTeamMember();
        $otherTeam = $this->makeTeam();
        $otherMonitor = $this->makeMonitor($otherTeam, MonitorStatus::Down);
        $suggestion = $this->makeSuggestion($otherTeam, $otherMonitor);

        $response = $this->postJson("/api/v1/ai-suggestions/{$suggestion->id}/dismiss");

        $response->assertNotFound();
        $suggestion->refresh();
        $this->assertSame(AiSuggestionStatus::Pending, $suggestion->status);
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $team = $this->makeTeam();

        $member = User::query()->create([
            'name' => 'Inbox Operator',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($member);

        return $team;
    }

    protected function makeTeam(): Team
    {
        $owner = User::query()->create([
            'name' => 'Team Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $owner->id,
            'name' => 'Inbox Team',
        ]);
    }

    protected function makeMonitor(Team $team, MonitorStatus $lastStatus): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Checkout API '.Str::random(6),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'last_status' => $lastStatus,
        ]);
    }

    /**
     * Persist a pending AI suggestion for the given monitor.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeSuggestion(Team $team, Monitor $monitor, array $overrides = []): AiSuggestion
    {
        return AiSuggestion::query()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time',
            'method' => 'mad',
            'score' => 6.2,
            'severity' => IncidentSeverity::Critical->value,
            'confidence' => AiConfidence::Medium,
            'source' => 'llm',
            'recommendation' => 'Investigate the response-time spike on this monitor.',
            'evidence' => [
                'observed' => 1200.0,
                'baseline' => 200.0,
            ],
            'dedupe_key' => 'dedupe:'.Str::uuid(),
            'status' => AiSuggestionStatus::Pending,
            'expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }
}
