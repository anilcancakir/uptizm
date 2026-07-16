<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use Illuminate\Validation\Rule;

/**
 * Validation rules for PATCH/PUT /monitors/{monitor}.
 *
 * Mirrors {@see StoreMonitorRequest} but makes every top-level field
 * optional via `sometimes`, so a partial edit only validates the keys it
 * sends. The SSRF host guard and the `auth_config` inner-shape rules from
 * the parent are reused verbatim; the auth_config credential rules stay
 * conditional (`required_if`/`required_with`) rather than `sometimes` so a
 * partial edit that does send credentials is still shape-checked.
 */
class UpdateMonitorRequest extends StoreMonitorRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],
            'url' => $this->targetRules(partial: true),
            'type' => [
                'sometimes',
                'required',
                Rule::enum(MonitorType::class),
            ],
            'method' => [
                'sometimes',
                'required',
                Rule::enum(HttpMethod::class),
            ],
            'check_interval_sec' => [
                'sometimes',
                'required',
                'integer',
                'min:30',
                'max:86400',
            ],
            'timeout_sec' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:120',
            ],
            'regions' => [
                'sometimes',
                'required',
                'array',
                'min:1',
            ],
            'regions.*' => [
                Rule::enum(MonitorRegion::class),
            ],
            'expected_status_code' => [
                'sometimes',
                'nullable',
                'integer',
                'min:100',
                'max:599',
            ],
            'request_headers' => [
                'sometimes',
                'nullable',
                'array',
            ],
            'request_body' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'slo_target' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'tags' => [
                'sometimes',
                'nullable',
                'array',
            ],
            'show_on_status_page' => [
                'sometimes',
                'boolean',
            ],
            'only_show_if_degraded' => [
                'sometimes',
                'boolean',
            ],
            'alert_on_down' => [
                'sometimes',
                'boolean',
            ],
            'alert_on_recover' => [
                'sometimes',
                'boolean',
            ],
            'ssl_tracking' => [
                'sometimes',
                'boolean',
            ],
            'ssl_alert_threshold_days' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:365',
            ],
            ...$this->authConfigRules(partial: true),
        ];
    }
}
