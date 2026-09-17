<?php

namespace Database\Factories;

use App\Enums\ActivityEventType;
use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'payment_id' => null,
            'actor_user_id' => null,
            'event_type' => ActivityEventType::OrderCreated,
            'notification_channel' => null,
            'reminder_window' => null,
            'notification_key' => null,
            'provider_message_id' => null,
            'details' => ['source' => 'test'],
            'created_at' => now(),
        ];
    }
}
