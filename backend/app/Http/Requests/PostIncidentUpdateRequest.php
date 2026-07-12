<?php

namespace App\Http\Requests;

use App\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /incidents/{incident}/updates: appending a
 * timeline note without changing the incident's lifecycle (unless an explicit
 * `status` override is given). Unlike the lifecycle actions, `message` is
 * required here: a bare timeline entry with no text carries no information.
 */
class PostIncidentUpdateRequest extends FormRequest
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
        return [
            'message' => [
                'required',
                'string',
                'max:2000',
            ],
            'is_public' => [
                'boolean',
            ],
            'status' => [
                'nullable',
                Rule::enum(IncidentStatus::class),
            ],
        ];
    }
}
