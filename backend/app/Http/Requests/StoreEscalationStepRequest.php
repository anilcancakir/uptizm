<?php

namespace App\Http\Requests;

use App\Enums\EscalationTargetType;
use App\Models\EscalationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /escalation-policies/{policy}/steps.
 *
 * `target_type` accepts only the people-only cases (see
 * {@see EscalationTargetType}). `target_id` is required for
 * {@see EscalationTargetType::User} (a user id validated against the routed
 * policy's own team via the `team_user` pivot, mirroring
 * {@see StoreOnCallRotationRequest}'s membership check) and unused for
 * {@see EscalationTargetType::OnCall}. Team-scoping the policy itself is
 * handled by the controller's 404-mask, not here.
 */
class StoreEscalationStepRequest extends FormRequest
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
        $policy = $this->route('policy');
        $teamId = $policy instanceof EscalationPolicy ? $policy->team_id : null;

        return [
            'position' => [
                'required',
                'integer',
                'min:0',
            ],
            'delay_minutes' => [
                'required',
                'integer',
                'min:0',
            ],
            'target_type' => [
                'required',
                Rule::enum(EscalationTargetType::class),
            ],
            'target_id' => [
                'nullable',
                'string',
                'required_if:target_type,user',
                Rule::exists('team_user', 'user_id')->where('team_id', $teamId),
            ],
        ];
    }
}
