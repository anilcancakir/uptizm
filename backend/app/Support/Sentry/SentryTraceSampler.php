<?php

namespace App\Support\Sentry;

use App\Jobs\AnalyzeMonitorJob;
use App\Jobs\PerformMonitorCheck;
use Sentry\Tracing\SamplingContext;

/**
 * Decides which transactions become spans in Sentry.
 *
 * WHY A SAMPLER AND NOT A RATE. Sentry bills spans on what it RECEIVES: its
 * dynamic-sampling documentation states that "metering is based on received,
 * not stored events", so nothing Sentry does after ingestion reduces the cost
 * and this callback is the only lever there is. A single flat rate cannot serve
 * this application, because its two most interesting workloads sit at opposite
 * ends of the volume scale and would need opposite rates.
 *
 * WHICH RATE ACTUALLY CONTROLS PROBE VOLUME, AND IT IS NOT THE OBVIOUS ONE
 *
 * A per-job rate for `PerformMonitorCheck` looks like the volume control and is
 * not one. `QueueIntegration` writes the DISPATCHING trace's decision into every
 * queued payload (`getTraceparent()`, unconditionally), and on the consuming
 * side it drops any job whose parent was unsampled BEFORE this sampler is
 * consulted at all. So the real chain is
 *
 *     console.command.scheduled  ->  ScheduleMonitorChecks  ->  N x PerformMonitorCheck
 *
 * and only the first link is ever asked a question. A rate written on the last
 * link is unreachable code that reads like a budget.
 *
 * The arithmetic therefore lives on {@see self::RATE_SCHEDULER}. There are
 * 86,400 thirty-second ticks a month; each fans out one check per monitor per
 * region, so a hundred monitors across five regions is ~500 checks a tick. At
 * 0.001 that is ~86 sampled ticks, ~43,000 check transactions a month, roughly
 * 500K spans against this org's 50M allowance.
 *
 * What that shape costs, stated rather than hidden: the samples arrive in ~86
 * bursts rather than spread evenly, so this is not a latency time series. It
 * does not need to be. Per-check latency is measured properly and completely in
 * `monitor_checks.response_ms`; what a trace adds is the code path around the
 * probe, and 86 of those a month is plenty to see one break.
 *
 * `analyze` is the opposite shape: a few hundred runs a MONTH, each spending
 * real model budget, each worth reconstructing when it goes wrong. It is the
 * one case that overrides an inherited decision, because the client that calls
 * it samples at 0.1 and would otherwise discard nine runs in ten.
 *
 * WHAT HAPPENS WHEN THE BUDGET IS WRONG. The plan carries
 * `onDemandMaxSpend = 0`, so overshooting the quota does not produce a bill.
 * It produces silence: Sentry answers 429 and the rest of the month's data is
 * dropped. That asymmetry is why every rate here is chosen low rather than
 * generous, and why a test pins each one.
 */
class SentryTraceSampler
{
    /**
     * Everything, for paths that are rare and expensive.
     */
    public const float RATE_ALWAYS = 1.0;

    /**
     * The API the Flutter client calls, when this service starts the trace.
     *
     * Most API traces arrive with a parent decision from the client and inherit
     * it instead, so this rate governs the server-originated remainder:
     * webhooks, and any client that does not propagate.
     */
    public const float RATE_API = 0.2;

    /**
     * Ordinary background work and public pages: real volume, moderate value.
     */
    public const float RATE_BACKGROUND = 0.05;

    /**
     * The scheduler, and THE control that decides probe volume.
     *
     * See the class docblock: every check job inherits this decision, so this
     * single number sets how many of the month's ~43M probes are traced. It is
     * not "how interesting is the scheduler", it is the whole probe budget.
     */
    public const float RATE_SCHEDULER = 0.001;

    /**
     * The probe queue when, and only when, it starts its own trace.
     *
     * Reachable from a dispatch with no parent (an Artisan command, a tinker
     * session), which is rare in production. The scheduled fan-out never
     * reaches it, for the reason spelled out in the class docblock, so do not
     * mistake this for the volume control.
     */
    public const float RATE_CHECK_QUEUE = 0.001;

