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
            // Both emitted so the edit form can prefill what the operator
            // configured. Without them the form fell back to its create-time
            // defaults and a plain rename silently rewrote the monitor.
            'request_body' => $this->resource->request_body,
            'ai_mode' => $this->resource->ai_mode,
            // Read back by the monitor form's own switch. Without it the field
            // is absent from every response, the client reads absent as false,
            // and an operator who turned it on found it off the next time they
            // opened the form: the write landed and the read denied it.
            'ai_auto_updates' => (bool) $this->resource->ai_auto_updates,
            'auth_config' => $this->redactAuthConfig($this->resource->auth_config),
            'slo_target' => $this->resource->slo_target,
            // The pinned paging ladder, or null to fall back to the team default.
            // Emitted so the edit form can render the monitor's real selection
            // instead of resetting it to a default on every save.
            'escalation_policy_id' => $this->resource->escalation_policy_id === null
                ? null
                : (string) $this->resource->escalation_policy_id,
            // Measured uptime over the trailing 24h, attached by the show
            // action only (null in list/edit responses and while a monitor
            // has no checks yet, which the client renders as "no data"). The
            // 7d/30d fields below are a different measurement (real minutes,
            // not a ratio) and are 0.0 rather than missing when nothing has
            // been measured, so the client can tell "nothing measured" from
            // "measured and fine". `window_minutes` is deliberately absent:
            // it is the constant 10080 / 43200 the client already owns.
            'uptime_24h' => $this->resource->uptime_24h,
            'slo_down_minutes_7d' => $this->resource->slo_down_minutes_7d,
            'slo_observed_minutes_7d' => $this->resource->slo_observed_minutes_7d,
            'slo_gap_minutes_7d' => $this->resource->slo_gap_minutes_7d,
            'slo_measured_minutes_7d' => $this->resource->slo_measured_minutes_7d,
            'slo_down_minutes_30d' => $this->resource->slo_down_minutes_30d,
            'slo_observed_minutes_30d' => $this->resource->slo_observed_minutes_30d,
            'slo_gap_minutes_30d' => $this->resource->slo_gap_minutes_30d,
            'slo_measured_minutes_30d' => $this->resource->slo_measured_minutes_30d,
            'tags' => $this->resource->tags ?? [],
            'show_on_status_page' => (bool) $this->resource->show_on_status_page,
            'only_show_if_degraded' => (bool) $this->resource->only_show_if_degraded,
            'follow_redirects' => (bool) $this->resource->follow_redirects,
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
            // Set when the EDGE refused to run a probe, which is a configuration
            // problem on this monitor rather than an outage of its target. It is
            // deliberately separate from `last_status`: a refused probe produces no
            // health verdict at all, so the status stays whatever the last real
            // probe said. Cleared by the next probe that reaches the target.
            'last_probe_error' => $this->resource->last_probe_error,
            'last_probe_error_at' => $this->resource->last_probe_error_at?->toIso8601String(),
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
