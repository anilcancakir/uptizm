<?php

namespace Tests\Unit\Models;

use App\Enums\NotificationChannelSeverity;
use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see NotificationChannel} shape: team-scoped, `channel_type`
 * and `severity` cast to their enums, and `credentials` encrypted at rest
 * (ciphertext in the column, decrypted array through the model).
 */
class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_slack_factory_state_casts_channel_type_to_enum(): void
    {
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $this->makeTeam()->id,
        ]);

        $this->assertSame(NotificationChannelType::Slack, $channel->channel_type);
    }

    public function test_credentials_decrypt_through_the_cast_but_are_ciphertext_in_the_column(): void
    {
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $this->makeTeam()->id,
            'credentials' => ['token' => 'x'],
        ]);

        $this->assertSame('x', $channel->credentials['token']);

        $rawColumn = (string) DB::table('notification_channels')
            ->where('id', $channel->id)
            ->value('credentials');

        $this->assertStringNotContainsString('"token":"x"', $rawColumn);
        // Ciphertext is not valid JSON; a plaintext leak would decode cleanly.
        $this->assertNull(json_decode($rawColumn, true));
    }

    public function test_belongs_to_its_team(): void
    {
        $team = $this->makeTeam();
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
        ]);

        $this->assertTrue($channel->team->is($team));
    }

    public function test_webhook_factory_state_stores_url_and_secret(): void
    {
        $channel = NotificationChannel::factory()->webhook()->create([
            'team_id' => $this->makeTeam()->id,
        ]);

        $this->assertSame(NotificationChannelType::Webhook, $channel->channel_type);
        $this->assertArrayHasKey('url', $channel->credentials);
        $this->assertArrayHasKey('secret', $channel->credentials);
    }

    public function test_defaults_are_enabled_and_severity_all(): void
    {
        $channel = NotificationChannel::factory()->slack()->create([
            'team_id' => $this->makeTeam()->id,
        ]);

        $this->assertTrue($channel->is_enabled);
        $this->assertSame(NotificationChannelSeverity::All, $channel->severity);
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Notification Channel Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Notification Channel Team',
        ]);
    }
}
