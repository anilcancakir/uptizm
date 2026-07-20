<?php

namespace App\Http\Resources;

use App\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single monitor consumed by the Flutter list / show /
 * edit screens.
 *
 * Enum casts are unwrapped to their snake_case backing values so the
 * client deserializes without extra parsing, and `auth_config` is passed
 * through {@see self::redactAuthConfig()} so credential secrets never
 * leave the server.
 *
 * @property Monitor $resource
 */
class MonitorResource extends JsonResource
{
    /**
     * Transform the monitor into its wire representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'name' => $this->resource->name,
            'url' => $this->resource->url,
            'type' => $this->resource->type?->value,
            'method' => $this->resource->method?->value,
            'status' => $this->resource->status,
            'last_status' => $this->resource->last_status?->value,
            'check_interval_sec' => $this->resource->check_interval_sec,
            'timeout_sec' => $this->resource->timeout_sec,
            'regions' => $this->resource->regions ?? [],
            'expected_status_code' => $this->resource->expected_status_code,
            'request_headers' => $this->resource->request_headers ?? [],
            'auth_config' => $this->redactAuthConfig($this->resource->auth_config),
            'slo_target' => $this->resource->slo_target,
            // Measured uptime over the trailing 24h / 7d / 30d, attached by the
            // show action only (null in list/edit responses and while a monitor
            // has no checks yet, which the client renders as "no data").
            'uptime_24h' => $this->resource->uptime_24h,
            'slo_uptime_7d' => $this->resource->slo_uptime_7d,
            'slo_uptime_30d' => $this->resource->slo_uptime_30d,
            'tags' => $this->resource->tags ?? [],
            'show_on_status_page' => (bool) $this->resource->show_on_status_page,
            'only_show_if_degraded' => (bool) $this->resource->only_show_if_degraded,
            'alert_on_down' => (bool) $this->resource->alert_on_down,
            'alert_on_recover' => (bool) $this->resource->alert_on_recover,
            'ssl_tracking' => (bool) $this->resource->ssl_tracking,
            'ssl_expires_at' => $this->resource->ssl_expires_at?->toIso8601String(),
            'ssl_last_checked_at' => $this->resource->ssl_last_checked_at?->toIso8601String(),
            'ssl_alert_threshold_days' => $this->resource->ssl_alert_threshold_days,
            'is_group' => (bool) $this->resource->is_group,
            'parent_id' => $this->resource->parent_id,
            'last_checked_at' => $this->resource->last_checked_at?->toIso8601String(),
            'last_response_ms' => $this->resource->last_response_ms,
            'next_check_at' => $this->resource->next_check_at?->toIso8601String(),
            'consecutive_fails' => $this->resource->consecutive_fails,
            'incident_threshold' => $this->resource->incident_threshold,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Reduce the auth_config to its non-secret descriptors before it leaves
     * the server.
     *
     * Fail-closed allowlist: only the three known non-secret keys survive
     * (`type` = auth flow, `username` = basic-auth user, `header` = the
     * api_key HEADER NAME, never its value). Every other key is dropped, so an
     * unforeseen secret field (password, token, key, ...) defaults to hidden
     * rather than leaking. Enough for the edit form to render the current
     * configuration without ever receiving a credential.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function redactAuthConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return array_intersect_key(
            $config,
            array_flip([
                'type',
                'username',
                'header',
            ]),
        );
    }
}
