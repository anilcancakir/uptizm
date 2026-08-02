<?php

namespace Tests\Feature\Billing;

use App\Enums\MonitorRegion;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-plan region allowance read by {@see PlanGate::maxRegionsPerMonitor()},
 * the single source Steps 5, 13 and 15 all read for the region cap.
 */
class PlanGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_free_team_may_only_select_a_single_region(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Solo Ops',
            'personal_team' => true,
        ]);

        $this->assertSame(1, (new PlanGate)->maxRegionsPerMonitor($team));
    }

    public function test_a_pro_team_may_select_every_region(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['user_id' => $user->id, 'name' => 'Pro Ops']);
        $team->forceFill(['plan' => 'pro'])->save();

        $this->assertSame(count(MonitorRegion::cases()), (new PlanGate)->maxRegionsPerMonitor($team));
    }
}
