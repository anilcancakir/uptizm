<?php

namespace App\Http\Requests;

use App\Enums\EscalationTargetType;
use App\Models\EscalationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /escalation-policies/{policy}/steps.
 *
 * `target_id` and `channel` are conditionally required depending on
 * `target_type` (see {@see EscalationTargetType}): a user id (validated
 * against the routed policy's own team via the `team_user` pivot, mirroring
 * {@see StoreOnCallRotationRequest}'s membership check) for
 * {@see EscalationTargetType::User}, a channel name for
 * {@see EscalationTargetType::Channel}, and neither for
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
            'channel' => [
                'nullable',
                'string',
                'max:255',
                'required_if:target_type,channel',
            ],
        ];
    }
}
