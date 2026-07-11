<?php

namespace App\Enums;

/**
 * The type of anomaly a {@see \App\Models\AiSuggestion} proposes acting on.
 *
 * MVP ships a single detector; more kinds join this enum as later detectors
 * land, without changing the suggestion schema.
 */
enum AiSuggestionKind: string
{
    case ResponseTimeAnomaly = 'response_time_anomaly';
}
