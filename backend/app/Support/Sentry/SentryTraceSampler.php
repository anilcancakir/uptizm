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
 * THE ARITHMETIC BEHIND THE NUMBERS
 *
 * The check queue sets the ceiling. The shortest sellable interval is 5 seconds
 * and the common one is 30 (`config/plans.php`), across 5 regions, so a hundred
 * monitors is ~1000 jobs a minute, ~43M a month. With `tracing.sql_queries` on,
 * each is a transaction of roughly a dozen spans, so capturing them all would
 * be ~500M spans against this org's 50M monthly allowance: ten times the quota
 * from one queue alone. {@see self::RATE_CHECK_QUEUE} brings that to ~500K and
 * still samples about one check a minute, which is enough to see a latency
 * trend and far more than enough to see a broken one.
 *
 * `analyze` is the opposite shape: a few hundred runs a MONTH, each spending
 * real model budget, each worth reconstructing when it goes wrong. It is
 * sampled at 1.0 and costs almost nothing at that rate.
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
     * The scheduler, which fires every 30 seconds forever.
     */
    public const float RATE_SCHEDULER = 0.01;

    /**
     * The probe queue. See the class docblock for why this is a thousandth.
     */
    public const float RATE_CHECK_QUEUE = 0.001;

    /**
     * The path prefix the analyze endpoint lives under.
     *
     * Matched as a prefix rather than an exact string so the sub-resources that
     * belong to a run (its status, its result) inherit the same treatment.
     */
    private const string ANALYZE_PATH = '/api/v1/monitors/analyze';

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
        // 1. A parent decision always wins, in BOTH directions. Re-deciding
        //    here would tear a distributed trace in half: a request the client
        //    sampled would arrive with no server side, which reads as the
        //    backend never having handled it. Note that `null` (no parent) is
        //    deliberately distinct from `false` (a parent that said no), which
        //    the idiomatic `if ($parentSampled)` check collapses.
        $parentSampled = $context->getParentSampled();

        if ($parentSampled !== null) {
            return $parentSampled ? self::RATE_ALWAYS : 0.0;
        }

        $transactionContext = $context->getTransactionContext();

        if ($transactionContext === null) {
            return self::RATE_BACKGROUND;
        }

        $name = $transactionContext->getName();

        // 2. Branch on the operation the SDK tagged, since the same rate means
        //    very different volume for a queue job and an HTTP request.
        return match ($transactionContext->getOp()) {
            'queue.process' => self::rateForJob($name),
            'http.server' => self::rateForPath($name),
            'console.command.scheduled', 'console.command' => self::RATE_SCHEDULER,
            default => self::RATE_BACKGROUND,
        };
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
        if (str_starts_with($path, self::ANALYZE_PATH)) {
            return self::RATE_ALWAYS;
        }

        if (str_starts_with($path, self::API_PREFIX)) {
            return self::RATE_API;
        }

        // Everything else is the public surface: status pages (cached 60s) and
        // the marketing pages. High volume, anonymous, and low information.
        return self::RATE_BACKGROUND;
    }
}
