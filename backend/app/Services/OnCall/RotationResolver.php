<?php

namespace App\Services\OnCall;

use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resolves "who is on call right now" for an {@see OnCallSchedule}: an active
 * {@see OnCallOverride} wins outright, otherwise the ordered rotation ring is
 * walked to find the responder holding the current shift.
 *
 * The math runs on absolute instants (Unix timestamps), never on server-local
 * wall-clock helpers, so it is timezone-safe: the schedule's `created_at`
 * anchors the ring and each responder's `shift_hours` accumulates into the
 * cycle. Override windows are compared inclusively in PHP against the resolved
 * moment, keeping the result identical across the sqlite test database and the
 * production `timestamptz` columns.
 */
class RotationResolver
{
    /**
     * Resolve the responder on call for the schedule at the given instant
     * (defaulting to now), honoring an active override before the ring.
     *
     * @param  OnCallSchedule  $schedule  the schedule to resolve (its `rotations`
     *                                    and `overrides` are read from the relation)
     * @param  CarbonInterface|null  $now  the instant to resolve for; defaults to the current time
     * @return User|null the on-call responder, or null when the ring is empty and no override covers `$now`
     */
    public function resolve(OnCallSchedule $schedule, ?CarbonInterface $now = null): ?User
    {
        $moment = $now ?? Carbon::now();

        // 1. An override that covers this instant supersedes the ring.
        $override = $this->activeOverride($schedule, $moment);
        if ($override !== null) {
            return $override->user;
        }

        // 2. No ring means nobody is on call: return null rather than throw.
        $rotations = $schedule->rotations()->get();
        if ($rotations->isEmpty()) {
            return null;
        }

        // 3. Walk the ring to the responder holding the current shift.
        return $this->ringResponder($schedule, $rotations, $moment);
    }

    /**
     * The override covering `$moment`, or null. When several overlap, the one
     * whose window started most recently wins (the most recent decision), with
     * `created_at` then `id` breaking exact ties for determinism.
     */
    private function activeOverride(OnCallSchedule $schedule, CarbonInterface $moment): ?OnCallOverride
    {
        return $schedule->overrides()->get()
            ->filter(fn (OnCallOverride $override): bool => $moment->betweenIncluded(
                $override->starts_at,
                $override->ends_at,
            ))
            ->sortByDesc(fn (OnCallOverride $override): array => [
                $override->starts_at->getTimestamp(),
                $override->created_at?->getTimestamp() ?? 0,
                (string) $override->getKey(),
            ])
            ->first();
    }

    /**
     * Walk the ordered ring and return the responder whose `shift_hours` slot
     * contains the elapsed offset since the schedule anchor.
     *
     * @param  Collection<int, OnCallRotation>  $rotations  the ring, ordered by `position`
     */
    private function ringResponder(OnCallSchedule $schedule, Collection $rotations, CarbonInterface $moment): User
    {
        $cycleHours = (float) $rotations->sum('shift_hours');

        // Degenerate ring (all shifts zero): the first responder holds indefinitely.
        if ($cycleHours <= 0.0) {
            return $rotations->first()->user;
        }

        // Elapsed hours since the anchor, on absolute instants; before the
        // anchor the first responder holds, so the offset is clamped to zero.
        $anchor = Carbon::instance($schedule->created_at);
        $elapsedHours = max(0.0, ($moment->getTimestamp() - $anchor->getTimestamp()) / 3600.0);
        $offset = fmod($elapsedHours, $cycleHours);

        $boundary = 0.0;
        foreach ($rotations as $rotation) {
            $boundary += (float) $rotation->shift_hours;
            if ($offset < $boundary) {
                return $rotation->user;
            }
        }

        // Float rounding at the cycle edge: fall back to the last slot.
        return $rotations->last()->user;
    }
}
