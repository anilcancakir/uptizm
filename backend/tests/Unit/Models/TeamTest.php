<?php

namespace Tests\Unit\Models;

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks {@see Team::entitledPlan()} as the single source-of-truth read for
 * a team's billing entitlement: it defaults to {@see Plan::Free} and
 * reflects the column-backed value once the `plan` column is set.
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitled_plan_defaults_to_free(): void
    {
        $team = $this->makeTeam();

        $this->assertSame(Plan::Free, $team->entitledPlan());
    }

    public function test_entitled_plan_returns_the_column_backed_plan(): void
    {
        $team = $this->makeTeam();
        $team->update(['plan' => Plan::Pro]);
        $team->refresh();

        $this->assertSame(Plan::Pro, $team->entitledPlan());
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Team Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Entitlement Team',
        ]);
    }
}
