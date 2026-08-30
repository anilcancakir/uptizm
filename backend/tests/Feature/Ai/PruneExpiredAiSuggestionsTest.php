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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FindsScheduledJobs;
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
    use FindsScheduledJobs;
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

    /**
     * A backlog is cleared in BOUNDED statements, not in one sweep.
     *
     * The row counts alone cannot tell those two apart, and this test used to
     * assert nothing else: one unbounded `DELETE` removes the same 12 rows and
     * leaves the same survivor, so the whole file stayed green with `->limit()`
     * deleted from the job entirely (verified by deleting it). It was a second
     * copy of the first test in this file wearing a chunking name.
     *
     * What separates the two is the number of statements the job issues. A walk
     * bounded at 5 needs ceil(12/5) = 3 of them; an unbounded delete needs exactly
     * 2, one that removes everything and one that returns 0 and ends the loop. So
     * 3 is the floor that an unbounded sweep cannot reach, and asserting a floor
     * rather than an exact count leaves room for a legitimate loop-condition
     * refactor (stopping on a short batch instead of an empty one) without
     * reddening.
     *
     * This matters in production and not only in principle: the chunk bound is
     * what keeps a lock off the whole table while `SweepAiSuggestions` is still
     * inserting behind the sweep every two minutes.
     */
    public function test_it_prunes_a_backlog_in_bounded_statements(): void
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

        // Registered after the fixtures so only the job's own statements count.
        $deletes = 0;
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
                $deletes++;
            }
        });

        (new PruneExpiredAiSuggestions)->handle(chunkSize: 5);

        $this->assertGreaterThanOrEqual(
            3,
            $deletes,
            "A backlog of 12 was cleared in {$deletes} delete statement(s) at a chunk size of 5, "
            .'so the chunk bound never reached the query: the sweep took the whole table in one '
            .'statement and held its locks for as long as that took.'
        );

        $this->assertSame(1, AiSuggestion::query()->count());
        $this->assertDatabaseHas('ai_suggestions', ['id' => $kept->id]);
    }

    /**
     * The daily entry exists, dispatches THIS job, and carries both guards.
     *
     * The job's own docblock cites the digest that was written, tested and
     * plan-gated while nothing outside the test suite ever dispatched it. This
     * file had the same blind spot: every test above constructs the job by hand,
     * so deleting the `Schedule::job()` entry from `routes/console.php` left all
     * of them green and the table would grow forever again.
     */
    public function test_the_daily_schedule_entry_runs_the_prune_with_both_guards(): void
    {
        $event = $this->scheduledEventDispatching(PruneExpiredAiSuggestions::class);

        $this->assertTrue(
            $event->onOneServer,
            'The prune is scheduled without onOneServer(), so every web host dispatches its own.'
        );
        $this->assertTrue(
            $event->withoutOverlapping,
            'The prune is scheduled without withoutOverlapping(), so a slow sweep is re-entered.'
        );
        $this->assertMatchesRegularExpression(
            '/^\d{1,2} \d{1,2} \* \* \*$/',
            $event->expression,
            'Housekeeping this size belongs on a fixed daily time (dailyAt), not on a sub-daily '
            .'cadence that competes with the sweep inserting rows.'
        );
    }

    /**
     * The queue the job asks for is one a worker actually drains.
     *
     * `onQueue()` takes a free-form string that nothing validates, so a typo parks
     * every dispatch on a queue no supervisor lists: the schedule fires, the job
     * queues, `schedule:list` looks correct, and the prune never runs. That is the
     * missing-trigger failure one layer lower down, and the test above cannot see
     * it. Asserting membership of Horizon's own list rather than the literal 'ai'
     * pins the property that matters (something is listening) instead of the name.
     *
     * It reads EVERY supervisor rather than a named one, because which supervisor
     * owns `ai` is not the property under test and has already changed once: the
     * queue moved from `supervisor-1` to `background` when the tolerant queues were
     * collapsed into a single pool. Naming a supervisor here would have failed that
     * refactor while the thing it guards (a worker drains this queue) still held.
     */
    public function test_it_is_dispatched_onto_a_queue_horizon_drains(): void
    {
        $queue = (new PruneExpiredAiSuggestions)->queue;

        $supervisors = (array) config('horizon.defaults');

        $this->assertNotEmpty($supervisors, 'Horizon declares no supervisors at all.');

        $drained = array_merge(...array_map(
            fn (array $supervisor): array => (array) ($supervisor['queue'] ?? []),
            array_values($supervisors),
        ));

        $this->assertContains(
            $queue,
            $drained,
            "The job is dispatched onto '{$queue}', which no Horizon supervisor lists ("
            .implode(', ', $drained).'), so every dispatch would queue and never run.'
        );
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
