<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Per-team daily spend guard for AI triage, enforced at the spend point.
 *
 * A single atomic counter per team per day caps how many triage invocations a
 * team may spend, so a runaway sweep or a hostile burst cannot rack up unbounded
 * provider cost. The counter is incremented ATOMICALLY (Redis `INCR`, or the
 * configured store's atomic increment) so two workers racing the same last unit
 * of budget can never both win: exactly one crosses the cap.
 *
 * The budget is a proposal cap, never an alert gate: when it is exhausted the
 * caller DEGRADES to a deterministic statistical suggestion, it does not drop
 * the anomaly. This service only answers "may I spend one unit right now?".
 */
class AiBudget
{
    /**
     * Cache key prefix for the per-team, per-day counter.
     */
    private const KEY_PREFIX = 'ai-budget';

    /**
     * Try to spend one unit of the team's daily triage budget.
     *
     * Atomic across concurrent workers: the first-write seeds the counter with a
     * TTL that expires it at end-of-day, then every caller does an atomic
     * increment and compares the post-increment count against the configured cap.
     *
     * @param  string  $teamId  The team whose daily budget is being spent.
     * @return bool True when the spend is within cap, false when over budget.
     */
    public function tryConsume(string $teamId): bool
    {
        $key = $this->keyFor($teamId);

        // 1. Seed the counter to zero with an end-of-day TTL only on first write.
        //    `add` is atomic (SETNX): a concurrent seed cannot reset a live
        //    counter or its expiry, so the window stays exactly one day.
        Cache::add($key, 0, $this->secondsUntilEndOfDay());

        // 2. Atomic increment is the single source of truth for who spent what;
        //    the returned post-increment count decides the cap, not a prior read.
        $count = (int) Cache::increment($key);

        return $count <= $this->cap();
    }

    /**
     * The configured daily invocation cap per team.
     */
    private function cap(): int
    {
        return (int) config('ai.budget.daily_per_team', 100);
    }

    /**
     * The per-team, per-day counter key, e.g. `ai-budget:{team}:2026-07-11`.
     */
    private function keyFor(string $teamId): string
    {
        return sprintf('%s:%s:%s', self::KEY_PREFIX, $teamId, now()->format('Y-m-d'));
    }

    /**
     * Seconds remaining until midnight, so the counter resets with the calendar
     * day. Floored at one second so a same-instant expiry cannot drop the key
     * before the increment lands.
     */
    private function secondsUntilEndOfDay(): int
    {
        return max(1, (int) round(now()->diffInSeconds(now()->endOfDay(), true)));
    }
}
