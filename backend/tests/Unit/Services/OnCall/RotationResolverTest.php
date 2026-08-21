<?php

namespace Tests\Unit\Services\OnCall;

use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use App\Services\OnCall\RotationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks {@see RotationResolver::resolve()}: who is on call for a schedule at a
 * given instant. Covers the rotation ring boundaries (the responder must flip
 * exactly at a shift edge), the per-responder shift-length cycle, override
 * precedence (an active override supersedes the ring, latest-starting override
 * wins when several overlap), and the empty-rotation null.
 *
 * The anchor for the ring math is the schedule's `created_at`; tests pin it to
 * a fixed UTC instant so the shift boundaries are exact and timezone-safe.
 */
class RotationResolverTest extends TestCase
{
    use RefreshDatabase;

    private const ANCHOR = '2026-01-01 00:00:00';

    public function test_resolves_first_responder_at_the_anchor_instant(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $responders = $this->responders($schedule);

        $onCall = (new RotationResolver)->resolve($schedule, $this->anchor());

        $this->assertNotNull($onCall);
        $this->assertTrue($onCall->is($responders[0]));
    }

    public function test_responder_flips_exactly_at_the_shift_boundary(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $responders = $this->responders($schedule);
        $resolver = new RotationResolver;

        // Just before the 24h edge: still the first responder.
        $this->assertTrue(
            $resolver->resolve($schedule, $this->anchor()->addHours(24)->subSecond())->is($responders[0]),
        );

        // Exactly at the 24h edge: flips to the second responder.
        $this->assertTrue(
            $resolver->resolve($schedule, $this->anchor()->addHours(24))->is($responders[1]),
        );
    }

    /**
     * The whole point of the `timezone` column: shift boundaries land on the
     * schedule's OWN wall clock, so an 8-hour ring hands over at 00:00, 08:00 and
     * 16:00 there rather than at whatever minute the schedule was created.
     */
    public function test_an_eight_hour_ring_hands_over_on_local_clock_hours(): void
    {
        $schedule = $this->makeRing([8, 8, 8]);
        // Created mid-afternoon on purpose: under the old rule this minute WAS the
        // handover, and every boundary for the rest of the rota's life inherited
        // it.
        $schedule->forceFill([
            'created_at' => CarbonImmutable::parse('2026-06-01 15:37:12', 'UTC'),
            'timezone' => 'Europe/Istanbul',
        ])->save();
        $schedule = $schedule->fresh();

        $responders = $this->responders($schedule);
        $resolver = new RotationResolver;

        $at = fn (string $local): CarbonImmutable => CarbonImmutable::parse($local, 'Europe/Istanbul');

        // The anchor day's own slots, read in local time.
        $this->assertTrue($resolver->resolve($schedule, $at('2026-06-01 00:00:00'))->is($responders[0]));
        $this->assertTrue($resolver->resolve($schedule, $at('2026-06-01 07:59:59'))->is($responders[0]));
        $this->assertTrue($resolver->resolve($schedule, $at('2026-06-01 08:00:00'))->is($responders[1]));
        $this->assertTrue($resolver->resolve($schedule, $at('2026-06-01 16:00:00'))->is($responders[2]));

        // And the next day starts the cycle again on the same clock hours, since
        // 24 divides the 24-hour cycle.
        $this->assertTrue($resolver->resolve($schedule, $at('2026-06-02 00:00:00'))->is($responders[0]));
        $this->assertTrue($resolver->resolve($schedule, $at('2026-06-02 08:00:00'))->is($responders[1]));
    }

    /**
     * A DST transition does not drag the handover off the local clock.
     *
     * Counting absolute hours would: a rota anchored before America/New_York
     * springs forward would hand over at 00:00 local until the transition and at
     * 01:00 local for the rest of the summer. The boundary asserted below sits
     * AFTER the transition and 47 absolute hours after the anchor day, so it is
     * only a boundary under wall-clock counting.
     */
    public function test_a_dst_transition_does_not_drag_the_handover_off_local_midnight(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $schedule->forceFill([
            'created_at' => CarbonImmutable::parse('2027-03-13 12:00:00', 'UTC'),
            'timezone' => 'America/New_York',
        ])->save();
        $schedule = $schedule->fresh();

        $responders = $this->responders($schedule);
        $resolver = new RotationResolver;
        $at = fn (string $local): CarbonImmutable => CarbonImmutable::parse($local, 'America/New_York');

        // The fixture has to actually straddle the transition or this proves
        // nothing, and `diffInHours` cannot say so: it compares absolute instants
        // whatever timezone the objects carry. The UTC OFFSET is what changes.
        $this->assertNotSame(
            $at('2027-03-13 12:00:00')->getOffset(),
            $at('2027-03-15 12:00:00')->getOffset(),
            'the fixture does not straddle a DST transition',
        );

        // Day one, before the clocks move.
        $this->assertTrue($resolver->resolve($schedule, $at('2027-03-13 23:59:59'))->is($responders[0]));
        $this->assertTrue($resolver->resolve($schedule, $at('2027-03-14 00:00:00'))->is($responders[1]));

        // Day three, after them: local midnight again, 47 absolute hours after the
        // anchor day began. Absolute-hour counting would still be mid-shift here.
        $this->assertTrue($resolver->resolve($schedule, $at('2027-03-15 00:00:00'))->is($responders[0]));
        $this->assertTrue($resolver->resolve($schedule, $at('2027-03-15 23:59:59'))->is($responders[0]));
    }

