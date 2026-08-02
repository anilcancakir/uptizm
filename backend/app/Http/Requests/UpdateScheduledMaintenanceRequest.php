<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation rules for PATCH/PUT /scheduled-maintenances/{maintenance}.
 *
 * Mirrors {@see StoreScheduledMaintenanceRequest} but makes every field
 * optional via `sometimes`, so a partial edit only validates the keys it
 * sends. The window bounds are the one exception: they move as a PAIR, because
 * `after:starts_at` has nothing to compare against when only `ends_at` is
 * submitted, and a rule that quietly passes is worse than one that asks for
 * both bounds.
 */
class UpdateScheduledMaintenanceRequest extends StoreScheduledMaintenanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $teamId = $this->user()?->current_team_id;

        return [
            'status_page_id' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('status_pages', 'id')->where('team_id', $teamId),
            ],
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'suppress_alerts' => [
                'sometimes',
                'boolean',
            ],
            'starts_at' => [
                'required_with:ends_at',
                'date',
            ],
            'ends_at' => [
                'required_with:starts_at',
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
