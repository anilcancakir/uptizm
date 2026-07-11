<?php

namespace App\Enums;

/**
 * How confident the AI detector is in a proposed {@see \App\Models\AiSuggestion}.
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
