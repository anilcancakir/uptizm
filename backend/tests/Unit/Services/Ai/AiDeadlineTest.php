<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiDeadline;
use App\Services\Ai\LaravelAiAnalysisGateway;
use ReflectionClass;
use Tests\TestCase;

/**
 * The shared provider budget one request may spend across every model call.
 *
 * The production failure it exists for: `POST /monitors/analyze` makes up to
 * three model calls, each limit was at or above the `octane.max_execution_time`
 * that killed the worker first, so none of them could fire and the endpoint
 * answered 500 with a PHP fatal instead of degrading.
 */
class AiDeadlineTest extends TestCase
{
    public function test_a_fresh_request_may_spend_almost_the_whole_budget(): void
    {
        // A RANGE and not an equality, because the answer is wall time: the
        // budget starts running when the instance is resolved, and `floor()`
        // rounds the remainder DOWN, which is the safe direction for something
        // handed to a provider as a timeout. Asserting 45 exactly would be a
        // test that fails on its own clock.
        config(['ai.request_budget_seconds' => 45, 'ai.minimum_call_seconds' => 8]);

        $seconds = $this->app->make(AiDeadline::class)->secondsForCall();

        $this->assertGreaterThanOrEqual(43, $seconds);
        $this->assertLessThanOrEqual(45, $seconds);
    }

    public function test_a_ceiling_caps_a_call_that_should_never_take_the_whole_budget(): void
    {
        // The research turn is an enrichment, so it is capped even when the
        // whole budget is free.
        config(['ai.request_budget_seconds' => 45, 'ai.minimum_call_seconds' => 8]);

        $this->assertSame(30, $this->app->make(AiDeadline::class)->secondsForCall(30));
    }

    public function test_a_spent_budget_refuses_the_call_rather_than_starting_one_that_cannot_finish(): void
    {
        // THE assertion. Null is what makes every caller degrade to its
        // deterministic answer; without it the third call starts anyway, runs
        // past the worker's wall, and PHP kills the request.
        config(['ai.request_budget_seconds' => 5, 'ai.minimum_call_seconds' => 8]);

        $this->assertNull($this->app->make(AiDeadline::class)->secondsForCall());
    }

    public function test_the_budget_is_shared_across_calls_rather_than_granted_per_call(): void
    {
        // Three comfortable per-call limits still add up past the request wall,
        // which is why a per-call timeout could not have fixed this. The second
        // call has to see less than the first.
        config(['ai.request_budget_seconds' => 45, 'ai.minimum_call_seconds' => 1]);
        $deadline = $this->app->make(AiDeadline::class);

        $first = $deadline->secondsForCall();
        usleep(1_100_000);
        $second = $deadline->secondsForCall();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertLessThan($first, $second, 'the budget must be spent down, not reissued');
    }

    public function test_restarting_discards_time_spent_before_the_unit_of_work_began(): void
    {
        // `MonitorController::analyze()` calls this on entry, and the test suite
        // is the environment that needs it most: nothing resets a scoped binding
        // between assertions here the way Octane does between requests, so
        // without the restart a feature test would measure its own setup as
        // budget the model had already spent.
        config(['ai.request_budget_seconds' => 45, 'ai.minimum_call_seconds' => 8]);
        $deadline = $this->app->make(AiDeadline::class);

        usleep(1_100_000);
        $spent = $deadline->secondsForCall();
        $deadline->restart();

        $this->assertNotNull($spent);
        $this->assertGreaterThan($spent, $deadline->secondsForCall(), 'a restart has to return the spent second');
    }

    public function test_it_is_scoped_so_octane_cannot_leak_one_request_budget_into_the_next(): void
    {
        // Under Octane the container survives the request. A singleton would
        // carry the first request's start time forever and every later analyze
        // would believe its budget was already spent, which is a worse failure
        // than the one this class fixes because it would be permanent.
        $first = $this->app->make(AiDeadline::class);
        $this->assertSame($first, $this->app->make(AiDeadline::class), 'same request, same instance');

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(AiDeadline::class), 'new scope, new budget');
    }

    public function test_the_budget_funds_the_suggestion_ceiling_and_still_leaves_a_discovery_call(): void
    {
        // The second production symptom, and the reason 45 became 75: the budget
        // was EXACTLY the suggestion turn's own ceiling, so a suggestion that
        // used its whole limit left zero behind and metric discovery, which runs
        // last and has no ceiling of its own, was refused on every slow analyze.
        // The operator saw an empty `suggested_metrics` on a health endpoint the
        // extractor had found forty candidates in.
        //
        // The ceiling is read by reflection rather than written here as 45. A
        // literal would make this test agree with a change that reintroduced the
        // bug: raising the ceiling past the headroom is the same defect as
        // lowering the budget onto it, and only one of those two edits is
        // visible from this file.
        $ceiling = (new ReflectionClass(LaravelAiAnalysisGateway::class))
            ->getConstant('SUGGESTION_TIMEOUT_SECONDS');

        $this->assertIsInt($ceiling, 'the suggestion ceiling has to exist for this bound to mean anything');

        $this->assertGreaterThanOrEqual(
            $ceiling + (int) config('ai.minimum_call_seconds'),
            (int) config('ai.request_budget_seconds'),
            'the budget must fund a maximal suggestion AND still start the discovery call behind it',
        );
    }

    public function test_the_budget_sits_below_the_worker_wall_that_would_kill_the_request(): void
    {
        // The ordering IS the fix, so it is asserted rather than left in a
        // comment: a budget at or above the worker's limit is decorative,
        // because PHP kills the request before any timeout can fire. That is
        // exactly the state production was in, at 45 against 30.
        $this->assertLessThan(
            (int) config('octane.max_execution_time'),
            (int) config('ai.request_budget_seconds'),
            'ai.request_budget_seconds must stay below octane.max_execution_time',
        );
    }
}
