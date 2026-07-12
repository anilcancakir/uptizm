<?php

namespace Database\Factories;

use App\Models\OnCallOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnCallOverride>
 */
class OnCallOverrideFactory extends Factory
{
    /**
     * @var class-string<OnCallOverride>
     */
    protected $model = OnCallOverride::class;

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
            'starts_at' => now(),
            'ends_at' => now()->addHours(4),
        ];
    }
}
