<?php

namespace App\Events;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/**
 * A reading landed: one persisted check, pushed to the team's live surfaces.
 *
 * This is the companion to {@see MonitorStatusChanged}, and the split is the
 * point. That event answers "this monitor's health CHANGED" and fires only on a
 * transition, which is correct for the things a transition drives (incident
 * lists, notifications, a reload). But three of the dashboard's four KPI groups
 * and the whole of a monitor's latency history are derived from the READING, not
 * from the transition:
 *
 *  - `avg_response_ms` is the mean of every monitor's `last_response_ms`.
 *  - `uptime_24h` is computed live off the raw check stream.
 *  - the monitor snapshot's "last checked" line is `last_checked_at`.
 *
 * All three move on every check and none of them moved on screen, because
 * nothing carried them. An operator watching a healthy fleet saw a frozen page
 * and had to navigate to learn that anything was still being measured.
 *
 * A REFUSED probe does not reach this event, for the same reason it writes no
 * check row: it measured nothing about the target, so there is no reading to
 * report. See `CheckPersistenceService::persist()` step 0.
 */
class MonitorCheckRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  Monitor  $monitor  The monitor, refreshed past the denorm UPDATE, so
     *                            the payload's `last_*` fields are this check's.
     * @param  MonitorCheck  $check  The durable reading this event announces.
     */
    public function __construct(
        public readonly Monitor $monitor,
        public readonly MonitorCheck $check,
    ) {}

    /**
     * The team's private channel, the same one every monitoring event uses.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('teams.'.$this->monitor->team_id),
        ];
    }

    /**
     * The wire event name. Magic's Reverb channel matches a listener by EXACT
     * string (`reverb_broadcast_driver.dart`), so this name is a frozen contract
     * with the Flutter client's `RealtimeService`.
     */
    public function broadcastAs(): string
    {
        return 'check.recorded';
    }

    /**
     * The reading, plus the monitor health it produced.
     *
     * Two naming decisions, both load-bearing:
     *
     *  1. The denormalised fields are keyed EXACTLY as the client's `Monitor`
     *     model stores them (`last_status`, `last_checked_at`,
     *     `last_response_ms`), so a consumer applies them with a per-key
     *     `setAttribute` and there is no translation table to drift out of sync.
     *  2. This region's verdict is `result`, NOT `status`. `Monitor.status` on the
     *     client reads the ADMIN column (`active`/`paused`) first and only then
     *     `last_status`; a payload key called `status` would land on that field
     *     and render a perfectly healthy monitor as paused.
     *
     * Nothing here can carry a secret: no url, no auth_config, no request or
     * response headers, no body preview. A dashboard needs the number and the
     * verdict, and the detail page fetches the rest through the authorised API.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'monitor_id' => $this->monitor->id,
            'region' => $this->check->region,
            'result' => $this->check->status?->value,
            'response_ms' => $this->check->response_ms,
            'checked_at' => $this->check->checked_at?->toIso8601String(),
            'last_status' => $this->monitor->last_status?->value,
            'last_checked_at' => $this->monitor->last_checked_at?->toIso8601String(),
            'last_response_ms' => $this->monitor->last_response_ms,
        ];
    }
}
