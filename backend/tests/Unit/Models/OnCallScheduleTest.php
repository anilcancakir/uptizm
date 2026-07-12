<?php

namespace Tests\Unit\Models;

use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see OnCallSchedule} team-scope and its `rotations`/`overrides`
 * relations, including the `rotations()` ordering by `position` that the
 * rotation resolver (S25) relies on.
 */
class OnCallScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_team(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);

        $this->assertTrue($schedule->team->is($team));
    }

    public function test_rotations_relation_returns_rotations_ordered_by_position(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $first = $this->makeUser();
        $second = $this->makeUser();

        // Insert out of position order to prove the relation sorts by
        // position, not insert order.
        OnCallRotation::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $second->id,
            'position' => 1,
        ]);
        OnCallRotation::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $first->id,
            'position' => 0,
        ]);

        $ordered = $schedule->rotations()->get();

        $this->assertSame([$first->id, $second->id], $ordered->pluck('user_id')->all());
    }

    public function test_overrides_relation_returns_attached_overrides(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $user = $this->makeUser();

        OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);

        $this->assertCount(1, $schedule->overrides);
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = $this->makeUser();

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'On-Call Team',
        ]);
    }

    /**
     * Creates a persisted user with a unique email.
     */
    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'On-Call Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
    }

    /**
     * Creates a persisted on-call schedule for the given team.
     */
    protected function makeSchedule(Team $team): OnCallSchedule
    {
        return OnCallSchedule::factory()->create([
            'team_id' => $team->id,
        ]);
    }
}
