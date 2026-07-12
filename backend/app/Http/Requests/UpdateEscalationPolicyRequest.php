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
        ];
    }
}
