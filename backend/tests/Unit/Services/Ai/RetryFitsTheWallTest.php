<?php

namespace Tests\Unit\Services\Ai;

use App\Jobs\PublishAiIncidentUpdate;
use App\Services\Ai\Concerns\BoundsRetriesToTheWall;
use App\Services\Ai\LaravelAiAssistantGateway;
use App\Services\Ai\LaravelAiIncidentAnalysisGateway;
use App\Services\Ai\LaravelAiIncidentDraftGateway;
use Tests\TestCase;

/**
 * The retry has to fit inside the wall the first attempt already spent from.
 *
 * MEASURED on production the day this shipped: `GET /incidents/{id}/analysis`
 * answered **HTTP 500 after 90.6 seconds**, and the log named it
 * `Maximum execution time of 90 seconds exceeded`, which is Octane's. The
 * timeline says the rest: the request started around 13:45:30, the first model
 * call completed at 13:46:04 after 34.3 s, its output was non-conforming so the
 * gateway retried, and the retry was still running when Octane killed the
 * request at 13:47:00.
 *
 * The reasoning that shipped the 75-second timeout was incomplete and this is the
 * correction. 75 bounds ONE call, and each of these endpoints makes up to TWO, so
 * the pair could reach 150 against a 90-second wall. Naming the wall in a docblock
 * did not make the arithmetic true.
 *
 * All three in-request gateways have the shape, not just the one that was
 * observed failing: the analysis, the draft and the assistant each retry once,
 * and each runs inside an HTTP request. Fixing only the observed one is the
 * pattern this project has shipped four times.
 */
class RetryFitsTheWallTest extends TestCase
{
    /**
     * The gateways that retry inside a request, and must therefore bound it.
     *
     * @var list<class-string>
     */
    private const GATEWAYS = [
        LaravelAiIncidentAnalysisGateway::class,
        LaravelAiIncidentDraftGateway::class,
        LaravelAiAssistantGateway::class,
    ];

    public function test_every_retrying_gateway_bounds_its_retry(): void
    {
        // The seam rather than a value: a gateway that loses the concern goes
        // straight back to an unbounded pair of calls.
        foreach (self::GATEWAYS as $gateway) {
            $this->assertContains(
                BoundsRetriesToTheWall::class,
                class_uses($gateway) ?: [],
                $gateway.' can retry without bounding it',
            );
        }
    }

    public function test_the_whole_operation_fits_under_octanes_wall(): void
    {
        // The wall is read live from config for the same reason `AiDeadlineTest`
        // reads its own: a number copied into an assertion stops tracking the
        // thing it was derived from.
        $octane = (int) config('octane.max_execution_time');

        $this->assertLessThanOrEqual(
            $octane - 10,
            LaravelAiIncidentAnalysisGateway::WALL_SECONDS,
            'the operation budget leaves no room to answer after it gives up',
        );
    }

    public function test_two_operations_fit_inside_the_autonomous_job(): void
    {
        // The queue path has its own wall and the same defect: the job runs an
        // analysis and then a draft, and each can retry, so four unbounded calls
        // could reach 300 s against a 180 s job. Bounding the OPERATION rather
        // than the call is what makes this hold: two operations at the wall are
        // 150, which fits.
        $job = new PublishAiIncidentUpdate('irrelevant', 'investigating');

        $this->assertLessThan(
            $job->timeout,
            2 * LaravelAiIncidentAnalysisGateway::WALL_SECONDS,
            'the analysis plus the draft must fit inside the job that runs both',
        );
    }

    public function test_a_retry_gets_what_is_left_of_the_wall(): void
    {
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);

        // A first attempt that took 30 s leaves the rest of the wall.
        //
        // A range rather than an exact subtraction, and the first draft of this
        // test got it wrong: `microtime` moves between the two calls and the
        // answer is floored, so `75 - 30` is 44 as often as 45. Asserting the
        // arithmetic instead of the property made the test fail for a reason that
        // had nothing to do with the behaviour.
        $left = $gateway->secondsLeftForRetry(microtime(true) - 30);

        $this->assertNotNull($left);
        $this->assertGreaterThanOrEqual(LaravelAiIncidentAnalysisGateway::WALL_SECONDS - 31, $left);
        $this->assertLessThanOrEqual(LaravelAiIncidentAnalysisGateway::WALL_SECONDS - 30, $left);
    }

    public function test_a_retry_with_no_room_is_refused_rather_than_started(): void
    {
        // The half that fixes the 500. A first attempt that nearly spent the wall
        // must not start a second call that cannot finish inside it; the caller
        // degrades instead, which every one of them already handles.
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);

        $this->assertNull(
            $gateway->secondsLeftForRetry(microtime(true) - (LaravelAiIncidentAnalysisGateway::WALL_SECONDS - 2)),
            'two seconds is not enough to be worth a model call',
        );
    }

    public function test_the_floor_matches_the_projects_own_minimum_call(): void
    {
        // Reused rather than invented: `ai.minimum_call_seconds` is the number
        // `AiDeadline` already refuses to start a call below, and a second
        // opinion about what "too little time" means would be one too many.
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);
        $minimum = (int) config('ai.minimum_call_seconds');

        $justUnder = LaravelAiIncidentAnalysisGateway::WALL_SECONDS - $minimum + 1;
        $justOver = LaravelAiIncidentAnalysisGateway::WALL_SECONDS - $minimum;

        $this->assertNull($gateway->secondsLeftForRetry(microtime(true) - $justUnder));
        $this->assertSame($minimum, $gateway->secondsLeftForRetry(microtime(true) - $justOver));
    }

    public function test_the_first_attempt_still_gets_the_whole_wall(): void
    {
        // The other direction, which a purely defensive edit would break: the
        // fix must not shrink the budget of a call that has spent nothing. Every
        // successful call measured on this provider that day: 3.6, 5.6, 8.9,
        // 13.8, 20.5, 24.5, 34.3, 47.0, 47.7 and 56.5 seconds.
        foreach (self::GATEWAYS as $gateway) {
            $this->assertGreaterThan(
                56,
                app($gateway)->timeout(),
                $gateway.' would degrade calls this provider answers',
            );
        }
    }
}
