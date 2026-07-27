<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\NotificationChannelController;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see NotificationChannelController}'s team-scoped CRUD, the 404-mask
 * on cross-team access, the credential masking the Resource enforces (a raw
 * token/secret never travels back), the webhook-url SSRF guard at store time,
 * and the honest `POST .../test` result (a downstream failure reports failure,
 * not a false success). Routes are the real `api/v1/notification-channels`
 * surface registered in `routes/api.php`.
 */
class NotificationChannelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_slack_channel_for_the_current_team(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Ops Slack',
            'channel_type' => 'slack',
            'credentials' => [
                'token' => 'xoxb-super-secret-token',
                'channel' => '#alerts',
            ],
            'severity' => 'all',
            'is_enabled' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Ops Slack');
        $response->assertJsonPath('data.channel_type', 'slack');
        $response->assertJsonPath('data.team_id', $team->id);

        // The raw token must never travel back to the client, in any field.
        $this->assertStringNotContainsString('xoxb-super-secret-token', $response->getContent());
        $response->assertJsonMissingPath('data.credentials.token');
        $response->assertJsonPath('data.credentials.has_token', true);

        $this->assertDatabaseHas('notification_channels', [
            'team_id' => $team->id,
            'name' => 'Ops Slack',
            'channel_type' => 'slack',
        ]);
    }

    public function test_store_returns_the_schema_defaults_when_severity_is_omitted(): void
    {
        // Regression: `severity` and `is_enabled` are `sometimes` in the request
        // and default in the SCHEMA, so a create that omits them left those
        // attributes absent on the in-memory model. The resource reads
        // `severity->value`, so the response raised a 500 on a channel that HAD
        // been created: the client reported a failed save for a channel that
        // then showed up in its own list. Every other store test sends both
        // fields explicitly, which is exactly why this went unnoticed.
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Defaults Slack',
            'channel_type' => 'slack',
            'credentials' => [
                'token' => 'xoxb-super-secret-token',
                'channel' => '#alerts',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.severity', 'all');
        $response->assertJsonPath('data.is_enabled', true);

        $this->assertDatabaseHas('notification_channels', [
            'team_id' => $team->id,
            'name' => 'Defaults Slack',
            'severity' => 'all',
        ]);
    }

    public function test_store_rejects_a_channel_type_outside_the_enum(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Mystery',
            'channel_type' => 'carrier-pigeon',
            'credentials' => [
                'token' => 'x',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('channel_type');
    }

    public function test_store_rejects_a_slack_channel_missing_its_token(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Tokenless',
            'channel_type' => 'slack',
            'credentials' => [
                'channel' => '#alerts',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credentials.token');
    }

    public function test_store_rejects_a_webhook_url_that_targets_an_internal_host(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Bad Hook',
            'channel_type' => 'webhook',
            'credentials' => [
                'url' => 'https://169.254.169.254/hook',
                'secret' => 'signing-secret',
            ],
            'severity' => 'all',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credentials.url');
        $this->assertDatabaseCount('notification_channels', 0);
    }

    public function test_store_rejects_a_non_https_webhook_url(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Insecure Hook',
            'channel_type' => 'webhook',
            'credentials' => [
                'url' => 'http://example.com/hook',
                'secret' => 'signing-secret',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credentials.url');
    }

    public function test_store_accepts_an_allowed_https_webhook_url(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Deploy Hook',
            'channel_type' => 'webhook',
            'credentials' => [
                'url' => 'https://example.com/hook',
                'secret' => 'top-secret-signing-value',
            ],
            'severity' => 'critical',
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString('top-secret-signing-value', $response->getContent());
        $response->assertJsonPath('data.credentials.has_secret', true);

        $this->assertDatabaseHas('notification_channels', [
            'team_id' => $team->id,
            'name' => 'Deploy Hook',
            'channel_type' => 'webhook',
            'severity' => 'critical',
        ]);
    }

    public function test_store_creates_a_teams_channel_and_masks_the_url_to_the_host(): void
    {
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Ops Teams',
            'channel_type' => 'teams',
            'credentials' => [
                'url' => 'https://example.com/webhookb2/abc?sig=super-secret-sas',
            ],
            'severity' => 'all',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.channel_type', 'teams');

        // The Workflows url carries a ?sig= SAS secret: only the host may travel
        // back, never the full url.
        $this->assertStringNotContainsString('super-secret-sas', $response->getContent());
        $response->assertJsonMissingPath('data.credentials.url');
        $response->assertJsonPath('data.credentials.has_url', true);
        $response->assertJsonPath('data.credentials.url_host', 'example.com');

        $this->assertDatabaseHas('notification_channels', [
            'team_id' => $team->id,
            'name' => 'Ops Teams',
            'channel_type' => 'teams',
        ]);
    }

    public function test_store_rejects_a_teams_url_that_targets_an_internal_host(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Bad Teams',
            'channel_type' => 'teams',
            'credentials' => [
                'url' => 'https://169.254.169.254/webhook',
            ],
            'severity' => 'all',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credentials.url');
        $this->assertDatabaseCount('notification_channels', 0);
    }

    public function test_store_rejects_a_teams_channel_missing_its_url(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/notification-channels', [
            'name' => 'Urlless Teams',
            'channel_type' => 'teams',
            'credentials' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credentials.url');
    }

    public function test_show_masks_teams_credentials_to_the_host_only(): void
    {
        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->teams()->create([
            'team_id' => $team->id,
            'credentials' => [
                'url' => 'https://example.com/webhookb2/abc?sig=super-secret-sas',
            ],
        ]);

        $response = $this->getJson("/api/v1/notification-channels/{$channel->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('super-secret-sas', $response->getContent());
        $response->assertJsonPath('data.credentials.url_host', 'example.com');
        $response->assertJsonMissingPath('data.credentials.url');
    }

    public function test_index_lists_only_the_current_teams_channels(): void
    {
        $team = $this->actingAsTeamMember();
        NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'name' => 'Mine',
        ]);

        $foreignTeam = $this->makeForeignTeam();
        NotificationChannel::factory()->slack()->create([
            'team_id' => $foreignTeam->id,
            'name' => 'Theirs',
        ]);

        $response = $this->getJson('/api/v1/notification-channels');

        $response->assertStatus(200);
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_index_reports_push_as_provisioned_when_an_app_id_is_configured(): void
    {
        config(['magic-starter.onesignal.app_id' => 'onesignal-app-id']);

        $this->actingAsTeamMember();

        $response = $this->getJson('/api/v1/notification-channels');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.push_provisioned', true);
    }

    public function test_index_reports_push_as_not_provisioned_when_the_app_id_is_empty(): void
    {
        config(['magic-starter.onesignal.app_id' => null]);

        $this->actingAsTeamMember();

        $response = $this->getJson('/api/v1/notification-channels');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.push_provisioned', false);
    }

    public function test_show_masks_webhook_credentials(): void
    {
        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
            'credentials' => [
                'url' => 'https://example.com/hook',
                'secret' => 'top-secret-value',
            ],
        ]);

        $response = $this->getJson("/api/v1/notification-channels/{$channel->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('top-secret-value', $response->getContent());
        $response->assertJsonPath('data.credentials.has_secret', true);
        $response->assertJsonMissingPath('data.credentials.secret');
    }

    public function test_show_masks_a_cross_team_channel_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $foreignTeam->id,
        ]);

        $this->getJson("/api/v1/notification-channels/{$channel->id}")->assertStatus(404);
    }

    public function test_update_changes_the_channel(): void
    {
        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'name' => 'Old Name',
        ]);

        $response = $this->putJson("/api/v1/notification-channels/{$channel->id}", [
            'name' => 'New Name',
            'is_enabled' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'New Name');
        $response->assertJsonPath('data.is_enabled', false);
    }

    public function test_update_masks_a_cross_team_channel_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $foreignTeam->id,
            'name' => 'Theirs',
        ]);

        $response = $this->putJson("/api/v1/notification-channels/{$channel->id}", [
            'name' => 'Hijacked',
        ]);

        $response->assertStatus(404);
        $this->assertNotSame('Hijacked', $channel->fresh()->name);
    }

    public function test_destroy_deletes_the_channel(): void
    {
        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->deleteJson("/api/v1/notification-channels/{$channel->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);
    }

    public function test_destroy_masks_a_cross_team_channel_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = $this->makeForeignTeam();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $foreignTeam->id,
        ]);

        $response = $this->deleteJson("/api/v1/notification-channels/{$channel->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('notification_channels', ['id' => $channel->id]);
    }

    public function test_test_send_reports_success_when_the_downstream_post_succeeds(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'credentials' => [
                'token' => 'xoxb-team-token',
                'channel' => '#alerts',
            ],
        ]);

        $response = $this->postJson("/api/v1/notification-channels/{$channel->id}/test");

        $response->assertStatus(200);
        $response->assertJsonPath('data.delivered', true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://slack.com/api/chat.postMessage');
    }

    public function test_test_send_reports_failure_when_the_downstream_post_fails(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'invalid_auth'], 200),
        ]);

        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'credentials' => [
                'token' => 'xoxb-bad-token',
                'channel' => '#alerts',
            ],
        ]);

        $response = $this->postJson("/api/v1/notification-channels/{$channel->id}/test");

        $response->assertStatus(502);
        $response->assertJsonPath('data.delivered', false);
    }

    public function test_test_send_reports_failure_on_a_webhook_3xx_response(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 301),
        ]);

        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
            'credentials' => [
                'url' => 'https://example.com/hook',
                'secret' => 'top-secret-signing-value',
            ],
        ]);

        $response = $this->postJson("/api/v1/notification-channels/{$channel->id}/test");

        $response->assertStatus(502);
        $response->assertJsonPath('data.delivered', false);
    }

    public function test_test_send_reports_failure_when_the_webhook_target_is_ssrf_blocked(): void
    {
        Http::fake();

        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
            'credentials' => [
                'url' => 'https://169.254.169.254/hook',
                'secret' => 'top-secret-signing-value',
            ],
        ]);

        $response = $this->postJson("/api/v1/notification-channels/{$channel->id}/test");

        $response->assertStatus(502);
        $response->assertJsonPath('data.delivered', false);
        Http::assertNothingSent();
    }

    public function test_test_send_reports_failure_when_credentials_are_empty(): void
    {
        Http::fake();

        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'credentials' => [
                'token' => '',
                'channel' => '#alerts',
            ],
        ]);

        $response = $this->postJson("/api/v1/notification-channels/{$channel->id}/test");

        $response->assertStatus(502);
        $response->assertJsonPath('data.delivered', false);
        Http::assertNothingSent();
    }

    public function test_test_send_delivers_a_teams_adaptive_card(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->teams()->create([
            'team_id' => $team->id,
            'credentials' => [
                'url' => 'https://example.com/webhookb2/abc?sig=super-secret-sas',
            ],
        ]);

        $response = $this->postJson("/api/v1/notification-channels/{$channel->id}/test");

        $response->assertStatus(200);
        $response->assertJsonPath('data.delivered', true);
        Http::assertSent(fn ($request): bool => $request['type'] === 'message');
    }

    public function test_test_send_masks_a_cross_team_channel_as_404(): void
    {
        Http::fake();

        $this->actingAsTeamMember();
        $foreignTeam = $this->makeForeignTeam();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $foreignTeam->id,
        ]);

        $this->postJson("/api/v1/notification-channels/{$channel->id}/test")->assertStatus(404);
        Http::assertNothingSent();
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
     * Build a persisted foreign team, owned by a fresh user, unrelated to the
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
}
