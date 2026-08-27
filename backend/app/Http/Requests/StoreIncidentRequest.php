<?php

namespace App\Http\Requests;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Support\IdFormat;
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
                'bail',
                'required',
                ...IdFormat::rules(),
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
            // Whether to announce the open to the affected pages' confirmed
            // subscribers. Optional, and TRUE when omitted, because that is
            // what the form has always shown and a client that predates the
            // field should behave as its own UI promised.
            'notify' => [
                'sometimes',
                'boolean',
            ],
            // The customer-facing impact. Optional: omitted keeps the
            // projection from severity, which is what every automated open
            // uses. Only a human is in a position to say the two differ.
            'impact' => [
                'sometimes',
                // Nullable, not just optional: a client that always sends the
                // key and uses null for "no override" is as valid as one that
                // omits it, and `sometimes` alone only skips an ABSENT key.
                'nullable',
                Rule::enum(IncidentImpact::class),
            ],
        ];
    }
}
