<?php

namespace App\Http\Resources;

use App\Models\ScheduledMaintenance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single scheduled maintenance window.
 *
 * Every timestamp ships as ISO-8601 with its offset, because the client stores
 * a `DateTime` and cannot recover an offset from a naive string. The affected
 * components come from the `scheduled_maintenance_monitors` pivot and are only
 * present when that relation was eager-loaded, mirroring
 * {@see IncidentResource}.
 *
 * `announced_at` is READ-ONLY here: it is emitted so the client can tell an
 * announced window from a silent one, but neither request class accepts it, so
 * the announce-once guard is only ever written by the mail job that owns it.
 *
 * @property ScheduledMaintenance $resource
 */
class ScheduledMaintenanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'status_page_id' => $this->resource->status_page_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'suppress_alerts' => (bool) $this->resource->suppress_alerts,
            'starts_at' => $this->resource->starts_at?->toIso8601String(),
            'ends_at' => $this->resource->ends_at?->toIso8601String(),
            'announced_at' => $this->resource->announced_at?->toIso8601String(),
            'monitors' => $this->whenLoaded(
                'monitors',
                fn () => $this->resource->monitors->map(fn ($monitor) => [
                    'monitor_id' => $monitor->id,
                    'name' => $monitor->name,
                ])->values(),
            ),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
