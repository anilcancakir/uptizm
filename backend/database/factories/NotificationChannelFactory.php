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
}
