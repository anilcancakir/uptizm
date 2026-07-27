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
 * relations were eager-loaded. Every endpoint that returns a schedule does
 * eager-load them ({@see OnCallController::index()}, {@see OnCallController::show()},
 * and the mutating rotation/override actions), so the client always receives a
 * complete ring; the guards stay in place because `store()` and `update()`
 * answer with a freshly written model whose relations were never loaded, and
 * emitting `rotations: []` there would read as "the ring is empty" rather than
 * "the ring was not part of this response".
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
