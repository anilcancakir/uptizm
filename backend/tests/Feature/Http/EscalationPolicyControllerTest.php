<?php

namespace Tests\Feature\Http;

use App\Enums\EscalationTargetType;
use App\Http\Controllers\Api\V1\EscalationPolicyController;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see EscalationPolicyController}'s
 * team-scoped policy CRUD, step add/remove/reorder, `target_type` validation,
 * and the 404-mask on cross-team access. Routes are the real
 * `api/v1/escalation-policies` surface registered in `routes/api.php`.
 */
class EscalationPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_policy_for_the_current_team(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/escalation-policies', [
            'name' => 'Primary Escalation',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Primary Escalation');
        $response->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('escalation_policies', [
            'team_id' => $team->id,
            'name' => 'Primary Escalation',
        ]);
    }

    public function test_store_accepts_and_returns_the_paging_flags(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/escalation-policies', [
            'name' => 'Repeating Escalation',
            'repeat_last_step' => true,
            'is_default' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.repeat_last_step', true);
        $response->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('escalation_policies', [
            'team_id' => $team->id,
            'name' => 'Repeating Escalation',
            'repeat_last_step' => true,
            'is_default' => true,
        ]);
    }

    public function test_a_policy_created_without_the_flags_keeps_todays_behaviour(): void
    {
        $team = $this->actingAsTeamMember();

        // A client that predates the columns, which is every client until the
        // matching Flutter release ships. It must create the policy it always
        // created rather than one that repeats or claims the team default.
        $this->postJson('/api/v1/escalation-policies', ['name' => 'Plain'])
            ->assertStatus(201)
            ->assertJsonPath('data.repeat_last_step', false)
            ->assertJsonPath('data.is_default', false);

        $this->assertDatabaseHas('escalation_policies', [
            'team_id' => $team->id,
            'name' => 'Plain',
            'repeat_last_step' => false,
            'is_default' => false,
        ]);
    }

    public function test_marking_a_default_demotes_the_teams_previous_one(): void
    {
        $team = $this->actingAsTeamMember();
        $first = $this->makePolicy($team->id, 'First');
        $first->update(['is_default' => true]);

        $second = $this->makePolicy($team->id, 'Second');

        $this->putJson("/api/v1/escalation-policies/{$second->id}", [
            'is_default' => true,
        ])->assertStatus(200)->assertJsonPath('data.is_default', true);

        // Marking MOVES the default rather than answering 422 and asking the
        // operator to unmark the other one first. A team has one fallback
        // ladder, so two marked policies is a state the API must not hold.
        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
    }

    public function test_marking_a_default_leaves_another_teams_default_alone(): void
    {
        $team = $this->actingAsTeamMember();
        $foreignTeam = $this->makeForeignTeam();
        $foreignDefault = $this->makePolicy($foreignTeam->id, 'Theirs');
        $foreignDefault->update(['is_default' => true]);

        $mine = $this->makePolicy($team->id, 'Mine');

        $this->putJson("/api/v1/escalation-policies/{$mine->id}", [
            'is_default' => true,
        ])->assertStatus(200);

        // The demotion is scoped by team. Without that predicate one team
        // marking a default would silently clear every other team's.
        $this->assertTrue($foreignDefault->refresh()->is_default);
    }

    public function test_renaming_a_policy_does_not_clear_its_flags(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Before');
        $policy->update(['repeat_last_step' => true, 'is_default' => true]);

        // A PATCH-shaped update that mentions neither flag. `sometimes` is what
        // keeps them; a plain `boolean` rule would leave them absent from
        // validated() and the update would be a no-op, but a `required` one
        // would 422 and a defaulted one would silently clear them.
        $this->putJson("/api/v1/escalation-policies/{$policy->id}", [
            'name' => 'After',
        ])->assertStatus(200);

        $policy->refresh();
        $this->assertSame('After', $policy->name);
        $this->assertTrue($policy->repeat_last_step);
        $this->assertTrue($policy->is_default);
    }

    public function test_index_lists_only_the_current_teams_policies(): void
    {
        $team = $this->actingAsTeamMember();
        $this->makePolicy($team->id, 'Mine');

        $foreignTeam = $this->makeForeignTeam();
        $this->makePolicy($foreignTeam->id, 'Theirs');

        $response = $this->getJson('/api/v1/escalation-policies');

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_show_returns_the_policy_with_its_steps(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');
        EscalationStep::create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        $response = $this->getJson("/api/v1/escalation-policies/{$policy->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.steps.0.target_type', 'on_call');
    }

    public function test_show_masks_cross_team_policy_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPolicy = $this->makePolicy($foreignTeam->id, 'Theirs');

        $response = $this->getJson("/api/v1/escalation-policies/{$foreignPolicy->id}");

        $response->assertStatus(404);
    }

    public function test_update_changes_the_policy(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $response = $this->putJson("/api/v1/escalation-policies/{$policy->id}", ['name' => 'Renamed']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Renamed');
    }

    public function test_update_masks_cross_team_policy_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPolicy = $this->makePolicy($foreignTeam->id, 'Theirs');

        $response = $this->putJson("/api/v1/escalation-policies/{$foreignPolicy->id}", ['name' => 'Hijacked']);

        $response->assertStatus(404);
        $this->assertNotSame('Hijacked', $foreignPolicy->fresh()->name);
    }

    public function test_destroy_deletes_the_policy(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $response = $this->deleteJson("/api/v1/escalation-policies/{$policy->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('escalation_policies', ['id' => $policy->id]);
    }

    public function test_destroy_masks_cross_team_policy_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPolicy = $this->makePolicy($foreignTeam->id, 'Theirs');

        $response = $this->deleteJson("/api/v1/escalation-policies/{$foreignPolicy->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('escalation_policies', ['id' => $foreignPolicy->id]);
    }

    public function test_add_step_targeting_on_call_requires_no_target_id_or_channel(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $response = $this->postJson("/api/v1/escalation-policies/{$policy->id}/steps", [
            'position' => 0,
            'delay_minutes' => 5,
            'target_type' => 'on_call',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('escalation_steps', [
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'target_type' => 'on_call',
        ]);
    }

    public function test_add_step_targeting_user_requires_a_target_id_in_the_team(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $response = $this->postJson("/api/v1/escalation-policies/{$policy->id}/steps", [
            'position' => 0,
            'delay_minutes' => 5,
            'target_type' => 'user',
            'target_id' => $user->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('escalation_steps', [
            'escalation_policy_id' => $policy->id,
            'target_type' => 'user',
            'target_id' => $user->id,
        ]);
    }

    public function test_add_step_targeting_user_rejects_a_missing_target_id(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $response = $this->postJson("/api/v1/escalation-policies/{$policy->id}/steps", [
            'position' => 0,
            'delay_minutes' => 5,
            'target_type' => 'user',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('target_id');
    }

    public function test_add_step_targeting_user_rejects_a_user_outside_the_team(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $outsider = User::factory()->create();

        $response = $this->postJson("/api/v1/escalation-policies/{$policy->id}/steps", [
            'position' => 0,
            'delay_minutes' => 5,
            'target_type' => 'user',
            'target_id' => $outsider->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('target_id');
    }

    public function test_add_step_rejects_an_invalid_target_type(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');

        $response = $this->postJson("/api/v1/escalation-policies/{$policy->id}/steps", [
            'position' => 0,
            'delay_minutes' => 5,
            'target_type' => 'carrier-pigeon',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('target_type');
    }

    public function test_remove_step_deletes_it(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');
        $step = EscalationStep::create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        $response = $this->deleteJson("/api/v1/escalation-policies/{$policy->id}/steps/{$step->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('escalation_steps', ['id' => $step->id]);
    }

    public function test_remove_step_masks_a_step_from_another_policy_as_404(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');
        $otherPolicy = $this->makePolicy($team->id, 'Other');
        $foreignStep = EscalationStep::create([
            'escalation_policy_id' => $otherPolicy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        $response = $this->deleteJson("/api/v1/escalation-policies/{$policy->id}/steps/{$foreignStep->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('escalation_steps', ['id' => $foreignStep->id]);
    }

    public function test_remove_step_masks_cross_team_policy_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPolicy = $this->makePolicy($foreignTeam->id, 'Theirs');
        $foreignStep = EscalationStep::create([
            'escalation_policy_id' => $foreignPolicy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        $response = $this->deleteJson("/api/v1/escalation-policies/{$foreignPolicy->id}/steps/{$foreignStep->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('escalation_steps', ['id' => $foreignStep->id]);
    }

    public function test_reorder_steps_updates_positions(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');
        $first = EscalationStep::create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);
        $second = EscalationStep::create([
            'escalation_policy_id' => $policy->id,
            'position' => 1,
            'delay_minutes' => 5,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        $response = $this->patchJson("/api/v1/escalation-policies/{$policy->id}/steps/reorder", [
            'order' => [
                ['id' => $first->id, 'position' => 5],
                ['id' => $second->id, 'position' => 6],
            ],
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseHas('escalation_steps', ['id' => $first->id, 'position' => 5]);
        $this->assertDatabaseHas('escalation_steps', ['id' => $second->id, 'position' => 6]);
    }

    public function test_reorder_steps_rejects_an_id_not_owned_by_this_policy(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id, 'Mine');
        $otherPolicy = $this->makePolicy($team->id, 'Other');
        $foreignStep = EscalationStep::create([
            'escalation_policy_id' => $otherPolicy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
        ]);

        $response = $this->patchJson("/api/v1/escalation-policies/{$policy->id}/steps/reorder", [
            'order' => [
                ['id' => $foreignStep->id, 'position' => 3],
            ],
        ]);

        $response->assertStatus(404);
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * Authenticate as a user whose current team is a freshly created team,
     * with the acting user also attached as a `team_user` row (the pivot
     * the step's `target_id` user validation checks against).
     *
     * @return array{0: Team, 1: User}
     */
    protected function actingAsTeamMemberWithMember(): array
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();
        $team->users()->attach($user->id, ['role' => 'admin']);

        Sanctum::actingAs($user);

        return [$team, $user];
    }

    /**
     * Builds a persisted foreign team, owned by a fresh user, unrelated to
     * the acting user.
     */
    protected function makeForeignTeam(): Team
    {
        return Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
    }

    /**
     * Build a persisted escalation policy for the given team.
     */
    protected function makePolicy(string $teamId, string $name): EscalationPolicy
    {
        return EscalationPolicy::create([
            'team_id' => $teamId,
            'name' => $name,
        ]);
    }
}
