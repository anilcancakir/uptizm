<?php

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single incident. Ships the affected-component pivot, the
 * assigned responder, and the unified update timeline when those relations are
 * eager-loaded (see IncidentController::index()/show()).
 *
 * `assignee` is a deliberately minimal `{id, name}` object (or null): the
 * client renders an owner label and posts the id back, so no other user field
 * belongs on an incident payload. The postmortem ships flat as
 * `postmortem_body` + `postmortem_published_at`; a body with a null stamp is an
 * internal draft the public status page never renders.
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
            // Flat, alongside `title`, not nested: the client reads attributes
            // through getAttribute() and a nested map would need a hand-rolled
            // decode at every reader. `title_params` defaults to an empty array
            // rather than null so absent/null/empty are not three states meaning
            // the same thing.
            'title_key' => $this->resource->title_key,
            'title_params' => $this->resource->title_params ?? [],
            'lifecycle' => $this->resource->lifecycle?->value,
            'severity' => $this->resource->severity?->value,
            'impact' => $this->resource->impact?->value,
            'signal_source' => $this->resource->signal_source?->value,
            'ai_owned' => (bool) $this->resource->ai_owned,
            'primary_monitor_id' => $this->resource->primary_monitor_id,
            'trigger_metric_key' => $this->resource->trigger_metric_key,
            'started_at' => $this->resource->started_at?->toIso8601String(),
            'resolved_at' => $this->resource->resolved_at?->toIso8601String(),
            'postmortem_body' => $this->resource->postmortem_body,
            'postmortem_published_at' => $this->resource->postmortem_published_at?->toIso8601String(),
            // `whenLoaded` yields null for a loaded-but-empty relation, so an
            // unassigned incident ships an explicit `assignee: null` while a
            // payload that never loaded the relation omits the key entirely.
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->resource->assignee->id,
                'name' => $this->resource->assignee->name,
            ]),
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
