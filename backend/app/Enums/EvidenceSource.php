<?php

namespace App\Enums;

/**
 * Which evidence zone a single AI-analysis evidence row draws on.
 *
 * The honest-AI-boundary constrains the analysis model to cite ONLY one of
 * these three owned zones for every piece of evidence: the incident's own
 * timeline, a recorded check, or the affected monitor. It is never free text,
 * so the client can render the source label without ever surfacing a
 * fabricated or invented origin. A model response carrying any other source is
 * dropped before it reaches the wire.
 */
enum EvidenceSource: string
{
    case Timeline = 'timeline';
    case Check = 'check';
    case Monitor = 'monitor';
}
