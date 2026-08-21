<?php

namespace App\Services\OnCall;

use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Resolves "who is on call right now" for an {@see OnCallSchedule}: an active
 * {@see OnCallOverride} wins outright, otherwise the ordered rotation ring is
 * walked to find the responder holding the current shift.
 *
 * Shift boundaries land on the schedule's OWN wall clock: an 8-hour ring in
 * `Europe/Istanbul` hands over at 00:00, 08:00 and 16:00 there, every day of the
 * year. That is what the `timezone` column is for, and until this it was stored,
 * editable, rendered and read by nothing: the ring was anchored on the raw
 * `created_at` instant, so a rota handed the pager over at whatever minute the
 * schedule happened to be created.
 *
 * The position in the cycle is therefore a wall-clock quantity, built from whole
 * LOCAL CALENDAR DAYS since the anchor day plus the local time of day, never from
 * a running count of absolute hours. Two consequences, and both are the reason it
 * is spelled that way:
 *
 * - A DST transition cannot drift a handover. Counting absolute hours would put
 *   an anchored-in-winter rota's midnight handover at 01:00 all summer.
 * - A transition cannot fire one twice or skip one either. A spring-forward day
 *   has no 02:00, so no instant maps to a 02:00 boundary and the ring simply
 *   moves on; a fall-back day has two 01:30s and both map to the SAME slot, which
 *   is the safe direction for a pager.
 *
 * Everything else still runs on absolute instants. Override windows are compared
 * inclusively in PHP against the resolved moment, keeping the result identical
 * across the sqlite test database and the production `timestamptz` columns.
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
        return $this->resolveRotation($schedule, $now)?->user
            ?? $this->activeOverride($schedule, $now ?? Carbon::now())?->user;
    }

    /**
     * The rotation SLOT holding the pager, or null when an override covers the
     * moment, the ring is empty, or the moment predates the schedule.
     *
     * Callers that only need the person use {@see self::resolve()}. The slot
     * exists because the person is not enough to mark a ring: one responder can
     * legitimately occupy two slots (a weekday one and a weekend one), and the
     * on-call surface matched the badge on the USER, so BOTH of that person's rows
     * claimed to be the current shift, with two different shift lengths. Only the
     * resolver knows which slot the clock is in, so only the resolver can say.
     *
     * Null under an active override is deliberate rather than a gap: the ring is
     * not holding the pager then, and marking a row would say it was.
     *
     * @param  CarbonInterface|null  $now  the instant to resolve for; defaults to now
     */
    public function resolveRotation(OnCallSchedule $schedule, ?CarbonInterface $now = null): ?OnCallRotation
    {
        $moment = $now ?? Carbon::now();

        // 1. An override that covers this instant supersedes the ring, and takes
        //    the ring out of the answer entirely.
        if ($this->activeOverride($schedule, $moment) !== null) {
            return null;
        }

        // 2. No ring means nobody is on call: return null rather than throw.
        $rotations = $schedule->rotations()->get();
        if ($rotations->isEmpty()) {
            return null;
        }

        // 3. Walk the ring to the slot holding the current shift.
        return $this->ringSlot($schedule, $rotations, $moment);
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
     * Walk the ordered ring and return the SLOT whose `shift_hours` window
     * contains the elapsed offset since the schedule anchor.
     *
     * @param  Collection<int, OnCallRotation>  $rotations  the ring, ordered by `position`
     */
    private function ringSlot(OnCallSchedule $schedule, Collection $rotations, CarbonInterface $moment): OnCallRotation
    {
        $cycleHours = (float) $rotations->sum('shift_hours');

        // Degenerate ring (all shifts zero): the first responder holds indefinitely.
        if ($cycleHours <= 0.0) {
            return $rotations->first();
        }

        // Elapsed WALL-CLOCK hours since the anchor day, in the schedule's own
        // zone; before the anchor day the first responder holds, so the offset is
        // clamped to zero.
        $elapsedHours = $this->elapsedWallClockHours($schedule, $moment);
        $offset = fmod($elapsedHours, $cycleHours);

        $boundary = 0.0;
        foreach ($rotations as $rotation) {
            $boundary += (float) $rotation->shift_hours;
            if ($offset < $boundary) {
                return $rotation;
            }
        }

        // Float rounding at the cycle edge: fall back to the last slot.
        return $rotations->last();
    }

    /**
     * Hours from the schedule's anchor DAY (local midnight, in the schedule's own
     * timezone) to [$moment], counted on the wall clock.
     *
     * Whole local calendar days times 24, plus the local time of day. The day
     * count is taken by lifting each LOCAL CALENDAR DATE into UTC midnight and
     * subtracting, which is an exact multiple of 86400 and therefore immune to the
     * 23- and 25-hour days a DST zone produces. Subtracting the two local
     * start-of-days directly would divide 82800 seconds by 86400 and lose a day.
     *
     * A moment before the anchor day answers 0.0, so the first responder holds
     * from the schedule's creation backwards; the ring has nothing to say about
     * time before it existed.
     */
    private function elapsedWallClockHours(OnCallSchedule $schedule, CarbonInterface $moment): float
    {
        $zone = $this->safeZone($schedule);

        $local = Carbon::instance($moment)->setTimezone($zone);
        $anchorLocal = Carbon::instance($schedule->created_at)->setTimezone($zone);

        $anchorDate = Carbon::createFromFormat('Y-m-d H:i:s', $anchorLocal->format('Y-m-d').' 00:00:00', 'UTC');
        $momentDate = Carbon::createFromFormat('Y-m-d H:i:s', $local->format('Y-m-d').' 00:00:00', 'UTC');

        $days = intdiv($momentDate->getTimestamp() - $anchorDate->getTimestamp(), 86400);

        if ($days < 0) {
            return 0.0;
        }

        $hoursIntoDay = $local->hour + ($local->minute / 60.0) + ($local->second / 3600.0);

        return ($days * 24.0) + $hoursIntoDay;
    }

    /**
     * The schedule's timezone, or UTC when it is absent or not a real zone.
     *
     * `Carbon::setTimezone()` throws `InvalidTimeZoneException` on an unknown
     * identifier, and the caller here is the path that decides who to page, so a
     * bad row must degrade rather than take paging down with it. The store and
     * update requests now validate the column, but rows written before that rule
     * existed are already in the database, and validation is not a claim about
     * history.
     *
     * Silent on purpose: this runs inside an escalation step, and an operator who
     * mistyped a zone months ago needs the page more than they need the log line.
     */
    private function safeZone(OnCallSchedule $schedule): string
    {
        $zone = (string) ($schedule->timezone ?? '');

        if ($zone === '') {
            return 'UTC';
        }

        return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }
}
