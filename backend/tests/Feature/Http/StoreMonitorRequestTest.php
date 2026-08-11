<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\HostGuard;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the extended create-monitor validation surface: the full field
 * set (request headers, auth_config credentials, SLO target, SSL tracking)
 * is accepted, the shared {@see HostGuard} still
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

    public function test_store_accepts_a_tcp_host_port_target(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'type' => 'tcp',
            'url' => 'db.example.com:5432',
        ]);

        $response->assertStatus(201);
        $monitor = Monitor::query()->where('team_id', $team->id)->sole();
        $this->assertSame('db.example.com:5432', $monitor->url);
    }

    public function test_store_rejects_a_tcp_target_without_a_port(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // A TCP check connects to a specific port, so a bare host is rejected.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'type' => 'tcp',
            'url' => 'db.example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_store_rejects_a_tcp_target_shaped_as_a_full_url(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // A TCP target is a bare host[:port]; a scheme + path is not accepted.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'type' => 'tcp',
            'url' => 'https://db.example.com/health',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_store_rejects_a_tcp_target_on_an_internal_host(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // The SSRF guard extracts the host from host:port and blocks RFC1918.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'type' => 'tcp',
            'url' => '10.0.0.5:5432',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_a_tcp_target_with_an_out_of_range_port(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'type' => 'tcp',
            'url' => 'db.example.com:99999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_store_still_rejects_a_scheme_less_host_port_for_http(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // The HTTP branch keeps the strict `url` rule: a bare host:port is not
        // a valid URL and must be rejected.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'type' => 'http',
            'url' => 'db.example.com:5432',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
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

    public function test_store_rejects_an_oversized_bearer_token(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // Every credential field travels inside the HMAC-signed relay spec
        // and was unbounded before ValidatesAuthConfig added a max: rule.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'auth_config' => [
                'type' => 'bearer',
                'token' => str_repeat('a', 3000),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auth_config.token');
    }

    public function test_store_accepts_a_bearer_token_at_the_max_bound(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'auth_config' => [
                'type' => 'bearer',
                'token' => str_repeat('a', 2048),
            ],
        ]);

        $response->assertStatus(201);
        $monitor = Monitor::query()->where('team_id', $team->id)->sole();
        $this->assertSame(2048, strlen((string) $monitor->auth_config['token']));
    }

    public function test_store_rejects_an_oversized_basic_auth_username(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'auth_config' => [
                'type' => 'basic',
                'username' => str_repeat('a', 256),
                'password' => 'secret',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('auth_config.username');
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
     * A valid create payload targeting a public host from a single region.
     *
     * @return array<string, mixed>
     */
    protected function validPayload(): array
    {
        return [
            'name' => 'API Health',
            // 180s is the Free tier's fastest allowed interval and one region is
            // its per-monitor allowance, so the base payload is plan-valid for
            // the default (Free) acting team. Region enforcement itself is
            // covered in MonitorControllerTest.
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 180,
            'timeout_sec' => 30,
            'regions' => [
                'us-east',
            ],
            'expected_status_code' => 200,
        ];
    }
}
