<?php

namespace App\Jobs;

use App\Mail\ScheduledMaintenanceCancelled;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells a status page's confirmed subscribers that a window they were promised
 * is no longer happening.
 *
 * The third outbound mail to third parties, and the one that closes a gap the
 * other two opened: {@see AnnounceScheduledMaintenance} mails "maintenance is
 * coming", and cancelling that window used to say nothing, so the mail in
 * somebody's inbox went on naming work that would never happen.
 *
 * IT CARRIES VALUES, NOT A MODEL, which is the whole shape of this job. The
 * window is DELETED by the time a worker picks this up, so `SerializesModels`
 * would resolve nothing and `deleteWhenMissingModels` would drop the job
 * silently: the announcement has to be assembled from data captured before the
 * delete and handed over whole.
 *
 * ONLY FOR A WINDOW THAT WAS ANNOUNCED. A window cancelled before its
 * announcement went out is a window nobody was told about, and mailing a
 * cancellation for it would be the first they hear of either. The caller gates
 * on `announced_at`; this job does not re-check it, because by then there is no
 * row left to read.
 *
 * ANNOUNCE ONCE, through a cache claim rather than a column, for the same
 * reason: the row that would have carried the flag is gone. The key is the
 * window's id, which outlives it.
 *
 * CONSENT is `opt_in_confirmed_at`, exactly as the other two: the provenance
 * column only the public confirm endpoint writes. An address an operator pasted
 * in before the add path required a click is byte-identical on `confirmed_at`.
 */
class AnnounceMaintenanceCancelled implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. A retry could only ever find the claim spent and mail
     * nobody, so a failure belongs in `failed_jobs` where an operator can see
     * it rather than in a retry loop that cannot make progress.
     */
    public int $tries = 1;

    /**
     * How many recipients are read per query while fanning out.
     */
    private const int RECIPIENT_CHUNK = 200;

    /**
     * How long the announce-once claim survives.
     *
     * A day, matching the paging guards: long enough that every retry and
     * duplicate delivery of this job lands inside it, short enough that the
     * cache does not accumulate keys for windows nobody will mention again.
     */
    private const int CLAIM_TTL_SECONDS = 86400;

    /**
     * @param  string  $maintenanceId  The deleted window's id, used as the
     *                                 announce-once key since its row is gone.
     * @param  string  $statusPageId  The page whose subscribers were told.
     * @param  string  $title  The window's title, as the public saw it.
     * @param  array<int, string>  $componentNames  Affected components as the
     *                                              PAGE published them,
     *                                              resolved before the delete.
     */
    public function __construct(
        public string $maintenanceId,
        public string $statusPageId,
        public string $title,
        public array $componentNames = [],
    ) {}

    public function handle(): void
    {
        if (! Cache::add($this->claimKey(), true, self::CLAIM_TTL_SECONDS)) {
            Log::info('Maintenance cancellation skipped: already claimed.', [
                'maintenance_id' => $this->maintenanceId,
            ]);

            return;
        }

        $page = StatusPage::query()->find($this->statusPageId);

        if ($page === null) {
            // The page went with the window (a team deleting both, a cascade).
            // Its subscribers went with it, so there is nobody to tell.
            Log::info('Maintenance cancellation had no status page.', [
                'maintenance_id' => $this->maintenanceId,
            ]);

            return;
        }

        $announced = 0;

        $page->subscribers()
            ->whereNotNull('opt_in_confirmed_at')
            ->chunkById(self::RECIPIENT_CHUNK, function (Collection $subscribers) use (
                $page,
                &$announced,
            ): void {
                foreach ($subscribers as $subscriber) {
                    $announced += $this->queueAnnouncement($page, $subscriber) ? 1 : 0;
                }
            });

        Log::info('Maintenance cancellation fanned out.', [
            'maintenance_id' => $this->maintenanceId,
            'status_page_id' => $page->getKey(),
            'recipients' => $announced,
        ]);
    }

    /**
     * The announce-once key for this cancellation.
     */
    protected function claimKey(): string
    {
        return "maintenance-cancellation-announced:{$this->maintenanceId}";
    }

    /**
     * Queue the cancellation for one recipient, reporting rather than
     * rethrowing a transport failure: the claim is already spent, so a rethrow
     * would abandon every remaining recipient while re-mailing those already
     * handed over.
     */
    protected function queueAnnouncement(StatusPage $page, StatusPageSubscriber $subscriber): bool
    {
        try {
            // The subscriber's captured language, carried explicitly: a queue
            // worker has no request to inherit a locale from and a subscriber
            // is not a `User`, so `HasLocalePreference` never fires here.
            Mail::to($subscriber->email)
                ->locale($subscriber->locale ?? (string) config('app.default_locale'))
                ->queue(new ScheduledMaintenanceCancelled(
                    $page,
                    $this->title,
                    $subscriber,
                    $this->componentNames,
                ));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
