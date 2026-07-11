<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorType;
use App\Http\Controllers\Api\V1\StatusPageController;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see StatusPageController}'s
 * team-scoped CRUD, the attach/detach/reorder monitor-membership actions,
 * and the 404-mask on cross-team access. Routes are the real
 * `api/v1/status-pages` surface registered in `routes/api.php`.
 */
class StatusPageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_status_page_for_the_current_team(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/status-pages', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'acme-status');
        $response->assertJsonPath('data.team_id', $team->id);

        $this->assertDatabaseHas('status_pages', [
            'team_id' => $team->id,
            'slug' => 'acme-status',
        ]);
    }

    public function test_store_rejects_a_duplicate_slug(): void
    {
        $this->actingAsTeamMember();
        $this->postJson('/api/v1/status-pages', $this->validPayload())->assertStatus(201);

        $response = $this->postJson('/api/v1/status-pages', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
    }

    public function test_index_lists_only_the_current_teams_pages(): void
    {
        $team = $this->actingAsTeamMember();
        $this->makeStatusPage($team->id, 'mine');

        $foreignTeam = $this->makeForeignTeam();
        $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->getJson('/api/v1/status-pages');

        $response->assertStatus(200);
        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('mine', $slugs);
        $this->assertNotContains('theirs', $slugs);
    }

    public function test_show_returns_the_page_with_its_monitors(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $monitor = $this->makeMonitor($team->id);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $response = $this->getJson("/api/v1/status-pages/{$page->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.monitors.0.id', $monitor->id);
    }

    public function test_show_masks_cross_team_page_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->getJson("/api/v1/status-pages/{$foreignPage->id}");

        $response->assertStatus(404);
    }

    public function test_update_masks_cross_team_page_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->putJson("/api/v1/status-pages/{$foreignPage->id}", ['name' => 'Hijacked']);

        $response->assertStatus(404);
        $this->assertNotSame('Hijacked', $foreignPage->fresh()->name);
    }

    public function test_update_changes_the_page_and_busts_the_public_cache(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $monitor = $this->makeMonitor($team->id);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        Cache::put('status-page:mine', ['stale' => true], 60);

        $response = $this->putJson("/api/v1/status-pages/{$page->id}", ['name' => 'Renamed Status']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Renamed Status');
        $this->assertFalse(Cache::has('status-page:mine'));
    }

    public function test_destroy_deletes_the_page_and_busts_its_own_cache_entry(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $monitor = $this->makeMonitor($team->id);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        Cache::put('status-page:mine', ['stale' => true], 60);

        $response = $this->deleteJson("/api/v1/status-pages/{$page->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('status_pages', ['id' => $page->id]);
        $this->assertFalse(Cache::has('status-page:mine'));
    }

    public function test_destroy_masks_cross_team_page_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->deleteJson("/api/v1/status-pages/{$foreignPage->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('status_pages', ['id' => $foreignPage->id]);
    }

    public function test_attach_adds_a_monitor_to_the_component_list(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $monitor = $this->makeMonitor($team->id);

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/monitors", [
            'monitor_id' => $monitor->id,
            'display_order' => 2,
            'custom_label' => 'Public API',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.monitors.0.id', $monitor->id);
        $response->assertJsonPath('data.monitors.0.custom_label', 'Public API');
        $this->assertDatabaseHas('status_page_monitors', [
            'status_page_id' => $page->id,
            'monitor_id' => $monitor->id,
            'display_order' => 2,
        ]);
    }

    public function test_attach_rejects_a_monitor_owned_by_another_team(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $foreignTeam = $this->makeForeignTeam();
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/monitors", [
            'monitor_id' => $foreignMonitor->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('monitor_id');
    }

    public function test_detach_removes_a_monitor_from_the_component_list(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $monitor = $this->makeMonitor($team->id);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $response = $this->deleteJson("/api/v1/status-pages/{$page->id}/monitors/{$monitor->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('status_page_monitors', [
            'status_page_id' => $page->id,
            'monitor_id' => $monitor->id,
        ]);
    }

    public function test_reorder_updates_display_order_for_every_attached_monitor(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $first = $this->makeMonitor($team->id);
        $second = $this->makeMonitor($team->id);
        $page->monitors()->attach([
            $first->id => ['display_order' => 0],
            $second->id => ['display_order' => 1],
        ]);

        $response = $this->patchJson("/api/v1/status-pages/{$page->id}/monitors/reorder", [
            'order' => [
                ['id' => $first->id, 'display_order' => 1],
                ['id' => $second->id, 'display_order' => 0],
            ],
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseHas('status_page_monitors', [
            'status_page_id' => $page->id,
            'monitor_id' => $first->id,
            'display_order' => 1,
        ]);
        $this->assertDatabaseHas('status_page_monitors', [
            'status_page_id' => $page->id,
            'monitor_id' => $second->id,
            'display_order' => 0,
        ]);
    }

    public function test_reorder_rejects_a_monitor_id_not_attached_to_this_page(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $foreignMonitor = $this->makeMonitor($team->id);

        $response = $this->patchJson("/api/v1/status-pages/{$page->id}/monitors/reorder", [
            'order' => [
                ['id' => $foreignMonitor->id, 'display_order' => 0],
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
     * Builds a persisted status page for the given team.
     */
    protected function makeStatusPage(string $teamId, string $slug): StatusPage
    {
        return StatusPage::create([
            'team_id' => $teamId,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
        ]);
    }

    /**
     * Build a persisted monitor for the given team.
     */
    protected function makeMonitor(string $teamId): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }

    /**
     * A valid create payload for a status page.
     *
     * @return array<string, mixed>
     */
    protected function validPayload(): array
    {
        return [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ];
    }
}
