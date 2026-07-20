<?php

namespace App\Jobs;

use App\Enums\MonitorRegion;
use App\Models\Monitor;
use App\Services\Monitoring\RelayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Executes one probe for a (monitor, region) pair by pushing the spec to
 * the Cloudflare relay and handing the parsed result to
 * {@see ProcessCheckResult} for persistence.
 *
 * The worker returns the outcome inline in the `/run` response, so this job
 * hands the already-fetched result straight to the processing queue instead
 * of waiting on an async callback. It owns just the network round-trip: any
 * persistence or threshold side-effect lives downstream so a worker failure
 * retries cheaply.
 */
class PerformMonitorCheck implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int
     */
    public $backoff = 10;

    /**
     * @param  Monitor  $monitor  The monitor to probe.
     * @param  string  $region  Target region value (see {@see MonitorRegion}).
     */
    public function __construct(
        public Monitor $monitor,
        public string $region,
    ) {
        $this->onQueue('checks');
    }

    public function handle(RelayClient $relay): void
    {
        // 1. Push to the regional worker and get the parsed result inline.
        $result = $relay->dispatch($this->monitor, $this->region);

        // 2. Hand off to the processing queue for persistence.
        ProcessCheckResult::dispatch($this->monitor->id, $this->region, $result->toArray())
            ->onQueue('processing');
    }
}
