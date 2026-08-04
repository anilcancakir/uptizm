<?php

namespace App\Jobs;

use App\Enums\AiSuggestionStatus;
use App\Models\AiSuggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes the AI suggestions that expired without anyone acting on them.
 *
 * {@see TriageAnomalyCandidate} and {@see SweepAiSuggestions} stamp every
 * suggestion with `expires_at = now + 7 days`, and the sweep creates one per
 * fresh anomaly every two minutes. Nothing ever deleted them: `expires_at` was
 * read in exactly one place, as a visibility filter in
 * `DashboardController::aiInbox()`, so an unactioned suggestion left the inbox
 * and then stayed in the table forever. On a noisy fleet that is the fastest
 * growing table in the system and none of it is reachable.
 *
 * THE CUT IS NARROWER THAN "EXPIRED". Only a suggestion that expired while still
 * PENDING is dead weight, because nobody ever acted on it. An accepted one
 * records a human decision and carries the `accepted_incident_id` that says which
 * incident came out of it; a dismissed one records the opposite decision. Both are
 * the audit trail of what the operator did with the AI's advice, so neither is
 * storage to reclaim, whatever its age.
 *
 * A NULL `expires_at` IS NOT AN EXPIRY. The inbox treats it as never-expiring, so
 * such a row is still live and actionable, and the `expires_at < now` comparison
 * already excludes it: `NULL < now` evaluates to NULL, never to true. An explicit
 * `whereNotNull` was tried and dropped again because no test could tell the two
 * queries apart; the behaviour is pinned by a test instead of by a predicate the
 * planner discards.
 *
 * It runs on the `ai` supervisor to keep AI housekeeping with the rest of the AI
 * work, and it neither calls a gateway nor spends {@see AiBudget}: this is a
 * scoped row delete, not an AI operation.
 */
class PruneExpiredAiSuggestions implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many rows are deleted per statement.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Seconds for which only one copy of this job may run, guarding against an
     * overlapping daily tick while a prior sweep is still deleting.
     *
     * @var int
     */
    public $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('ai');
    }

    /**
     * Delete every pending suggestion whose expiry has passed.
     *
     * @param  int|null  $chunkSize  Rows per delete statement; defaults to
     *                               [CHUNK_SIZE] and is overridable so a test can
     *                               exercise the multi-chunk path.
     */
    public function handle(?int $chunkSize = null): void
    {
        $size = $chunkSize ?? self::CHUNK_SIZE;

        // Delete in bounded batches rather than one statement: a backlog on a
        // noisy fleet can be large, and a single unbounded DELETE holds locks for
        // as long as it takes while the sweep keeps inserting behind it.
        do {
            $deleted = AiSuggestion::query()
                ->where('status', AiSuggestionStatus::Pending)
                ->where('expires_at', '<', now())
                ->limit($size)
                ->delete();
        } while ($deleted > 0);
    }
}
