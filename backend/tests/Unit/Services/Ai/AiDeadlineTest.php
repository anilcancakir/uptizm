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

    public function test_the_answer_turn_is_not_funded_worse_than_the_enrichment_behind_it(): void
    {
        // The third production symptom, measured 2026-08-09 at 22:43 and 22:46
        // UTC: two analyzes came back with the deterministic baseline and
        // `the AI service was unreachable`, on runs where metric discovery
        // answered with nine correct metrics. Horizon's retained records put step
        // 4 at 38.17s and 39.09s against the suggestion turn's 40 second ceiling,
        // and the whole job at 57.68s and 75.10s against a 150 second budget. So
        // the shared budget was never close; the ANSWER turn was the only one of
        // the three wearing a tight per-call ceiling, and it wore the tightest
        // one of all.
        //
        // The bound is a PRIORITY ordering, not a measurement, which is what
        // makes it assertable at all. Of the three calls, the suggestion is the
        // answer: without it the operator gets a deterministic prefill, a
        // `default` region basis and a `low` confidence grade. Research is
        // declared optional by its own gateway ("a suggestion without research is
        // still a suggestion") and metric discovery degrades to an empty array on
        // its own. So the answer must not be granted less wall time than the
        // enrichment that runs behind it can spend, and that remainder is what
        // `secondsForCall()` with no ceiling hands metric discovery.
        //
        // Both ceilings are read by reflection for the reason the test above
        // states: a literal here would agree with the very edit that reintroduces
        // the inversion.
        $gateway = new ReflectionClass(LaravelAiAnalysisGateway::class);

        $suggestion = $gateway->getConstant('SUGGESTION_TIMEOUT_SECONDS');
        $research = $gateway->getConstant('RESEARCH_TIMEOUT_SECONDS');

        $this->assertIsInt($suggestion, 'the suggestion ceiling has to exist for this bound to mean anything');
        $this->assertIsInt($research, 'the research ceiling has to exist for this bound to mean anything');

        $this->assertGreaterThanOrEqual(
            (int) config('ai.request_budget_seconds') - $research - $suggestion,
            $suggestion,
            'metric discovery may spend more of the budget than the suggestion it enriches',
        );
    }

    /**
     * The shortest wall between the operator and this worker, measured rather
     * than configured.
     *
     * IDENTIFIED 2026-08-09, and kept as a constant because the number is the
     * cheapest reminder of how it was missed.
     *
     * A 75-second analyze answered an operator 504 twice on 2026-08-07, back when
     * the model calls still ran inside `POST /monitors/analyze`, and a direct
     * measurement cut at 60.1 seconds. It was OURS: the api vhost's `location /`
     * proxies to Octane and declared no `proxy_read_timeout`, so it inherited
     * nginx's DEFAULT of 60. Now 125 in `deploy/vhost-uptizm.com.conf` and on the
     * box.
     *
     * Why it took a day: grepping the vhost for timeout directives returns 3600
     * and 720 and no 60, so nginx was eliminated. The 3600 belongs to the Reverb
     * WebSocket block above `location /`, and the `http` context sets nothing. A
     * DEFAULT does not appear in a grep, and its absence was read as "a high value
     * is set here". This budget was then sized around a wall believed to belong to
     * somebody else.
     *
     * The constant stays at 60 rather than moving to 125 on purpose: it is not a
     * live bound any more (this budget runs on a worker, so the assertions below
     * measure against the analyze supervisor's timeout instead), it is the
     * historical figure the reasoning hangs on.
     */
    private const int OBSERVED_PROXY_WALL_SECONDS = 60;

    public function test_the_budget_keeps_the_request_inside_the_wall_the_operator_actually_hits(): void
    {
        // Re-scoped: this used to compare against OBSERVED_PROXY_WALL_SECONDS
        // above, because the budget ran inside the same HTTP request that wall
        // cuts. It does not any more; the wall that can now strand an operator
        // watching a spinner is the analyze Horizon supervisor's own worker
        // timeout (config/horizon.php), read live so this test cannot drift
        // away from AnalyzeQueueConfigTest's chain.
        //
        // The margin is for what the budget does NOT cover inside that worker:
        // writing the terminal run state, broadcasting it, and releasing the
        // in-flight lock, all AFTER the last model call answers or times out. A
        // budget sized flush against the supervisor timeout spends that work
        // past it, and Horizon kills the worker before failed() or the
        // completion path can run, leaving the operator's form spinning
        // forever. 10 is not a fresh guess: it is the same gap step 1 already
        // reserved between the job's own $timeout (160) and its supervisor's
        // (170) for exactly this overhead, so this test does not invent a
        // second number for the one reservation.
        $margin = 10;
        $supervisorTimeout = (int) config('horizon.defaults.analyze.timeout');

        $this->assertGreaterThan(
            0,
            $supervisorTimeout,
            'the analyze supervisor timeout must resolve to a positive number for this bound to mean anything',
        );

        $this->assertLessThanOrEqual(
            $supervisorTimeout - $margin,
            (int) config('ai.request_budget_seconds'),
            'a budget that can outlast the analyze worker leaves the run killed mid-cleanup instead of failed()',
        );
    }

    public function test_the_budget_sits_below_the_worker_wall_that_would_kill_the_request(): void
    {
        // Re-scoped from `octane.max_execution_time`: Octane's wall governs an
        // HTTP REQUEST, and this budget does not run inside one any more. The
        // wall that would now kill it before any timeout inside it can fire is
        // the analyze Horizon supervisor's own worker timeout, read live for
        // the same reason the test above does.
        //
        // Kept alongside the margin-based test above rather than folded into
        // it: that one asks whether the budget leaves the worker room to clean
        // up after itself; this one asks the narrower question the original
        // test was for, whether the ordering itself holds at all. A budget at
        // or above the worker's own limit is decorative, because Horizon kills
        // the job before any timeout inside it can fire, which is exactly the
        // state production was in once, at 45 against a 30 second Octane wall.
        $this->assertLessThan(
            (int) config('horizon.defaults.analyze.timeout'),
            (int) config('ai.request_budget_seconds'),
            'ai.request_budget_seconds must stay below the analyze supervisor\'s worker timeout',
        );
    }
}
