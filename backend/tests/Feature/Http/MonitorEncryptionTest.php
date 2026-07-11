<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Monitor;
use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the at-rest encryption of `auth_config` and the fail-closed allowlist
 * the {@see MonitorResource} applies on output.
 *
 * The raw DB column must hold ciphertext (never plaintext secrets), the model
 * cast must round-trip the decrypted array, and the wire shape must emit only
 * the non-secret descriptors (type, username, header name).
 */
class MonitorEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
            Route::get('monitors/{monitor}', [MonitorController::class, 'show']);
        });
    }

    public function test_auth_config_is_encrypted_at_rest_and_never_leaks_the_secret(): void
    {
        $team = $this->actingAsTeamMember();

        $monitor = $this->makeMonitor($team->id, [
            'auth_config' => [
                'type' => 'api_key',
                'key' => 'SECRET',
                'header' => 'X-Api-Key',
            ],
        ]);

        // 1. The raw column holds ciphertext: the plaintext secret is absent.
        $rawColumn = DB::table('monitors')->where('id', $monitor->id)->value('auth_config');
        $this->assertIsString($rawColumn);
        $this->assertStringNotContainsString('SECRET', $rawColumn);

        // 2. The cast transparently decrypts back to the original array.
        $this->assertSame('SECRET', $monitor->fresh()->auth_config['key']);

        // 3. The resource emits only the allowlisted descriptors, no secret.
        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.auth_config.type', 'api_key');
        $response->assertJsonPath('data.auth_config.header', 'X-Api-Key');
        $response->assertJsonMissingPath('data.auth_config.key');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
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
}
