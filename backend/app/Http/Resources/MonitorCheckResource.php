<?php

namespace App\Http\Resources;

use App\Models\MonitorCheck;
use App\Services\Monitoring\CheckAggregateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single probe execution row, used both by the raw check
 * history endpoint and by {@see CheckAggregateService::responseTimeSamples()},
 * whose synthetic bucketed rows share the same {@see MonitorCheck}
 * shape (with `id`/`monitor_id`/`region` left null for an aggregated dot).
 *
 * @property MonitorCheck $resource
 */
class MonitorCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'region' => $this->region,
            'status' => $this->status?->value,
            'status_code' => $this->status_code,
            'response_ms' => $this->response_ms,
            'timing_dns_ms' => $this->timing_dns_ms,
            'timing_connect_ms' => $this->timing_connect_ms,
            'timing_tls_ms' => $this->timing_tls_ms,
            'timing_ttfb_ms' => $this->timing_ttfb_ms,
            'timing_download_ms' => $this->timing_download_ms,
            'checked_at' => $this->checked_at,
            'error_message' => $this->error_message,
        ];
    }
}
