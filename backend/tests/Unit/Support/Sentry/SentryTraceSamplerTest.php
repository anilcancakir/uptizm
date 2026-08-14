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
 * THE TESTS BELOW PIN EFFECTIVE RATES, NOT INTENDED ONES, and that distinction
 * is why several of them pass a `parentSampled` argument. Sentry's queue
 * integration propagates the dispatching trace's decision into every job
 * payload and drops an unsampled job before this sampler is consulted, so a
 * test that only ever exercises the parentless case would happily certify a
 * rate that production never applies. Two of these assertions exist because
 * exactly that happened: the check-queue rate was unreachable, and the AI rate
 * was being overridden to zero by an unsampled caller.
 */
class SentryTraceSamplerTest extends TestCase
{
    /**
     * A parent decision wins for ordinary traffic, which is what keeps a
     * distributed trace in one piece.
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
     * The rate that actually governs probe volume, and it is the SCHEDULER's.
     *
     * This is the correction that matters most in this file. A per-job rate for
     * `PerformMonitorCheck` reads like the volume control and is not one:
     * `QueueIntegration` writes the dispatching trace's decision into every
     * queued payload, and refuses a job whose parent was unsampled BEFORE this
     * sampler is consulted. The chain is therefore
     * `console.command.scheduled` -> `ScheduleMonitorChecks` -> every
     * `PerformMonitorCheck` it fans out, and only the first link is ever asked.
     *
     * So the arithmetic lives here: 86,400 thirty-second ticks a month, each
     * fanning out one check per monitor per region. At 0.001 that is ~86
     * sampled ticks, which at a hundred monitors across five regions is ~43,000
     * check transactions a month. The per-check latency this does NOT sample is
     * not lost, it is measured properly in `monitor_checks.response_ms`; what a
     * trace adds is the code path around it.
     */
    public function test_the_scheduler_rate_is_the_one_that_governs_probe_volume(): void
    {
        $context = $this->contextFor('monitoring:schedule-checks', 'console.command.scheduled');

        $this->assertSame(0.001, SentryTraceSampler::sample($context));
    }

    /**
     * The consequence of the above, pinned so nobody "fixes" it back.
     *
     * A check job reaching this sampler at all means its dispatching trace was
     * already sampled, so the honest answer is to keep it: a sampled tick whose
     * fan-out is missing is worse than useless, it is a trace that looks like
     * the dispatcher did nothing.
     */
    public function test_a_check_job_under_a_sampled_parent_is_kept(): void
    {
        $context = $this->contextFor(PerformMonitorCheck::class, 'queue.process', parentSampled: true);

        $this->assertSame(1.0, SentryTraceSampler::sample($context));
    }

    /**
     * The rare expensive path, and the one case where a parent decision is
     * deliberately OVERRIDDEN.
     *
     * Analyze spends real model budget a few hundred times a month. Left to
     * inheritance it would be traced at the rate of whoever called it: the
     * Flutter client samples at 0.1, so nine runs in ten would arrive with
     * `parentSampled = false` and be discarded, and the one path worth
     * reconstructing would be the one path with no trace.
     */
    public function test_the_analyze_job_is_sampled_even_under_an_unsampled_parent(): void
    {
        $context = $this->contextFor(AnalyzeMonitorJob::class, 'queue.process', parentSampled: false);

        $this->assertSame(1.0, SentryTraceSampler::sample($context));
    }

    /**
     * The same override on the request half.
     */
    public function test_a_model_spending_endpoint_is_sampled_even_under_an_unsampled_parent(): void
    {
        $context = $this->contextFor('/api/v1/monitors/analyze', 'http.server', parentSampled: false);

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
     * Every OTHER path that spends model budget, and the list is the reason
     * this is a suffix match rather than a prefix one.
     *
     * Each of these sits behind a route parameter, so there is no shared prefix
     * to key on. They are individually cheap to sample because they are rare
     * (one per incident, not one per check), and individually worth it because
     * a failure costs an operator both money and an answer.
     */
    public function test_every_model_spending_endpoint_is_always_sampled(): void
    {
        $paths = [
            '/api/v1/incidents/42/draft-update',
            '/api/v1/incidents/42/draft-postmortem',
            '/api/v1/assistant',
        ];

        foreach ($paths as $path) {
            $this->assertSame(
                1.0,
                SentryTraceSampler::sample($this->contextFor($path, 'http.server')),
                "$path spends AI budget, so it is worth a trace every time.",
            );
        }
    }

    /**
     * The trap a prefix match walks straight into.
     *
     * `/monitors/analyze/{run}` is the POLLING endpoint the client hits while a
     * run is in flight, so it is called many times per analyze rather than once.
     * A prefix match on `/monitors/analyze` swept it up and sampled it at 1.0,
     * which is the opposite of what its call pattern deserves.
     */
    public function test_the_analyze_polling_endpoint_is_not_treated_as_the_analyze_call(): void
    {
        $context = $this->contextFor('/api/v1/monitors/analyze/9f8e7d6c', 'http.server');

        $this->assertSame(0.2, SentryTraceSampler::sample($context));
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
