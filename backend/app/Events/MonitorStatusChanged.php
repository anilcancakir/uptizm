<?php

namespace App\Events;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a monitor's health crosses into a new state (up/down/degraded/
 * paused). Broadcast on the owning team's private channel so every connected
 * dashboard for that team updates in real time.
 *
 * Dispatched after commit so subscribers never observe a status that a rolled
 * back transaction would have undone. The payload deliberately carries the
 * monitor's health only, never its url, auth_config, or request headers: those
 * are secrets and must never leave the server on a broadcast channel.
 */
class MonitorStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  Monitor  $monitor  The monitor whose health changed.
     * @param  ?MonitorStatus  $from  Prior health, or null when no status was recorded before.
     * @param  MonitorStatus  $to  New health after the transition.
     */
    public function __construct(
        public readonly Monitor $monitor,
        public readonly ?MonitorStatus $from,
        public readonly MonitorStatus $to,
    ) {}

    /**
     * The channels the event should broadcast on.
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
     * The wire name the client subscribes to for this event.
     */
    public function broadcastAs(): string
    {
        return 'monitor.status';
    }

    /**
     * The broadcast payload: the monitor's health only, never its secrets.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'monitor_id' => $this->monitor->id,
            'name' => $this->monitor->name,
            'status' => $this->to->value,
            'previous_status' => $this->from?->value,
            'last_checked_at' => $this->monitor->last_checked_at?->toIso8601String(),
            'last_response_ms' => $this->monitor->last_response_ms,
        ];
    }
}
