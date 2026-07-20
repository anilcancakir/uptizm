<?php

namespace App\Http\Requests;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the POST /monitors/{monitor}/metrics endpoint.
 *
 * Validates a new metric definition nested under a monitor. The `key`
 * is unique per monitor so metric values remain joinable across time;
 * team-scoping the monitor itself is handled by the controller's 404-mask
 * (see MonitorMetricController::authorizeMonitor()), not here.
 */
class StoreMonitorMetricRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $monitor = $this->route('monitor');
        $monitorId = $monitor instanceof Monitor ? $monitor->id : $monitor;

        return [
            'group_name' => [
                'nullable',
                'string',
                'max:80',
            ],
            'label' => [
                'required',
                'string',
                'max:120',
            ],
            'key' => [
                'required',
                'string',
                'max:40',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('monitor_metrics', 'key')->where('monitor_id', $monitorId),
            ],
            'type' => [
                'required',
                Rule::enum(MetricType::class),
            ],
            'source' => [
                'nullable',
                Rule::enum(MetricSource::class),
            ],
            'extraction_path' => [
                'nullable',
                'string',
                'max:500',
            ],
            'unit' => [
                'nullable',
                Rule::enum(MetricUnit::class),
            ],
            'threshold_direction' => [
                'nullable',
                Rule::enum(ThresholdDirection::class),
            ],
            'warn_bound' => [
                'nullable',
                'numeric',
            ],
            'critical_bound' => [
                'nullable',
                'numeric',
            ],
            'display_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ];
    }
}
