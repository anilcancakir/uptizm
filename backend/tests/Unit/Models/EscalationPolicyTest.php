<?php

namespace Tests\Unit\Models;

use App\Enums\EscalationTargetType;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see EscalationPolicy} <-> {@see EscalationStep} relation
 * shapes: a policy has many steps ordered by `position`, and a step
 * belongs back to its policy.
 */
class EscalationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_steps_relation_returns_steps_ordered_by_position(): void
    {
        $policy = $this->makePolicy($this->makeTeam());

        // Insert out of position order to prove the relation sorts by
        // `position`, not by insertion/creation order.
        $second = $this->makeStep($policy, position: 1, delayMinutes: 15);
        $first = $this->makeStep($policy, position: 0, delayMinutes: 0);

        $ordered = $policy->steps()->get();

        $this->assertSame([$first->id, $second->id], $ordered->pluck('id')->all());
    }

    public function test_step_belongs_to_its_policy(): void
    {
        $policy = $this->makePolicy($this->makeTeam());
        $step = $this->makeStep($policy, position: 0, delayMinutes: 5);

        $this->assertTrue($step->policy->is($policy));
    }

    public function test_policy_belongs_to_its_team(): void
    {
        $team = $this->makeTeam();
        $policy = $this->makePolicy($team);

        $this->assertTrue($policy->team->is($team));
    }

    public function test_step_target_type_casts_to_enum(): void
    {
        $policy = $this->makePolicy($this->makeTeam());
        $step = $this->makeStep($policy, position: 0, delayMinutes: 0, targetType: EscalationTargetType::User);

        $this->assertSame(EscalationTargetType::User, $step->target_type);
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Escalation Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Escalation Team',
        ]);
    }

    /**
     * Creates a persisted escalation policy for the given team.
     */
    protected function makePolicy(Team $team): EscalationPolicy
    {
        return EscalationPolicy::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary On-Call Policy',
        ]);
    }

    /**
     * Creates a persisted escalation step for the given policy.
     */
    protected function makeStep(
        EscalationPolicy $policy,
        int $position,
        int $delayMinutes,
        EscalationTargetType $targetType = EscalationTargetType::OnCall,
    ): EscalationStep {
        return EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => $position,
            'delay_minutes' => $delayMinutes,
            'target_type' => $targetType,
        ]);
    }
}
