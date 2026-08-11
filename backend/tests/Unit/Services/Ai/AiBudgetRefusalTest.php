<?php

namespace Tests\Unit\Services\Ai;

use App\Enums\MetricType;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Exceptions\AiBudgetExhaustedException;
use App\Models\Monitor;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AnalysisPayload;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\LaravelAiMetricDiscoveryGateway;
use App\Services\Ai\MetricDiscoveryPayload;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Monitoring\MetricCandidateExtractor;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A model call that was never made is not a model answer that could not be
 * trusted, and the logs have to say which one happened.
 *
 * The production line these tests exist for, from `POST /monitors/analyze` on
 * 2026-08-07, where the operator got no suggested metrics at all:
 *
 *   Metric discovery degraded: the model output could not be trusted.
 *   {"monitor_id":"","exception":"Metric discovery gateway received
 *    non-conforming structured output."}
 *
 * No output existed to distrust. The suggestion turn ahead of it had spent the
 * request's shared budget, {@see App\Services\Ai\AiDeadline} refused the third
 * call, and the raw-call seam expressed that refusal by returning null, which is
 * the same value it uses for "the model did not answer with structured output".
 * Both gateways retry once on that null and then report non-conforming output,
 * so one budget refusal produced two no-op attempts and a log line pointing at
 * a prompt that was never issued.
 *
 * Each test below is written so that restoring `return null` in the seam it
 * covers turns exactly that test red.
 */
class AiBudgetRefusalTest extends TestCase
{
    /**
     * A team id for the budget meter. Any stable string does; the cache counter
     * is keyed by it and the array store starts empty per test.
     */
    private const TEAM_ID = '11111111-1111-1111-1111-111111111111';

    public function test_a_spent_budget_refuses_metric_discovery_as_a_budget_failure_not_a_trust_failure(): void
    {
        // A budget under the minimum stands in for "the two calls ahead of this
        // one already spent it", which is the state the third call was in.
        config(['ai.request_budget_seconds' => 1, 'ai.minimum_call_seconds' => 8]);

        $this->expectException(AiBudgetExhaustedException::class);

        (new LaravelAiMetricDiscoveryGateway)->discover($this->discoveryPayload());
    }

    public function test_a_refused_discovery_call_never_reports_itself_as_non_conforming_output(): void
    {
        // The complement of the test above, stated on the MESSAGE rather than
        // the class: an exception that reads as non-conforming output is what
        // sent a reader looking at a prompt, so the wording is part of the fix
        // and not incidental to it.
        config(['ai.request_budget_seconds' => 1, 'ai.minimum_call_seconds' => 8]);

        try {
            (new LaravelAiMetricDiscoveryGateway)->discover($this->discoveryPayload());
            $this->fail('a refused call has to raise rather than return a result');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('non-conforming', $exception->getMessage());
            $this->assertStringContainsString('budget', $exception->getMessage());
        }
    }

    public function test_a_spent_budget_refuses_the_suggestion_turn_as_a_budget_failure(): void
    {
        // The same seam on the other gateway. `analyze()` runs the optional
        // research turn first, which degrades to null silently and correctly on
        // a thin budget, so what raises here is the suggestion itself.
        config(['ai.request_budget_seconds' => 1, 'ai.minimum_call_seconds' => 8]);

        $this->expectException(AiBudgetExhaustedException::class);

        (new LaravelAiAnalysisGateway)->analyze($this->analysisPayload());
    }

    public function test_the_discovery_service_logs_a_spent_budget_as_a_budget_line(): void
    {
        // THE user-visible assertion, and the one the production line failed.
        // `AiBudgetExhaustedException` extends RuntimeException on purpose (an
        // unhandled class here would 500 the request the whole surface exists to
        // keep answering), so the branch that names it has to be caught FIRST or
        // the older label silently wins. Ordering the catches wrong is exactly
        // what this test measures.
        Log::spy();
        config(['ai.request_budget_seconds' => 1, 'ai.minimum_call_seconds' => 8]);

        $suggestions = $this->discoveryService()->discover(
            new Monitor(['type' => MonitorType::Http, 'url' => 'https://example.com/health']),
            '{"latency_ms": 12.5}',
            self::TEAM_ID,
        );

        // Still the ordinary empty degrade: nothing about the honest label
        // changes what the operator receives.
        $this->assertSame([], $suggestions);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'AI budget was already spent'))
            ->once();

        // Both argument positions have to be given, and the second one matters:
        // the real call is `warning($message, $context)`, so a single-element
        // expectation matches no call at all and passes whatever happened.
        Log::shouldNotHaveReceived('warning', [
            Mockery::pattern('/could not be trusted/'),
            Mockery::any(),
        ]);
    }

    /**
     * A minimal well-formed discovery payload. Its content is irrelevant here:
     * every test in this file refuses before a prompt is built.
     */
    private function discoveryPayload(): MetricDiscoveryPayload
    {
        return new MetricDiscoveryPayload(
            url: 'https://example.com/health',
            monitorType: MonitorType::Http->value,
            candidateRefs: ['c1'],
            digestRows: [
                [
                    'ref' => 'c1',
                    'src' => 'json_path',
                    'path' => 'latency_ms',
                    'value' => '12.5',
                    'types' => [MetricType::Numeric->value],
                ],
            ],
        );
    }

    /**
     * A minimal well-formed analysis payload, under the same irrelevance.
     */
    private function analysisPayload(): AnalysisPayload
    {
        return new AnalysisPayload(
            url: 'https://example.com/health',
            region: MonitorRegion::USEast->value,
            statusCode: 200,
            responseMs: 180,
            timingDnsMs: 10,
            timingConnectMs: 20,
            timingTlsMs: 30,
            timingTtfbMs: 100,
            timingDownloadMs: 20,
            knownRegions: MonitorRegion::values(),
            errorMessage: null,
            responseBodyPreview: null,
            responseHeaders: [],
            teamId: self::TEAM_ID,
            digest: null,
            targetLocation: null,
        );
    }

    /**
     * The real service over the real gateway: the point is that the exception
     * crosses the boundary between them and lands in the right catch, so
     * nothing in that chain may be faked.
     */
    private function discoveryService(): MetricDiscoveryService
    {
        return new MetricDiscoveryService(
            new MetricCandidateExtractor,
            new LaravelAiMetricDiscoveryGateway,
            new AiBudget,
        );
    }
}
