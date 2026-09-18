<?php

namespace App\Services\Orders;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
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

    public function forceDelete(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::withTrashed()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            ActivityLog::query()->where('order_id', $lockedOrder->getKey())->delete();
            Payment::query()->where('order_id', $lockedOrder->getKey())->delete();
            $lockedOrder->forceDelete();
        }, attempts: 3);
    }
}
