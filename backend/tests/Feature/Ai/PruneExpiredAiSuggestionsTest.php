<?php

namespace Tests\Feature\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\IncidentSeverity;
use App\Enums\MonitorType;
use App\Jobs\PruneExpiredAiSuggestions;
use App\Models\AiSuggestion;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks what the AI-suggestion reaper may and may not delete.
 *
 * `TriageAnomalyCandidate` stamps every suggestion with `expires_at = now + 7
 * days` and `SweepAiSuggestions` creates one per fresh anomaly every two
 * minutes, but nothing ever deleted them: `expires_at` was read in exactly one
 * place, as a visibility filter in the inbox query, so an unactioned suggestion
 * left the UI and stayed in the table forever.
 *
 * The cut is narrower than "expired": only a suggestion that expired while still
 * PENDING is dead weight, because nobody ever acted on it. An accepted one
 * records a human decision and carries `accepted_incident_id`; a dismissed one
 * records the opposite decision. Deleting either would destroy the audit trail of
 * what the operator did with the AI's advice, which is not storage hygiene.
 */
class PruneExpiredAiSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_suggestion_that_expired_while_pending(): void
    {
        $suggestion = $this->makeSuggestion(
            status: AiSuggestionStatus::Pending,
            expiresAt: now()->subDay(),
        );

        (new PruneExpiredAiSuggestions)->handle();

        $this->assertDatabaseMissing('ai_suggestions', ['id' => $suggestion->id]);
    }

    public function test_it_keeps_a_pending_suggestion_that_has_not_expired(): void
    {
        $suggestion = $this->makeSuggestion(
            status: AiSuggestionStatus::Pending,
            expiresAt: now()->addDay(),
        );

        (new PruneExpiredAiSuggestions)->handle();

        $this->assertDatabaseHas('ai_suggestions', ['id' => $suggestion->id]);
    }

    // The inbox query treats a null `expires_at` as never-expiring
    // (`whereNull('expires_at')->orWhere('expires_at', '>', now())`), so such a
    // row is LIVE and pruning it would delete something the operator can still act
    // on.
    public function test_it_keeps_a_pending_suggestion_with_no_expiry(): void
    {
        $suggestion = $this->makeSuggestion(
            status: AiSuggestionStatus::Pending,
            expiresAt: null,
        );

        (new PruneExpiredAiSuggestions)->handle();

        $this->assertDatabaseHas('ai_suggestions', ['id' => $suggestion->id]);
    }

    public function test_it_keeps_an_expired_accepted_suggestion(): void
    {
        $suggestion = $this->makeSuggestion(
            status: AiSuggestionStatus::Accepted,
            expiresAt: now()->subMonth(),
        );

        (new PruneExpiredAiSuggestions)->handle();

        $this->assertDatabaseHas('ai_suggestions', ['id' => $suggestion->id]);
    }

    public function test_it_keeps_an_expired_dismissed_suggestion(): void
    {
        $suggestion = $this->makeSuggestion(
            status: AiSuggestionStatus::Dismissed,
            expiresAt: now()->subMonth(),
        );

        (new PruneExpiredAiSuggestions)->handle();

        $this->assertDatabaseHas('ai_suggestions', ['id' => $suggestion->id]);
    }

    public function test_it_prunes_a_backlog_larger_than_one_chunk(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->makeSuggestion(
                status: AiSuggestionStatus::Pending,
                expiresAt: now()->subDay(),
            );
        }
        $kept = $this->makeSuggestion(
            status: AiSuggestionStatus::Pending,
            expiresAt: now()->addDay(),
        );

        (new PruneExpiredAiSuggestions)->handle(chunkSize: 5);

        $this->assertSame(1, AiSuggestion::query()->count());
        $this->assertDatabaseHas('ai_suggestions', ['id' => $kept->id]);
    }

    protected function makeSuggestion(
        AiSuggestionStatus $status,
        ?Carbon $expiresAt,
    ): AiSuggestion {
        $user = User::query()->create([
            'name' => 'Reaper Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Reaper Team',
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
            'dedupe_key' => Str::uuid()->toString(),
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }
}
