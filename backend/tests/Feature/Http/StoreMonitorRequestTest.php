<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Monitor;
use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the extended create-monitor validation surface: the full field
 * set (request headers, auth_config credentials, SLO target, SSL tracking)
 * is accepted, the shared {@see \App\Support\Monitoring\HostGuard} still
 * rejects internal targets, and the auth_config inner-shape guard refuses a
 * credential map that omits its matching secret.
 *
 * Route wiring for /api/v1/monitors is a later step, so this test registers
 * the controller against a Sanctum-guarded route group of its own, matching
 * the harness in {@see MonitorControllerTest}.
 */
class StoreMonitorRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
            Route::post('monitors', [MonitorController::class, 'store']);
        });
    }

    public function test_store_accepts_full_monitor_field_set(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'request_headers' => [
                'X-Trace' => 'on',
            ],
            'request_body' => '{"ping":true}',
            'auth_config' => [
                'type' => 'bearer',
                'token' => 'super-secret-token',
            ],
            'slo_target' => 99.9,
            'ssl_tracking' => true,
            'ssl_alert_threshold_days' => 30,
            'tags' => [
                'prod',
            ],
        ]);

        $response->assertStatus(201);

        $monitor = Monitor::query()->where('team_id', $team->id)->sole();
        $this->assertSame('super-secret-token', $monitor->auth_config['token']);
        $this->assertTrue((bool) $monitor->ssl_tracking);
        $this->assertEqualsWithDelta(99.9, (float) $monitor->slo_target, 0.001);
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
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_auth_config_missing_matching_secret(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // A bearer auth_config with no token must fail the inner-shape guard.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'auth_config' => [
                'type' => 'bearer',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auth_config.token');
    }

    public function test_store_rejects_auth_config_without_type(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'auth_config' => [
                'token' => 'orphan-token',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auth_config.type');
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
