<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Services\FeedFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fan-out job that queues one {@see IngestServiceFeed} per catalog service whose
 * official status feed is due to be polled.
 *
 * Selection lives in {@see Service::scopeDueForFeedIngest()} so the five
 * refusals it encodes (unpublished, no feed source, no url, terms unreviewed,
 * feed disabled) are stated once and can be read in one place. The per-service
 * polling FLOOR is deliberately not part of this query: it is enforced against
 * the newest snapshot inside {@see FeedFetcher}, which is the only place that
 * holds under a re-dispatch or a retry as well as under a schedule tick.
 *
 * One isolated job per service, never a single loop, so one unreachable provider
 * fails only its own fetch, mirroring `app/Jobs/ScheduleSslChecks.php`.
 *
 * Both this job and its children run on the `feeds` queue, which is registered
 * in TWO places on purpose: `composer.json`'s `scripts.dev` queue list (what
 * drains it locally) and `config/horizon.php` supervisor-1 (what drains it on
 * the server). Registered in only one of them, the schedule fires, jobs queue,
 * `schedule:list` looks right, and no feed is ever ingested in production.
 */
class IngestServiceFeeds implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Seconds for which only one copy of this fan-out may run, guarding against
     * a tick landing while a prior fan-out is still enqueuing.
     *
     * @var int
     */
    public $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('feeds');
    }

    public function handle(): void
    {
        $serviceIds = Service::query()
            ->dueForFeedIngest()
            ->pluck('id');

        foreach ($serviceIds as $serviceId) {
            IngestServiceFeed::dispatch((string) $serviceId)->onQueue('feeds');
        }
    }
}
