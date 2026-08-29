<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\NotificationChannelSeverity;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Jobs\PublishAiIncidentUpdate;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\ScheduledMaintenance;
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\OnCall\EscalationDispatcher;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Logging\EvidenceLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification as NotificationInstance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Shared off-lock dispatch seam for a completed incident evaluation. It fires
 * the three side effects that must never run inside a per-monitor lock: the
 * incident lifecycle notifications (each gated on the monitor's matching alert
 * flag), the live-dashboard broadcasts (UNCONDITIONAL on the alert flags), and
 * the status-page cache bust.
 *
 * Extracted from {@see CheckPersistenceService} so both the automated persist
 * path and the manual incident-write path drive the exact same dispatch, in the
 * exact same order, against the same guards. Callers MUST invoke it only after
 * releasing the monitor lock: every notification here is `ShouldQueue` and both
 * events are `ShouldDispatchAfterCommit`, so nothing may enqueue while the
 * critical section is held.
 */
class IncidentDispatcher
{
    /**
     * Per-channel throttle window, in seconds. A flapping monitor or a
     * correlated multi-monitor outage can burst many opens/resolves at once;
     * this cooldown collapses them to a single send per channel so one
     * Slack/webhook endpoint is never hammered. Accepted v1 tradeoff: a genuine
     * distinct incident inside the window is suppressed, not queued.
     */
    protected const CHANNEL_THROTTLE_SECONDS = 60;

    public function __construct(
        protected StatusPageCache $statusPageCache,
        protected EscalationDispatcher $escalationDispatcher,
    ) {}

