<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\Monitoring\CheckPersistenceService;
use App\Support\Monitoring\CheckResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists a {@see CheckResult} already fetched by {@see PerformMonitorCheck}.
 *
 * The result travels as a plain array (the worker wire payload) rather than
 * a serialized value object so the job survives the queue round-trip
 * cheaply; this job rehydrates it via {@see CheckResult::fromWorkerPayload()}
 * before delegating to {@see CheckPersistenceService}. It never re-probes and
 * it never re-reads the body: the relay already ran and the upstream job already
 * extracted the metric samples and resolved the content-archive decision from the
 * full response, which is why both travel as separate scalars rather than inside
 * the payload. This job only persists the outcome. AI-mode fan-out is
 * intentionally absent, `ai_mode` is off in this iteration.
 */
class ProcessCheckResult implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Raw metric values keyed by metric key, extracted upstream from the full
     * body. Scalars only: the body they came from never rides this queue.
     *
     * Null means "extraction never ran for this payload" (a TCP probe, a content
     * type the edge filtered, a worker older than the body field, or a direct
     * caller), which is what routes persistence to the 10 KiB preview fallback.
     * An empty ARRAY means extraction ran against the full body and matched
     * nothing, which must NOT fall back.
     *
     * Declared in the class body rather than promoted so it carries a real
     * property-level default. `unserialize()` does not call the constructor, so a
     * job serialized onto Redis before this field existed restores without it; a
     * promoted property would then be UNINITIALIZED and throw on first access,
     * failing every in-flight job permanently across the deploy.
     *
     * @var array<string, string>|null
     */
    public ?array $samples = null;

    /**
     * ADDRESS of the archived version this check's content resolved to, which on
     * an unchanged page is an EARLIER body's raw hash rather than this one's.
     * Null when nothing was archived. Class-body declared for the same
     * deploy-safety reason as {@see $samples}.
     */
    public ?string $contentHash = null;

    /**
     * This body's own change signal. Class-body declared for the same
     * deploy-safety reason as {@see $samples}.
     */
    public ?string $contentHashNormalized = null;

    /**
     * @param  string  $monitorId  The monitor the probe ran against.
     * @param  string  $region  Target region value the probe ran in.
     * @param  array<string, mixed>  $payload  Worker wire payload for the result.
     * @param  array<string, string>|null  $samples  See {@see $samples}.
     */
    public function __construct(
        public string $monitorId,
        public string $region,
        public array $payload,
        ?array $samples = null,
        ?string $contentHash = null,
        ?string $contentHashNormalized = null,
    ) {
        $this->samples = $samples;
        $this->contentHash = $contentHash;
        $this->contentHashNormalized = $contentHashNormalized;

        $this->onQueue('processing');
    }

    public function handle(CheckPersistenceService $persistence): void
    {
        // 1. Resolve the monitor; abandon the row if it was deleted mid-flight.
        $monitor = Monitor::query()->findOrFail($this->monitorId);

        // 2. Rehydrate the already-fetched result and persist it alongside the
        //    samples and the content hashes the body-owning stage resolved.
        $result = CheckResult::fromWorkerPayload($this->payload);

        $persistence->persist(
            $monitor,
            $result,
            $this->samples,
            $this->contentHash,
            $this->contentHashNormalized,
        );
    }
}
