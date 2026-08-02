<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Jobs\PerformMonitorCheck;
use App\Models\Monitor;
use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the per-monitor manual-check cooldown on `POST monitors/{id}/test`.
 *
 * The cooldown is enforced by a single conditional UPDATE on
 * `last_manual_check_at` (see {@see MonitorController::test()}),
 * not a route-level throttle, so this exercises the real registered route
 * from `routes/api.php` rather than a test-local route group.
 */
class MonitorTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_manual_check_is_accepted_and_queues_a_job_per_region(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id, ['regions' => ['us-east', 'eu-west']]);

        $response = $this->postJson("/api/v1/monitors/{$monitor->id}/test");

        $response->assertStatus(202);
        Queue::assertPushed(PerformMonitorCheck::class, 2);
    }

    public function test_second_manual_check_within_the_cooldown_is_refused_with_remaining_seconds(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $first = $this->postJson("/api/v1/monitors/{$monitor->id}/test");
        $second = $this->postJson("/api/v1/monitors/{$monitor->id}/test");

        $first->assertStatus(202);
        $second->assertStatus(429);

        $retryAfterSeconds = $second->json('retry_after_seconds');
        $this->assertIsInt($retryAfterSeconds);
        $this->assertGreaterThan(0, $retryAfterSeconds);

        // Exactly one queue push across both requests: the claim's conditional
        // UPDATE affected a row only once, so only the first call dispatched.
        Queue::assertPushed(PerformMonitorCheck::class, 1);
    }

    public function test_manual_check_is_allowed_again_once_the_cooldown_has_elapsed(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id, [
            'last_manual_check_at' => now()->subSeconds(61),
        ]);

        $response = $this->postJson("/api/v1/monitors/{$monitor->id}/test");

        $response->assertStatus(202);
        Queue::assertPushed(PerformMonitorCheck::class, 1);
    }

    public function test_manual_check_on_a_foreign_team_monitor_is_masked_as_not_found(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($otherTeam->id);

        $response = $this->postJson("/api/v1/monitors/{$foreignMonitor->id}/test");

        $response->assertStatus(404);
        Queue::assertNothingPushed();
    }

    /**
     * Authenticate as a fresh user owning a fresh personal team.
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
     * Build a persisted monitor for the given team.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(string $teamId, array $overrides = []): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
            ...$overrides,
        ]);
    }
}
