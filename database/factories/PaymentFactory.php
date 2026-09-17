<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'registered_by' => User::factory(),
            'amount' => '50.00',
            'payment_type' => PaymentType::Deposit,
            'paid_at' => now(),
            'status' => PaymentStatus::Registered,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
            'notes' => null,
        ];
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_type' => PaymentType::Partial,
        ]);
    }

    public function settlement(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_type' => PaymentType::Settlement,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Voided,
            'voided_at' => now(),
            'voided_by' => User::factory(),
            'void_reason' => fake()->sentence(6),
        ]);
    }
}
