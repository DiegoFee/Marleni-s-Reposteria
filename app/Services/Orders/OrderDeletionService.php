<?php

namespace App\Services\Orders;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderDeletionService
{
    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->delete();
        }, attempts: 3);
    }

    public function forceDelete(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $lockedOrder = Order::withTrashed()
                ->with(['customer', 'payments'])
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            DB::table('order_deletion_audits')->insert([
                'order_number' => $lockedOrder->order_number,
                'actor_user_id' => $actor->getKey(),
                'details' => json_encode([
                    'customer' => [
                        'id' => $lockedOrder->customer?->getKey(),
                        'full_name' => $lockedOrder->customer?->full_name,
                        'phone' => $lockedOrder->customer?->phone,
                    ],
                    'capture_mode' => $lockedOrder->capture_mode->value,
                    'cake_category_id' => $lockedOrder->cake_category_id,
                    'base_price_id' => $lockedOrder->base_price_id,
                    'cake_description' => $lockedOrder->cake_description,
                    'agreed_price' => (string) $lockedOrder->agreed_price,
                    'delivery_at' => $lockedOrder->delivery_at->toIso8601String(),
                    'status' => $lockedOrder->status->value,
                    'payments' => $lockedOrder->payments->map(static fn (Payment $payment): array => [
                        'id' => $payment->getKey(),
                        'amount' => (string) $payment->amount,
                        'payment_type' => $payment->payment_type->value,
                        'paid_at' => $payment->paid_at->toIso8601String(),
                        'status' => $payment->status->value,
                        'voided_at' => $payment->voided_at?->toIso8601String(),
                        'void_reason' => $payment->void_reason,
                        'notes' => $payment->notes,
                    ])->all(),
                ], JSON_THROW_ON_ERROR),
                'deleted_at' => now(),
            ]);

            ActivityLog::query()->where('order_id', $lockedOrder->getKey())->delete();
            Payment::query()->where('order_id', $lockedOrder->getKey())->delete();
            $lockedOrder->forceDelete();
        }, attempts: 3);
    }
}
