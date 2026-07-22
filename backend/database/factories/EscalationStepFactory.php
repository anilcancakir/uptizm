<?php

namespace Database\Factories;

use App\Enums\EscalationTargetType;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationStep>
 */
class EscalationStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'escalation_policy_id' => EscalationPolicy::factory(),
            'position' => 0,
            'delay_minutes' => fake()->numberBetween(0, 30),
            'target_type' => EscalationTargetType::OnCall,
        ];
    }

    /**
     * Target a specific user instead of the on-call rotation.
     */
    public function targetingUser(string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'target_type' => EscalationTargetType::User,
            'target_id' => $userId,
        ]);
    }
}
