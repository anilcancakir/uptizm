<?php

namespace App\Enums;

use App\Models\AiSuggestion;

/**
 * The type of anomaly a {@see AiSuggestion} proposes acting on.
 *
 * MVP ships a single detector; more kinds join this enum as later detectors
 * land, without changing the suggestion schema.
 */
enum AiSuggestionKind: string
{
    case ResponseTimeAnomaly = 'response_time_anomaly';
}
