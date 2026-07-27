<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\OnCallController;
use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see OnCallController}'s team-scoped schedule CRUD, rotation +
 * override management, the resolved-responder read, and the 404-mask on
 * cross-team access. Routes are the real `api/v1/on-call` surface
 * registered in `routes/api.php`.
 */
class OnCallControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_schedule_for_the_current_team(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/on-call/schedules', [
            'name' => 'Primary Rotation',
            'timezone' => 'Europe/Istanbul',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Primary Rotation');
        $response->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('on_call_schedules', [
            'team_id' => $team->id,
            'name' => 'Primary Rotation',
        ]);
    }

    public function test_index_lists_only_the_current_teams_schedules(): void
    {
        $team = $this->actingAsTeamMember();
        $this->makeSchedule($team->id, 'Mine');

        $foreignTeam = $this->makeForeignTeam();
        $this->makeSchedule($foreignTeam->id, 'Theirs');

        $response = $this->getJson('/api/v1/on-call/schedules');

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_index_carries_each_schedules_rotation_ring_and_overrides(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $rotation = OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);
        $override = OnCallOverride::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(4),
        ]);

        $response = $this->getJson('/api/v1/on-call/schedules');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.rotations.0.id', $rotation->id);
        $response->assertJsonPath('data.0.rotations.0.user_id', $user->id);
        $response->assertJsonPath('data.0.rotations.0.user_name', $user->name);
        $response->assertJsonPath('data.0.rotations.0.shift_hours', 24);
        $response->assertJsonPath('data.0.overrides.0.id', $override->id);
        $response->assertJsonPath('data.0.overrides.0.user_name', $user->name);
    }

    public function test_show_returns_the_schedule_with_its_rotations_and_overrides(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        $response = $this->getJson("/api/v1/on-call/schedules/{$schedule->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.rotations.0.user_id', $user->id);
    }

    public function test_show_masks_cross_team_schedule_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignSchedule = $this->makeSchedule($foreignTeam->id, 'Theirs');

        $response = $this->getJson("/api/v1/on-call/schedules/{$foreignSchedule->id}");

        $response->assertStatus(404);
    }

    public function test_update_changes_the_schedule(): void
    {
        $team = $this->actingAsTeamMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $response = $this->putJson("/api/v1/on-call/schedules/{$schedule->id}", ['name' => 'Renamed']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Renamed');
    }

    public function test_update_masks_cross_team_schedule_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignSchedule = $this->makeSchedule($foreignTeam->id, 'Theirs');

        $response = $this->putJson("/api/v1/on-call/schedules/{$foreignSchedule->id}", ['name' => 'Hijacked']);

        $response->assertStatus(404);
        $this->assertNotSame('Hijacked', $foreignSchedule->fresh()->name);
    }

    public function test_destroy_deletes_the_schedule(): void
    {
        $team = $this->actingAsTeamMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('on_call_schedules', ['id' => $schedule->id]);
    }

    public function test_destroy_masks_cross_team_schedule_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignSchedule = $this->makeSchedule($foreignTeam->id, 'Theirs');

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$foreignSchedule->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('on_call_schedules', ['id' => $foreignSchedule->id]);
    }

    public function test_add_rotation_adds_a_responder_slot(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $response = $this->postJson("/api/v1/on-call/schedules/{$schedule->id}/rotations", [
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 12,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('on_call_rotations', [
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
        ]);
    }

    public function test_add_rotation_rejects_a_user_outside_the_team(): void
    {
        $team = $this->actingAsTeamMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $outsider = User::factory()->create();

        $response = $this->postJson("/api/v1/on-call/schedules/{$schedule->id}/rotations", [
            'user_id' => $outsider->id,
            'position' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');
    }

    public function test_remove_rotation_deletes_the_slot(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $rotation = OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}/rotations/{$rotation->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('on_call_rotations', ['id' => $rotation->id]);
    }

    public function test_remove_rotation_masks_a_slot_from_another_schedule_as_404(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $otherSchedule = $this->makeSchedule($team->id, 'Other');
        $foreignRotation = OnCallRotation::create([
            'schedule_id' => $otherSchedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}/rotations/{$foreignRotation->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('on_call_rotations', ['id' => $foreignRotation->id]);
    }

    public function test_reorder_rotations_updates_positions(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $first = OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);
        $second = OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 1,
            'shift_hours' => 24,
        ]);

        $response = $this->patchJson("/api/v1/on-call/schedules/{$schedule->id}/rotations/reorder", [
            'order' => [
                ['id' => $first->id, 'position' => 5],
                ['id' => $second->id, 'position' => 6],
            ],
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseHas('on_call_rotations', ['id' => $first->id, 'position' => 5]);
        $this->assertDatabaseHas('on_call_rotations', ['id' => $second->id, 'position' => 6]);
    }

    public function test_reorder_rotations_rejects_an_id_not_owned_by_this_schedule(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $otherSchedule = $this->makeSchedule($team->id, 'Other');
        $foreignRotation = OnCallRotation::create([
            'schedule_id' => $otherSchedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        $response = $this->patchJson("/api/v1/on-call/schedules/{$schedule->id}/rotations/reorder", [
            'order' => [
                ['id' => $foreignRotation->id, 'position' => 3],
            ],
        ]);

        $response->assertStatus(404);
    }

    public function test_add_override_adds_a_temporary_responder(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $response = $this->postJson("/api/v1/on-call/schedules/{$schedule->id}/overrides", [
            'user_id' => $user->id,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addHours(4)->toIso8601String(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('on_call_overrides', [
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_add_override_rejects_an_end_before_the_start(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $response = $this->postJson("/api/v1/on-call/schedules/{$schedule->id}/overrides", [
            'user_id' => $user->id,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->subHour()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ends_at');
    }

    public function test_remove_override_deletes_it(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $override = OnCallOverride::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(4),
        ]);

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}/overrides/{$override->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('on_call_overrides', ['id' => $override->id]);
    }

    public function test_remove_override_masks_an_override_from_another_schedule_as_404(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        $otherSchedule = $this->makeSchedule($team->id, 'Other');
        $foreignOverride = OnCallOverride::create([
            'schedule_id' => $otherSchedule->id,
            'user_id' => $user->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(4),
        ]);

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}/overrides/{$foreignOverride->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('on_call_overrides', ['id' => $foreignOverride->id]);
    }

    public function test_current_resolves_the_responder_for_a_given_schedule(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        $response = $this->getJson("/api/v1/on-call/current?schedule_id={$schedule->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.schedule_id', $schedule->id);
        $response->assertJsonPath('data.user.id', $user->id);
    }

    public function test_current_returns_null_user_for_an_empty_ring(): void
    {
        $team = $this->actingAsTeamMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');

        $response = $this->getJson("/api/v1/on-call/current?schedule_id={$schedule->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.user', null);
    }

    public function test_current_masks_a_foreign_schedule_id_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignSchedule = $this->makeSchedule($foreignTeam->id, 'Theirs');

        $response = $this->getJson("/api/v1/on-call/current?schedule_id={$foreignSchedule->id}");

        $response->assertStatus(404);
    }

    public function test_current_without_a_schedule_id_resolves_every_team_schedule(): void
    {
        [$team, $user] = $this->actingAsTeamMemberWithMember();
        $schedule = $this->makeSchedule($team->id, 'Mine');
        OnCallRotation::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        $foreignTeam = $this->makeForeignTeam();
        $this->makeSchedule($foreignTeam->id, 'Theirs');

        $response = $this->getJson('/api/v1/on-call/current');

        $response->assertStatus(200);
        $scheduleIds = array_column($response->json('data'), 'schedule_id');
        $this->assertSame([$schedule->id], $scheduleIds);
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
     * rotation/override user-id validation checks against).
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
     * Build a persisted on-call schedule for the given team.
     */
    protected function makeSchedule(string $teamId, string $name): OnCallSchedule
    {
        return OnCallSchedule::create([
            'team_id' => $teamId,
            'name' => $name,
            'timezone' => 'UTC',
        ]);
    }
}
