<?php

namespace Database\Factories;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorContentVersion>
 */
class MonitorContentVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Neither {@see Monitor} nor {@see Team} expose a working `factory()`
     * (no `MonitorFactory`/`TeamFactory` class backs their `HasFactory` use,
     * see `EscalationPolicyFactory`'s equally unexercised `Team::factory()`),
     * so the relations are built the same way the rest of the test suite
     * builds them: `User::factory()` (the one model here that DOES have a
     * factory) owning a `Team::query()->create()`, in turn owning a
     * `Monitor::query()->create()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => fake()->company(),
        ]);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => fake()->words(3, true).' monitor',
            'type' => MonitorType::Http,
            'url' => fake()->url(),
            'check_interval_sec' => 60,
        ]);

        // A real body so `byte_size` is the actual raw decoded length, not a
        // number disconnected from the hashes derived from it.
        $body = fake()->paragraphs(3, true);

        return [
            'monitor_id' => $monitor->id,
            'team_id' => $team->id,
            'content_hash' => hash('sha256', $body),
            'content_hash_normalized' => hash('sha256', preg_replace('/\s+/', ' ', $body)),
            'byte_size' => strlen($body),
            'content_type' => 'text/html',
            'truncated' => false,
            'normalizer_version' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
