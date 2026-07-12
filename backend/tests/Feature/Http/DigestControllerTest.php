<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorType;
use App\Jobs\GenerateWeeklyDigest;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\TeamDigest;
use App\Models\User;
use App\Services\Ai\DigestGateway;
use App\Services\Ai\DigestPayload;
use App\Services\Ai\DigestResult;
use App\Services\Ai\FakeDigestGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers GET /api/v1/incidents/digest: the weekly-digest read endpoint.
 *
 * The digest is always generated ahead of time by
 * {@see GenerateWeeklyDigest}; the endpoint only reads the latest persisted
 * {@see TeamDigest} row and never calls the LLM synchronously
 * from the request, which the throwing-gateway test proves directly.
 */
class DigestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_returns_the_latest_persisted_digest_for_the_team(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);
        [$team, $user] = $this->makeTeam();
        GenerateWeeklyDigest::dispatchSync((string) $team->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/incidents/digest');

        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.summary',
            'Deterministic digest stub: uptime held steady with no notable regressions this week.',
        );
        $response->assertJsonPath('data.confidence', 'medium');
        $response->assertJsonPath('data.incident_count', 0);
    }

    public function test_digest_never_calls_the_llm_synchronously_from_the_request(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);
        [$team, $user] = $this->makeTeam();
        GenerateWeeklyDigest::dispatchSync((string) $team->id);

        // Rebind to a throwing gateway AFTER the digest is already persisted:
        // if the endpoint ever ran the digest inline, this request would 500.
        $this->app->instance(DigestGateway::class, new class implements DigestGateway
        {
            public function summarize(DigestPayload $payload): DigestResult
            {
                throw new RuntimeException('The LLM must never be called from the read endpoint.');
            }
        });

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/incidents/digest');

        $response->assertStatus(200);
    }

    public function test_digest_404s_a_team_with_no_generated_digest_yet(): void
    {
        [, $user] = $this->makeTeam();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/incidents/digest');

        $response->assertStatus(404);
    }

    public function test_digest_is_team_scoped(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);
        [$teamA] = $this->makeTeam();
        [$teamB, $userB] = $this->makeTeam();
        GenerateWeeklyDigest::dispatchSync((string) $teamA->id);

        $response = $this->actingAs($userB, 'sanctum')->getJson('/api/v1/incidents/digest');

        $response->assertStatus(404);
    }

    public function test_digest_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/incidents/digest');

        $response->assertStatus(401);
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function makeTeam(): array
    {
        $user = User::query()->create([
            'name' => 'Digest Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Digest Team',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$team, $user];
    }
}
