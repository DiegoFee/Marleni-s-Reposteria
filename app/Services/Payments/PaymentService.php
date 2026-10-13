<?php

namespace App\Services\Payments;

use App\Enums\ActivityEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\MoneyValidation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * Funcion: register()
     *
     * Descripcion: Registra el pago y su actividad en una transaccion para evitar movimientos sin auditoria.
     *
     * Version: 1.0
     *
     * @param  array<string, mixed>  $data
     */
    public function register(Order $order, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($order, $data, $actor): Payment {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('registerPayment', $lockedOrder);

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'payment' => 'Los pedidos entregados o cancelados ya no aceptan nuevos pagos.',
                ]);
            }

            $amount = MoneyValidation::requiredPositiveAmount($data['amount'] ?? null, 'paymentAmount');

            $registeredTotal = $this->registeredTotal($lockedOrder);
            $agreedPrice = $this->money($lockedOrder->agreed_price);
            $newTotal = $registeredTotal->plus($amount);

            if ($newTotal->isGreaterThan($agreedPrice)) {
                throw ValidationException::withMessages([
                    'paymentAmount' => trans('validation.custom.paymentAmount.balance'),
                ]);
            }

            $paymentType = $this->paymentType($registeredTotal, $amount, $agreedPrice);
            $payment = $lockedOrder->payments()->create([
                'registered_by' => $actor->getKey(),
                'amount' => $amount->toScale(2)->toString(),
                'payment_type' => $paymentType,
                'paid_at' => $data['paid_at'] ?? now(),
                'status' => PaymentStatus::Registered,
                'notes' => blank($data['notes'] ?? null) ? null : trim((string) $data['notes']),
            ]);

            $lockedOrder->activityLogs()->create([
                'payment_id' => $payment->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => ActivityEventType::PaymentRegistered,
                'details' => array_filter([
                    'amount' => $amount->toScale(2)->toString(),
                    'payment_type' => $paymentType->value,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'notes' => $payment->notes,
                ], static fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            return $payment->fresh();
        }, attempts: 3);
    }

    /**
     * Funcion: void()
     *
     * Descripcion: Marca el pago como anulado sin borrarlo para conservar el monto y el motivo en el historial.
     *
     * Version: 1.0
     */
    public function void(Payment $payment, string $reason, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $paymentReference = Payment::query()
                ->whereKey($payment->getKey())
                ->firstOrFail();
            $lockedOrder = Order::query()
                ->whereKey($paymentReference->order_id)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('voidPayment', $lockedOrder);
            $lockedPayment = Payment::query()
                ->whereKey($paymentReference->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $trimmedReason = trim($reason);

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'payment' => 'Los pagos de pedidos entregados o cancelados ya no se pueden modificar.',
                ]);
            }

            if ($lockedPayment->status !== PaymentStatus::Registered) {
                throw ValidationException::withMessages([
                    'payment' => 'El pago seleccionado ya fue anulado.',
                ]);
            }

            if ($trimmedReason === '') {
                throw ValidationException::withMessages([
                    'voidReason' => trans('validation.custom.voidReason.required'),
                ]);
            }

            $voidedAt = now();
            $lockedPayment->update([
                'status' => PaymentStatus::Voided,
                'voided_at' => $voidedAt,
                'voided_by' => $actor->getKey(),
                'void_reason' => $trimmedReason,
            ]);

            $lockedOrder->activityLogs()->create([
                'payment_id' => $lockedPayment->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => ActivityEventType::PaymentVoided,
                'details' => [
                    'amount' => (string) $lockedPayment->amount,
                    'payment_type' => $lockedPayment->payment_type->value,
                    'voided_at' => $voidedAt->toIso8601String(),
                    'void_reason' => $trimmedReason,
                ],
                'created_at' => $voidedAt,
            ]);

            return $lockedPayment->fresh();
        }, attempts: 3);
    }

    private function registeredTotal(Order $order): BigDecimal
    {
        return $this->money(
            Payment::query()
                ->where('order_id', $order->getKey())
                ->where('status', PaymentStatus::Registered->value)
                ->sum('amount'),
        );
    }

    private function paymentType(BigDecimal $registeredTotal, BigDecimal $amount, BigDecimal $agreedPrice): PaymentType
    {
        if ($registeredTotal->isEqualTo('0')) {
            return PaymentType::Deposit;
        }

        return $registeredTotal->plus($amount)->isEqualTo($agreedPrice)
            ? PaymentType::Settlement
            : PaymentType::Partial;
    }

    private function money(mixed $amount): BigDecimal
    {
        return BigDecimal::of(blank($amount) ? '0' : (string) $amount);
    }
}