    /**
     * Dispatch the off-lock side effects of a completed evaluation.
     *
     * Both notifications are `ShouldQueue`, so a send enqueues rather than
     * blocks; the alert flag is the gate, so a monitor opted out of down or
     * recovery alerts stays silent. The broadcasts are a live UI refresh, not a
     * page, so they ignore the `alert_on_*` gate entirely.
     *
     * A second gate sits in front of the paging paths: an open
     * {@see ScheduledMaintenance} window withholds every page for the monitors
     * ATTACHED TO IT, so planned work does not wake anyone. It withholds the
     * page only: the incident still opens, still broadcasts, and still busts the
     * public page's cache.
     *
     * @param  Monitor  $monitor  The monitor whose evaluation produced the outcome.
     * @param  array{
     *     opened: ?Incident,
     *     resolved: ?Incident,
     *     escalated?: ?Incident,
     *     status_change: array{from: ?MonitorStatus, to: MonitorStatus}|null
     * }  $outcome  The evaluator result: opened/resolved/escalated incident refs
     *   plus the in-lock health transition (any slot null when nothing changed).
     *   `escalated` is read with a null coalesce because the write-side callers
     *   ({@see IncidentWriteService}) compose this
     *   array by hand and have no escalation to report.
     */
    public function dispatch(Monitor $monitor, array $outcome): void
    {
        // 0. Resolve the planned window (if any) muting this monitor right now.
        //    Only a lifecycle change can page, so a quiet evaluation never pays
        //    for the query, and a suppression is logged rather than taken
        //    silently: an unexplained missing page is indistinguishable from a
        //    broken alert pipeline.
        $escalated = $outcome['escalated'] ?? null;
        $suppressedBy = $outcome['opened'] !== null || $outcome['resolved'] !== null || $escalated !== null
            ? $this->openSuppressingWindow($monitor)
            : null;

        if ($suppressedBy !== null) {
            $this->logSuppression($monitor, $suppressedBy, $outcome['opened'] ?? $outcome['resolved'] ?? $escalated);
        }

        // 1. A threshold open pages the team, gated on the down-alert flag, then
        //    fans out to the team's enabled integration channels (Slack/webhook)
        //    under the same gate: a muted monitor stays silent everywhere.
        if ($outcome['opened'] !== null && $monitor->alert_on_down && $suppressedBy === null) {
            $opened = new IncidentOpened($outcome['opened']);
            Notification::send($outcome['opened']->team->users, $opened);
            $this->dispatchChannels($outcome['opened'], $opened, 'opened');
        }

        // 1b. A severity RAISE on an already-open incident pages under the same
        //     down-alert gate. This is the half that was missing: the evaluator
        //     deduped by metric key alone, so a metric configured to warn on
        //     `degraded` and page on `down` opened at warn and then never told
        //     the critical-only channels anything, because those gate on the
        //     incident's own severity and nothing raised it.
        //
        //     Its own notification class, not IncidentOpened with a flag: the
        //     operator has been watching this incident, and open-shaped copy
        //     about it reads as a second unrelated outage.
        if ($escalated !== null && $monitor->alert_on_down && $suppressedBy === null) {
            $notification = new IncidentEscalated($escalated);
            Notification::send($escalated->team->users, $notification);
            $this->dispatchChannels($escalated, $notification, 'escalated');
        }

        // 2. A recovery clears the page, gated on the recover-alert flag, and
        //    mirrors the open path's channel fan-out. Also withheld inside a
        //    window: relief from a page nobody received is noise.
        if ($outcome['resolved'] !== null && $monitor->alert_on_recover && $suppressedBy === null) {
            $resolved = new IncidentResolved($outcome['resolved']);
            Notification::send($outcome['resolved']->team->users, $resolved);
            $this->dispatchChannels($outcome['resolved'], $resolved, 'resolved');
        }

        // 3. Broadcast the incident lifecycle to the team's live dashboard.
        //    Unlike the notifications above, these are UNCONDITIONAL on the alert
        //    flags: a broadcast is a passive UI refresh, not a page, so the
        //    alert_on_* gate applies only to the notifications.
        if ($outcome['opened'] !== null) {
            event(new IncidentBroadcast($outcome['opened'], 'opened'));
        }

        if ($escalated !== null) {
            event(new IncidentBroadcast($escalated, 'escalated'));
        }

        if ($outcome['resolved'] !== null) {
            event(new IncidentBroadcast($outcome['resolved'], 'resolved'));
        }

        // 3b. Hand an autonomous status update to the queue, for a monitor whose
        //     operator allowed it.
        //
        //     HERE, and not beside `IncidentWriteService::dispatchOpened()` where
        //     it started. That method is reached only by a manual create and a
        //     reopen; the automated open goes `CheckPersistenceService` ->
        //     `ThresholdEvaluator::evaluate()` -> this dispatcher, so the hook
        //     sat on two rare paths and missed every threshold and metric open,
        //     which is to say almost all of them. Live, an incident opened with
        //     the switch on and nothing was ever queued.
        //
        //     This is the one seam every announcement crosses exactly once, in
        //     both directions, which is also why it cannot double-post.
        $this->dispatchAutonomousUpdate($monitor, $outcome['opened'], IncidentStatus::Investigating);
        $this->dispatchAutonomousUpdate($monitor, $outcome['resolved'], IncidentStatus::Resolved);

        // 4. Broadcast the monitor health flip to the same live dashboard, only
        //    when the in-lock read recorded a real transition. The guard
        //    (changed, not paused) already ran inside the transaction, so a set
        //    `status_change` is always a broadcastable flip. `$monitor` was
        //    refreshed in place post-UPDATE, so the payload reads fresh
        //    last_checked_at / last_response_ms.
        //
        //    `from` may be NULL: that is a brand-new monitor's first result, the
        //    one an operator is most likely to be watching for. The event types
        //    it `?MonitorStatus` and sends `previous_status` through `?->value`.
        if ($outcome['status_change'] !== null) {
            event(new MonitorStatusChanged(
                $monitor,
                $outcome['status_change']['from'],
                $outcome['status_change']['to'],
            ));
        }

        // 5. Bust the public status-page cache off-lock whenever the lifecycle
        //    changed. An open/resolve mutates the affected monitor's component
        //    status, so every containing page's cached read model is now stale
        //    and must be forgotten immediately, not after the 60s TTL. This is
        //    wired at the pivot boundary (not an Incident observer), which fires
        //    before monitors()->attach() and so cannot see the containing pages.
        //    An escalation counts too: it rewrites the incident's severity AND
        //    its title, and StatusPageAssembler puts that title straight into the
        //    public read model, so a cached page would keep showing the state the
        //    incident has moved on from until the TTL expired.
        if ($outcome['opened'] !== null || $outcome['resolved'] !== null || $escalated !== null) {
            $this->statusPageCache->invalidateForMonitors([$monitor->id]);
        }

        // 6. Walk the escalation ladder for a freshly opened incident, off-lock.
        //    This is an additive side effect on `opened`: it queues the paging
        //    chain (on-call resolution + delayed step jobs) without touching the
        //    notification/broadcast/cache ordering above. A team with no policy
        //    escalates to nothing (the dispatcher no-ops). The ladder is the
        //    loudest page of all, so an open window withholds it too, and unlike
        //    step 1 it is ungated by `alert_on_down`, so this check is the only
        //    thing standing between planned work and a 3am phone call.
        if ($outcome['opened'] !== null && $suppressedBy === null) {
            $this->escalationDispatcher->escalate($outcome['opened']);
        }
    }

