<?php

namespace App\Services\Orders;

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Crea un pedido, su anticipo inicial y la auditoria en una sola transaccion.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Order
    {
        return DB::transaction(function () use ($data, $actor): Order {
            $captureMode = $this->captureMode($data['capture_mode']);
            $agreedPrice = $this->money($data['agreed_price']);
            $depositAmount = $this->money($data['deposit_amount'] ?? '0');

            $this->validateCatalogSelection($captureMode, $data);

            if ($agreedPrice->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([
                    'agreedPrice' => 'El precio pactado debe ser mayor que cero.',
                ]);
            }

            if ($depositAmount->isNegative() || $depositAmount->isGreaterThan($agreedPrice)) {
                throw ValidationException::withMessages([
                    'depositAmount' => 'El anticipo no puede superar el precio pactado.',
                ]);
            }

            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $data['customer_id'],
                'created_by' => $actor->id,
                'capture_mode' => $captureMode,
                'cake_category_id' => $captureMode === CaptureMode::Standard ? $data['cake_category_id'] : null,
                'base_price_id' => $captureMode === CaptureMode::Standard ? $data['base_price_id'] : null,
                'cake_description' => $captureMode === CaptureMode::Custom ? trim((string) $data['cake_description']) : null,
                'agreed_price' => $agreedPrice->toScale(2)->toString(),
                'delivery_at' => $data['delivery_at'],
                'status' => OrderStatus::Pending,
            ]);

            $this->recordActivity(
                order: $order,
                actor: $actor,
                eventType: ActivityEventType::OrderCreated,
                details: $this->orderDetails($order, $depositAmount->toScale(2)->toString()),
            );

            if ($depositAmount->isPositive()) {
                $payment = $order->payments()->create([
                    'registered_by' => $actor->id,
                    'amount' => $depositAmount->toScale(2)->toString(),
                    'payment_type' => PaymentType::Deposit,
                    'paid_at' => now(),
                    'status' => PaymentStatus::Registered,
                ]);

                $this->recordActivity(
                    order: $order,
                    payment: $payment,
                    actor: $actor,
                    eventType: ActivityEventType::PaymentRegistered,
                    details: [
                        'amount' => $depositAmount->toScale(2)->toString(),
                        'payment_type' => PaymentType::Deposit->value,
                    ],
                );
            }

            return $order->fresh(['customer', 'cakeCategory', 'basePrice', 'payments', 'activityLogs']);
        }, attempts: 3);
    }

    /**
     * Actualiza un pedido y audita los cambios relevantes dentro de una transaccion.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data, User $actor): Order
    {
        return DB::transaction(function () use ($order, $data, $actor): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $captureMode = $this->captureMode($data['capture_mode']);
            $agreedPrice = $this->money($data['agreed_price']);
            $status = $data['status'] instanceof OrderStatus
                ? $data['status']
                : OrderStatus::from((string) $data['status']);
            $registeredPayments = $this->money(
                Payment::query()
                    ->where('order_id', $lockedOrder->getKey())
                    ->where('status', PaymentStatus::Registered->value)
                    ->sum('amount'),
            );

            $this->validateCatalogSelection($captureMode, $data);

            if ($agreedPrice->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([
                    'agreedPrice' => 'El precio pactado debe ser mayor que cero.',
                ]);
            }

            if ($agreedPrice->isLessThan($registeredPayments)) {
                throw ValidationException::withMessages([
                    'agreedPrice' => 'El precio pactado no puede ser menor que los pagos activos.',
                ]);
            }

            $oldDetails = $this->orderDetails($lockedOrder);

            $lockedOrder->fill([
                'customer_id' => $data['customer_id'],
                'capture_mode' => $captureMode,
                'cake_category_id' => $captureMode === CaptureMode::Standard ? $data['cake_category_id'] : null,
                'base_price_id' => $captureMode === CaptureMode::Standard ? $data['base_price_id'] : null,
                'cake_description' => $captureMode === CaptureMode::Custom ? trim((string) $data['cake_description']) : null,
                'agreed_price' => $agreedPrice->toScale(2)->toString(),
                'delivery_at' => $data['delivery_at'],
                'status' => $status,
            ]);

            if (! $lockedOrder->isDirty()) {
                return $lockedOrder->fresh(['customer', 'cakeCategory', 'basePrice', 'payments', 'activityLogs']);
            }

            $lockedOrder->save();

            $changes = array_values(array_intersect(array_keys($lockedOrder->getChanges()), [
                'customer_id',
                'capture_mode',
                'cake_category_id',
                'base_price_id',
                'cake_description',
                'agreed_price',
                'delivery_at',
            ]));

            if ($changes !== []) {
                $this->recordActivity(
                    order: $lockedOrder,
                    actor: $actor,
                    eventType: ActivityEventType::OrderUpdated,
                    details: [
                        'fields' => $changes,
                        'before' => $oldDetails,
                        'after' => $this->orderDetails($lockedOrder),
                    ],
                );
            }

            if ($lockedOrder->wasChanged('status')) {
                $this->recordActivity(
                    order: $lockedOrder,
                    actor: $actor,
                    eventType: ActivityEventType::OrderStatusChanged,
                    details: [
                        'from' => $oldDetails['status'],
                        'to' => $lockedOrder->status->value,
                    ],
                );
            }

            return $lockedOrder->fresh(['customer', 'cakeCategory', 'basePrice', 'payments', 'activityLogs']);
        }, attempts: 3);
    }

    private function captureMode(mixed $captureMode): CaptureMode
    {
        return $captureMode instanceof CaptureMode
            ? $captureMode
            : CaptureMode::from((string) $captureMode);
    }

    private function money(mixed $amount): BigDecimal
    {
        return BigDecimal::of(blank($amount) ? '0' : (string) $amount);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCatalogSelection(CaptureMode $captureMode, array $data): void
    {
        if ($captureMode === CaptureMode::Custom) {
            if (filled($data['cake_category_id'] ?? null) || filled($data['base_price_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'captureMode' => 'Un pedido personalizado no puede usar referencias del catalogo.',
                ]);
            }

            if (blank($data['cake_description'] ?? null)) {
                throw ValidationException::withMessages([
                    'cakeDescription' => 'La descripcion es obligatoria para un pedido personalizado.',
                ]);
            }

            return;
        }

        if (! CakeCategory::query()->whereKey($data['cake_category_id'] ?? null)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'cakeCategoryId' => 'Selecciona una categoria activa.',
            ]);
        }

        if (! BasePrice::query()->whereKey($data['base_price_id'] ?? null)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'basePriceId' => 'Selecciona un precio base activo.',
            ]);
        }
    }

    private function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'PED-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (Order::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDetails(Order $order, ?string $depositAmount = null): array
    {
        return array_filter([
            'order_number' => $order->order_number,
            'customer_id' => $order->customer_id,
            'capture_mode' => $order->capture_mode->value,
            'cake_category_id' => $order->cake_category_id,
            'base_price_id' => $order->base_price_id,
            'cake_description' => $order->cake_description,
            'agreed_price' => (string) $order->agreed_price,
            'delivery_at' => $order->delivery_at?->toIso8601String(),
            'status' => $order->status->value,
            'deposit_amount' => $depositAmount,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function recordActivity(
        Order $order,
        User $actor,
        ActivityEventType $eventType,
        array $details,
        ?Payment $payment = null,
    ): ActivityLog {
        return $order->activityLogs()->create([
            'payment_id' => $payment?->getKey(),
            'actor_user_id' => $actor->getKey(),
            'event_type' => $eventType,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
