<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Foundation\Application;

/**
 * One request's shared budget of provider wall time.
 *
 * Registered as a SCOPED binding, so each HTTP request (and each queued job)
 * gets its own instance and Octane's persistent container cannot leak one
 * request's spent time into the next.
 *
 * The problem it exists for, measured in production on 2026-08-07:
 * `POST /monitors/analyze` makes up to three model calls in one request, the
 * suggestion, the research turn and metric discovery. Each had its own limit or
 * none at all (45, 30, and unbounded), and every one of them was at or above
 * the 30 second `octane.max_execution_time` that would kill the worker first.
 * So the timeouts written to protect the request could never fire: PHP raised a
 * fatal inside Guzzle's curl handler and the endpoint answered 500, instead of
 * the graceful degrade to a deterministic suggestion that every one of those
 * call sites is already written to perform.
 *
 * Per-call limits cannot fix that on their own. Three calls under a comfortable
 * per-call limit still add up past the request wall, so the budget has to be
 * shared and spent down, which is what this class does.
 *
 * It deliberately does NOT enforce anything itself. It answers "how long may
 * this call take", the caller passes that to the provider, and the provider's
 * own timeout does the work. A deadline that tried to interrupt work in flight
 * would be a second mechanism competing with the one already there.
 */
class AiDeadline
{
    /**
     * When the budget started running, as a monotonic-ish float second.
     */
    protected float $startedAt;

    public function __construct(protected Application $app)
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Seconds a model call may be given right now, or null when there is not
     * enough left to be worth starting one.
     *
     * Null is the interesting return and callers must treat it as "the provider
     * is unavailable", which is a state all of them already handle: it is the
     * same degrade an outage, a refused answer or a missing key produces, so a
     * budget that runs out changes no response shape.
     *
     * [$ceiling] caps the answer for a call that should never take the whole
     * budget even when the whole budget is free, such as the research turn,
     * which is an enrichment and not the answer.
     */
    public function secondsForCall(?int $ceiling = null): ?int
    {
        $remaining = (int) floor($this->budget() - $this->elapsed());

        if ($remaining < $this->minimum()) {
            return null;
        }

        return $ceiling === null ? $remaining : min($remaining, $ceiling);
    }

    /**
     * Seconds spent since this request's budget started.
     */
    public function elapsed(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /**
     * The whole request's provider budget, in seconds.
     */
    public function budget(): int
    {
        return (int) $this->app['config']->get('ai.request_budget_seconds', 150);
    }

    /**
     * The least time worth starting a call with.
     */
    public function minimum(): int
    {
        return (int) $this->app['config']->get('ai.minimum_call_seconds', 8);
    }

    /**
     * Restart the budget.
     *
     * {@see App\Jobs\AnalyzeMonitorJob::handle()} will call this on entry once
     * that job exists (the async-analyze plan's step 5), and it is BELT AND
     * BRACES there rather than the thing that prevents a leak. The tempting
     * claim is that a Horizon process would otherwise carry one analyze's spent
     * time into the next, and it is false: the queue worker already resets
     * scoped bindings between jobs, at
     * `Illuminate\Queue\QueueServiceProvider:263`, which calls
     * `$app->forgetScopedInstances()` in the reset callback it hands to the
     * Worker. So a job gets a fresh instance either way. The call stays because
     * it costs nothing, states the anchor explicitly at the top of the unit of
     * work, and survives a future change to that reset; do not re-describe it as
     * the mechanism that stops a leak.
     *
     * {@see App\Http\Controllers\Api\V1\MonitorController::analyze()} calls
     * this today, before that split, and its own comment explains why: the
     * clock has to start before the probe so the probe's 30 second timeout
     * spends from the same budget instead of sitting outside it. That anchor
     * moves with the model calls once the job owns them: the probe finishes
     * inside the request, on its own 30 second timeout only, before the job
     * (and this restart) ever runs, so it stops sharing this clock at all.
     * Until then, the controller is still the caller and its comment still
     * describes it.
     *
     * Anchoring at the top of a unit of work rather than at the first prompt is
     * the point either way: lazy resolution would start the clock at the first
     * model call and let anything ahead of it inside that same unit of work run
     * for free against the wall this budget exists to protect.
     *
     * Also for tests.
     */
    public function restart(): void
    {
        $this->startedAt = microtime(true);
    }
}
