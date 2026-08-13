<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an operator's rating of a stored analysis.
 *
 * `analysis_id` is carried by the client rather than resolved server-side from
 * the incident, and that is the point: the operator rated the text they were
 * looking at, and the current analysis for an incident can move between the
 * paint and the click when a check lands in between. The controller still
 * checks the id belongs to this incident and this team, so naming it here buys
 * precision, not trust.
 *
 * Existence is deliberately NOT checked with `exists:` here. The rule would run
 * before the controller's team scope and would answer "no such analysis" for one
 * that belongs to another team, which is the difference between a 404 mask and a
 * confirmation that the row exists.
 */
class StoreAnalysisFeedbackRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'analysis_id' => ['required', 'string'],
            'helpful' => ['required', 'boolean'],
        ];
    }
}
