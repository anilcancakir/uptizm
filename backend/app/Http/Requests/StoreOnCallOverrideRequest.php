<?php

namespace App\Http\Requests;

use App\Models\OnCallSchedule;
use App\Support\IdFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /on-call/schedules/{schedule}/overrides.
 *
 * `user_id` must belong to the routed schedule's team, mirroring
 * {@see StoreOnCallRotationRequest}; `ends_at` must fall after `starts_at`
 * so the override window is never inverted.
 */
class StoreOnCallOverrideRequest extends FormRequest
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
        $schedule = $this->route('schedule');
        $teamId = $schedule instanceof OnCallSchedule ? $schedule->team_id : null;

        return [
            'user_id' => [
                'bail',
                'required',
                ...IdFormat::rules(),
                Rule::exists('team_user', 'user_id')->where('team_id', $teamId),
            ],
            'starts_at' => [
                'required',
                'date',
            ],
            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],
        ];
    }
}
