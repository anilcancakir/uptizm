<?php

namespace App\Enums;

use App\Models\EscalationStep;

/**
 * Who an {@see EscalationStep} pages when it fires. Escalation is people-only:
 * integration channels (Slack/webhook) self-fire on incidents rather than
 * being paged through the escalation ladder.
 *
 * - `OnCall`: the currently on-call responder for the team (resolved at
 *   dispatch time; no direct target reference).
 * - `User`: a specific user, addressed by {@see EscalationStep::$target_id}.
 */
enum EscalationTargetType: string
{
    case OnCall = 'on_call';
    case User = 'user';
}
