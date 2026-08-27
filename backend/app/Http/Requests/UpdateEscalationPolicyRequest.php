<?php

namespace App\Http\Requests;

/**
 * Validation rules for PATCH/PUT /escalation-policies/{policy}.
 *
 * Mirrors {@see StoreEscalationPolicyRequest} but makes every field optional
 * via `sometimes`, so a partial edit only validates the keys it sends.
 */
class UpdateEscalationPolicyRequest extends StoreEscalationPolicyRequest
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
            // Omitting either leaves it as it was: this is a PATCH-shaped
            // update, so an editor that only renames a policy must not silently
            // clear its paging flags.
            'repeat_last_step' => [
                'sometimes',
                'boolean',
            ],
            'is_default' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
