<?php

namespace App\Http\Requests;

use App\Enums\IncidentSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /incidents: opening a manual incident.
 *
 * `monitor_id` is scoped to the acting user's current team so an operator can
 * never open an incident against another team's monitor (the exists rule
 * doubles as the team-ownership check the controller would otherwise need).
 */
class StoreIncidentRequest extends FormRequest
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
            'monitor_id' => [
                'required',
                'string',
                Rule::exists('monitors', 'id')->where('team_id', $this->user()?->current_team_id),
            ],
            'severity' => [
                'required',
                Rule::enum(IncidentSeverity::class),
            ],
            'title' => [
                'required',
                'string',
                'max:200',
            ],
            'message' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
