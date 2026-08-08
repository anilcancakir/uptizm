<?php

namespace Tests\Unit\Support\Monitoring;

use App\Enums\AnalyzeRunStatus;
use App\Support\Monitoring\AnalyzeRunStore;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the cache-backed run store that carries one analyze run's state
 * between the request, the worker and the client's poll.
 *
 * `find()` returning null is asserted as a REAL state throughout, not as an
 * error path: {@see AnalyzeRunStore}'s own docblock is explicit that an
 * evicted or expired run is exactly as reachable as a never-started one, and
 * a test suite that only ever asserts the happy path would certify a store
 * whose caller cannot tell the two apart.
 */
class AnalyzeRunStoreTest extends TestCase
{
    /** A run that was never started is indistinguishable from one that was evicted. */
    public function test_find_on_an_unknown_run_id_returns_null(): void
    {
        $store = new AnalyzeRunStore;

        $this->assertNull($store->find((string) Str::uuid()));
    }

    /** The full round trip the step's Done-when names. */
    public function test_start_advance_complete_find_round_trips_what_was_written(): void
    {
        $store = new AnalyzeRunStore;
        $runId = (string) Str::uuid();
        $probe = ['region' => 'fra', 'status_code' => 200, 'response_ms' => 812];

        $store->start($runId, 'team-1', $probe);

        $queued = $store->find($runId);
        $this->assertSame(AnalyzeRunStatus::Queued->value, $queued['status']);
        $this->assertSame('team-1', $queued['team_id']);
        $this->assertSame($probe, $queued['probe']);
        $this->assertNull($queued['result']);

        $store->advance($runId, 1, AnalyzeRunStore::STATE_DONE);

        $analyzing = $store->find($runId);
        $this->assertSame(AnalyzeRunStatus::Analyzing->value, $analyzing['status']);
        $this->assertSame(1, $analyzing['step']);
        $this->assertSame(AnalyzeRunStore::STATE_DONE, $analyzing['steps'][1]);
        // The probe block written by start() is untouched by an unrelated advance().
        $this->assertSame($probe, $analyzing['probe']);

        $result = ['rationale' => 'looks healthy', 'suggested_metrics' => []];
        $store->complete($runId, $result);

        $completed = $store->find($runId);
        $this->assertSame(AnalyzeRunStatus::Completed->value, $completed['status']);
        $this->assertSame($result, $completed['result']);
        // The step map accumulated by advance() survives a later complete().
        $this->assertSame(AnalyzeRunStore::STATE_DONE, $completed['steps'][1]);
    }

    /** advance() accumulates one entry per step rather than overwriting the map. */
    public function test_advance_accumulates_a_state_per_step(): void
    {
        $store = new AnalyzeRunStore;
        $runId = (string) Str::uuid();

        $store->start($runId, 'team-1', []);
        $store->advance($runId, 1, AnalyzeRunStore::STATE_DONE);
        $store->advance($runId, 2, AnalyzeRunStore::STATE_SKIPPED);
        $store->advance($runId, 3, AnalyzeRunStore::STATE_RUNNING);

        $run = $store->find($runId);

        $this->assertSame([
            1 => AnalyzeRunStore::STATE_DONE,
            2 => AnalyzeRunStore::STATE_SKIPPED,
            3 => AnalyzeRunStore::STATE_RUNNING,
        ], $run['steps']);
        $this->assertSame(3, $run['step']);
    }

    /**
     * fail() leaves a state a caller can distinguish from one still in
     * progress: this is the step's own Done-when wording, asserted directly
     * against every other terminal and non-terminal status.
     */
    public function test_fail_leaves_a_terminal_state_distinguishable_from_in_progress(): void
    {
        $store = new AnalyzeRunStore;
        $runId = (string) Str::uuid();

        $store->start($runId, 'team-1', []);
        $store->advance($runId, 1, AnalyzeRunStore::STATE_RUNNING);
        $store->fail($runId, 'gateway timed out');

        $run = $store->find($runId);

        $this->assertSame(AnalyzeRunStatus::Failed->value, $run['status']);
        $this->assertSame('gateway timed out', $run['reason']);
        $this->assertNotSame(AnalyzeRunStatus::Queued->value, $run['status']);
        $this->assertNotSame(AnalyzeRunStatus::Analyzing->value, $run['status']);
        $this->assertNotSame(AnalyzeRunStatus::Completed->value, $run['status']);
    }

    /** fail() with no reason (the failed(null) hook path) still lands a terminal state. */
    public function test_fail_without_a_reason_still_terminates_the_run(): void
    {
        $store = new AnalyzeRunStore;
        $runId = (string) Str::uuid();

        $store->start($runId, 'team-1', []);
        $store->fail($runId);

        $run = $store->find($runId);

        $this->assertSame(AnalyzeRunStatus::Failed->value, $run['status']);
        $this->assertNull($run['reason']);
    }

    /** advance()/complete()/fail() against a run that never existed are silent no-ops. */
    public function test_writes_against_an_unknown_run_id_are_silent_no_ops(): void
    {
        $store = new AnalyzeRunStore;
        $runId = (string) Str::uuid();

        $store->advance($runId, 1, AnalyzeRunStore::STATE_RUNNING);
        $store->complete($runId, ['rationale' => 'unreachable']);
        $store->fail($runId, 'unreachable');

        $this->assertNull($store->find($runId));
    }
}
