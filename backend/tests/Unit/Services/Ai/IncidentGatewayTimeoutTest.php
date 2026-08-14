<?php

namespace Tests\Unit\Services\Ai;

use App\Jobs\PublishAiIncidentUpdate;
use App\Services\Ai\LaravelAiIncidentAnalysisGateway;
use App\Services\Ai\LaravelAiIncidentDraftGateway;
use Laravel\Ai\Promptable;
use Tests\TestCase;

/**
 * The incident model calls declare their own per-call timeout.
 *
 * They used not to, and the value they inherited was invisible: `laravel/ai`'s
 * {@see Promptable::getTimeout()} falls through to a hardcoded 60
 * when a promptable passes no timeout, defines no `timeout()` method, and
 * carries no `#[Timeout]` attribute. Both incident gateways matched all three,
 * so 60 governed them by accident rather than by decision, and nothing in the
 * application said so.
 *
 * Measured against the live provider on 2026-08-14, one call each:
 * 6.7s, 8.3s, 21.0s, 22.8s, 29.2s, and one that ran past 60 and degraded
 * (`cURL error 28: Operation timed out after 60001 milliseconds`). The tail is
 * real and it moves, so the number has to be a choice.
 *
 * The ceiling is the reason it is not simply large. Unlike the analyze path,
 * whose model calls were deliberately moved onto their own Horizon queue, a
 * cache-miss read of `GET api/v1/incidents/{incident}/analysis` still calls the
 * model INSIDE an Octane request. That is a considered difference rather than an
 * oversight: analyze makes three calls whose sum cleared no wall, this path
 * makes one. But it means the call has to lose to our own timeout before it
 * loses to Octane's, or the operator gets a hard 500 where a clean degrade was
 * available.
 */
class IncidentGatewayTimeoutTest extends TestCase
{
    public function test_both_incident_gateways_declare_a_timeout(): void
    {
        // `method_exists` is exactly what the vendor checks, so this asserts the
        // seam rather than a value: a gateway that loses the method silently
        // goes back to inheriting 60.
        $this->assertTrue(method_exists(LaravelAiIncidentAnalysisGateway::class, 'timeout'));
        $this->assertTrue(method_exists(LaravelAiIncidentDraftGateway::class, 'timeout'));
    }

    public function test_the_timeout_loses_to_our_clock_before_octanes(): void
    {
        // The wall is read live from config for the same reason `AiDeadlineTest`
        // reads its own: a number copied into an assertion stops tracking the
        // thing it was derived from the moment somebody tunes the deploy.
        $octane = (int) config('octane.max_execution_time');

        foreach ($this->timeouts() as $gateway => $timeout) {
            $this->assertLessThan(
                $octane,
                $timeout,
                "{$gateway} must degrade before Octane kills the request",
            );
        }
    }

    public function test_the_timeout_leaves_the_request_room_around_the_call(): void
    {
        // Strictly under is not enough on its own: a timeout one second below the
        // wall means the degrade path, the JSON render and the response all have
        // to happen inside that second. Ten is the margin, matching the shape of
        // the margin assertion in `AiDeadlineTest`.
        $octane = (int) config('octane.max_execution_time');

        foreach ($this->timeouts() as $gateway => $timeout) {
            $this->assertLessThanOrEqual(
                $octane - 10,
                $timeout,
                "{$gateway} leaves no room to answer after it gives up",
            );
        }
    }

    public function test_the_timeout_covers_the_measured_latency(): void
    {
        // The other direction, and the one a purely defensive edit would break:
        // a timeout tuned down to satisfy the ceiling above would start
        // degrading calls that were going to succeed. 30 is above every
        // successful call measured on this provider so far.
        foreach ($this->timeouts() as $gateway => $timeout) {
            $this->assertGreaterThan(
                30,
                $timeout,
                "{$gateway} would degrade calls that the provider answers",
            );
        }
    }

    public function test_two_calls_still_fit_inside_the_autonomous_job(): void
    {
        // `PublishAiIncidentUpdate` makes up to two: the analysis and then the
        // draft. Both at full timeout has to stay under the job's own ceiling,
        // or Horizon kills it mid-run and the operator gets neither.
        $job = new PublishAiIncidentUpdate('irrelevant', 'investigating');

        $this->assertLessThan(
            $job->timeout,
            array_sum($this->timeouts()),
            'the analysis plus the draft must fit inside the job that runs both',
        );
    }

    /**
     * The declared timeout of each incident gateway, keyed by class name.
     *
     * @return array<string, int>
     */
    protected function timeouts(): array
    {
        return [
            'IncidentAnalysisGateway' => app(LaravelAiIncidentAnalysisGateway::class)->timeout(),
            'IncidentDraftGateway' => app(LaravelAiIncidentDraftGateway::class)->timeout(),
        ];
    }
}
