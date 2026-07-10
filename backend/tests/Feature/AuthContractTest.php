<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the phase-1 api/v1 contract consumed 1:1 by the uptizm Flutter
 * client (magic_starter): register, login, fetch the authenticated user,
 * create a team, switch the active team, and list sessions.
 */
class AuthContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exercises the full auth + team contract in one flow, asserting the
     * `{data, ...}` envelope and status code at every step.
     */
    public function test_register_login_team_and_session_flow(): void
    {
        // 1. Register a new user.
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Contract Tester',
            'email' => 'contract-tester@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $registerResponse->assertStatus(201);
        $registerResponse->assertJsonPath('data.user.email', 'contract-tester@example.com');
        $this->assertIsString($registerResponse->json('data.token'));

        // 2. Log in with the same credentials.
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'contract-tester@example.com',
            'password' => 'Password123',
        ]);

        $loginResponse->assertStatus(200);
        $this->assertIsString($loginResponse->json('data.token'));

        $token = $loginResponse->json('data.token');

        // 3. Fetch the authenticated user with the bearer token.
        $userResponse = $this->withToken($token)->getJson('/api/v1/auth/user');

        $userResponse->assertStatus(200);
        $userId = $userResponse->json('data.id');
        $this->assertIsString($userId);
        $userResponse->assertJsonPath('data.email', 'contract-tester@example.com');

        // 4. Create a team.
        $teamResponse = $this->withToken($token)->postJson('/api/v1/teams', [
            'name' => 'Contract Team',
        ]);

        $teamResponse->assertStatus(201);
        $teamResponse->assertJsonPath('data.name', 'Contract Team');
        $teamId = $teamResponse->json('data.id');
        $this->assertIsString($teamId);

        // 5. Switch the active team to the newly created team.
        $switchResponse = $this->withToken($token)->putJson('/api/v1/user/current-team', [
            'team_id' => $teamId,
        ]);

        $switchResponse->assertStatus(200);
        $switchResponse->assertJsonPath('data.current_team.id', $teamId);

        // 6. List active sessions.
        $sessionsResponse = $this->withToken($token)->getJson('/api/v1/sessions');

        $sessionsResponse->assertStatus(200);
        $this->assertIsArray($sessionsResponse->json('data'));
    }
}