    /**
     * The paths that spend model budget, matched on their SUFFIX.
     *
     * A suffix rather than a prefix, for two independent reasons. Three of the
     * four sit behind a route parameter (`/incidents/{id}/draft-update`), so
     * there is no prefix to key on. And a prefix on `/monitors/analyze` also
     * swallows `/monitors/analyze/{run}`, which is the POLLING endpoint the
     * client hits repeatedly while a run is in flight: exactly the call pattern
     * that should NOT be sampled at 1.0. A test pins that distinction.
     *
     * ADDING AN AI ENDPOINT MEANS ADDING IT HERE. Nothing detects model spend
     * automatically, and the cost of forgetting is quiet: the endpoint is
     * sampled like ordinary API traffic, so its failures are visible one time
     * in five.
     *
     * @var list<string>
     */
    private const array MODEL_SPENDING_SUFFIXES = [
        '/monitors/analyze',
        '/draft-update',
        '/draft-postmortem',
        '/assistant',
    ];

    /**
     * The API prefix, which is the contract with the Flutter client.
     */
    private const string API_PREFIX = '/api/v1/';

    /**
     * Answer the sample rate for one transaction.
     *
     * Registered as an ARRAY CALLABLE in `config/sentry.php`, not a closure:
     * `config:cache` serialises that file through `var_export()`, which cannot
     * represent a closure, and the deploy runs `config:cache`.
     *
     * @param  SamplingContext  $context  What the SDK knows about the transaction it is about to start.
     * @return float The fraction of such transactions to keep, 0.0 to 1.0.
     */
    public static function sample(SamplingContext $context): float
    {
        $transactionContext = $context->getTransactionContext();

        if ($transactionContext === null) {
            return self::RATE_BACKGROUND;
        }

        $name = $transactionContext->getName();
        $op = $transactionContext->getOp();

        // 1. Model spend overrides an inherited decision, and this ordering is
        //    the whole reason the check exists here rather than below. Analyze
        //    is called BY the Flutter client, which samples at 0.1, so under
        //    plain inheritance nine runs in ten arrive with
        //    `parentSampled = false` and the one path worth reconstructing is
        //    the one path that never has a trace.
        if (self::spendsModelBudget($op, $name)) {
            return self::RATE_ALWAYS;
        }

        // 2. Otherwise a parent decision wins, in BOTH directions. Re-deciding
        //    here would tear a distributed trace in half: a request the client
        //    sampled would arrive with no server side, which reads as the
        //    backend never having handled it. `null` (no parent) is deliberately
        //    distinct from `false` (a parent that said no), which the idiomatic
        //    `if ($parentSampled)` check collapses.
        $parentSampled = $context->getParentSampled();

        if ($parentSampled !== null) {
            return $parentSampled ? self::RATE_ALWAYS : 0.0;
        }

        // 3. A trace that starts HERE picks its own rate. Which of these arms
        //    actually decides volume is not obvious: see the class docblock.
        return match ($op) {
            'queue.process' => self::rateForJob($name),
            'http.server' => self::rateForPath($name),
            'console.command.scheduled', 'console.command' => self::RATE_SCHEDULER,
            default => self::RATE_BACKGROUND,
        };
    }

    /**
     * Whether this transaction is one that costs money to run.
     *
     * @param  string|null  $op  The SDK's operation tag.
     * @param  string  $name  The transaction name: a job class, or a request path.
     */
    private static function spendsModelBudget(?string $op, string $name): bool
    {
        if ($op === 'queue.process') {
            return $name === AnalyzeMonitorJob::class;
        }

        if ($op !== 'http.server') {
            return false;
        }

        foreach (self::MODEL_SPENDING_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rate for a queued job, keyed on its resolved class name.
     *
     * @param  string  $jobName  The job class, as `QueueIntegration` resolved it.
     */
    private static function rateForJob(string $jobName): float
    {
        return match ($jobName) {
            PerformMonitorCheck::class => self::RATE_CHECK_QUEUE,
            AnalyzeMonitorJob::class => self::RATE_ALWAYS,
            default => self::RATE_BACKGROUND,
        };
    }

    /**
     * The rate for an HTTP request, keyed on its path.
     *
     * @param  string  $path  The request path, leading slash included, as the tracing middleware set it.
     */
    private static function rateForPath(string $path): float
    {
        foreach (self::MODEL_SPENDING_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return self::RATE_ALWAYS;
            }
        }

        if (str_starts_with($path, self::API_PREFIX)) {
            return self::RATE_API;
        }

        // Everything else is the public surface: status pages (cached 60s) and
        // the marketing pages. High volume, anonymous, and low information.
        return self::RATE_BACKGROUND;
    }
}
