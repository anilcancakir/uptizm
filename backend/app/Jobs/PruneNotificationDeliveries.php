<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Nightly retention sweep for {@see NotificationDelivery}: deletes every
 * attempted-delivery row outside the configured retention window.
 *
 * Copies the retention guard and the chunked delete from
 * {@see PruneContentArchive}, the only other prune in this codebase, rather
 * than inventing a third shape: a misconfigured window resolving below one
 * day would otherwise delete every delivery row in a single sweep, and an
 * offset-paginated chunk would skip a row for every row this job removes.
 *
 * Unlike `PruneContentArchive` there is no blob to reconcile and no shared
 * FUSE mount to stall on, so the sweep is a plain chunked row delete with no
 * survivor query and no cross-request lock: two overlapping sweeps would only
 * ever re-select and re-delete the same already-gone rows, which is harmless,
 * and `withoutOverlapping()` on the schedule entry already keeps that from
 * happening in practice.
 */
class PruneNotificationDeliveries implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Rows held in memory at once. `chunkById` keyset-paginates on the primary
     * key, which is what makes it safe to delete inside the callback: an
     * offset-paginated chunk would skip a row for every row removed.
     */
    protected const int CHUNK_SIZE = 200;

    /**
     * One attempt. A retry would re-derive the same cutoff and re-visit rows
     * the interrupted run had not reached yet, which is exactly what the next
     * nightly run does anyway.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Delete every delivery row outside the retention window.
     *
     * @throws RuntimeException When the configured retention window is not a
     *                          positive number of days.
     */
    public function handle(): void
    {
        // 1. Resolve the cutoff BEFORE deleting anything. A window of zero days
        //    puts the cutoff at `now()` and deletes every delivery ever recorded
        //    in a single sweep, so it has to fail before the first delete rather
        //    than after the last one.
        $cutoff = $this->cutoff();

        // 2. Walk the expired rows in bounded chunks, deleting each chunk as a
        //    single statement rather than one `DELETE` per row.
        NotificationDelivery::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(self::CHUNK_SIZE, function (Collection $expired): void {
                NotificationDelivery::query()
                    ->whereKey($expired->modelKeys())
                    ->delete();
            });
    }

    /**
     * Start of the retention window: anything created before this is deleted.
     *
     * @throws RuntimeException When `retention_days` is not at least one day. It
     *                          is env-driven, and at zero every delivery ever
     *                          recorded is outside the window, so a single bad
     *                          value would empty the audit trail in one sweep. A
     *                          failed job is visible in Horizon; a silent mass
     *                          delete of the delivery history is not.
     */
    protected function cutoff(): CarbonInterface
    {
        $days = (int) config('notification-deliveries.retention_days');

        if ($days < 1) {
            throw new RuntimeException(
                'notification-deliveries.retention_days resolved to ['.$days.'], which deletes '
                .'every delivery row at once; the retention sweep needs a window of at least one day.'
            );
        }

        return now()->subDays($days);
    }
}
