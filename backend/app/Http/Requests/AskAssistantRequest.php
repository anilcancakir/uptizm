<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules for POST /assistant.
 *
 * Mirrors {@see AnalyzeMonitorRequest}'s team-mandatory authorization: the
 * per-team AI budget is spent against the acting user's current team, so a
 * team is mandatory for every question asked.
 */
class AskAssistantRequest extends FormRequest
{
    /**
     * Only an authenticated user acting on a team may ask the assistant: the
     * per-team AI budget is spent against that team, so a team is mandatory.
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
            'question' => [
                'required',
                'string',
                'max:1000',
            ],
        ];
    }
}
