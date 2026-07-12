<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\OnCallController;
use App\Models\OnCallSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single on-call schedule consumed by the Flutter on-call
 * schedule screen.
 *
 * The `rotations` and `overrides` arrays are only populated when their
 * relations were eager-loaded (see {@see OnCallController::show()}),
 * so `index` stays a single query while `show` and the mutating
 * rotation/override actions can carry the full ring + override list.
 *
 * @property OnCallSchedule $resource
 */
class OnCallScheduleResource extends JsonResource
{
    /**
     * Transform the schedule into its wire representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'name' => $this->resource->name,
            'timezone' => $this->resource->timezone,
            'rotations' => $this->whenLoaded('rotations', function (): array {
                return $this->resource->rotations
                    ->map(static fn ($rotation): array => [
                        'id' => $rotation->id,
                        'user_id' => $rotation->user_id,
                        'user_name' => $rotation->user?->name,
                        'position' => $rotation->position,
                        'shift_hours' => $rotation->shift_hours,
                    ])
                    ->all();
            }),
            'overrides' => $this->whenLoaded('overrides', function (): array {
                return $this->resource->overrides
                    ->map(static fn ($override): array => [
                        'id' => $override->id,
                        'user_id' => $override->user_id,
                        'user_name' => $override->user?->name,
                        'starts_at' => $override->starts_at?->toIso8601String(),
                        'ends_at' => $override->ends_at?->toIso8601String(),
                    ])
                    ->all();
            }),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
