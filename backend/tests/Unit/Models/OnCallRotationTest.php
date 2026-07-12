<?php

namespace Tests\Unit\Models;

use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see OnCallRotation} `schedule()`/`user()` relations and the
 * factory-produced default shape (the ordered ring of responders).
 */
class OnCallRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_schedule(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $rotation = $this->makeRotation($schedule, $this->makeUser());

        $this->assertTrue($rotation->schedule->is($schedule));
    }

    public function test_belongs_to_a_user(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $user = $this->makeUser();
        $rotation = $this->makeRotation($schedule, $user);

        $this->assertTrue($rotation->user->is($user));
    }

    public function test_factory_defaults_a_valid_rotation(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);

        $rotation = $this->makeRotation($schedule, $this->makeUser());

        $this->assertSame(24, $rotation->shift_hours);
        $this->assertIsInt($rotation->position);
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

    /**
     * Creates a persisted rotation for the given schedule and responder.
     */
    protected function makeRotation(OnCallSchedule $schedule, User $user): OnCallRotation
    {
        return OnCallRotation::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);
    }
}
