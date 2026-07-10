<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use Illuminate\Validation\Rule;

/**
 * Validation rules for PATCH/PUT /monitors/{monitor}.
 *
 * Mirrors {@see StoreMonitorRequest} but makes every field optional via
 * `sometimes`, so a partial edit only validates the keys it sends. The
 * SSRF host guard from the parent is reused verbatim for any URL change.
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
            'url' => [
                'sometimes',
                'required',
                'string',
                'url',
                'max:2048',
                $this->noInternalHost(),
            ],
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
            'auth_config' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ];
    }
}
