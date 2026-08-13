<?php

namespace App\Services\Ai;

use App\Models\AiIncidentAnalysis;

/**
 * What the analysis endpoint got back: the wire shape to render, and the stored
 * row it came from when there is one.
 *
 * The pair exists because the two facts have different lifetimes and the caller
 * needs both. `result` always answers; `stored` is null on every degrade path,
 * because a deterministic baseline is the absence of a model answer and is
 * never written to `ai_incident_analyses`.
 *
 * Returning them together is what keeps the endpoint at ONE model call. The
 * first shape of this returned only the nullable row, and the controller had to
 * re-run the analysis to get something to render on the null branch: a second
 * budget unit spent, or a second failure, to recompute an answer the service
 * already had in hand.
 */
readonly class ResolvedAnalysis
{
    /**
     * @param  array<string, mixed>  $result  The wire shape, from the store or from a fresh run.
     * @param  AiIncidentAnalysis|null  $stored  The row it was read from or written to, null on a degrade.
     */
    public function __construct(
        public array $result,
        public ?AiIncidentAnalysis $stored,
    ) {}
}
