<?php

namespace App\Http\Resources;

use App\Models\IncidentUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for one entry on an incident's unified timeline. The column
 * is `message` (not `body`, see the IncidentUpdate model's source note on
 * `author`/`actor`); it is shipped raw (Markdown) so Flutter and the status
 * page each run their own render pipeline.
 *
 * @property IncidentUpdate $resource
 */
class IncidentUpdateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'incident_id' => $this->resource->incident_id,
            'actor' => $this->resource->actor,
            'author' => $this->resource->author,
            'status' => $this->resource->status?->value,
            'message' => $this->resource->message,
            'is_public' => (bool) $this->resource->is_public,
            'autonomous' => (bool) $this->resource->autonomous,
            'display_at' => $this->resource->display_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
