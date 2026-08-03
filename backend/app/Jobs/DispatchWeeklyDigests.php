<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Billing\PlanGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Weekly fan-out job that queues one {@see GenerateWeeklyDigest} per team
 * entitled to read it.
 *
 * The digest job existed, was tested, and was plan-gated, but nothing ever
 * dispatched it outside the test suite, so `GET /incidents/digest` answered 404
 * forever even on a Business plan. This is the missing trigger.
 *
 * One isolated job is dispatched per team (never a single loop) so a team whose
 * week fails to compose fails only its own digest and leaves the rest untouched,
 * the same shape as {@see ScheduleSslChecks}.
 */
class DispatchWeeklyDigests implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Seconds for which only one copy of this job may run, guarding against an
     * overlapping weekly tick while a prior fan-out is still enqueuing.
     *
     * @var int
     */
    public $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('ai');
    }

    /**
     * Queue a digest for every team whose plan reaches the digest's AI tier.
     */
    public function handle(PlanGate $gate): void
    {
        // Chunk the roster so a large install never loads every team at once.
        Team::query()->each(function (Team $team) use ($gate): void {
            // Eligibility is decided HERE rather than inside the digest job.
            // Generating for a team below the tier would spend a unit of that
            // team's shared daily AI budget, the same budget triage and the
            // assistant draw on, and persist a row `DigestController` refuses
            // to serve it.
            if (! $gate->aiLevelAllows($team, GenerateWeeklyDigest::AI_LEVEL)) {
                return;
            }

            GenerateWeeklyDigest::dispatch((string) $team->id);
        });
    }
}
