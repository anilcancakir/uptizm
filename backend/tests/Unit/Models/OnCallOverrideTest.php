<?php

namespace Tests\Unit\Models;

use App\Models\OnCallOverride;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see OnCallOverride} `schedule()`/`user()` relations and the
 * `starts_at`/`ends_at` datetime cast that windows a temporary responder swap.
 */
class OnCallOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_schedule(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $override = $this->makeOverride($schedule, $this->makeUser());

        $this->assertTrue($override->schedule->is($schedule));
    }

    public function test_belongs_to_a_user(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $user = $this->makeUser();
        $override = $this->makeOverride($schedule, $user);

        $this->assertTrue($override->user->is($user));
    }

    public function test_starts_at_and_ends_at_are_cast_to_datetime(): void
    {
        $team = $this->makeTeam();
        $schedule = $this->makeSchedule($team);
        $override = $this->makeOverride($schedule, $this->makeUser());

        $this->assertInstanceOf(Carbon::class, $override->starts_at);
        $this->assertInstanceOf(Carbon::class, $override->ends_at);
        $this->assertTrue($override->ends_at->isAfter($override->starts_at));
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
     * Creates a persisted override for the given schedule and responder.
     */
    protected function makeOverride(OnCallSchedule $schedule, User $user): OnCallOverride
    {
        return OnCallOverride::factory()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);
    }
}
