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
 * before delegating to {@see CheckPersistenceService}. It never re-probes:
 * the relay already ran, this job only persists the outcome. AI-mode fan-out
 * is intentionally absent, `ai_mode` is off in this iteration.
 */
class ProcessCheckResult implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param string              $monitorId The monitor the probe ran against.
     * @param string              $region    Target region value the probe ran in.
     * @param array<string, mixed> $payload  Worker wire payload for the result.
     */
    public function __construct(
        public string $monitorId,
        public string $region,
        public array $payload,
    ) {
        $this->onQueue('processing');
    }

    public function handle(CheckPersistenceService $persistence): void
    {
        // 1. Resolve the monitor; abandon the row if it was deleted mid-flight.
        $monitor = Monitor::query()->findOrFail($this->monitorId);

        // 2. Rehydrate the already-fetched result and persist it.
        $result = CheckResult::fromWorkerPayload($this->payload);

        $persistence->persist($monitor, $result);
    }
}
