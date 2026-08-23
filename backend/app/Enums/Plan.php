<?php

namespace App\Enums;

use App\Models\Team;

/**
 * The billing tier a {@see Team} is entitled to.
 *
 * The `teams.plan` column is the single source of truth, and never Cashier's
 * `subscribed()` check.
 *
 * It is read two ways, deliberately, and the difference matters. Every DISPLAY
 * and GUARD reader goes through {@see Team::entitledPlan()}, which collapses a
 * NULL column to {@see self::Free} because those callers want a tier to render
 * or to compare and there is no honest third answer for them. ARBITRATION reads
 * the RAW attribute instead, in `WriteTeamEntitlement`, named in prose rather
 * than through a `{@see}`: importing an Action into an Enum inverts the layering
 * for a docblock's sake, and nothing here would catch the reference going stale,
 * since this backend ships no PHPStan and pint counts a docblock mention as
 * usage. Arbitration needs the raw column
 * because for it a NULL means "this team holds nothing" and collapsing
 * that to `Free` would make a first grant look like a same-tier write. A
 * revocation therefore writes NULL rather than naming this enum's cheapest case:
 * naming a tier in order to say "owed nothing" was a proxy, and it was the proxy
 * that would have had to become a config key when this code is packaged.
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
