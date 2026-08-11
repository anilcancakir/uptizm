<?php

namespace App\Events;

use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time incident notification pushed to a team's private channel so the
 * Flutter Echo client can refresh its incident list without polling.
 *
 * Dispatched (a later step) from the monitoring pipeline when an incident
 * opens or resolves. The single event carries both lifecycle transitions; the
 * wire name varies by {@see self::$kind} so the client can listen for
 * `incident.opened` and `incident.resolved` separately on the same channel.
 *
 * The payload is the leak boundary: {@see self::broadcastWith()} mirrors the
 * redacted, team-owned allowlist of {@see IncidentResource}
 * and never exposes the primary monitor's url, auth_config, password, or
 * headers.
 */
class IncidentBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new incident broadcast.
     *
     * @param  Incident  $incident  The incident whose lifecycle changed.
     * @param  string  $kind  The transition being broadcast: `opened` or `resolved`.
     * @return void
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly string $kind,
    ) {}

    /**
     * The channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('teams.'.$this->incident->team_id),
        ];
    }

    /**
     * The wire event name the client listens for. Varies by kind so a single
     * event class serves both `incident.opened` and `incident.resolved`.
     */
    public function broadcastAs(): string
    {
        return "incident.{$this->kind}";
    }

    /**
     * The redacted, team-owned payload the client receives, deliberately omitting
     * every monitor connection secret (url, auth_config, headers).
     *
     * A subset of the {@see IncidentResource} allowlist rather than a mirror of it,
     * and the difference is deliberate: the resource also carries `title_key` and
     * `title_params`, which the client renders a localized title from, while this
     * payload is a TRIGGER. `RealtimeService.onIncidentEvent` reads no field off it
     * and only arms a refetch, so `title` here is never displayed and the localized
     * one arrives through the resource on the refetch. Do not add fields to close a
     * gap nothing reads; if a future client renders a card straight off the socket,
     * that is when the two title columns belong here.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->incident->id,
            'team_id' => $this->incident->team_id,
            'title' => $this->incident->title,
            'lifecycle' => $this->incident->lifecycle?->value,
            'severity' => $this->incident->severity?->value,
            'impact' => $this->incident->impact?->value,
            'signal_source' => $this->incident->signal_source?->value,
            'ai_owned' => (bool) $this->incident->ai_owned,
            'primary_monitor_id' => $this->incident->primary_monitor_id,
            'monitor_name' => $this->incident->primaryMonitor?->name ?? 'A monitor',
            'trigger_metric_key' => $this->incident->trigger_metric_key,
            'started_at' => $this->incident->started_at?->toIso8601String(),
            'resolved_at' => $this->incident->resolved_at?->toIso8601String(),
        ];
    }
}