    /**
     * Queue the autonomous status update for one side of an outcome.
     *
     * Null incident means this outcome did not make that transition, so there is
     * nothing to write about. The `ai_auto_updates` check here only keeps the
     * queue clean: the job re-reads it at fire time, because dispatch and fire
     * are minutes apart and the flag is the operator's consent.
     *
     * The stage is `investigating` at open rather than the incident's own
     * `detected`: detected is what the monitoring system calls an incident
     * nobody has picked up, and it is never what a customer is told.
     *
     * Neither the connection nor the queue is named here any more. Both live on
     * the job as constants its constructor applies, so the routing travels with
     * the class rather than with each dispatch site; a `->onQueue('ai')` here was
     * how the job came to ride a connection whose `retry_after` it outlives.
     */
    protected function dispatchAutonomousUpdate(
        Monitor $monitor,
        ?Incident $incident,
        IncidentStatus $stage,
    ): void {
        if ($incident === null || ! $monitor->ai_auto_updates) {
            return;
        }

        PublishAiIncidentUpdate::dispatch((string) $incident->getKey(), $stage->value);
    }

    /**
     * The open, alert-suppressing maintenance window this monitor is attached
     * to, or null when it is not inside one.
     *
     * Deliberately narrow, and the narrowness is the point: the match is on the
     * window's OWN pivot rows and on the clock sitting between its own bounds.
     * Widening it to the team or to the containing status page would silence a
     * genuine outage on a monitor nobody planned work for, which is a far more
     * expensive failure than a page an operator expected.
     *
     * `suppress_alerts` is the switch; a window created with it off announces
     * the work publicly and still pages.
     *
     * The open-and-suppressing half is `ScheduledMaintenance::openSuppressing()`,
     * shared with the escalation ladder so the two paging paths cannot drift
     * apart on what "inside a window" means. The team predicate narrows, never
     * widens: no request can attach a foreign monitor today, so it removes
     * nothing, and it means a foreign window can never silence this team even
     * if that ever changes.
     */
    protected function openSuppressingWindow(Monitor $monitor): ?ScheduledMaintenance
    {
        return ScheduledMaintenance::query()
            ->openSuppressing()
            ->where('scheduled_maintenances.team_id', $monitor->team_id)
            ->whereHas(
                'monitors',
                fn (Builder $monitors): Builder => $monitors->whereKey($monitor->getKey()),
            )
            ->first();
    }

