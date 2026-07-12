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
