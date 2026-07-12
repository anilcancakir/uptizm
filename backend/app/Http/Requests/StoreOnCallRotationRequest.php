<?php

namespace App\Http\Requests;

use App\Models\OnCallSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /on-call/schedules/{schedule}/rotations.
 *
 * `user_id` must belong to the routed schedule's team (the `team_user`
 * pivot), mirroring {@see StatusPageController::attachMonitor()}'s
 * membership check; team-scoping the schedule itself is handled by the
 * controller's 404-mask, not here. `position` is unique per schedule to
 * match the `(schedule_id, position)` database constraint.
 */
class StoreOnCallRotationRequest extends FormRequest
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
        $scheduleId = $schedule instanceof OnCallSchedule ? $schedule->id : $schedule;
        $teamId = $schedule instanceof OnCallSchedule ? $schedule->team_id : null;

        return [
            'user_id' => [
                'required',
                'string',
                Rule::exists('team_user', 'user_id')->where('team_id', $teamId),
            ],
            'position' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('on_call_rotations', 'position')->where('schedule_id', $scheduleId),
            ],
            'shift_hours' => [
                'nullable',
                'integer',
                'min:1',
                'max:8760',
            ],
        ];
    }
}
