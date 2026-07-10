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
            'auth_config' => $this->redactAuthConfig($this->resource->auth_config),
            'slo_target' => $this->resource->slo_target,
            'show_on_status_page' => (bool) $this->resource->show_on_status_page,
            'only_show_if_degraded' => (bool) $this->resource->only_show_if_degraded,
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
     * Strip credential secrets from the auth_config before it leaves the
     * server.
     *
     * The secret-bearing fields (password, bearer/api token, header value)
     * are removed; non-secret descriptors (type, username, header name)
     * survive so the edit form can render the current configuration
     * without ever receiving the secret itself.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function redactAuthConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        foreach (['password', 'token', 'value'] as $secret) {
            unset($config[$secret]);
        }

        return $config;
    }
}
