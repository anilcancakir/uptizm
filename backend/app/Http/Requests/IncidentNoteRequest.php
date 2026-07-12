<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\V1\IncidentController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules shared by the three incident lifecycle actions that carry
 * an optional operator note: resolve, acknowledge, and reopen
 * (see {@see IncidentController}). All three
 * accept the same shape (an optional free-text message), so a single request
 * class covers them instead of three near-identical copies.
 */
class IncidentNoteRequest extends FormRequest
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
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
