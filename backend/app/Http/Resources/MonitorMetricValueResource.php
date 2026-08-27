<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\MonitorMetricController;
use App\Models\MonitorMetricValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recorded reading of one metric.
 *
 * The same five fields {@see MonitorMetricController::serializeValue()}
 * emits for the chart, as a resource so the paged table can share the shape:
 * one wire contract for a reading, not two that drift.
 *
 * `band` IS THE FROZEN VERDICT, not a live one. It records how the reading was
 * judged by the thresholds in force when it was taken, so a metric whose bounds
 * were edited afterwards keeps a history that says what it said at the time.
 * Recomputing it here would rewrite the past every time somebody fixed a
 * misconfiguration.
 *
 * @mixin MonitorMetricValue
 */
class MonitorMetricValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'recorded_at' => $this->resource->recorded_at?->toIso8601String(),
            'numeric_value' => $this->resource->numeric_value !== null
                ? (float) $this->resource->numeric_value
                : null,
            'string_value' => $this->resource->string_value,
            'status_value' => $this->resource->status_value?->value,
            'band' => $this->resource->band?->value,
        ];
    }
}
