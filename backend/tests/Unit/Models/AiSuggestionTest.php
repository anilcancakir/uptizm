<?php

namespace Tests\Unit\Models;

use App\Enums\AiConfidence;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see AiSuggestion} cast round-trip (confidence/kind/status enums,
 * evidence array, score float, expires_at datetime), the `team()`/`monitor()`/
 * `acceptedIncident()` relations, and the `scopePending`/`scopeForTeam` query
 * scopes used by the per-team inbox.
 */
class AiSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_pending_excludes_accepted_and_dismissed_suggestions(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $this->makeSuggestion($team, $monitor, status: AiSuggestionStatus::Pending);
        $this->makeSuggestion($team, $monitor, status: AiSuggestionStatus::Accepted);
        $this->makeSuggestion($team, $monitor, status: AiSuggestionStatus::Dismissed);

        $pending = AiSuggestion::query()->pending()->get();

        $this->assertCount(1, $pending);
        $this->assertTrue($pending->first()->status === AiSuggestionStatus::Pending);
    }

    public function test_scope_for_team_filters_to_the_given_team(): void
    {
        $team = $this->makeTeam();
        $otherTeam = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $otherMonitor = $this->makeMonitor($otherTeam);
        $this->makeSuggestion($team, $monitor);
        $this->makeSuggestion($otherTeam, $otherMonitor);

        $forTeam = AiSuggestion::query()->forTeam($team->id)->get();

        $this->assertCount(1, $forTeam);
        $this->assertSame($team->id, $forTeam->first()->team_id);
    }

    public function test_casts_round_trip_enums_array_float_and_datetime(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $suggestion = $this->makeSuggestion($team, $monitor);
        $suggestion->refresh();

        $this->assertInstanceOf(AiConfidence::class, $suggestion->confidence);
        $this->assertInstanceOf(AiSuggestionKind::class, $suggestion->kind);
        $this->assertInstanceOf(AiSuggestionStatus::class, $suggestion->status);
        $this->assertIsArray($suggestion->evidence);
        $this->assertIsFloat($suggestion->score);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $suggestion->expires_at);
    }

    public function test_team_and_monitor_and_accepted_incident_relations_resolve(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API outage',
            'impact' => 'critical',
            'severity' => 'critical',
            'signal_source' => 'ai_anomaly',
            'lifecycle' => 'detected',
            'ai_owned' => true,
            'started_at' => now(),
        ]);
        $suggestion = $this->makeSuggestion($team, $monitor, acceptedIncident: $incident);

        $this->assertTrue($suggestion->team->is($team));
        $this->assertTrue($suggestion->monitor->is($monitor));
        $this->assertTrue($suggestion->acceptedIncident->is($incident));
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'AI Suggestion Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'AI Suggestion Team',
        ]);
    }

    /**
     * Creates a persisted monitor for the given team.
     */
    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API',
            'type' => 'http',
            'url' => 'https://example.com/api',
            'check_interval_sec' => 60,
        ]);
    }

    /**
     * Creates a persisted AI suggestion for the given team/monitor.
     */
    protected function makeSuggestion(
        Team $team,
        Monitor $monitor,
        AiSuggestionStatus $status = AiSuggestionStatus::Pending,
        ?Incident $acceptedIncident = null,
    ): AiSuggestion {
        return AiSuggestion::query()->create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time',
            'method' => 'zscore',
            'score' => 3.2,
            'severity' => 'warn',
            'confidence' => AiConfidence::High,
            'source' => 'llm',
            'recommendation' => 'Investigate the API response time spike.',
            'evidence' => [
                'observed' => 820,
                'baseline' => 210,
            ],
            'dedupe_key' => Str::uuid()->toString(),
            'status' => $status,
            'expires_at' => now()->addDay(),
            'accepted_incident_id' => $acceptedIncident?->id,
        ]);
    }
}
