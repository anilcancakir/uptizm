<?php

namespace Database\Factories;

use App\Models\ScheduledMaintenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledMaintenance>
 */
class ScheduledMaintenanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `team_id` and `status_page_id` have no default: callers pass them
     * explicitly, mirroring the rest of the suite's team-scoped factories.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'title' => fake()->words(3, true).' maintenance',
            'description' => fake()->sentence(),
            'suppress_alerts' => true,
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+2 hours'),
        ];
    }
}
