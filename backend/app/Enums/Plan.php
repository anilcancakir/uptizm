<?php

namespace App\Enums;

use App\Models\Team;

/**
 * The billing tier a {@see Team} is entitled to.
 *
 * The `teams.plan` column is the single source of truth; it is read
 * exclusively through {@see Team::entitledPlan()}, never
 * through Cashier's `subscribed()` check.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';

    /**
     * Resolve a plan from a raw column value, defaulting to Free when the
     * value is null or does not match a known case.
     */
    public static function fromColumnValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Free;
    }
}
