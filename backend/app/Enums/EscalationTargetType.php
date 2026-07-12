<?php

namespace App\Enums;

use App\Models\EscalationStep;

/**
 * Who an {@see EscalationStep} pages when it fires.
 *
 * - `OnCall`: the currently on-call responder for the team (resolved at
 *   dispatch time; no direct target reference).
 * - `User`: a specific user, addressed by {@see EscalationStep::$target_id}.
 * - `Channel`: an out-of-band channel (e.g. a Slack webhook name), addressed
 *   by {@see EscalationStep::$channel}.
 */
enum EscalationTargetType: string
{
    case OnCall = 'on_call';
    case User = 'user';
    case Channel = 'channel';
}
