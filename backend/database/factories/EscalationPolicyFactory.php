<?php

namespace Database\Factories;

use App\Models\EscalationPolicy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationPolicy>
 */
class EscalationPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true).' escalation policy',
        ];
    }
}
