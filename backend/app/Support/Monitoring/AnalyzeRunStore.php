<?php

namespace App\Support\Monitoring;

use App\Enums\AnalyzeRunStatus;
use Illuminate\Support\Facades\Cache;

/**
 * Carries one `POST /api/v1/monitors/analyze` run's state between the
 * request that accepts it, the worker that does the work, and the client
 * that polls for the result.
 *
 * A CACHE ENTRY, NOT A ROW, deliberately. Production runs `CACHE_STORE=redis`
 * (`.env` `CACHE_STORE`, `config/cache.php:18`), so every write here is a
 * Redis `SET`, never a PostgreSQL write, and that is what makes writing on
 * every step advance affordable: the result this store's `complete()` holds
 * is read once, within about a minute, by the form that asked for it, and a
 * table row for that lifetime would outlive its only reader by design.
 *
 * THE RISK THAT MAKES THIS CORRECT ALSO MAKES IT FRAGILE. The box's Redis
 * runs `volatile-lru` with a 512 MB ceiling, so every key this class writes
 * is an eviction candidate under memory pressure, same as every other TTL'd
 * key in this app. {@see find()} returning null is therefore a REAL state a
 * caller must handle (the run was evicted, or its TTL simply expired), not
 * an impossible one a caller may assume away. A caller that treats null as
 * "still running" produces a client that polls forever for a run that will
 * never answer again.
 *
 * FIVE METHODS, NOT SIX: there is deliberately no `forget()`. Nothing in this
 * feature ever needs to delete a run early, and an unused deletion method is
 * exactly the kind of thing a later change wires up against the wrong run
 * (`forget()` before a lock release, `forget()` on a request that only
 * READ the run). The {@see TTL_SECONDS} expiry is the only cleanup path.
 */
class AnalyzeRunStore
{
    /**
     * Seconds a run's cache entry survives without a write.
     *
     * Long enough that an operator who tabs away mid-run and comes back
     * still finds their result (the job's own worker timeout is well under
     * this, so a run that is still legitimately in progress never loses its
     * entry to this alone); short enough that a run nobody ever collects
     * does not linger in a 512 MB Redis instance that is already under
     * pressure (see the class docblock).
     *
     * Refreshed on every write ({@see start()}, {@see advance()},
     * {@see complete()}, {@see fail()}), so the fifteen minutes measure time
     * since the LAST activity on a run, not time since it was created: a
     * run that is genuinely progressing keeps renewing its own lease, and
     * only a run nobody is touching (finished and collected, or abandoned)
     * counts down to eviction.
     */
    private const int TTL_SECONDS = 900;

    /**
     * Cache key prefix for one run's state.
     *
     * Distinct from the job's own `analyze:{run}:trial` guard key (see the
     * analyze job's trial-meter comment): that key and this one are written
     * by different callers for different reasons, and sharing a prefix would
     * make a `Cache::forget()` typo in one able to erase the other.
     */
    private const string KEY_PREFIX = 'analyze-run';

    /**
     * A step reported as still running.
     */
    public const string STATE_RUNNING = 'running';

    /**
     * A step that finished normally.
     */
    public const string STATE_DONE = 'done';

    /**
     * A step that genuinely did not run this time (for example, the
     * research turn when no credential was supplied). Distinct from
     * {@see STATE_RUNNING} on purpose: a step left `running` forever is what
     * hangs the client's poll on work that was never going to happen.
     */
    public const string STATE_SKIPPED = 'skipped';

    /**
     * A step that raised and ended the run.
     */
    public const string STATE_FAILED = 'failed';

    /**
     * Create a run in {@see AnalyzeRunStatus::Queued}, with the probe block
     * already known (the relay probe finishes inside the request, before
     * this is ever called) and no step progress or result yet.
     *
     * @param  string  $runId  Caller-minted id (the request mints it, not this store).
     * @param  string  $teamId  The team the run belongs to; {@see find()}'s only
     *                          caller-facing authorisation anchor, since a run is
     *                          never authorised by possession of its id alone.
     * @param  array<string, mixed>  $probe  The `region`/`status_code`/`response_ms`
     *                                       block the client already renders.
     */
    public function start(string $runId, string $teamId, array $probe): void
    {
        $this->write($runId, [
            'status' => AnalyzeRunStatus::Queued->value,
            'team_id' => $teamId,
            'step' => 0,
            'steps' => [],
            'probe' => $probe,
            'result' => null,
        ]);
    }

