<?php

namespace Tests\Unit\Support\Sentry;

use App\Jobs\AnalyzeMonitorJob;
use App\Jobs\PerformMonitorCheck;
use App\Support\Sentry\SentryTraceSampler;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Tests\TestCase;

/**
 * Locks the sample rates, because getting them wrong is silent in both
 * directions.
 *
 * Sentry bills SPANS on what it RECEIVES, not on what it keeps: its own
 * dynamic-sampling doc says "metering is based on received, not stored events",
 * so server-side retention tuning is not a cost lever and this sampler is the
 * only one there is. This org's plan includes 50M spans a month and carries
 * `onDemandMaxSpend = 0`, which means overshooting does not produce a bill, it
 * produces a MONTH OF DROPPED DATA once the quota is gone.
 *
 * The arithmetic that sets these numbers: the shortest sellable check interval
 * is 5 seconds and the common one is 30, across 5 regions, so a hundred
 * monitors is already ~1000 queued jobs a minute, ~43M a month. Each is a
 * transaction with roughly a dozen spans once `tracing.sql_queries` is on, so
 * capturing them all would be ~500M spans against a 50M allowance: ten times
 * the quota, from one queue. At 0.001 the same queue costs ~500K spans and
 * still produces about one sampled check a minute, which is plenty to watch a
 * latency trend.
 *
 * The opposite mistake is just as real and this file pins it too: `analyze`
 * runs a few hundred times a MONTH and each run spends real money on model
 * calls, so it is sampled at 1.0. Sampling it like a check would mean the one
 * path worth a trace is the one path that never has one.
 */
class SentryTraceSamplerTest extends TestCase
{
    /**
     * A parent decision always wins, and this is the assertion that keeps
     * distributed traces intact.
     *
     * The Flutter client starts a trace and propagates it; if this service
     * re-decided independently, a trace the client kept would arrive here with
     * its server half missing, which reads as "the backend never handled the
     * request" rather than as a sampling artefact.
     */
    public function test_it_inherits_a_positive_parent_decision(): void
    {
        $context = $this->contextFor('/api/v1/monitors', 'http.server', parentSampled: true);

        $this->assertSame(1.0, SentryTraceSampler::sample($context));
    }

    /**
     * The other half of inheritance, and the half a naive implementation drops:
     * `if ($parentSampled)` treats "parent said no" and "there is no parent"
     * identically, so a trace the head deliberately discarded gets a stray
     * orphan span from this service anyway.
     */
    public function test_it_inherits_a_negative_parent_decision(): void
    {
        $context = $this->contextFor('/api/v1/monitors', 'http.server', parentSampled: false);

        $this->assertSame(0.0, SentryTraceSampler::sample($context));
    }

    /**
     * The volume driver. See the class docblock for the arithmetic.
     */
    public function test_the_check_queue_is_sampled_at_the_floor(): void
    {
        $context = $this->contextFor(PerformMonitorCheck::class, 'queue.process');

        $this->assertSame(0.001, SentryTraceSampler::sample($context));
    }

    /**
     * The rare expensive path. A few hundred runs a month, each spending model
     * budget, so every one of them is worth a trace.
     */
    public function test_the_analyze_job_is_always_sampled(): void
    {
        $context = $this->contextFor(AnalyzeMonitorJob::class, 'queue.process');

        $this->assertSame(1.0, SentryTraceSampler::sample($context));
    }

    /**
     * Everything else on a queue is neither: real work, moderate volume.
     */
    public function test_other_queues_get_the_middle_rate(): void
    {
        $context = $this->contextFor('App\Jobs\PerformSslCheck', 'queue.process');

        $this->assertSame(0.05, SentryTraceSampler::sample($context));
    }

    /**
     * The API the Flutter client calls, when it starts the trace itself.
     */
    public function test_api_requests_get_the_api_rate(): void
    {
        $context = $this->contextFor('/api/v1/monitors', 'http.server');

        $this->assertSame(0.2, SentryTraceSampler::sample($context));
    }

    /**
     * The analyze ENDPOINT, not just the job behind it. It runs a live probe
     * and queues the model work, so the request half is worth as much as the
     * job half.
     */
    public function test_the_analyze_endpoint_is_always_sampled(): void
    {
        $context = $this->contextFor('/api/v1/monitors/analyze', 'http.server');

        $this->assertSame(1.0, SentryTraceSampler::sample($context));
    }

    /**
     * Public status pages are cached for 60s and served to anonymous traffic,
     * so they are high-volume and low-information.
     */
    public function test_public_pages_get_the_low_rate(): void
    {
        $context = $this->contextFor('/s/acme-status', 'http.server');

        $this->assertSame(0.05, SentryTraceSampler::sample($context));
    }

    /**
     * The scheduler fires every 30 seconds forever. It is worth watching, but
     * not once a minute.
     */
    public function test_scheduled_commands_are_sampled_sparsely(): void
    {
        $context = $this->contextFor('monitoring:dispatch', 'console.command.scheduled');

        $this->assertSame(0.01, SentryTraceSampler::sample($context));
    }

    /**
     * An op the SDK adds in a future version must not silently become 100%.
     */
    public function test_an_unknown_op_falls_back_to_the_low_rate(): void
    {
        $context = $this->contextFor('something.new', 'not.an.op.we.know');

        $this->assertSame(0.05, SentryTraceSampler::sample($context));
    }

    /**
     * Build a sampling context the way the SDK does.
     *
     * @param  string  $name  The transaction name: a request path for `http.server`, a job class for `queue.process`.
     * @param  string  $op  The operation the SDK tagged the transaction with.
     * @param  bool|null  $parentSampled  The upstream decision, or null when this service starts the trace.
     */
    private function contextFor(string $name, string $op, ?bool $parentSampled = null): SamplingContext
    {
        $transactionContext = new TransactionContext($name);
        $transactionContext->setOp($op);

        if ($parentSampled !== null) {
            $transactionContext->setParentSampled($parentSampled);
        }

        return SamplingContext::getDefault($transactionContext);
    }
}