    /**
     * The hour a fall-back repeats maps to ONE slot, so nobody is handed the pager
     * twice for the same wall-clock hour.
     *
     * 01:30 happens twice on 2027-11-07 in America/New_York, an absolute hour
     * apart. Both are 01:30 on the local clock, so both land in the same shift.
     * The safe direction for a pager: a repeated handover is worse than a repeated
     * hour inside one shift.
     */
    public function test_a_repeated_hour_does_not_hand_the_pager_over_twice(): void
    {
        $schedule = $this->makeRing([1, 1]);
        $schedule->forceFill([
            'created_at' => CarbonImmutable::parse('2027-11-07 00:00:00', 'America/New_York'),
            'timezone' => 'America/New_York',
        ])->save();
        $schedule = $schedule->fresh();

        $responders = $this->responders($schedule);
        $resolver = new RotationResolver;

        // The two 01:30s, named by their offsets rather than by a local string,
        // because "2027-11-07 01:30" is ambiguous by construction.
        $first = CarbonImmutable::parse('2027-11-07 05:30:00', 'UTC');
        $second = CarbonImmutable::parse('2027-11-07 06:30:00', 'UTC');

        $this->assertSame(
            '01:30',
            $first->setTimezone('America/New_York')->format('H:i'),
            'the first instant is not 01:30 local',
        );
        $this->assertSame(
            '01:30',
            $second->setTimezone('America/New_York')->format('H:i'),
            'the second instant is not 01:30 local',
        );

        $this->assertTrue($resolver->resolve($schedule, $first)->is($responders[1]));
        $this->assertTrue(
            $resolver->resolve($schedule, $second)->is($responders[1]),
            'the repeated hour must stay in the same shift',
        );
    }

    /**
     * A stored zone that is not a real zone degrades to UTC instead of throwing.
     *
     * `Carbon::setTimezone()` raises `InvalidTimeZoneException` on an unknown
     * identifier, and this resolver runs inside the escalation step that decides
     * who to page, so a bad row must not be able to stop a page. The store and
     * update requests validate the column now, but rows written before that rule
     * existed are already in the database and validation says nothing about
     * history.
     */
    public function test_an_unknown_stored_zone_falls_back_to_utc_rather_than_throwing(): void
    {
        $schedule = $this->makeRing([24, 24]);
        // Written straight through the query builder, because the model and the
        // FormRequest would both refuse it. That is the point: this is the shape
        // of a row that predates the rule.
        DB::table('on_call_schedules')
            ->where('id', $schedule->getKey())
            ->update(['timezone' => 'Mars/Olympus_Mons']);
        $schedule = $schedule->fresh();

        $resolver = new RotationResolver;
        $responders = $this->responders($schedule);

        // The UTC answer, which is what the ring did before the column meant
        // anything: ANCHOR is UTC midnight, so 24 hours in flips the slot.
        $this->assertTrue($resolver->resolve($schedule, $this->anchor())->is($responders[0]));
        $this->assertTrue(
            $resolver->resolve($schedule, $this->anchor()->addHours(24))->is($responders[1]),
        );
    }

    public function test_ring_wraps_after_a_full_cycle(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $responders = $this->responders($schedule);
        $resolver = new RotationResolver;

        // 48h == one full cycle: back to the first responder.
        $this->assertTrue(
            $resolver->resolve($schedule, $this->anchor()->addHours(48))->is($responders[0]),
        );

        // 73h == 25h into the second cycle: inside the second responder's slot.
        $this->assertTrue(
            $resolver->resolve($schedule, $this->anchor()->addHours(73))->is($responders[1]),
        );
    }

    public function test_cycle_honors_per_responder_shift_lengths(): void
    {
        // Responder 0 holds 2h, responder 1 holds 6h: cycle is 8h, not 2*N.
        $schedule = $this->makeRing([2, 6]);
        $responders = $this->responders($schedule);
        $resolver = new RotationResolver;

        // 1h in: inside responder 0's 2h slot.
        $this->assertTrue($resolver->resolve($schedule, $this->anchor()->addHours(1))->is($responders[0]));

        // 3h in: past responder 0's slot, inside responder 1's 6h slot.
        $this->assertTrue($resolver->resolve($schedule, $this->anchor()->addHours(3))->is($responders[1]));

        // 8h in: full cycle elapsed, back to responder 0.
        $this->assertTrue($resolver->resolve($schedule, $this->anchor()->addHours(8))->is($responders[0]));
    }