    /**
     * Record one step's terminal state and move the run into
     * {@see AnalyzeRunStatus::Analyzing}.
     *
     * A run that has vanished (evicted, expired, or never started) is left
     * alone rather than recreated: fabricating a fresh entry here would carry
     * no {@see $teamId}, so {@see find()} could never authorise anyone against
     * it, and a run advancing after its own eviction has nothing useful left
     * to report anyway.
     *
     * @param  int  $step  The step ordinal this call reports on.
     * @param  string  $state  One of {@see STATE_RUNNING}, {@see STATE_DONE},
     *                         {@see STATE_SKIPPED} or {@see STATE_FAILED}.
     */
    public function advance(string $runId, int $step, string $state): void
    {
        $run = $this->read($runId);

        if ($run === null) {
            return;
        }

        $run['status'] = AnalyzeRunStatus::Analyzing->value;
        $run['step'] = $step;
        $run['steps'][$step] = $state;

        $this->write($runId, $run);
    }

    /**
     * Write the final result and move the run into
     * {@see AnalyzeRunStatus::Completed}.
     *
     * Same non-recreating guard as {@see advance()}: a result for a run
     * nobody can be authorised against is not worth inventing a team id for.
     *
     * @param  array<string, mixed>  $result  The rationale, up to ten suggested
     *                                        metrics with bands, and the probe
     *                                        block, exactly as the synchronous
     *                                        response used to carry them.
     */
    public function complete(string $runId, array $result): void
    {
        $run = $this->read($runId);

        if ($run === null) {
            return;
        }

        $run['status'] = AnalyzeRunStatus::Completed->value;
        $run['result'] = $result;

        $this->write($runId, $run);
    }

    /**
     * Write the terminal {@see AnalyzeRunStatus::Failed} state.
     *
     * `$reason` is optional because the class's own `failed()` hook (the one
     * fired by a killed worker, not by a caught exception) is handed a
     * possibly-null `Throwable` and has nothing to say beyond "it failed".
     * A caller that DOES know why passes it through, so a status page or an
     * operator log line can distinguish a gateway error from a timeout.
     *
     * A COMPLETED RUN IS NEVER DOWNGRADED, and the window that makes this
     * necessary is small, real and reachable. The analyze job (`App\Jobs\
     * AnalyzeMonitorJob`, named in prose rather than imported: a store that
     * depended on a job would be the wrong direction, and a `{@see}` with a
     * fully-qualified name trips Pint's `fully_qualified_strict_types`)
     * writes {@see complete()} and then, still inside its 160-second alarm,
     * broadcasts the terminal tick and releases its lock. A SIGALRM in that
     * window runs the job's `failed()` hook, which lands here, and without this
     * guard it would overwrite `status` with `failed` while leaving the finished
     * `result` sitting underneath it. The client reads the status, not the
     * result, so an operator whose analysis had genuinely succeeded would be
     * handed nothing at all. Terminal means terminal: the first terminal write
     * for a run is the one that stands.
     */
    public function fail(string $runId, ?string $reason = null): void
    {
        $run = $this->read($runId);

        if ($run === null) {
            return;
        }

        if (($run['status'] ?? null) === AnalyzeRunStatus::Completed->value) {
            return;
        }

        $run['status'] = AnalyzeRunStatus::Failed->value;
        $run['reason'] = $reason;

        $this->write($runId, $run);
    }

    /**
     * Read back everything written for one run.
     *
     * Null is a REAL state, not a bug: see the class docblock. A caller
     * (the GET endpoint, the client's poll) must render it as "run this
     * again", never as "still running".
     *
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        return $this->read($runId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $runId): ?array
    {
        /** @var array<string, mixed>|null $run */
        $run = Cache::get($this->keyFor($runId));

        return $run;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function write(string $runId, array $run): void
    {
        // `put()` rather than `add()`: every write here is an intentional
        // overwrite of the same run's own entry, not a first-writer-wins
        // seed the way `AiBudget::tryConsume()`'s counter is. Re-issuing the
        // TTL on every write is what makes it measure time-since-last-write
        // (see TTL_SECONDS) instead of time-since-creation.
        Cache::put($this->keyFor($runId), $run, self::TTL_SECONDS);
    }

    private function keyFor(string $runId): string
    {
        return sprintf('%s:%s', self::KEY_PREFIX, $runId);
    }
}
