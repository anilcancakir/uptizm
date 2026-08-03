<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Services\FeedFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetches ONE catalog service's official status feed through
 * {@see FeedFetcher}, which owns every polling constraint and records both
 * outcomes (a parsed reading and a failure) as a `service_feed_snapshots` row.
 *
 * `$tries = 1`, and that is a constraint rather than a default. A retry is a
 * second request to somebody else's server, so it would have to answer to the
 * 60-second floor; and the two responses worth reacting to (`429`, `403`)
 * disable the feed instead of being retried. There is nothing left for a retry
 * to fix that the next scheduled tick does not fix more politely, so the job
 * makes exactly one attempt and records what happened.
 *
 * Carries the service ID rather than the model so a deletion between the
 * fan-out and the run is a no-op instead of a `ModelNotFoundException`
 * (the shape `app/Jobs/PerformSslCheck.php` uses for the same reason).
 */
class IngestServiceFeed implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 1;

    /**
     * @param  string  $serviceId  Primary key of the service whose feed to fetch.
     */
    public function __construct(
        public string $serviceId,
    ) {
        $this->onQueue('feeds');
    }

    /**
     * @param  FeedFetcher  $fetcher  Owns the User-Agent, the 60-second floor, the conditional request, the SSRF re-check and the auto-disable.
     */
    public function handle(FeedFetcher $fetcher): void
    {
        $service = Service::query()->find($this->serviceId);

        if ($service === null) {
            return;
        }

        $fetcher->fetch($service);
    }
}
