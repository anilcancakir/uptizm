<?php

namespace App\Http\Requests;

/**
 * Validation rules for PATCH/PUT /on-call/schedules/{schedule}.
 *
 * Mirrors {@see StoreOnCallScheduleRequest} but makes every field optional
 * via `sometimes`, so a partial edit only validates the keys it sends.
 */
class UpdateOnCallScheduleRequest extends StoreOnCallScheduleRequest
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
            'timezone' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
            ],
        ];
    }
}
