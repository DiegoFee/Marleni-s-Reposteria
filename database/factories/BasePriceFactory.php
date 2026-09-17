<?php

namespace Database\Factories;

use App\Models\BasePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BasePrice>
 */
class BasePriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'amount' => fake()->unique()->randomFloat(2, 1, 9999),
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
