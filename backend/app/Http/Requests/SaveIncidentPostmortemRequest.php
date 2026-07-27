<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules for POST /incidents/{incident}/postmortem: storing the
 * postmortem body and, optionally, publishing it to the public status page.
 *
 * `body` is required (a postmortem with no text is nothing to save) and
 * `publish` defaults to false, so a save is an internal draft unless the
 * operator explicitly asks for a publication.
 */
class SaveIncidentPostmortemRequest extends FormRequest
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
            'body' => [
                'required',
                'string',
                'max:20000',
            ],
            'publish' => [
                'boolean',
            ],
        ];
    }
}
