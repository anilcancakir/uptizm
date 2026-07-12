<?php

namespace Database\Factories;

use App\Models\OnCallSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnCallSchedule>
 */
class OnCallScheduleFactory extends Factory
{
    /**
     * @var class-string<OnCallSchedule>
     */
    protected $model = OnCallSchedule::class;

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
            'name' => fake()->words(2, true).' schedule',
            'timezone' => 'UTC',
        ];
    }
}
