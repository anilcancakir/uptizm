<?php

namespace App\Support\Proxy;

/**
 * The proxy regions a deployment can actually probe from.
 *
 * One function because four places need the same answer and MUST agree: the
 * catalog seeder's precondition, the region set it stamps on every catalog
 * monitor, the `/bot` page's published region count and daily-request figure,
 * and the migration that backfills existing monitors. They used to carry four
 * copies of the same `array_filter`, with three docblocks asserting the copies
 * were identical "character for character", which is exactly the claim a single
 * function makes true instead of merely promising.
 *
 * Two properties of the answer are load-bearing.
 *
 * It filters on `location` rather than on key membership, because
 * `config/proxy.php` DECLARES every region statically and only the env-driven
 * `location` says whether this deployment sourced one; that config's own
 * docblock calls an empty location "DECLARED BUT UNUSABLE", and
 * `ProxyPool::hasRegion()` treats key membership as
 * necessary but not sufficient for the same reason. Counting keys would keep
 * claiming a region an operator had blanked out to disable.
 *
 * It reads CONFIG and never the database, because `ShowBotController`
 * is served with no connection available and would 500 on a query. Config is the
 * one signal all four callers can read identically, which is what stops the
 * public page and the seeded monitors from publishing two different counts.
 */
final class ProxyRegions
{
    /**
     * Every region key under `config('proxy.sources')` that carries a source.
     *
     * @return list<string>
     */
    public static function sourced(): array
    {
        return array_values(array_keys(array_filter(
            (array) config('proxy.sources', []),
            static fn (array $source): bool => filled($source['location'] ?? null),
        )));
    }

    /**
     * How many regions a catalog monitor can actually be probed from.
     */
    public static function sourcedCount(): int
    {
        return count(self::sourced());
    }
}
