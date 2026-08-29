<?php

namespace App\Services\Ai\Concerns;

/**
 * Keeps a gateway's single retry inside the wall its first attempt already spent
 * from.
 *
 * MEASURED on production on 2026-08-14: `GET /incidents/{id}/analysis` answered
 * HTTP 500 after 90.6 seconds and the log named it
 * `Maximum execution time of 90 seconds exceeded`, which is Octane's. The
 * timeline says the rest. The request started around 13:45:30, the first model
 * call completed at 13:46:04 after 34.3 s, its output was non-conforming so the
 * gateway retried, and the retry was still running when Octane killed the request
 * at 13:47:00.
 *
 * The per-call timeout that shipped the day before was chosen against that same
 * wall, and the reasoning was incomplete in a way a docblock cannot fix: 75
 * bounds ONE call, and each of these endpoints makes up to TWO, so the pair could
 * reach 150 against a 90-second wall.
 *
 * WHY A WALL PER OPERATION RATHER THAN PER CALL
 *
 * These gateways run on two paths with two different walls: inside an Octane
 * request (`octane.max_execution_time`, 90) and inside
 * `PublishAiIncidentUpdate` on the queue (`$timeout`, 160, running
 * an analysis and then a draft). Budgeting the OPERATION at 75 satisfies both
 * without asking which path it is on, which is the reason it is one number and
 * not a branch on `runningInConsole()`:
 *
 *   HTTP:  one operation  <= 75  < 90
 *   queue: two operations <= 150 < 160
 *
 * A branch would have to be right about the context at every call site, and it
 * would be one more thing to be wrong about than an arithmetic that holds either
 * way.
 *
 * The floor comes from `ai.minimum_call_seconds`, the number `AiDeadline`
 * already refuses to start a call below. A second opinion about what "too little
 * time left" means would be one opinion too many.
 */
trait BoundsRetriesToTheWall
{
    /**
     * Seconds one operation may spend across BOTH of its attempts.
     *
     * Fifteen under Octane's ninety, because giving up is not free: the degrade
     * path, the JSON render and the response all happen after this budget is
     * gone. `RetryFitsTheWallTest` reads the wall from config and pins the
     * margin, so tuning the deploy fails the test rather than the request.
     */
    public const int WALL_SECONDS = 75;

    /**
     * How long a retry may run, or null when there is not enough left to bother.
     *
     * Null is the interesting return and the caller must treat it as untrusted
     * output, which is the state it was already in: the first attempt came back
     * non-conforming, and the only question is whether a second one can finish
     * inside the wall. When it cannot, the honest move is the degrade the caller
     * already has rather than a call the server will kill mid-flight.
     *
     * @param  float  $startedAt  `microtime(true)` from before the FIRST attempt.
     */
    public function secondsLeftForRetry(float $startedAt): ?int
    {
        $left = (int) floor(self::WALL_SECONDS - (microtime(true) - $startedAt));
        $minimum = (int) config('ai.minimum_call_seconds', 8);

        return $left >= $minimum ? $left : null;
    }
}
