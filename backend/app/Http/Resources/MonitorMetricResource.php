<?php

namespace App\Http\Resources;

use App\Models\MonitorMetric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a metric definition used by the metrics library and monitor
 * editor screens. Enum fields are emitted as string values so the Flutter app
 * can map them back to Dart enums without extra parsing.
 *
 * The `latest` block is only present when the controller eager-attaches a
 * `latestValue` relation (see MonitorMetricController::index()); it is
 * omitted entirely for a bare create/update response.
 *
 * @property MonitorMetric $resource
 */
class MonitorMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'monitor_id' => $this->resource->monitor_id,
            'group_name' => $this->resource->group_name,
            'label' => $this->resource->label,
            'key' => $this->resource->key,
            'type' => $this->resource->type?->value,
            'source' => $this->resource->source?->value,
            'extraction_path' => $this->resource->extraction_path,
            'unit' => $this->resource->unit?->value,
            'threshold_direction' => $this->resource->threshold_direction?->value,
            'warn_bound' => $this->resource->warn_bound !== null
                ? (float) $this->resource->warn_bound
                : null,
            'critical_bound' => $this->resource->critical_bound !== null
                ? (float) $this->resource->critical_bound
                : null,
            'display_order' => (int) $this->resource->display_order,
            'latest' => $this->whenLoaded('latestValue', function () {
                $value = $this->resource->getRelation('latestValue');
                if ($value === null) {
                    return null;
                }

                return [
                    'recorded_at' => $value->recorded_at?->toIso8601String(),
                    'numeric_value' => $value->numeric_value !== null ? (float) $value->numeric_value : null,
                    'string_value' => $value->string_value,
                    'status_value' => $value->status_value?->value,
                    'band' => $value->band?->value,
                ];
            }),
        ];
    }
}
