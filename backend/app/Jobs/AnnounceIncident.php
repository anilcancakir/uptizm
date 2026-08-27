<?php

namespace App\Jobs;

use App\Mail\IncidentAnnounced;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Services\StatusPages\StatusPageAssembler;
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
 * Announces a newly opened incident to the confirmed subscribers of every status
 * page that publishes one of its affected components.
 *
 * The second outbound mail to third parties this product sends, after
 * {@see AnnounceScheduledMaintenance}, and it carries the same four guards for
 * the same reasons. They are restated rather than referenced because the cost of
 * getting one wrong is mail from our own sending domain to somebody who did not
 * ask for it.
 *
 * CONSENT. A recipient holds `opt_in_confirmed_at`, the provenance column only
 * the public confirm endpoint writes. `confirmed_at` and a missing
 * `confirmed_token` are NOT usable: a completed public opt-in and an address an
 * operator pasted in before the add path required a click are byte-identical on
 * both.
 *
 * ANNOUNCE ONCE. `incidents.subscribers_announced_at` is claimed with a single
 * conditional UPDATE before anything is queued, inside `handle()` rather than at
 * the call site. On a row created microseconds earlier the column is always
 * null, so a claim on the request path would be vacuous, and every way this job
 * can run again (a worker retry, a re-dispatch, a duplicate delivery) would
 * still re-mail.
 *
 * ONLY WHAT THE PAGE PUBLISHES. The component names come from
 * {@see StatusPageAssembler::publicComponentLabels()}, never from the incident's
 * own monitor pivot. The pivot names components a page owner deliberately hid
 * and uses internal monitor names where the page publishes a `custom_label`, and
 * these names reach self-selected public readers. A page that publishes none of
 * the affected components is skipped entirely: its subscribers were told nothing
 * about those components and have no reason to hear about them now.
 *
 * BOUNDED. One attempt, and a transport failure on a single recipient is
 * REPORTED rather than rethrown, because the claim is already spent and a
 * rethrow would abandon every remaining recipient while re-mailing the ones
 * already handed over.
 *
 * Dispatched ONLY from the manual open, and only on an explicit operator yes.
 * An automated open must never reach it: a flapping monitor opens and resolves
 * repeatedly, and each of those would be mail nobody chose to send.
 *
 * Rides the `default` queue, alongside the maintenance announcement.
 */
class AnnounceIncident implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. A retry could only ever find the incident already claimed and
     * mail nobody, so a failure belongs in `failed_jobs` where an operator can
     * see it rather than in a retry loop that cannot make progress.
     */
    public int $tries = 1;

    /**
     * An incident deleted between the open and this job announces nothing.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * How many recipients are read per query while fanning out.
     */
    private const int RECIPIENT_CHUNK = 200;

    public function __construct(public Incident $incident) {}

    public function handle(StatusPageAssembler $assembler): void
    {
        // 1. Claim the announcement. A spent claim ends the job here, so this is
        //    also the whole answer to "what happens if this runs twice".
        if (! $this->claim()) {
            Log::info('Incident announcement skipped: already claimed.', [
                'incident_id' => $this->incident->getKey(),
            ]);

            return;
        }

        /** @var list<string> $monitorIds */
        $monitorIds = $this->incident->monitors()->pluck('monitors.id')->all();

        if ($monitorIds === []) {
            // Nothing to name and nothing to scope the pages by. The claim stays
            // spent: an incident with no affected components has no announcement
            // to make, now or on a later retry.
            Log::info('Incident announcement had no affected components.', [
                'incident_id' => $this->incident->getKey(),
            ]);

            return;
        }

        // 2. Every page of the incident's own team that publishes at least one
        //    affected component. Scoped by team because a status page is a
        //    tenant's own publication and an incident never crosses that line.
        $pages = StatusPage::query()
            ->where('team_id', $this->incident->team_id)
            ->whereHas('monitors', fn ($query) => $query->whereIn('monitors.id', $monitorIds))
            ->get();

        $announced = 0;

        foreach ($pages as $page) {
            $componentNames = $assembler->publicComponentLabels($page, $monitorIds);

            if ($componentNames === []) {
                // The page carries the monitor but does not publish it (hidden,
                // paused, degraded-only-while-up). Its readers were never told
                // this component exists, so an outage mail naming nothing would
                // be the first they hear of it.
                continue;
            }

            $announced += $this->announceToPage($page, $componentNames);
        }

        Log::info('Incident announcement fanned out.', [
            'incident_id' => $this->incident->getKey(),
            'status_pages' => $pages->count(),
            'recipients' => $announced,
        ]);
    }

    /**
     * Claim the announce-once guard for this incident.
     *
     * A single conditional UPDATE, so two workers holding the same job can never
     * both proceed: exactly one of them sees an affected row.
     */
    protected function claim(): bool
    {
        return Incident::query()
            ->whereKey($this->incident->getKey())
            ->whereNull('subscribers_announced_at')
            ->update(['subscribers_announced_at' => now()]) > 0;
    }

    /**
     * Fan out to one page's confirmed subscribers, returning how many were
     * handed to the transport.
     *
     * @param  array<int, string>  $componentNames
     */
    protected function announceToPage(StatusPage $page, array $componentNames): int
    {
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

        return $announced;
    }

    /**
     * Queue the announcement for one recipient, reporting rather than rethrowing
     * a transport failure.
     *
     * @param  array<int, string>  $componentNames
     */
    protected function queueAnnouncement(
        StatusPage $page,
        StatusPageSubscriber $subscriber,
        array $componentNames,
    ): bool {
        try {
            // The subscriber's captured language, carried explicitly. A queue
            // worker has no request to inherit a locale from and a subscriber is
            // not a `User`, so `HasLocalePreference` never fires here: without
            // this the body and the subject would both resolve in the deployment
            // default rather than in the language this reader agreed to.
            Mail::to($subscriber->email)
                ->locale($subscriber->locale ?? (string) config('app.default_locale'))
                ->queue(
                    new IncidentAnnounced($page, $this->incident, $subscriber, $componentNames),
                );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
