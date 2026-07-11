<?php

namespace App\Enums;

/**
 * Lifecycle of an {@see \App\Models\AiSuggestion} in the operator inbox.
 *
 * Fail-safe default is Pending (set by the migration column default), so a
 * write that omits `status` never silently disappears from the inbox.
 */
enum AiSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
