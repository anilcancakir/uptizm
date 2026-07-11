<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Jobs\PerformMonitorCheck;
use App\Models\Monitor;
use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the team-scoped monitor CRUD + lifecycle surface: the SSRF host
 * denylist on create, the 404-mask on cross-team access, the auth_config
 * secret redaction on output, and the pause lifecycle transition.
 *
 * Route wiring for /api/v1/monitors is a later step, so this test registers
 * the controller against a Sanctum-guarded route group of its own to
 * exercise the full HTTP path today.
 */
class MonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
            Route::get('monitors', [MonitorController::class, 'index']);
            Route::post('monitors', [MonitorController::class, 'store']);
            Route::get('monitors/{monitor}', [MonitorController::class, 'show']);
            Route::put('monitors/{monitor}', [MonitorController::class, 'update']);
            Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy']);
            Route::post('monitors/{monitor}/pause', [MonitorController::class, 'pause']);
            Route::post('monitors/{monitor}/resume', [MonitorController::class, 'resume']);
            Route::post('monitors/{monitor}/test', [MonitorController::class, 'test']);
        });
    }

    public function test_store_creates_monitor_and_returns_check_interval_sec(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.check_interval_sec', 60);
        $response->assertJsonPath('data.status', 'active');

        // A first check is fanned out per configured region.
        Queue::assertPushed(PerformMonitorCheck::class, 2);
    }

    public function test_store_rejects_cloud_metadata_link_local_url(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'url' => 'http://169.254.169.254/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_integer_ip_literal_for_loopback(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // 2130706433 is the 32-bit integer form of 127.0.0.1; without literal
        // normalization it slips past the dotted-quad denylist.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'url' => 'http://2130706433/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        Queue::assertNothingPushed();
    }

    public function test_store_drops_null_expected_status_code_and_applies_db_default(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        // A payload carrying an explicit null must not 500 on the NOT NULL
        // column; the key is dropped so the DB default (200) applies.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'expected_status_code' => null,
        ]);

        $response->assertStatus(201);

        $monitor = Monitor::query()->where('team_id', $team->id)->sole();
        $this->assertSame(200, $monitor->expected_status_code);
    }

    public function test_store_rejects_rfc1918_private_url(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'url' => 'http://10.0.0.5/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_store_rejects_loopback_and_internal_hosts(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        foreach (['http://localhost/', 'http://127.0.0.1/', 'http://api.internal/'] as $url) {
            $response = $this->postJson('/api/v1/monitors', [
                ...$this->validPayload(),
                'url' => $url,
            ]);

            $response->assertStatus(422, "Expected {$url} to be rejected.");
            $response->assertJsonValidationErrors('url');
        }
    }

    public function test_show_masks_cross_team_monitor_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);

        $response = $this->getJson("/api/v1/monitors/{$foreignMonitor->id}");

        $response->assertStatus(404);
    }

    public function test_resource_never_leaks_auth_config_secret(): void
    {
        $team = $this->actingAsTeamMember();

        $monitor = $this->makeMonitor($team->id, [
            'auth_config' => [
                'type' => 'bearer',
                'username' => 'ops',
                'token' => 'super-secret-token',
                'password' => 'super-secret-password',
                'value' => 'super-secret-value',
            ],
        ]);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.auth_config.type', 'bearer');
        $response->assertJsonPath('data.auth_config.username', 'ops');
        $response->assertJsonMissingPath('data.auth_config.token');
        $response->assertJsonMissingPath('data.auth_config.password');
        $response->assertJsonMissingPath('data.auth_config.value');

        // Defense in depth: no secret string survives anywhere in the body.
        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_pause_sets_status_to_paused(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->postJson("/api/v1/monitors/{$monitor->id}/pause");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'paused');
        $this->assertNull($monitor->fresh()->next_check_at);
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

    /**
     * A valid create payload targeting a public host across two regions.
     *
     * @return array<string, mixed>
     */
    protected function validPayload(): array
    {
        return [
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => [
                'us-east',
                'eu-west',
            ],
            'expected_status_code' => 200,
        ];
    }
}
