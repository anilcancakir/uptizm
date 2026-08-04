<?php

namespace App\Services\Proxy;

use App\Models\Proxy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers exactly three questions about a region's exit pool and nothing
 * else: is the region usable, which exit to try next, and how an exit's
 * failure history should move its availability.
 *
 * `take()` picks RANDOMLY rather than round-robin, because there is no
 * shared cursor a Horizon worker could coordinate through: workers are
 * long-lived and stateless between jobs, and a random pick needs no
 * coordination to avoid two workers repeatedly colliding on the same exit.
 *
 * THE BOUNDARY THAT MATTERS: {@see self::penalise()} may be called ONLY
 * from the transport-failure path (a proxy that could not carry the
 * request at all). It must NEVER be called in response to an HTTP status
 * the target returned. A 403 is the target's answer, not the exit's
 * failure; penalising the exit for carrying it back would be rotating
 * away from a block, which this product publishes that it does not do
 * (see `resources/legal/bot.en.md`). Later steps that classify a failure
 * as proxy-attributed vs. target-attributed are what keeps this boundary
 * real; this class trusts its caller on that point.
 */
class ProxyPool
{
    /**
     * Whether a region can actually be probed right now: it must be named
     * in `config('proxy.sources')` AND have at least one healthy exit.
     *
     * Both conditions matter independently: a region can be configured with
     * an empty or unset source location (see `config/proxy.php`'s docblock),
     * which declares the region but leaves its pool permanently empty, so
     * config membership alone is never evidence the region is usable.
     */
    public function hasRegion(string $region): bool
    {
        if (! array_key_exists($region, (array) config('proxy.sources', []))) {
            return false;
        }

        return Proxy::query()->healthy()->region($region)->exists();
    }

    /**
     * Pick a random healthy exit for a region, excluding the given ids.
     *
     * The predicate is constrained by region and health BEFORE the random
     * order is applied (`scopeHealthy()`/`scopeRegion()` both hit the
     * `(region, enabled, available_at)` index), so the `ORDER BY RANDOM()`
     * this issues never runs over an unbounded set.
     *
     * @param  array<int, string>  $excludeIds
     */
    public function take(string $region, array $excludeIds = []): ?Proxy
    {
        return Proxy::query()
            ->healthy()
            ->region($region)
            ->when(
                $excludeIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('id', $excludeIds),
            )
            ->inRandomOrder()
            ->first();
    }

    /**
     * Record a transport-failure against an exit and push it out of
     * rotation for a full-jitter exponential backoff window.
     *
     * The delay is drawn from the WHOLE window (`random_int(0, $ceiling)`),
     * not applied as jitter around a fixed midpoint: two workers whose exits
     * die in the same tick must not reanimate in the same tick either, or
     * they collide on the same retry schedule they were meant to avoid.
     * `$attempts` here is the value AFTER the increment, so the first
     * failure draws from `[0, base]` rather than `[0, base / 2]`.
     */
    public function penalise(Proxy $proxy): void
    {
        $attempts = $proxy->failed_attempts + 1;

        $base = (int) config('proxy.health.base_backoff_seconds');
        $max = (int) config('proxy.health.max_backoff_seconds');

        $ceiling = (int) min($base * 2 ** ($attempts - 1), $max);

        $proxy->update([
            'failed_attempts' => $attempts,
            'available_at' => now()->addSeconds(random_int(0, $ceiling)),
        ]);
    }

    /**
     * Record a transport-success against an exit, healing it gradually
     * rather than flipping it straight back to a clean record: a single
     * good probe should not erase a run of prior failures instantly.
     */
    public function reward(Proxy $proxy): void
    {
        $proxy->update([
            'failed_attempts' => max(0, $proxy->failed_attempts - 1),
            'available_at' => null,
        ]);
    }
}
