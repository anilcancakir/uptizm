<?php

namespace App\Jobs;

use App\Mail\ScheduledMaintenanceAnnounced;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Announces a newly planned maintenance window to the confirmed subscribers of
 * the status page it belongs to. This is the product's first outbound mail to
 * third parties, so both of its guards are load-bearing.
 *
 * CONSENT. A recipient is a subscriber holding `opt_in_confirmed_at`, the
 * provenance column only the public confirm endpoint writes. `confirmed_at` and
 * a missing `confirmed_token` are NOT usable here: a completed public opt-in and
 * an address an operator pasted in before the add path required a click are
 * byte-identical on both, so selecting on them would announce to addresses this
 * system never saw consent for, on the product's own sending domain. That is
 * exactly why the provenance column exists.
 *
 * ANNOUNCE ONCE. The window's `announced_at` is claimed with a single
 * conditional UPDATE (null to now) before anything is queued, and the fan-out
 * runs only when that UPDATE affected a row. The claim lives HERE rather than in
 * the controller for a reason: on a row created microseconds earlier
 * `announced_at` is always null, so a claim on the request path would be
 * vacuous, and every way this job can run a second time (a worker retry, a
 * re-dispatch, a duplicate queue delivery) would still re-mail. Claiming inside
 * `handle()` makes the guarantee hold for every one of those, and a window that
 * was deleted before the job ran is discarded rather than announced.
 *
 * Bounded like the outbound notification channels, for the same reason they are:
 * one attempt, and a transport failure on a single recipient is REPORTED rather
 * than rethrown, because the claim is already spent and a rethrow would abandon
 * every remaining recipient while re-mailing the ones already handed over.
 *
 * Rides the `default` queue, which is the one Horizon's primary supervisor
 * consumes alongside the domain queues.
 */
class AnnounceScheduledMaintenance implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. A retry could only ever find the window already claimed and
     * mail nobody, so a failure belongs in `failed_jobs` where an operator can
     * see it rather than in a retry loop that cannot make progress.
     */
    public int $tries = 1;

    /**
     * A window deleted between the create request and this job announces
     * nothing: the job is dropped instead of failing on a missing model.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * How many recipients are read per query while fanning out.
     */
    private const int RECIPIENT_CHUNK = 200;

    public function __construct(public ScheduledMaintenance $maintenance) {}

    public function handle(): void
    {
        // 1. Claim the announcement. A spent claim ends the job here, so this is
        //    also the whole answer to "what happens if this runs twice".
        if (! $this->claim()) {
            Log::info('Maintenance announcement skipped: already claimed.', [
                'maintenance_id' => $this->maintenance->getKey(),
            ]);

            return;
        }

        // 2. Resolve the window's public shape once. The successful claim above
        //    proves the window row is still there, and `status_page_id` is NOT
        //    NULL behind a cascading FK, so the page is guaranteed present;
        //    `firstOrFail` keeps that guarantee loud instead of leaning on a
        //    nullable it then has to pretend to handle. The component names are
        //    read here rather than inside the mailable so the pivot is not
        //    reloaded once per recipient.
        $page = $this->maintenance->statusPage()->firstOrFail();
        $componentNames = $this->maintenance->monitors()->pluck('name')->all();

        // 3. Fan out, one queued mail per recipient. Never `Mail::to()->send()`:
        //    a synchronous fan-out would hold the worker open across one
        //    third-party handshake per subscriber.
        $announced = 0;

        $page->subscribers()
            ->whereNotNull('opt_in_confirmed_at')
            ->chunkById(self::RECIPIENT_CHUNK, function (Collection $subscribers) use (
                $page,
                $componentNames,
                &$announced,
            ): void {
                foreach ($subscribers as $subscriber) {
                    $announced += $this->queueAnnouncement($page, $subscriber, $componentNames) ? 1 : 0;
                }
            });

        Log::info('Maintenance announcement fanned out.', [
            'maintenance_id' => $this->maintenance->getKey(),
            'status_page_id' => $page->getKey(),
            'recipients' => $announced,
        ]);
    }

    /**
     * Claim the announce-once guard for this window.
     *
     * A single conditional UPDATE, so two workers holding the same job can never
     * both proceed: exactly one of them sees an affected row.
     */
    protected function claim(): bool
    {
        return ScheduledMaintenance::query()
            ->whereKey($this->maintenance->getKey())
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]) > 0;
    }

    /**
     * Queue the announcement for one recipient, reporting rather than rethrowing
     * a transport failure.
     *
     * The claim is already spent by the time this runs, so letting an exception
     * escape would cost every remaining recipient their mail while re-sending it
     * to those already handed to the transport. The same trade the outbound
     * notification channels make when their retry budget runs out.
     *
     * @param  array<int, string>  $componentNames
     */
    protected function queueAnnouncement(
        StatusPage $page,
        StatusPageSubscriber $subscriber,
        array $componentNames,
    ): bool {
        try {
            Mail::to($subscriber->email)->queue(
                new ScheduledMaintenanceAnnounced($page, $this->maintenance, $subscriber, $componentNames),
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
