<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\EscalationPolicyController;
use App\Models\EscalationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single escalation policy consumed by the Flutter
 * escalation policy editor.
 *
 * The `steps` array is only populated when the relation was eager-loaded
 * (see {@see EscalationPolicyController::show()}), so `index` stays a single
 * query while `show` and the mutating step actions carry the full chain.
 *
 * @property EscalationPolicy $resource
 */
class EscalationPolicyResource extends JsonResource
{
    /**
     * Transform the policy into its wire representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'name' => $this->resource->name,
            'steps' => $this->whenLoaded('steps', function (): array {
                return $this->resource->steps
                    ->map(static fn ($step): array => [
                        'id' => $step->id,
                        'position' => $step->position,
                        'delay_minutes' => $step->delay_minutes,
                        'target_type' => $step->target_type->value,
                        'target_id' => $step->target_id,
                        'channel' => $step->channel,
                    ])
                    ->all();
            }),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
