<?php

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single incident. Ships the affected-component pivot and
 * the unified update timeline when those relations are eager-loaded (see
 * IncidentController::index()/show()).
 *
 * @property Incident $resource
 */
class IncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'title' => $this->resource->title,
            'lifecycle' => $this->resource->lifecycle?->value,
            'severity' => $this->resource->severity?->value,
            'impact' => $this->resource->impact?->value,
            'signal_source' => $this->resource->signal_source?->value,
            'ai_owned' => (bool) $this->resource->ai_owned,
            'primary_monitor_id' => $this->resource->primary_monitor_id,
            'trigger_metric_key' => $this->resource->trigger_metric_key,
            'started_at' => $this->resource->started_at?->toIso8601String(),
            'resolved_at' => $this->resource->resolved_at?->toIso8601String(),
            'monitors' => $this->whenLoaded(
                'monitors',
                fn () => $this->resource->monitors->map(fn ($monitor) => [
                    'monitor_id' => $monitor->id,
                    'name' => $monitor->name,
                    'component_status_at_start' => $monitor->pivot->component_status_at_start ?? null,
                    'component_status_current' => $monitor->pivot->component_status_current ?? null,
                ])->values(),
            ),
            'updates' => $this->whenLoaded(
                'updates',
                fn () => IncidentUpdateResource::collection($this->resource->updates),
            ),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
