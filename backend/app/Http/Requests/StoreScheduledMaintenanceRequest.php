<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /scheduled-maintenances: planning a maintenance
 * window.
 *
 * `status_page_id` and every `monitor_ids` entry are scoped to the acting
 * user's current team, so an operator can never announce a window on another
 * team's page or name another team's monitor as an affected component (the
 * exists rules double as the team-ownership check the controller would
 * otherwise need, mirroring {@see StoreIncidentRequest}).
 *
 * Two columns are deliberately absent from the rule set and therefore from
 * `validated()`: `announced_at` is the announce-once guard the subscriber mail
 * job owns, and `team_id` is taken from the authenticated user rather than the
 * payload. `suppress_alerts` IS accepted, but only through the explicit
 * boolean rule below.
 */
class StoreScheduledMaintenanceRequest extends FormRequest
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
        $teamId = $this->user()?->current_team_id;

        return [
            'status_page_id' => [
                'required',
                'string',
                Rule::exists('status_pages', 'id')->where('team_id', $teamId),
            ],
            'title' => [
                'required',
                'string',
                'max:200',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'suppress_alerts' => [
                'sometimes',
                'boolean',
            ],
            'starts_at' => [
                'required',
                'date',
            ],
            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],
            'monitor_ids' => [
                'sometimes',
                'array',
            ],
            'monitor_ids.*' => [
                'string',
                Rule::exists('monitors', 'id')->where('team_id', $teamId),
            ],
        ];
    }
}
