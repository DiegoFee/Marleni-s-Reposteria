<?php

namespace Database\Factories;

use App\Models\CakeCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CakeCategory>
 */
class CakeCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
