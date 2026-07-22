<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\MonitorStatus;
use App\Enums\NotificationChannelSeverity;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\OnCall\EscalationDispatcher;
use App\Services\StatusPages\StatusPageCache;
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
     * @param  Monitor  $monitor  The monitor whose evaluation produced the outcome.
     * @param  array{
     *     opened: ?Incident,
     *     resolved: ?Incident,
     *     status_change: array{from: MonitorStatus, to: MonitorStatus}|null
     * }  $outcome  The evaluator result: opened/resolved incident refs plus the
     *   in-lock health transition (any slot null when nothing changed).
     */
    public function dispatch(Monitor $monitor, array $outcome): void
    {
        // 1. A threshold open pages the team, gated on the down-alert flag, then
        //    fans out to the team's enabled integration channels (Slack/webhook)
        //    under the same gate: a muted monitor stays silent everywhere.
        if ($outcome['opened'] !== null && $monitor->alert_on_down) {
            $opened = new IncidentOpened($outcome['opened']);
            Notification::send($outcome['opened']->team->users, $opened);
            $this->dispatchChannels($outcome['opened'], $opened);
        }

        // 2. A recovery clears the page, gated on the recover-alert flag, and
        //    mirrors the open path's channel fan-out.
        if ($outcome['resolved'] !== null && $monitor->alert_on_recover) {
            $resolved = new IncidentResolved($outcome['resolved']);
            Notification::send($outcome['resolved']->team->users, $resolved);
            $this->dispatchChannels($outcome['resolved'], $resolved);
        }

        // 3. Broadcast the incident lifecycle to the team's live dashboard.
        //    Unlike the notifications above, these are UNCONDITIONAL on the alert
        //    flags: a broadcast is a passive UI refresh, not a page, so the
        //    alert_on_* gate applies only to the notifications.
        if ($outcome['opened'] !== null) {
            event(new IncidentBroadcast($outcome['opened'], 'opened'));
        }

        if ($outcome['resolved'] !== null) {
            event(new IncidentBroadcast($outcome['resolved'], 'resolved'));
        }

        // 4. Broadcast the monitor health flip to the same live dashboard, only
        //    when the in-lock read recorded a real transition. The guard (prior
        //    non-null, changed, not paused) already ran inside the transaction,
        //    so a set `status_change` is always a broadcastable flip. `$monitor`
        //    was refreshed in place post-UPDATE, so the payload reads fresh
        //    last_checked_at / last_response_ms.
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
        if ($outcome['opened'] !== null || $outcome['resolved'] !== null) {
            $this->statusPageCache->invalidateForMonitors([$monitor->id]);
        }

        // 6. Walk the escalation ladder for a freshly opened incident, off-lock.
        //    This is an additive side effect on `opened`: it queues the paging
        //    chain (on-call resolution + delayed step jobs) without touching the
        //    notification/broadcast/cache ordering above. A team with no policy
        //    escalates to nothing (the dispatcher no-ops).
        if ($outcome['opened'] !== null) {
            $this->escalationDispatcher->escalate($outcome['opened']);
        }
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
     */
    protected function dispatchChannels(Incident $incident, NotificationInstance $notification): void
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
            if (! Cache::add($this->throttleKey($channel), true, now()->addSeconds(self::CHANNEL_THROTTLE_SECONDS))) {
                continue;
            }

            Notification::send($channel, $notification);
        }
    }

    /**
     * The per-channel throttle cache key.
     */
    protected function throttleKey(NotificationChannel $channel): string
    {
        return "notification-channel-throttle:{$channel->getKey()}";
    }
}
