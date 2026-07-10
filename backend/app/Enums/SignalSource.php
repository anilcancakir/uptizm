<?php

namespace App\Enums;

/**
 * Origin of the signal that opened an incident.
 *
 * Uptizm unifies three detection paths into one incident pipe so the UI
 * and audit trail can describe "who noticed first":
 *   - UserThreshold: a user-defined metric band was crossed.
 *   - AiAnomaly:     an AI detector fired (gated by AiMode).
 *   - Manual:        a human filed the incident directly.
 */
enum SignalSource: string
{
    case UserThreshold = 'user_threshold';
    case AiAnomaly = 'ai_anomaly';
    case Manual = 'manual';
}
