<?php

namespace Database\Factories;

use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => fake()->unique()->bothify('ORD-####-????'),
            'customer_id' => Customer::factory(),
            'created_by' => User::factory(),
            'capture_mode' => CaptureMode::Standard,
            'cake_category_id' => CakeCategory::factory(),
            'base_price_id' => BasePrice::factory(),
            'cake_description' => null,
            'agreed_price' => '150.00',
            'delivery_at' => now()->addDay(),
            'status' => OrderStatus::Pending,
        ];
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes): array => [
            'capture_mode' => CaptureMode::Custom,
            'cake_category_id' => null,
            'base_price_id' => null,
            'cake_description' => fake()->sentence(4),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Delivered,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Cancelled,
        ]);
    }
}
