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
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerContactData;
use App\Services\MoneyValidation;
use App\Services\Notifications\ReminderService;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderService
{
    public function __construct(private ReminderService $notificationService) {}

    /**
     * Crea el cliente nuevo opcional, el pedido, su anticipo y el historial en una transaccion.
     *
     * @param  array<string, mixed>  $data
     * @param  array{full_name: string, phone: string}|null  $newCustomerAttributes
     */
    public function create(array $data, User $actor, ?array $newCustomerAttributes = null): Order
    {
        Gate::forUser($actor)->authorize('create', Order::class);

        if ($newCustomerAttributes !== null) {
            Gate::forUser($actor)->authorize('create', Customer::class);

            if (($data['customer_id'] ?? null) !== null) {
                throw ValidationException::withMessages([
                    'customerId' => 'Selecciona un cliente existente o registra uno nuevo, no ambos.',
                ]);
            }

            $newCustomerAttributes = CustomerContactData::validatedAttributes($newCustomerAttributes);
        }

        try {
            $order = DB::transaction(function () use ($data, $actor, $newCustomerAttributes): Order {
                $captureMode = $this->captureMode($data['capture_mode'] ?? null);
                $agreedPrice = MoneyValidation::requiredPositiveAmount($data['agreed_price'] ?? null, 'agreedPrice');
                $depositAmount = MoneyValidation::optionalNonNegativeAmount($data['deposit_amount'] ?? null, 'depositAmount');
                $deliveryAt = $this->validateDeliveryAt($data['delivery_at'] ?? null);

                if ($newCustomerAttributes === null) {
                    $this->validateCustomerSelection($data['customer_id'] ?? null);
                }

                $this->validateCatalogSelection($captureMode, $data);

                if ($depositAmount->isGreaterThan($agreedPrice)) {
                    throw ValidationException::withMessages([
                        'depositAmount' => trans('validation.custom.depositAmount.lte'),
                    ]);
                }

                $customerId = $newCustomerAttributes === null
                    ? $data['customer_id']
                    : Customer::query()->create($newCustomerAttributes)->getKey();

                $order = Order::query()->create([
                    'order_number' => $this->generateOrderNumber(),
                    'customer_id' => $customerId,
                    'created_by' => $actor->id,
                    'capture_mode' => $captureMode,
                    'cake_category_id' => $captureMode === CaptureMode::Standard ? $data['cake_category_id'] : null,
                    'base_price_id' => $captureMode === CaptureMode::Standard ? $data['base_price_id'] : null,
                    'cake_description' => $captureMode === CaptureMode::Custom ? trim((string) $data['cake_description']) : null,
                    'agreed_price' => $agreedPrice->toScale(2)->toString(),
                    'delivery_at' => $deliveryAt,
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
        } catch (QueryException $exception) {
            $field = $newCustomerAttributes === null
                ? null
                : CustomerContactData::uniqueConstraintField($exception);

            if ($field !== null) {
                $attribute = str_ends_with($field, 'fullName') ? 'fullName' : 'phone';

                throw ValidationException::withMessages([
                    $field => trans('validation.unique', [
                        'attribute' => trans("validation.attributes.{$attribute}"),
                    ]),
                ]);
            }

            throw $exception;
        }

        $this->notificationService->sendOrderCreatedSummary($order);

        return $order;
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
            Gate::forUser($actor)->authorize('update', $lockedOrder);

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Los pedidos entregados o cancelados ya no se pueden editar.',
                ]);
            }

            $deliveryAt = $this->validateDeliveryAt($data['delivery_at'] ?? null, $lockedOrder);
            $captureMode = $this->captureMode($data['capture_mode'] ?? null);
            $agreedPrice = MoneyValidation::requiredPositiveAmount($data['agreed_price'] ?? null, 'agreedPrice');
            $registeredPayments = $this->money(
                Payment::query()
                    ->where('order_id', $lockedOrder->getKey())
                    ->where('status', PaymentStatus::Registered->value)
                    ->sum('amount'),
            );

            $customerId = $data['customer_id'] ?? null;
            $this->validateCustomerSelection($customerId);
            $this->validateCatalogSelection($captureMode, $data);

            if ($agreedPrice->isLessThan($registeredPayments)) {
                throw ValidationException::withMessages([
                    'agreedPrice' => trans('validation.custom.agreedPrice.payments'),
                ]);
            }

            $oldDetails = $this->orderDetails($lockedOrder);

            $lockedOrder->fill([
                'customer_id' => $customerId,
                'capture_mode' => $captureMode,
                'cake_category_id' => $captureMode === CaptureMode::Standard ? $data['cake_category_id'] : null,
                'base_price_id' => $captureMode === CaptureMode::Standard ? $data['base_price_id'] : null,
                'cake_description' => $captureMode === CaptureMode::Custom ? trim((string) $data['cake_description']) : null,
                'agreed_price' => $agreedPrice->toScale(2)->toString(),
                'delivery_at' => $deliveryAt,
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

            return $lockedOrder->fresh(['customer', 'cakeCategory', 'basePrice', 'payments', 'activityLogs']);
        }, attempts: 3);
    }

    public function changeStatus(Order $order, OrderStatus $destination, User $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $destination, $actor, $reason): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('changeStatus', $lockedOrder);

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se puede cambiar el estado de un pedido pendiente y no archivado.',
                ]);
            }

            if (! in_array($destination, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'No se puede reabrir un pedido ni asignar ese estado.',
                ]);
            }

            $registeredPayments = $this->money(
                Payment::query()
                    ->where('order_id', $lockedOrder->getKey())
                    ->where('status', PaymentStatus::Registered->value)
                    ->sum('amount'),
            );

            if ($destination === OrderStatus::Delivered && ! $registeredPayments->isEqualTo((string) $lockedOrder->agreed_price)) {
                throw ValidationException::withMessages([
                    'status' => 'El pedido solo puede marcarse como entregado cuando el precio pactado esté pagado por completo.',
                ]);
            }

            if ($destination === OrderStatus::Cancelled && $registeredPayments->isPositive()) {
                throw ValidationException::withMessages([
                    'status' => 'Anula primero los pagos activos antes de cancelar el pedido.',
                ]);
            }

            $previousStatus = $lockedOrder->status;
            $lockedOrder->status = $destination;
            $lockedOrder->save();

            $details = [
                'from' => $previousStatus->value,
                'to' => $destination->value,
            ];

            if ($reason !== null) {
                $details['reason'] = $reason;
            }

            $this->recordActivity(
                order: $lockedOrder,
                actor: $actor,
                eventType: ActivityEventType::OrderStatusChanged,
                details: $details,
            );

            return $lockedOrder->fresh(['customer', 'cakeCategory', 'basePrice', 'payments', 'activityLogs']);
        }, attempts: 3);
    }

    private function captureMode(mixed $captureMode): CaptureMode
    {
        if ($captureMode instanceof CaptureMode) {
            return $captureMode;
        }

        $mode = CaptureMode::tryFrom(is_string($captureMode) ? $captureMode : '');

        if ($mode === null) {
            throw ValidationException::withMessages([
                'captureMode' => trans('validation.custom.captureMode.enum'),
            ]);
        }

        return $mode;
    }

    private function money(mixed $amount): BigDecimal
    {
        return BigDecimal::of(blank($amount) ? '0' : (string) $amount);
    }

    private function validateDeliveryAt(mixed $requestedDeliveryAt, ?Order $order = null): Carbon
    {
        if (blank($requestedDeliveryAt)) {
            throw ValidationException::withMessages([
                'deliveryAt' => trans('validation.custom.deliveryAt.required'),
            ]);
        }

        if (! $requestedDeliveryAt instanceof DateTimeInterface) {
            if (! is_string($requestedDeliveryAt)) {
                throw ValidationException::withMessages([
                    'deliveryAt' => trans('validation.custom.deliveryAt.date'),
                ]);
            }

            Validator::make(
                ['deliveryAt' => $requestedDeliveryAt],
                ['deliveryAt' => ['required', 'date']],
                [
                    'deliveryAt.required' => trans('validation.custom.deliveryAt.required'),
                    'deliveryAt.date' => trans('validation.custom.deliveryAt.date'),
                ],
            )->validate();
        }

        try {
            $timezone = (string) config('app.timezone');
            $deliveryAt = Carbon::parse($requestedDeliveryAt, $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'deliveryAt' => trans('validation.custom.deliveryAt.date'),
            ]);
        }

        if ($order !== null && $deliveryAt->format('Y-m-d\\TH:i') === $order->delivery_at->format('Y-m-d\\TH:i')) {
            return $order->delivery_at;
        }

        if ($deliveryAt->lessThan(now())) {
            throw ValidationException::withMessages([
                'deliveryAt' => trans('validation.custom.deliveryAt.after_or_equal'),
            ]);
        }

        return $deliveryAt;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCatalogSelection(CaptureMode $captureMode, array $data): void
    {
        if ($captureMode === CaptureMode::Custom) {
            if (filled($data['cake_category_id'] ?? null) || filled($data['base_price_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'captureMode' => 'Un pedido personalizado no puede usar referencias del catálogo.',
                ]);
            }

            if (blank($data['cake_description'] ?? null)) {
                throw ValidationException::withMessages([
                    'cakeDescription' => 'La descripción es obligatoria para un pedido personalizado.',
                ]);
            }

            return;
        }

        if (! CakeCategory::query()->whereKey($data['cake_category_id'] ?? null)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'cakeCategoryId' => 'Selecciona una categoría activa.',
            ]);
        }

        if (! BasePrice::query()->whereKey($data['base_price_id'] ?? null)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'basePriceId' => 'Selecciona un precio base activo.',
            ]);
        }
    }

    private function validateCustomerSelection(mixed $customerId): void
    {
        if (Customer::query()->whereKey($customerId)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'customerId' => 'Selecciona un cliente existente o regístralo antes de guardar el pedido.',
        ]);
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
