<?php

namespace Database\Factories;

use App\Models\OnCallRotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnCallRotation>
 */
class OnCallRotationFactory extends Factory
{
    /**
     * @var class-string<OnCallRotation>
     */
    protected $model = OnCallRotation::class;

    /**
     * Define the model's default state.
     *
     * `schedule_id`/`user_id` have no default: callers pass them explicitly,
     * mirroring the rest of the suite's relation-scoped factories.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'position' => fake()->unique()->numberBetween(0, 1000),
            'shift_hours' => 24,
        ];
    }
}