    public function test_active_override_supersedes_the_rotation(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $responders = $this->responders($schedule);
        $now = $this->anchor()->addHours(6);
        $cover = $this->makeUser();

        // Without the override, the ring picks responder 0 at +6h.
        $this->assertTrue((new RotationResolver)->resolve($schedule, $now)->is($responders[0]));

        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $cover->id,
            'starts_at' => $now->copy()->subHour(),
            'ends_at' => $now->copy()->addHour(),
        ]);

        $this->assertTrue((new RotationResolver)->resolve($schedule, $now)->is($cover));
    }

    public function test_override_window_is_inclusive_at_both_edges(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $cover = $this->makeUser();
        $start = $this->anchor()->addHours(4);
        $end = $this->anchor()->addHours(8);

        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $cover->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        $resolver = new RotationResolver;
        $this->assertTrue($resolver->resolve($schedule, $start)->is($cover));
        $this->assertTrue($resolver->resolve($schedule, $end)->is($cover));
    }

    public function test_override_outside_its_window_falls_back_to_the_ring(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $responders = $this->responders($schedule);
        $cover = $this->makeUser();

        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $cover->id,
            'starts_at' => $this->anchor()->addHours(4),
            'ends_at' => $this->anchor()->addHours(8),
        ]);

        // At +30h the override is long over; the ring picks responder 1.
        $onCall = (new RotationResolver)->resolve($schedule, $this->anchor()->addHours(30));

        $this->assertTrue($onCall->is($responders[1]));
    }

    public function test_latest_starting_override_wins_when_several_overlap(): void
    {
        $schedule = $this->makeRing([24, 24]);
        $now = $this->anchor()->addHours(6);
        $earlier = $this->makeUser();
        $later = $this->makeUser();

        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $earlier->id,
            'starts_at' => $now->copy()->subHours(3),
            'ends_at' => $now->copy()->addHours(3),
        ]);
        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $later->id,
            'starts_at' => $now->copy()->subHour(),
            'ends_at' => $now->copy()->addHours(3),
        ]);

        $onCall = (new RotationResolver)->resolve($schedule, $now);

        $this->assertTrue($onCall->is($later));
    }

    public function test_empty_rotation_returns_null(): void
    {
        $team = $this->makeTeam();
        $schedule = OnCallSchedule::factory()->create([
            'team_id' => $team->id,
        ]);

        $this->assertNull((new RotationResolver)->resolve($schedule, $this->anchor()));
    }

    public function test_empty_rotation_with_an_active_override_still_resolves_the_override(): void
    {
        $team = $this->makeTeam();
        $schedule = OnCallSchedule::factory()->create([
            'team_id' => $team->id,
        ]);
        $cover = $this->makeUser();
        $now = $this->anchor()->addHours(2);

        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $cover->id,
            'starts_at' => $now->copy()->subHour(),
            'ends_at' => $now->copy()->addHour(),
        ]);

        $this->assertTrue((new RotationResolver)->resolve($schedule, $now)->is($cover));
    }

    public function test_defaults_now_to_the_current_instant_when_omitted(): void
    {
        CarbonImmutable::setTestNow($this->anchor()->addHours(24));

        $schedule = $this->makeRing([24, 24]);
        $responders = $this->responders($schedule);

        // The schedule's anchor is pinned to ANCHOR, "now" is +24h: responder 1.
        $this->assertTrue((new RotationResolver)->resolve($schedule)->is($responders[1]));

        CarbonImmutable::setTestNow();
    }

    /**
     * Builds a schedule whose ring has one responder per entry in
     * `$shiftHours`, positioned in order, with the schedule anchor pinned to
     * {@see self::ANCHOR}.
     *
     * @param  array<int, int>  $shiftHours
     */
    private function makeRing(array $shiftHours): OnCallSchedule
    {
        $team = $this->makeTeam();
        $schedule = OnCallSchedule::factory()->create([
            'team_id' => $team->id,
        ]);
        $schedule->forceFill([
            'created_at' => $this->anchor(),
        ])->save();

        foreach ($shiftHours as $position => $hours) {
            OnCallRotation::factory()->create([
                'schedule_id' => $schedule->id,
                'user_id' => $this->makeUser()->id,
                'position' => $position,
                'shift_hours' => $hours,
            ]);
        }

        return $schedule->fresh();
    }

    /**
     * The responders of a ring in `position` order.
     *
     * @return array<int, User>
     */
    private function responders(OnCallSchedule $schedule): array
    {
        return $schedule->rotations()->get()
            ->map(fn (OnCallRotation $rotation): User => $rotation->user)
            ->all();
    }

    private function anchor(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::ANCHOR, 'UTC');
    }

    private function makeTeam(): Team
    {
        $user = $this->makeUser();

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'On-Call Team',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'On-Call Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
    }
}
