<?php

namespace Tests\Feature\Broadcasting;

use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proves the Sanctum-bearer private-channel authorization end-to-end against
 * the REAL registered `api/v1/broadcasting/auth` route (routes/channels.php
 * `teams.{teamId}` callback), not a faked broadcast manager. A wrong channel
 * callback would let one team subscribe to another team's realtime incident
 * stream, so this is the load-bearing tenant-isolation gate.
 */
class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The default `BROADCAST_CONNECTION` for the test environment (see
     * `phpunit.xml`) is `null`, whose broadcaster is an intentional no-op:
     * `NullBroadcaster::auth()` never invokes the registered channel
     * callback, so it would let this test pass even with a broken (or
     * missing) authorization rule. `withBroadcasting()` registers the
     * `routes/channels.php` callbacks on whichever driver is default AT BOOT
     * TIME, so the override must land before the application boots.
     * Switching to `reverb` (Pusher-protocol signing, no network call) here
     * forces the real `PusherBroadcaster::auth()` path, which does invoke
     * the `teams.{teamId}` callback and enforces `belongsToTeam()`.
     */
    protected function setUp(): void
    {
        putenv('BROADCAST_CONNECTION=reverb');
        $_ENV['BROADCAST_CONNECTION'] = 'reverb';
        $_SERVER['BROADCAST_CONNECTION'] = 'reverb';

        parent::setUp();
    }

    /**
     * A team member authorizing their OWN team's private channel gets a 200
     * with a non-empty `auth` signature, proving the route, the `sanctum`
     * guard, and the `belongsToTeam` channel callback all line up.
     */
    public function test_team_member_authorizes_own_team_channel(): void
    {
        $user = User::factory()->create();
        $team = $this->createTeamFor($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-teams.{$team->id}",
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('auth'));
    }

    /**
     * The same user authorizing ANOTHER team's channel (a team they do not
     * belong to) gets a 403, proving tenant isolation in the channel
     * callback.
     */
    public function test_team_member_cannot_authorize_another_teams_channel(): void
    {
        $user = User::factory()->create();
        $this->createTeamFor($user);

        $otherOwner = User::factory()->create();
        $otherTeam = $this->createTeamFor($otherOwner);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-teams.{$otherTeam->id}",
        ]);

        $response->assertStatus(403);
    }

    /**
     * An unauthenticated request to the broadcasting auth route is rejected.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $team = $this->createTeamFor(User::factory()->create());

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-teams.{$team->id}",
        ]);

        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    /**
     * Persist a personal team owned by the given user, matching the
     * magic-starter team creation shape used across the test suite.
     */
    protected function createTeamFor(User $user): Team
    {
        return Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
    }
}