    /**
     * Record a withheld page, naming the monitor and the window so the question
     * "why did nobody get paged" has an answer that outlives the shift.
     *
     * ON {@see EvidenceLog::CHANNEL}, NOT THE DEFAULT ONE. This was a
     * `Log::info()` on the application stack, and production runs
     * `LOG_LEVEL=warning`, so it was discarded before it reached a file: the one
     * fact in this class that leaves no row behind was also the one nothing kept.
     * The alternative of promoting it to `warning` was rejected deliberately, a
     * suppression is the system doing what it was told, and filing it as a fault
     * teaches an operator to ignore faults.
     *
     * @param  Incident|null  $incident  The lifecycle incident the page belonged to.
     */
    protected function logSuppression(Monitor $monitor, ScheduledMaintenance $window, ?Incident $incident): void
    {
        EvidenceLog::record('Incident paging suppressed by an open maintenance window.', [
            'monitor_id' => $monitor->getKey(),
            'scheduled_maintenance_id' => $window->getKey(),
            'incident_id' => $incident?->getKey(),
            'window_starts_at' => $window->starts_at?->toIso8601String(),
            'window_ends_at' => $window->ends_at?->toIso8601String(),
        ]);
    }

    /**
     * Fan an incident lifecycle notification out to the team's enabled
     * integration channels.
     *
     * Channel-class selection (Slack vs webhook, empty-credential skip) is owned
     * by the notification's `via()` branch; this only decides WHICH channels get
     * sent to. A channel is a target when it is enabled AND its severity band
     * matches the incident (`all` -> every incident; `critical` -> only critical
     * incidents). Each surviving channel is claimed against a per-channel Cache
     * throttle before sending, so a burst collapses to one send per window.
     *
     * @param  Incident  $incident  The opened/resolved incident driving the send.
     * @param  NotificationInstance  $notification  The prebuilt lifecycle notification.
     * @param  string  $event  The lifecycle event (`opened`/`resolved`), scoping the throttle window.
     */
    protected function dispatchChannels(Incident $incident, NotificationInstance $notification, string $event): void
    {
        $isCritical = $incident->severity === IncidentSeverity::Critical;

        $channels = NotificationChannel::query()
            ->where('team_id', $incident->team_id)
            ->where('is_enabled', true)
            ->get();

        foreach ($channels as $channel) {
            // 1. Drop channels whose severity band excludes this incident.
            if ($channel->severity === NotificationChannelSeverity::Critical && ! $isCritical) {
                continue;
            }

            // 2. Throttle per channel: `Cache::add` is atomic and returns false
            //    when the key is already held, so a burst inside the window is a
            //    no-op instead of a repeated hit on the endpoint.
            if (! Cache::add($this->throttleKey($channel, $incident, $event), true, now()->addSeconds(self::CHANNEL_THROTTLE_SECONDS))) {
                continue;
            }

            Notification::send($channel, $notification);
        }
    }

    /**
     * The throttle cache key: per channel, per INCIDENT, per lifecycle event.
     *
     * Scoping by event (`opened`/`resolved`) keeps an incident's `resolved` from
     * being suppressed by its own `opened` claiming the same window.
     *
     * Scoping by INCIDENT is what stops the window losing an outage. Without it
     * the key was `(channel, event)` alone, so a second incident opening inside
     * the 60 seconds was DROPPED: not deferred, not queued, dropped, with nothing
     * anywhere saying so. One shared dependency failing takes several monitors
     * down inside the same half minute, and the team's Slack heard about one of
     * them. Silence about a real outage is the failure this product exists to
     * prevent.
     *
     * What the window is still for, and still does: coalescing repeated
     * announcements of the SAME incident, which is what a
     * resolve-and-still-broken loop produces. The cost of the change is that a
     * burst of N distinct incidents is now N sends rather than one; each of them
     * is a real, separate outage, and the four integration channels already
     * carry a bounded, Retry-After-aware retry for an endpoint that rate-limits.
     */
    protected function throttleKey(NotificationChannel $channel, Incident $incident, string $event): string
    {
        return "notification-channel-throttle:{$channel->getKey()}:{$incident->getKey()}:{$event}";
    }
}
