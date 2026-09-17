<?php

namespace App\Services\Payments;

use App\Enums\ActivityEventType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private const MAX_MONEY = '99999999.99';

    /**
     * Registra un pago y su auditoria con el pedido bloqueado.
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
            $amount = $this->money($data['amount'] ?? null);

            if ($amount->isLessThanOrEqualTo(0) || $amount->isGreaterThan(self::MAX_MONEY)) {
                throw ValidationException::withMessages([
                    'paymentAmount' => 'El importe debe estar entre 0.01 y 99,999,999.99.',
                ]);
            }

            $registeredTotal = $this->registeredTotal($lockedOrder);
            $agreedPrice = $this->money($lockedOrder->agreed_price);
            $newTotal = $registeredTotal->plus($amount);

            if ($newTotal->isGreaterThan($agreedPrice)) {
                throw ValidationException::withMessages([
                    'paymentAmount' => 'El pago no puede superar el saldo pendiente.',
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
     * Anula un pago conservando su registro y dejando evidencia del motivo.
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
            $lockedPayment = Payment::query()
                ->whereKey($paymentReference->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $trimmedReason = trim($reason);

            if ($lockedPayment->status !== PaymentStatus::Registered) {
                throw ValidationException::withMessages([
                    'payment' => 'El pago seleccionado ya fue anulado.',
                ]);
            }

            if ($trimmedReason === '') {
                throw ValidationException::withMessages([
                    'voidReason' => 'Indica el motivo de la anulacion.',
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
