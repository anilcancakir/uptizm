<?php

namespace Database\Factories;

use App\Enums\NotificationChannelSeverity;
use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `team_id` has no default: callers pass it explicitly, mirroring the
     * rest of the suite's team-scoped factories.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' channel',
            'channel_type' => NotificationChannelType::Webhook,
            'credentials' => [
                'url' => fake()->url(),
                'secret' => Str::random(32),
            ],
            'is_enabled' => true,
            'severity' => NotificationChannelSeverity::All,
        ];
    }

    /**
     * Configure as a Slack channel with a per-team bot token.
     */
    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => NotificationChannelType::Slack,
            'credentials' => [
                'token' => 'xoxb-'.Str::random(24),
                'channel' => '#alerts',
            ],
        ]);
    }

    /**
     * Configure as a generic webhook channel with an HMAC secret.
     */
    public function webhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => NotificationChannelType::Webhook,
            'credentials' => [
                'url' => fake()->url(),
                'secret' => Str::random(32),
            ],
        ]);
    }

    /**
     * Configure as a PagerDuty channel with an Events API v2 routing key.
     */
    public function pagerduty(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => NotificationChannelType::PagerDuty,
            'credentials' => [
                'routing_key' => Str::random(32),
            ],
        ]);
    }

    /**
     * Configure as a Microsoft Teams channel with a Workflows webhook url.
     *
     * The url carries its own `?sig=` SAS token, so it is both the target and
     * the credential (there is no separate signing secret).
     */
    public function teams(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => NotificationChannelType::Teams,
            'credentials' => [
                'url' => 'https://example.com/webhookb2/'.Str::random(16).'?sig='.Str::random(24),
            ],
        ]);
    }
}
