<?php

namespace Database\Factories;

use App\Enums\ServiceStatusSource;
use App\Models\Monitor;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults to an UNPUBLISHABLE service (no terms review, no attached
     * monitor): {@see Service::canPublish()} requires both, and a factory
     * default that silently satisfied one of them would make a plain
     * `Service::factory()->create()` call in a future test look publishable
     * when it is not. Callers that need a publishable row use
     * {@see self::termsReviewed()} and attach a monitor explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'category' => fake()->randomElement([
                'cloud',
                'payments',
                'communication',
                'developer-tools',
            ]),
            'status_source' => ServiceStatusSource::None,
            'display_order' => 0,
        ];
    }

    /**
     * Mark the terms as reviewed: one of the two {@see Service::canPublish()}
     * conditions. The other (an attached monitor) is not a factory state,
     * because it needs a persisted {@see Monitor} the caller
     * must build with its own team.
     */
    public function termsReviewed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'terms_reviewed_at' => now(),
        ]);
    }
}
