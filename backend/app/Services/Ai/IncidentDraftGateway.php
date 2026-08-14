<?php

namespace App\Services\Ai;

/**
 * The single seam between the incident surface and the drafting LLM.
 *
 * The third gateway of this shape, after {@see IncidentAnalysisGateway} and
 * {@see AnalysisGateway}, and the first whose output is WRITING rather than a
 * judgement. That difference is the whole contract: the model is not asked what
 * broke here, it is handed an analysis that already answered that and asked to
 * put it in words for a particular reader.
 *
 * It produces a DRAFT and never a post. Nothing it returns reaches a customer
 * without the operator reading it, editing it, and pressing publish, which is
 * what makes it acceptable for a model to write in the operator's voice at all.
 */
interface IncidentDraftGateway
{
    /**
     * Draft the piece of writing named by the payload's kind.
     */
    public function draft(IncidentDraftPayload $payload): IncidentDraftResult;
}
