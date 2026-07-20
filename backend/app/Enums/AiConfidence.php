<?php

namespace App\Enums;

use App\Models\AiSuggestion;

/**
 * How confident the AI detector is in a proposed {@see AiSuggestion}.
 *
 * Drives inbox ordering and badge styling; it does not gate whether a
 * suggestion is written, only how strongly it is presented to the operator.
 */
enum AiConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
