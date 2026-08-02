<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\ScheduledMaintenanceController;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see ScheduledMaintenanceController}'s team-scoped window CRUD, the
 * 404-mask on cross-team access, the window-bound and team-scoped existence
 * validation, and the read-only nature of `announced_at`.
 *
 * Routes are the real `api/v1/scheduled-maintenances` surface registered in
 * `routes/api.php`.
 */
class ScheduledMaintenanceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_window_for_the_current_team(): void
    {
        $team = $this->actingAsTeamMember();
        $statusPage = $this->makeStatusPage($team);

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $statusPage->id,
            'title' => 'Database upgrade',
            'description' => 'Rolling PostgreSQL 17 upgrade.',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Database upgrade');
        $response->assertJsonPath('data.team_id', $team->id);
        $response->assertJsonPath('data.status_page_id', $statusPage->id);
        // The schema default reaches the wire without the client sending it.
        $response->assertJsonPath('data.suppress_alerts', true);
        $response->assertJsonPath('data.announced_at', null);

        $this->assertDatabaseHas('scheduled_maintenances', [
            'team_id' => $team->id,
            'status_page_id' => $statusPage->id,
            'title' => 'Database upgrade',
        ]);
    }

    public function test_store_emits_iso_8601_window_bounds(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'Cache flush',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $response->json('data.starts_at'),
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $response->json('data.ends_at'),
        );
    }

    public function test_store_attaches_the_submitted_monitors(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'API');

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'API redeploy',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'monitor_ids' => [$monitor->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.monitors.0.monitor_id', $monitor->id);
        $response->assertJsonPath('data.monitors.0.name', 'API');

        $this->assertDatabaseHas('scheduled_maintenance_monitors', [
            'scheduled_maintenance_id' => $response->json('data.id'),
            'monitor_id' => $monitor->id,
        ]);
    }

    public function test_store_rejects_ends_at_before_starts_at(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'Backwards window',
            'starts_at' => '2026-09-02T00:00:00Z',
            'ends_at' => '2026-09-01T22:00:00Z',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ends_at');
    }

    public function test_store_rejects_a_status_page_from_another_team(): void
    {
        $this->actingAsTeamMember();
        $foreignPage = $this->makeStatusPage($this->makeForeignTeam());

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $foreignPage->id,
            'title' => 'Cross-tenant window',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status_page_id');
    }

    public function test_store_rejects_a_monitor_from_another_team(): void
    {
        $team = $this->actingAsTeamMember();
        $foreignMonitor = $this->makeMonitor($this->makeForeignTeam(), 'Their API');

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'Cross-tenant components',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'monitor_ids' => [$foreignMonitor->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('monitor_ids.0');
    }

    public function test_store_rejects_a_scalar_monitor_ids_payload(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'Malformed components',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'monitor_ids' => 'not-an-array',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('monitor_ids');
    }

    public function test_store_ignores_an_announced_at_supplied_by_the_client(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'Announce-once guard',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'announced_at' => '2026-08-01T10:00:00Z',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.announced_at', null);
        $this->assertNull(ScheduledMaintenance::query()->findOrFail($response->json('data.id'))->announced_at);
    }

    public function test_store_accepts_an_explicit_suppress_alerts_false(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => 'Noisy window',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'suppress_alerts' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.suppress_alerts', false);
    }

    public function test_index_lists_only_the_current_teams_windows(): void
    {
        $team = $this->actingAsTeamMember();
        $this->makeWindow($team, 'Mine');

        $foreignTeam = $this->makeForeignTeam();
        $this->makeWindow($foreignTeam, 'Theirs');

        $response = $this->getJson('/api/v1/scheduled-maintenances');

        $response->assertStatus(200);
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Theirs', $titles);
    }

    public function test_show_returns_the_window_with_its_monitors(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');
        $monitor = $this->makeMonitor($team, 'API');
        $window->monitors()->attach($monitor->id);

        $response = $this->getJson("/api/v1/scheduled-maintenances/{$window->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Mine');
        $response->assertJsonPath('data.monitors.0.monitor_id', $monitor->id);
    }

    public function test_show_masks_a_cross_team_window_as_404_and_not_403(): void
    {
        $this->actingAsTeamMember();
        $foreignWindow = $this->makeWindow($this->makeForeignTeam(), 'Theirs');

        $response = $this->getJson("/api/v1/scheduled-maintenances/{$foreignWindow->id}");

        $response->assertStatus(404);
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_update_changes_the_window(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'title' => 'Renamed window',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Renamed window');
    }

    public function test_update_syncs_the_monitor_set_when_it_is_submitted(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');
        $first = $this->makeMonitor($team, 'API');
        $second = $this->makeMonitor($team, 'Web');
        $window->monitors()->attach($first->id);

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'monitor_ids' => [$second->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('scheduled_maintenance_monitors', [
            'scheduled_maintenance_id' => $window->id,
            'monitor_id' => $first->id,
        ]);
        $this->assertDatabaseHas('scheduled_maintenance_monitors', [
            'scheduled_maintenance_id' => $window->id,
            'monitor_id' => $second->id,
        ]);
    }

    public function test_update_leaves_the_monitor_set_alone_when_it_is_omitted(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');
        $monitor = $this->makeMonitor($team, 'API');
        $window->monitors()->attach($monitor->id);

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'title' => 'Renamed window',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('scheduled_maintenance_monitors', [
            'scheduled_maintenance_id' => $window->id,
            'monitor_id' => $monitor->id,
        ]);
    }

    public function test_update_rejects_ends_at_before_starts_at(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'starts_at' => '2026-09-02T00:00:00Z',
            'ends_at' => '2026-09-01T22:00:00Z',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ends_at');
    }

    public function test_update_requires_both_bounds_when_only_one_moves(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');

        // A lone bound cannot be validated against its pair, so the pair moves
        // together or not at all.
        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'ends_at' => '2026-09-05T00:00:00Z',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('starts_at');
    }

    public function test_update_ignores_an_announced_at_supplied_by_the_client(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'announced_at' => '2026-08-01T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.announced_at', null);
        $this->assertNull($window->fresh()->announced_at);
    }

    public function test_update_masks_a_cross_team_window_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignWindow = $this->makeWindow($this->makeForeignTeam(), 'Theirs');

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$foreignWindow->id}", [
            'title' => 'Hijacked',
        ]);

        $response->assertStatus(404);
        $this->assertNotSame('Hijacked', $foreignWindow->fresh()->title);
    }

    public function test_destroy_deletes_the_window(): void
    {
        $team = $this->actingAsTeamMember();
        $window = $this->makeWindow($team, 'Mine');

        $response = $this->deleteJson("/api/v1/scheduled-maintenances/{$window->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('scheduled_maintenances', ['id' => $window->id]);
    }

    public function test_destroy_masks_a_cross_team_window_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignWindow = $this->makeWindow($this->makeForeignTeam(), 'Theirs');

        $response = $this->deleteJson("/api/v1/scheduled-maintenances/{$foreignWindow->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('scheduled_maintenances', ['id' => $foreignWindow->id]);
    }

    public function test_the_surface_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/scheduled-maintenances');

        $response->assertStatus(401);
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
     * Builds a persisted foreign team, owned by a fresh user, unrelated to the
     * acting user.
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
     * Build a persisted status page for the given team.
     */
    protected function makeStatusPage(Team $team): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Public Status',
            'slug' => Str::uuid().'-status',
        ]);
    }

    /**
     * Build a persisted monitor for the given team.
     */
    protected function makeMonitor(Team $team, string $name): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => 'http',
            'url' => 'https://example.com/'.Str::slug($name),
            'check_interval_sec' => 60,
        ]);
    }

    /**
     * Build a persisted maintenance window for the given team, on a status
     * page created for that same team.
     */
    protected function makeWindow(Team $team, string $title): ScheduledMaintenance
    {
        return ScheduledMaintenance::factory()->create([
            'team_id' => $team->id,
            'status_page_id' => $this->makeStatusPage($team)->id,
            'title' => $title,
        ]);
    }
}
