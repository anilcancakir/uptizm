<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Thrown when the incident-analysis model returns structured output that does
 * not conform to the pinned schema, even after the single retry.
 *
 * {@see IncidentAnalysisService} catches this to degrade to the deterministic
 * baseline, so the RCA endpoint always answers with the identical empty-array
 * wire shape rather than a 500 or a hole. Infrastructure failures (a provider
 * outage, an auth error) surface as their own exceptions and are deliberately
 * NOT caught: they are real failures, not a non-conforming answer.
 */
class NonConformingAnalysisException extends RuntimeException {}
