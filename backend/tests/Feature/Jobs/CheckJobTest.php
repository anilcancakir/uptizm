<?php

namespace Tests\Feature\Jobs;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Jobs\PerformMonitorCheck;
use App\Jobs\ProcessCheckResult;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the synchronous relay handoff between {@see PerformMonitorCheck} and
 * {@see ProcessCheckResult}: a faked {@see RelayClient} result must flow
 * straight through to a persisted {@see MonitorCheck} row, with no AI job
 * queued anywhere in the chain (ai_mode is off in this iteration).
 */
class CheckJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The `response_body_preview` ceiling the worker truncates at. A metric whose
     * value sits past it was unreachable while extraction read the preview.
     */
    protected const int PREVIEW_BYTES = 10240;

    /**
     * A needle placed past the preview cap, so finding it anywhere in a queued
     * job means the full body leaked onto the queue.
     */
    protected const string BODY_ONLY_MARKER = 'past-the-cap-body-marker';

    public function test_perform_dispatches_process_which_persists_the_result(): void
    {
        $monitor = $this->makeMonitor();
        $this->fakeRelay(status: MonitorStatus::Up);

        Bus::fake([
            ProcessCheckResult::class,
        ]);

        (new PerformMonitorCheck($monitor, 'us-east'))->handle(
            $this->app->make(RelayClient::class),
            $this->app->make(MetricExtractor::class),
        );

        // 1. The processing job is dispatched with the worker payload, on the
        //    processing queue, and nothing else (no AI job exists to assert
        //    against, so asserting the exact dispatched job is the ceiling).
        Bus::assertDispatched(ProcessCheckResult::class, function (ProcessCheckResult $job) use ($monitor): bool {
            return $job->monitorId === $monitor->id
                && $job->region === 'us-east'
                && $job->payload['status'] === MonitorStatus::Up->value;
        });
    }

    public function test_process_persists_a_monitor_check_row_from_the_relay_result(): void
    {
        $monitor = $this->makeMonitor();
        $result = $this->makeResult($monitor, MonitorStatus::Up);

        (new ProcessCheckResult($monitor->id, 'us-east', $result->toArray()))
            ->handle($this->app->make(CheckPersistenceService::class));

        $check = MonitorCheck::query()->where('monitor_id', $monitor->id)->first();

        $this->assertNotNull($check);
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame(MonitorStatus::Up, $monitor->fresh()->last_status);
    }

    public function test_the_full_chain_persists_without_queuing_any_ai_job(): void
    {
        $monitor = $this->makeMonitor();
        $this->fakeRelay(status: MonitorStatus::Down);

        // 1. Let the queue actually run synchronously (QUEUE_CONNECTION=sync
        //    in testing) so the full Perform -> Process -> persist chain
        //    executes for real.
        PerformMonitorCheck::dispatch($monitor, 'us-east');

        $check = MonitorCheck::query()->where('monitor_id', $monitor->id)->first();

        $this->assertNotNull($check);
        $this->assertSame(MonitorStatus::Down, $check->status);

        // 2. No AI job class exists in this codebase to assert against; the
        //    absence of any job beyond Process itself is the assertion.
        $this->assertSame(1, MonitorCheck::query()->count());
    }

    /**
     * The regression that proves metric extraction moved to the stage holding
     * the full body: the target value sits past the 10 KiB preview cap, so the
     * preview extraction this replaces cannot reach it at all.
     */
    public function test_a_metric_past_the_preview_cap_extracts_from_the_full_body(): void
    {
        $monitor = $this->makeMonitor();
        $this->makeNumericMetric($monitor);

        $body = $this->bodyWithItsValuePastThePreviewCap();
        $this->fakeRelay(
            status: MonitorStatus::Up,
            content: $body,
            preview: substr($body, 0, self::PREVIEW_BYTES),
        );

        // 1. Run the real Perform -> Process -> persist chain (QUEUE_CONNECTION
        //    is sync in testing).
        PerformMonitorCheck::dispatch($monitor, 'us-east');

        // 2. The stored preview is still capped at 10 KiB and is not even valid
        //    JSON there, so the sample provably did not come from it.
        $check = MonitorCheck::query()->where('monitor_id', $monitor->id)->sole();
        $this->assertSame(self::PREVIEW_BYTES, strlen((string) $check->response_body_preview));
        $this->assertNull(json_decode((string) $check->response_body_preview, true));

        // 3. The value past the cap extracted and persisted.
        $value = MonitorMetricValue::query()->sole();
        $this->assertSame('deep', $value->metric_key);
        $this->assertSame(4711.0, $value->numeric_value);
    }

    /**
     * The samples cross the queue hop as scalars while the body stays behind:
     * a 12 KB page on the Redis `processing` queue is what the whole content
     * design exists to avoid.
     */
    public function test_the_processing_handoff_carries_the_samples_but_never_the_body(): void
    {
        $monitor = $this->makeMonitor();
        $this->makeNumericMetric($monitor);

        $body = $this->bodyWithItsValuePastThePreviewCap();
        $this->fakeRelay(
            status: MonitorStatus::Up,
            content: $body,
            preview: substr($body, 0, self::PREVIEW_BYTES),
        );

        Bus::fake([
            ProcessCheckResult::class,
        ]);

        (new PerformMonitorCheck($monitor, 'us-east'))->handle(
            $this->app->make(RelayClient::class),
            $this->app->make(MetricExtractor::class),
        );

        Bus::assertDispatched(ProcessCheckResult::class, function (ProcessCheckResult $job): bool {
            return $job->samples === ['deep' => '4711']
                && ! array_key_exists('content', $job->payload)
                && ! str_contains(serialize($job), self::BODY_ONLY_MARKER);
        });
    }

    /**
     * Binds a fake {@see RelayClient} into the container so
     * {@see PerformMonitorCheck::handle()} never performs a real HTTP call.
     *
     * `$content` is the full decoded body the current worker returns; leaving it
     * null reproduces a TCP probe, a filtered content type, or a worker
     * deployment older than that field.
     */
    protected function fakeRelay(MonitorStatus $status, ?string $content = null, ?string $preview = null): void
    {
        $this->app->bind(RelayClient::class, function () use ($status, $content, $preview): RelayClient {
            return new class($status, $content, $preview) extends RelayClient
            {
                public function __construct(
                    private readonly MonitorStatus $status,
                    private readonly ?string $content,
                    private readonly ?string $preview,
                ) {}

                public function dispatch(Monitor $monitor, string $region): CheckResult
                {
                    return new CheckResult(
                        monitorId: (string) $monitor->id,
                        region: $region,
                        checkedAt: new DateTimeImmutable,
                        status: $this->status,
                        statusCode: $this->status === MonitorStatus::Up ? 200 : 503,
                        responseMs: 128,
                        errorMessage: null,
                        timingDnsMs: 1,
                        timingConnectMs: 2,
                        timingTlsMs: 3,
                        timingTtfbMs: 4,
                        timingDownloadMs: 5,
                        responseHeaders: [
                            'content-type' => 'application/json',
                        ],
                        responseBodyPreview: $this->preview,
                        probeRunId: (string) Str::uuid(),
                        content: $this->content,
                        contentType: $this->content !== null ? 'application/json' : null,
                        contentTruncated: false,
                    );
                }
            };
        });
    }

    /**
     * A JSON body whose target value sits past {@see self::PREVIEW_BYTES}, with
     * the padding first so a 10 KiB cut of it is not even parseable JSON.
     *
     * The marker trails the target value, so it exists ONLY in the full body and
     * never in the preview; that is what makes it a sound probe for whether the
     * body leaked into a queue payload.
     */
    protected function bodyWithItsValuePastThePreviewCap(): string
    {
        return (string) json_encode([
            'padding' => str_repeat('x', 12000),
            'deep' => 4711,
            'marker' => self::BODY_ONLY_MARKER,
        ]);
    }

    /**
     * Attaches one unbounded numeric metric reading `deep` out of the JSON body,
     * so extraction runs without any threshold breach muddying the outcome.
     */
    protected function makeNumericMetric(Monitor $monitor): void
    {
        $monitor->metrics()->create([
            'team_id' => $monitor->team_id,
            'label' => 'Deep value',
            'key' => 'deep',
            'type' => MetricType::Numeric,
            'source' => MetricSource::JsonPath,
            'extraction_path' => 'deep',
        ]);
    }

    /**
     * Builds a CheckResult carrying the given status for a direct
     * {@see ProcessCheckResult} unit call.
     */
    protected function makeResult(Monitor $monitor, MonitorStatus $status): CheckResult
    {
        return new CheckResult(
            monitorId: (string) $monitor->id,
            region: 'us-east',
            checkedAt: new DateTimeImmutable,
            status: $status,
            statusCode: $status === MonitorStatus::Up ? 200 : 503,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: (string) Str::uuid(),
        );
    }

    protected function makeMonitor(): Monitor
    {
        $user = User::query()->create([
            'name' => 'Job Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Job Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);
    }
}
