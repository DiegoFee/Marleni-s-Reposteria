<?php

namespace App\Services\Orders;

use App\Enums\ActivityEventType;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderDeletionService
{
    /**
     * Funcion: delete()
     *
     * Descripcion: Archiva el pedido y, si esta pendiente, lo cancela sin borrar sus pagos ni su actividad.
     *
     * Version: 1.0
     */
    public function delete(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $lockedOrder);

            // -----------------------------------------------------------
            // Logica critica: Registra la cancelacion antes del archivo para conservar el cambio en el historial del pedido.
            // -----------------------------------------------------------
            if ($lockedOrder->status === OrderStatus::Pending) {
                $lockedOrder->status = OrderStatus::Cancelled;
                $lockedOrder->save();

                $lockedOrder->activityLogs()->create([
                    'actor_user_id' => $actor->getKey(),
                    'event_type' => ActivityEventType::OrderStatusChanged,
                    'details' => [
                        'from' => OrderStatus::Pending->value,
                        'to' => OrderStatus::Cancelled->value,
                        'reason' => 'retired_to_history',
                    ],
                    'created_at' => now(),
                ]);
            }

            $lockedOrder->delete();
        }, attempts: 3);
    }

    /**
     * Funcion: forceDelete()
     *
     * Descripcion: Guarda una copia auditable y elimina definitivamente el pedido y sus registros relacionados en una transaccion.
     *
     * Version: 1.0
     */
    public function forceDelete(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $lockedOrder = Order::withTrashed()
                ->with(['customer', 'payments'])
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('forceDelete', $lockedOrder);

            // -----------------------------------------------------------
            // Logica critica: Guarda la evidencia antes de borrar pagos y actividad; la transaccion evita una eliminacion parcial.
            // -----------------------------------------------------------
            DB::table('order_deletion_audits')->insert([
                'order_number' => $lockedOrder->order_number,
                'actor_user_id' => $actor->getKey(),
                'details' => json_encode([
                    'customer' => [
                        'id' => $lockedOrder->customer?->getKey(),
                        'full_name' => $lockedOrder->customer?->full_name,
                        'phone' => $lockedOrder->customer?->phoneFormatted(),
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
