<?php

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\NotificationMessageType;
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
use App\Livewire\Forms\OrderFormRules;
use App\Services\MoneyValidation;
use App\Services\Orders\OrderDeletionService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public Order $order;
    public bool $editing = false;
    public int $customerId = 0;
    public string $captureMode = '';
    public string $cakeCategoryId = '';
    public string $basePriceId = '';
    public string $cakeDescription = '';
    public string $agreedPrice = '';
    public string $deliveryAt = '';
    public string $paymentAmount = '';
    public string $paymentPaidAt = '';
    public string $paymentNotes = '';
    public bool $showVoidForm = false;
    public ?int $voidingPaymentId = null;
    public string $voidReason = '';
    public bool $showStatusConfirmation = false;
    public ?string $pendingStatus = null;
    public bool $showDeleteConfirmation = false;

    public function mount(Order $order): void
    {
        Gate::authorize('view', $order);
        $this->loadOrder($order);
    }

    public function updatedShowVoidForm(bool $showVoidForm): void
    {
        if (! $showVoidForm) {
            $this->voidingPaymentId = null;
            $this->voidReason = '';
            $this->resetValidation();
        }
    }

    public function updatedShowStatusConfirmation(bool $showStatusConfirmation): void
    {
        if (! $showStatusConfirmation) {
            $this->pendingStatus = null;
            $this->resetValidation();
        }
    }

    public function updatedShowDeleteConfirmation(bool $showDeleteConfirmation): void
    {
        if (! $showDeleteConfirmation) {
            $this->resetValidation();
        }
    }

    #[Computed]
    public function activityLogs(): Collection
    {
        return ActivityLog::query()
            ->where('order_id', $this->order->getKey())
            ->with('actorUser')
            ->latest('created_at')
            ->get();
    }

    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->orderBy('full_name')->get();
    }

    #[Computed]
    public function activeCategories(): Collection
    {
        return CakeCategory::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function activeBasePrices(): Collection
    {
        return BasePrice::query()->where('is_active', true)->orderBy('amount')->get();
    }

    #[Computed]
    public function registeredPaymentsTotal(): string
    {
        return (string) ($this->order->getAttribute('registered_payments_total') ?? '0.00');
    }

    #[Computed]
    public function suggestedPaymentType(): PaymentType
    {
        $registeredTotal = $this->money($this->registeredPaymentsTotal());
        $amount = $this->money($this->paymentAmount);

        if ($registeredTotal->isEqualTo('0')) {
            return PaymentType::Deposit;
        }

        return $amount->isPositive() && $registeredTotal->plus($amount)->isEqualTo((string) $this->order->agreed_price)
            ? PaymentType::Settlement
            : PaymentType::Partial;
    }

    public function startEditing(): void
    {
        Gate::authorize('update', $this->order);

        if (! $this->canEdit()) {
            $this->addError('status', 'Los pedidos entregados o cancelados ya no se pueden editar.');

            return;
        }

        $this->fillForm();
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->loadOrder($this->order);
        $this->editing = false;
        $this->resetValidation();
    }

    public function requestStatusChange(string $status): void
    {
        Gate::authorize('changeStatus', $this->order);

        $destination = OrderStatus::tryFrom($status);

        if (! in_array($destination, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            $this->addError('status', 'Solo puedes cambiar un pedido pendiente a entregado o cancelado.');

            return;
        }

        $this->pendingStatus = $destination->value;
        $this->showStatusConfirmation = true;
    }

    public function confirmStatusChange(OrderService $orderService): void
    {
        Gate::authorize('changeStatus', $this->order);

        $destination = OrderStatus::tryFrom((string) $this->pendingStatus);

        if (! in_array($destination, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            $this->addError('status', 'Solo puedes cambiar un pedido pendiente a entregado o cancelado.');

            return;
        }

        $actor = Auth::user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $this->order = $orderService->changeStatus($this->order, $destination, $actor);
        $this->pendingStatus = null;
        $this->showStatusConfirmation = false;
        $this->loadOrder($this->order);
        unset($this->activityLogs, $this->registeredPaymentsTotal);
        session()->flash('status', 'Estado del pedido actualizado correctamente.');
    }

    public function cancelStatusChange(): void
    {
        $this->pendingStatus = null;
        $this->showStatusConfirmation = false;
    }

    public function updatedCaptureMode(string $captureMode): void
    {
        if ($captureMode === CaptureMode::Custom->value) {
            $this->cakeCategoryId = '';
            $this->basePriceId = '';
            return;
        }

        $this->cakeDescription = '';
    }

    public function updatedBasePriceId(string $basePriceId): void
    {
        if ($basePriceId === '') {
            return;
        }

        $basePrice = BasePrice::query()
            ->whereKey($basePriceId)
            ->where('is_active', true)
            ->first();

        if ($basePrice !== null) {
            $this->agreedPrice = (string) $basePrice->amount;
        }
    }

    public function saveChanges(): void
    {
        Gate::authorize('update', $this->order);

        if (! $this->canEdit()) {
            $this->addError('status', 'Los pedidos entregados o cancelados ya no se pueden editar.');

            return;
        }

        $this->cakeDescription = trim($this->cakeDescription);
        $this->agreedPrice = trim($this->agreedPrice);
        $this->deliveryAt = trim($this->deliveryAt);

        $requireFutureDelivery = $this->deliveryAt !== $this->order->delivery_at->format('Y-m-d\\TH:i');
        $validated = $this->validate(OrderFormRules::order(
            captureMode: $this->captureMode,
            requireFutureDelivery: $requireFutureDelivery,
        ));

        $this->order = app(OrderService::class)->update($this->order, [
            'customer_id' => (int) $validated['customerId'],
            'capture_mode' => CaptureMode::from($validated['captureMode']),
            'cake_category_id' => blank($validated['cakeCategoryId']) ? null : (int) $validated['cakeCategoryId'],
            'base_price_id' => blank($validated['basePriceId']) ? null : (int) $validated['basePriceId'],
            'cake_description' => blank($validated['cakeDescription']) ? null : trim($validated['cakeDescription']),
            'agreed_price' => $validated['agreedPrice'],
            'delivery_at' => $validated['deliveryAt'],
        ], Auth::user());

        $this->editing = false;
        $this->loadOrder($this->order);
        unset($this->activityLogs);
        session()->flash('status', 'Pedido actualizado correctamente.');
    }

    public function deleteOrder(OrderDeletionService $orderDeletionService): void
    {
        Gate::authorize('delete', $this->order);

        if ($this->order->trashed()) {
            $this->addError('order', 'Este pedido ya está en el historial y solo se puede borrar desde allí.');

            return;
        }

        $actor = Auth::user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $orderDeletionService->delete($this->order, $actor);

        session()->flash('status', 'Pedido retirado al Historial correctamente.');

        $this->redirect(route('orders.index'), navigate: true);
    }

    public function savePayment(): void
    {
        Gate::authorize('registerPayment', $this->order);

        if (! $this->canEdit()) {
            $this->addError('payment', 'Los pedidos entregados o cancelados ya no aceptan pagos.');

            return;
        }

        $this->paymentAmount = trim($this->paymentAmount);
        $this->paymentPaidAt = trim($this->paymentPaidAt);
        $this->paymentNotes = trim($this->paymentNotes);

        $validated = $this->validate(OrderFormRules::payment());

        app(PaymentService::class)->register($this->order, [
            'amount' => $validated['paymentAmount'],
            'paid_at' => $validated['paymentPaidAt'],
            'notes' => blank($validated['paymentNotes']) ? null : trim($validated['paymentNotes']),
        ], Auth::user());

        $this->resetPaymentForm();
        $this->loadOrder($this->order);
        unset($this->activityLogs, $this->registeredPaymentsTotal, $this->suggestedPaymentType);
        session()->flash('status', 'Pago registrado correctamente.');
    }

    public function requestPaymentVoid(int $paymentId): void
    {
        Gate::authorize('voidPayment', $this->order);

        if (! $this->canEdit()) {
            $this->addError('payment', 'Los pagos de pedidos entregados o cancelados ya no se pueden modificar.');

            return;
        }

        Payment::query()
            ->where('order_id', $this->order->getKey())
            ->whereKey($paymentId)
            ->where('status', PaymentStatus::Registered->value)
            ->firstOrFail();

        $this->voidingPaymentId = $paymentId;
        $this->voidReason = '';
        $this->showVoidForm = true;
        $this->resetValidation();
    }

    public function cancelPaymentVoid(): void
    {
        $this->showVoidForm = false;
        $this->voidingPaymentId = null;
        $this->voidReason = '';
        $this->resetValidation();
    }

    public function voidPayment(): void
    {
        Gate::authorize('voidPayment', $this->order);

        $this->voidReason = trim($this->voidReason);

        $validated = $this->validate(OrderFormRules::voidPayment());

        $payment = Payment::query()
            ->where('order_id', $this->order->getKey())
            ->findOrFail($this->voidingPaymentId);

        app(PaymentService::class)->void($payment, trim($validated['voidReason']), Auth::user());

        $this->cancelPaymentVoid();
        $this->loadOrder($this->order);
        unset($this->activityLogs, $this->registeredPaymentsTotal, $this->suggestedPaymentType);
        session()->flash('status', 'Pago anulado correctamente.');
    }

    public function captureModeLabel(CaptureMode $captureMode): string
    {
        return match ($captureMode) {
            CaptureMode::Standard => __('Estándar'),
            CaptureMode::Custom => __('Personalizado'),
        };
    }

    public function statusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Pending => __('Pendiente'),
            OrderStatus::Delivered => __('Entregado'),
            OrderStatus::Cancelled => __('Cancelado'),
        };
    }

    public function pendingStatusLabel(): string
    {
        $status = OrderStatus::tryFrom((string) $this->pendingStatus);

        return $status === null ? '' : $this->statusLabel($status);
    }

    public function statusBadgeColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Pending => 'amber',
            OrderStatus::Delivered => 'green',
            OrderStatus::Cancelled => 'zinc',
        };
    }

    public function orderFieldLabel(string $field): string
    {
        return match ($field) {
            'customer_id' => __('Cliente'),
            'capture_mode' => __('Modalidad'),
            'cake_category_id' => __('Categoría'),
            'base_price_id' => __('Precio base'),
            'cake_description' => __('Descripción del pastel'),
            'agreed_price' => __('Precio pactado'),
            'delivery_at' => __('Fecha y hora de entrega'),
            default => $field,
        };
    }

    public function orderStatusLabel(string $status): string
    {
        $orderStatus = OrderStatus::tryFrom($status);

        return $orderStatus === null ? $status : $this->statusLabel($orderStatus);
    }

    public function eventLabel(ActivityEventType $eventType, ?ActivityLog $activityLog = null): string
    {
        return match ($eventType) {
            ActivityEventType::OrderCreated => __('Pedido creado'),
            ActivityEventType::OrderUpdated => __('Pedido actualizado'),
            ActivityEventType::OrderStatusChanged => match ($activityLog?->details['reason'] ?? null) {
                'retired_to_history' => __('Pedido retirado al Historial'),
                'archived_pending_repair' => __('Pedido archivado regularizado'),
                default => __('Estado actualizado'),
            },
            ActivityEventType::PaymentRegistered => ($activityLog?->details['payment_type'] ?? PaymentType::Deposit->value) === PaymentType::Deposit->value
                ? __('Anticipo registrado')
                : __('Pago registrado'),
            ActivityEventType::PaymentVoided => __('Pago anulado'),
            ActivityEventType::NotificationSent => ($activityLog?->details['notification_type'] ?? null) === NotificationMessageType::OrderCreatedSummary->value
                ? __('Resumen del pedido enviado')
                : __('Recordatorio enviado'),
            ActivityEventType::NotificationFailed => ($activityLog?->details['notification_type'] ?? null) === NotificationMessageType::OrderCreatedSummary->value
                ? __('Resumen del pedido fallido')
                : __('Recordatorio fallido'),
        };
    }

    public function activitySummary(ActivityLog $activityLog): string
    {
        return match ($activityLog->event_type) {
            ActivityEventType::OrderCreated => __('Se registró el pedido y su estado pendiente.'),
            ActivityEventType::OrderUpdated => __('Campos actualizados: ').implode(', ', array_map(fn (string $field): string => $this->orderFieldLabel($field), $activityLog->details['fields'] ?? [])),
            ActivityEventType::OrderStatusChanged => $this->orderStatusChangeSummary($activityLog),
            ActivityEventType::PaymentRegistered => $this->paymentTypeActivitySummary($activityLog),
            ActivityEventType::PaymentVoided => __('Pago de Q ').$this->formatMoney($activityLog->details['amount'] ?? '0.00').' '.__('anulado: ').($activityLog->details['void_reason'] ?? ''),
            ActivityEventType::NotificationSent => ($activityLog->details['notification_type'] ?? null) === NotificationMessageType::OrderCreatedSummary->value
                ? __('Resumen del pedido enviado a la administradora.')
                : __('Recordatorio confirmado.'),
            ActivityEventType::NotificationFailed => ($activityLog->details['notification_type'] ?? null) === NotificationMessageType::OrderCreatedSummary->value
                ? __('No se pudo enviar el resumen del pedido.')
                : __('No se pudo enviar el recordatorio.'),
        };
    }

    private function orderStatusChangeSummary(ActivityLog $activityLog): string
    {
        $summary = __('Cambio de ').$this->orderStatusLabel((string) ($activityLog->details['from'] ?? '')).__(' a ').$this->orderStatusLabel((string) ($activityLog->details['to'] ?? ''));

        return match ($activityLog->details['reason'] ?? null) {
            'retired_to_history' => $summary.__(' al retirar el pedido al Historial.'),
            'archived_pending_repair' => $summary.__(' durante la regularización de pedidos archivados.'),
            default => $summary,
        };
    }

    public function paymentTypeLabel(PaymentType $paymentType): string
    {
        return match ($paymentType) {
            PaymentType::Deposit => __('Anticipo'),
            PaymentType::Partial => __('Abono'),
            PaymentType::Settlement => __('Liquidación'),
        };
    }

    public function paymentTypeBadgeColor(PaymentType $paymentType): string
    {
        return match ($paymentType) {
            PaymentType::Deposit => 'blue',
            PaymentType::Partial => 'amber',
            PaymentType::Settlement => 'green',
        };
    }

    public function paymentStatusLabel(PaymentStatus $paymentStatus): string
    {
        return match ($paymentStatus) {
            PaymentStatus::Registered => __('Registrado'),
            PaymentStatus::Voided => __('Anulado'),
        };
    }

    public function pendingBalance(): string
    {
        $balance = BigDecimal::of((string) $this->order->agreed_price)
            ->minus($this->money($this->registeredPaymentsTotal()));

        return ($balance->isNegative() ? BigDecimal::of('0') : $balance)
            ->toScale(2)
            ->toString();
    }

    public function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public function formatAgreedPrice(): void
    {
        $this->agreedPrice = MoneyValidation::formatInputValue($this->agreedPrice);
    }

    public function formatPaymentAmount(): void
    {
        $this->paymentAmount = MoneyValidation::formatInputValue($this->paymentAmount);
    }

    public function deliveryAtMinimum(): ?string
    {
        if (
            $this->deliveryAt === $this->order->delivery_at->format('Y-m-d\\TH:i')
            && $this->order->delivery_at->lessThan(now())
        ) {
            return null;
        }

        $minimum = now();

        if ($minimum->format('s.u') !== '00.000000') {
            $minimum->addMinute();
        }

        return $minimum->startOfMinute()->format('Y-m-d\\TH:i');
    }

    public function formatDate(?\Carbon\CarbonInterface $date): string
    {
        return $date?->format('d/m/Y H:i') ?? '—';
    }

    private function loadOrder(Order $order): void
    {
        $this->order = Order::query()
            ->with([
                'customer',
                'cakeCategory',
                'basePrice',
                'payments.registeredBy',
                'payments.voidedBy',
                'activityLogs.actorUser',
            ])
            ->withSum([
                'payments as registered_payments_total' => fn (Builder $query): Builder => $query->where('status', PaymentStatus::Registered->value),
            ], 'amount')
            ->withTrashed()
            ->findOrFail($order->getKey());
        $this->fillForm();
        $this->resetPaymentForm();
        unset($this->activityLogs);
    }

    private function fillForm(): void
    {
        $this->customerId = $this->order->customer_id;
        $this->captureMode = $this->order->capture_mode->value;
        $this->cakeCategoryId = $this->order->cake_category_id === null ? '' : (string) $this->order->cake_category_id;
        $this->basePriceId = $this->order->base_price_id === null ? '' : (string) $this->order->base_price_id;
        $this->cakeDescription = $this->order->cake_description ?? '';
        $this->agreedPrice = (string) $this->order->agreed_price;
        $this->deliveryAt = $this->order->delivery_at->format('Y-m-d\TH:i');
    }

    private function resetPaymentForm(): void
    {
        $this->paymentAmount = '';
        $this->paymentPaidAt = now()->format('Y-m-d\TH:i');
        $this->paymentNotes = '';
        unset($this->suggestedPaymentType);
    }

    private function paymentTypeActivitySummary(ActivityLog $activityLog): string
    {
        $paymentType = PaymentType::tryFrom((string) ($activityLog->details['payment_type'] ?? PaymentType::Deposit->value));
        $label = $paymentType === null ? __('Pago') : $this->paymentTypeLabel($paymentType);

        return $label.__(' de Q ').$this->formatMoney($activityLog->details['amount'] ?? '0.00');
    }

    private function money(mixed $amount): BigDecimal
    {
        return is_numeric($amount) ? BigDecimal::of((string) $amount) : BigDecimal::of('0');
    }

    public function canEdit(): bool
    {
        return Auth::user()?->can('update', $this->order) === true
            && ! $this->order->trashed()
            && $this->order->status === OrderStatus::Pending;
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Detalle del pedido') }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">{{ $order->order_number }}</h1>
                        <flux:badge color="{{ $this->statusBadgeColor($order->status) }}">{{ $this->statusLabel($order->status) }}</flux:badge>
                    </div>
                    <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Creado el') }} {{ $this->formatDate($order->created_at) }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 self-start sm:self-end">
                <flux:button href="{{ route('orders.index') }}" wire:navigate variant="ghost" icon="arrow-left" tooltip="{{ __('Volver al listado de pedidos') }}">
                    {{ __('Volver') }}
                </flux:button>

                @if (! $editing && $this->canEdit())
                    <flux:button wire:click="startEditing" variant="primary" icon="pencil-square" tooltip="{{ __('Editar los datos del pedido') }}">
                        {{ __('Editar pedido') }}
                    </flux:button>
                @endif

                @can('delete', $order)
                    <flux:button wire:click="$set('showDeleteConfirmation', true)" variant="danger" icon="trash" class="marleni-danger-button" tooltip="{{ __('Retirar al Historial; se conservarán los pagos y la actividad') }}">
                        {{ __('Retirar pedido') }}
                    </flux:button>
                @endcan
            </div>
        </header>

        @if (! $editing && $order->status === OrderStatus::Pending && ! $order->trashed())
            @can('changeStatus', $order)
                <section class="flex flex-col gap-4 rounded-2xl border border-brand-200 bg-white p-5 shadow-sm dark:border-brand-800 dark:bg-brand-900/50 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-col gap-1">
                        <h2 class="font-semibold text-brand-950 dark:text-brand-50">{{ __('Cambiar estado') }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Entregar requiere el pago completo; cancelar requiere que no haya pagos activos. Este control no modifica otros datos.') }}</p>
                        @error('status')
                            <p class="text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button wire:click="requestStatusChange('delivered')" variant="primary" tooltip="{{ __('Cambiar únicamente el estado a Entregado') }}">
                            {{ __('Marcar entregado') }}
                        </flux:button>
                        <flux:button wire:click="requestStatusChange('cancelled')" variant="ghost" tooltip="{{ __('Cambiar únicamente el estado a Cancelado') }}">
                            {{ __('Cancelar pedido') }}
                        </flux:button>
                    </div>
                </section>
            @endcan
        @endif

        @if ($editing)
            <form wire:submit="saveChanges" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]">
                <x-validation-errors class="xl:col-span-2" :fields="['customerId', 'captureMode', 'cakeCategoryId', 'basePriceId', 'cakeDescription', 'agreedPrice', 'deliveryAt']" />
                <div class="flex flex-col gap-6">
                    <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                        <div class="flex flex-col gap-2">
                            <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Datos editables') }}</h2>
                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Los cambios relevantes quedaran visibles en el historial.') }}</p>
                            <p class="text-xs text-brand-600 dark:text-brand-300"><span aria-hidden="true">*</span> {{ __('Campo obligatorio') }}</p>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div>
                                <flux:select wire:model="customerId" label="{{ __('Cliente') }} *" :invalid="$errors->has('customerId')" aria-invalid="{{ $errors->has('customerId') ? 'true' : 'false' }}" required>
                                @foreach ($this->customers as $customer)
                                     <flux:select.option value="{{ $customer->id }}">{{ $customer->full_name }} · {{ $customer->phoneFormatted() }}</flux:select.option>
                                @endforeach
                                </flux:select>
                                <flux:error name="customerId" />
                            </div>

                            <div>
                                <flux:select wire:model.live="captureMode" label="{{ __('Modalidad') }} *" :invalid="$errors->has('captureMode')" aria-invalid="{{ $errors->has('captureMode') ? 'true' : 'false' }}" required>
                                <flux:select.option value="standard">{{ __('Estándar') }}</flux:select.option>
                                <flux:select.option value="custom">{{ __('Personalizado') }}</flux:select.option>
                                </flux:select>
                                <flux:error name="captureMode" />
                            </div>

                            @if ($captureMode === CaptureMode::Standard->value)
                                <div>
                                    <flux:select wire:model="cakeCategoryId" label="{{ __('Categoría') }} *" placeholder="{{ __('Selecciona una categoría') }}" :invalid="$errors->has('cakeCategoryId')" aria-invalid="{{ $errors->has('cakeCategoryId') ? 'true' : 'false' }}" required>
                                    @foreach ($this->activeCategories as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                    @endforeach
                                    </flux:select>
                                    <flux:error name="cakeCategoryId" />
                                </div>

                                <div>
                                    <flux:select wire:model.live="basePriceId" label="{{ __('Precio base') }} *" placeholder="{{ __('Selecciona un precio') }}" :invalid="$errors->has('basePriceId')" aria-invalid="{{ $errors->has('basePriceId') ? 'true' : 'false' }}" required>
                                    @foreach ($this->activeBasePrices as $basePrice)
                                        <flux:select.option value="{{ $basePrice->id }}">Q {{ number_format((float) $basePrice->amount, 2, '.', ',') }}</flux:select.option>
                                    @endforeach
                                    </flux:select>
                                    <flux:error name="basePriceId" />
                                </div>
                            @else
                                <div class="md:col-span-2">
                                    <flux:textarea wire:model="cakeDescription" label="{{ __('Descripción del pastel') }} *" rows="4" :invalid="$errors->has('cakeDescription')" aria-invalid="{{ $errors->has('cakeDescription') ? 'true' : 'false' }}" required />
                                    <flux:error name="cakeDescription" />
                                </div>
                            @endif
                        </div>
                    </section>
                </div>

                <aside class="flex h-fit flex-col gap-6">
                    <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                        <div class="flex flex-col gap-2">
                            <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Condiciones') }}</h2>
                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('El precio no puede reducirse por debajo de los pagos activos.') }}</p>
                        </div>

                        <div class="mt-5 flex flex-col gap-4">
                            <flux:field>
                                <flux:label>{{ __('Precio pactado') }} <span aria-hidden="true">*</span><span class="sr-only">{{ __('Obligatorio') }}</span></flux:label>
                                <flux:input.group>
                                    <flux:input.group.prefix>Q</flux:input.group.prefix>
                                    <flux:input wire:model="agreedPrice" wire:blur="formatAgreedPrice" type="text" inputmode="decimal" maxlength="11" :invalid="$errors->has('agreedPrice')" aria-invalid="{{ $errors->has('agreedPrice') ? 'true' : 'false' }}" required />
                                </flux:input.group>
                                <flux:error name="agreedPrice" />
                            </flux:field>
                            <div>
                                <flux:input wire:model="deliveryAt" label="{{ __('Fecha y hora de entrega') }} *" type="datetime-local" @if ($this->deliveryAtMinimum() !== null) min="{{ $this->deliveryAtMinimum() }}" @endif :invalid="$errors->has('deliveryAt')" aria-invalid="{{ $errors->has('deliveryAt') ? 'true' : 'false' }}" required />
                                <flux:error name="deliveryAt" />
                            </div>
                        </div>
                    </section>

                    <section class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <flux:button wire:click="cancelEditing" type="button" variant="ghost" tooltip="{{ __('Descartar los cambios del pedido') }}">{{ __('Cancelar') }}</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveChanges" tooltip="{{ __('Guardar los cambios del pedido') }}">{{ __('Guardar cambios') }}</flux:button>
                    </section>
                </aside>
            </form>
        @else
            <div class="grid gap-6 lg:grid-cols-3">
                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50 lg:col-span-2">
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Cliente') }}</p>
                        <h2 class="text-xl font-semibold text-brand-950 dark:text-brand-50">{{ $order->customer->full_name }}</h2>
                         <p class="text-sm text-brand-700 dark:text-brand-200">{{ $order->customer->phoneFormatted() }}</p>
                    </div>

                    <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ __('Modalidad') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-brand-950 dark:text-brand-50">{{ $this->captureModeLabel($order->capture_mode) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ __('Entrega') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-brand-950 dark:text-brand-50">{{ $this->formatDate($order->delivery_at) }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ __('Pastel') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-brand-950 dark:text-brand-50">
                                @if ($order->capture_mode === CaptureMode::Standard)
                                    {{ $order->cakeCategory?->name }} · Q {{ $this->formatMoney($order->basePrice?->amount) }}
                                @else
                                    {{ $order->cake_description }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Resumen financiero') }}</p>
                    <dl class="mt-5 flex flex-col gap-4">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-brand-700 dark:text-brand-200">{{ __('Precio pactado') }}</dt>
                            <dd class="font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($order->agreed_price) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-brand-700 dark:text-brand-200">{{ __('Total pagado') }}</dt>
                            <dd class="font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($this->registeredPaymentsTotal) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t border-brand-100 pt-4 text-sm dark:border-brand-800">
                            <dt class="font-semibold text-brand-700 dark:text-brand-200">{{ __('Saldo pendiente') }}</dt>
                            <dd class="text-lg font-semibold text-accent">Q {{ $this->formatMoney($this->pendingBalance()) }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,24rem)]">
                <div class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Historial de pagos') }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Los pagos anulados se conservan y no afectan el saldo.') }}</p>
                        @cannot('registerPayment', $order)
                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Tu rol permite consultar los pagos; solo la Dueña puede registrarlos o anularlos.') }}</p>
                        @endcannot
                    </div>

                    @if ($order->payments->isEmpty())
                        <p class="mt-6 text-sm text-brand-700 dark:text-brand-200">{{ __('Todavía no hay pagos registrados.') }}</p>
                    @else
                        <div class="mt-6 flex flex-col divide-y divide-brand-100 dark:divide-brand-800">
                            @foreach ($order->payments as $payment)
                                <article wire:key="payment-{{ $payment->id }}" class="flex flex-col gap-4 py-5 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:badge color="{{ $this->paymentTypeBadgeColor($payment->payment_type) }}">{{ $this->paymentTypeLabel($payment->payment_type) }}</flux:badge>
                                            <flux:badge color="{{ $payment->status === PaymentStatus::Registered ? 'green' : 'zinc' }}">{{ $this->paymentStatusLabel($payment->status) }}</flux:badge>
                                        </div>
                                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Fecha del pago') }}: {{ $this->formatDate($payment->paid_at) }}</p>
                                        @if ($payment->registeredBy)
                                            <p class="text-xs text-brand-600 dark:text-brand-300">{{ __('Registrado por') }}: {{ $payment->registeredBy->name }}</p>
                                        @endif
                                        @if ($payment->notes)
                                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ $payment->notes }}</p>
                                        @endif
                                        @if ($payment->status === PaymentStatus::Voided)
                                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Motivo') }}: {{ $payment->void_reason }}</p>
                                            @if ($payment->voidedBy)
                                                <p class="text-xs text-brand-600 dark:text-brand-300">{{ __('Anulado por') }}: {{ $payment->voidedBy->name }} · {{ $this->formatDate($payment->voided_at) }}</p>
                                            @endif
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 flex-col gap-3 sm:items-end">
                                        <p class="text-lg font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($payment->amount) }}</p>
                                        @if ($payment->status === PaymentStatus::Registered && $this->canEdit())
                                            <flux:button wire:click="requestPaymentVoid({{ $payment->id }})" variant="ghost" size="sm" tooltip="{{ __('Abrir la confirmación para anular este pago') }}">
                                                {{ __('Anular pago') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($this->canEdit())
                    <section class="h-fit rounded-2xl border border-brand-200 bg-brand-50 p-6 dark:border-brand-800 dark:bg-brand-950/50">
                        <div class="flex flex-col gap-2">
                            <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Registrar pago') }}</h2>
                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('El sistema clasifica el pago según el saldo disponible.') }}</p>
                        </div>

                        <form wire:submit="savePayment" class="mt-5 flex flex-col gap-4">
                            <x-validation-errors :fields="['paymentAmount', 'paymentPaidAt', 'paymentNotes']" />
                            <flux:field>
                                <flux:label>{{ __('Importe') }} <span aria-hidden="true">*</span><span class="sr-only">{{ __('Obligatorio') }}</span></flux:label>
                                <flux:input.group>
                                    <flux:input.group.prefix>Q</flux:input.group.prefix>
                                    <flux:input wire:model="paymentAmount" wire:blur="formatPaymentAmount" type="text" inputmode="decimal" maxlength="11" :invalid="$errors->has('paymentAmount')" aria-invalid="{{ $errors->has('paymentAmount') ? 'true' : 'false' }}" required />
                                </flux:input.group>
                                <flux:error name="paymentAmount" />
                            </flux:field>
                            <flux:input label="{{ __('Tipo de pago') }}" value="{{ $this->paymentTypeLabel($this->suggestedPaymentType) }}" readonly />
                            <div>
                                <flux:input wire:model="paymentPaidAt" label="{{ __('Fecha del pago') }} *" type="datetime-local" :invalid="$errors->has('paymentPaidAt')" aria-invalid="{{ $errors->has('paymentPaidAt') ? 'true' : 'false' }}" required />
                                <flux:error name="paymentPaidAt" />
                            </div>
                            <div>
                                <flux:textarea wire:model="paymentNotes" label="{{ __('Nota opcional') }}" rows="3" :invalid="$errors->has('paymentNotes')" aria-invalid="{{ $errors->has('paymentNotes') ? 'true' : 'false' }}" />
                                <flux:error name="paymentNotes" />
                            </div>
                            <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="savePayment" tooltip="{{ __('Registrar el pago y actualizar el saldo') }}">
                                {{ __('Registrar pago') }}
                            </flux:button>
                        </form>
                    </section>
                @endif
            </section>

             @if ($this->canEdit())
             <flux:modal wire:model="showVoidForm" focusable class="max-w-lg border border-brand-200 bg-brand-50 dark:border-brand-700 dark:bg-brand-950">
                 <form wire:submit="voidPayment" class="flex flex-col gap-5 text-base">
                     <x-validation-errors :fields="['voidReason']" />
                     <div class="flex items-start gap-4">
                         <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-800 text-xl font-bold text-white shadow-lg shadow-brand-900/20">!</div>
                         <div>
                             <flux:heading size="lg" class="font-display text-brand-950 dark:text-brand-50">{{ __('¿Anular pago?') }}</flux:heading>
                             <p class="mt-2 text-base leading-6 text-brand-700 dark:text-brand-200">{{ __('Se conservará, pero dejará de contar para el saldo.') }}</p>
                         </div>
                     </div>

                    <div>
                        <flux:textarea wire:model="voidReason" label="{{ __('Motivo de la anulación') }} *" rows="4" :invalid="$errors->has('voidReason')" aria-invalid="{{ $errors->has('voidReason') ? 'true' : 'false' }}" required />
                        <flux:error name="voidReason" />
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <flux:button wire:click="cancelPaymentVoid" type="button" variant="ghost" tooltip="{{ __('Cerrar sin anular el pago') }}">
                            {{ __('Cancelar') }}
                        </flux:button>
                         <flux:button type="submit" variant="danger" class="marleni-danger-button" tooltip="{{ __('Confirmar la anulación de este pago') }}">
                             {{ __('Anular pago') }}
                        </flux:button>
                    </div>
                </form>
             </flux:modal>
             @endif

             <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                <div class="flex flex-col gap-2">
                    <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Historial del pedido') }}</h2>
                    <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('La creación y los cambios relevantes se registran con su responsable.') }}</p>
                </div>

                @if ($this->activityLogs->isEmpty())
                    <p class="mt-6 text-sm text-brand-700 dark:text-brand-200">{{ __('Todavía no hay eventos registrados.') }}</p>
                @else
                    <ol class="mt-6 flex flex-col divide-y divide-brand-100 dark:divide-brand-800">
                        @foreach ($this->activityLogs as $activityLog)
                            <li wire:key="activity-{{ $activityLog->id }}" class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <div>
                                    <p class="font-semibold text-brand-950 dark:text-brand-50">{{ $this->eventLabel($activityLog->event_type, $activityLog) }}</p>
                                    <p class="mt-1 text-sm text-brand-700 dark:text-brand-200">{{ $this->activitySummary($activityLog) }}</p>
                                    <p class="mt-2 text-xs text-brand-600 dark:text-brand-300">{{ __('Responsable') }}: {{ $activityLog->actorUser?->name ?? __('Sistema') }}</p>
                                </div>
                                <time class="shrink-0 text-xs text-brand-600 dark:text-brand-300">{{ $this->formatDate($activityLog->created_at) }}</time>
                            </li>
                        @endforeach
                    </ol>
                @endif
             </section>

        @endif

             <flux:modal wire:model="showStatusConfirmation" focusable class="max-w-lg border border-brand-200 bg-brand-50 dark:border-brand-700 dark:bg-brand-950">
                 <div class="flex flex-col gap-5 text-base">
                     <div class="flex items-start gap-4">
                         <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-800 text-xl font-bold text-white shadow-lg shadow-brand-900/20">!</div>
                         <div>
                             <flux:heading size="lg" class="font-display text-brand-950 dark:text-brand-50">{{ __('¿Confirmar estado?') }}</flux:heading>
                             <p class="mt-2 text-base leading-6 text-brand-700 dark:text-brand-200">
                                  {{ __('Pasará a') }}
                                  <strong class="text-brand-950 dark:text-brand-50">{{ $this->pendingStatusLabel() }}</strong>.
                                  @if ($this->pendingStatus === OrderStatus::Delivered->value)
                                      {{ __('Se verificará que el precio pactado esté pagado por completo.') }}
                                  @elseif ($this->pendingStatus === OrderStatus::Cancelled->value)
                                      {{ __('Se verificará que no existan pagos activos. Si hay pagos, la Dueña deberá resolverlos primero.') }}
                                  @endif
                                  {{ __('Los pedidos en estado terminal no se pueden reabrir.') }}
                             </p>
                         </div>
                     </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <flux:button wire:click="cancelStatusChange" type="button" variant="ghost" tooltip="{{ __('Cancelar el cambio de estado') }}">
                            {{ __('Cancelar') }}
                        </flux:button>
                        <flux:button wire:click="confirmStatusChange" type="button" variant="primary" tooltip="{{ __('Confirmar el cambio de estado') }}">
                            {{ __('Confirmar cambio') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>

             @can('delete', $order)
             <flux:modal wire:model="showDeleteConfirmation" focusable class="max-w-lg border border-brand-200 bg-brand-50 dark:border-brand-700 dark:bg-brand-950">
                 <div class="flex flex-col gap-5 text-base">
                     <div class="flex items-start gap-4">
                         <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-800 text-xl font-bold text-white shadow-lg shadow-brand-900/20">!</div>
                         <div>
                              <flux:heading size="lg" class="font-display text-brand-950 dark:text-brand-50">{{ __('¿Retirar pedido al Historial?') }}</flux:heading>
                                <p class="mt-2 text-base leading-6 text-brand-700 dark:text-brand-200">{{ __('El pedido dejará de aparecer en Pedidos y conservará sus pagos y eventos. Si está Pendiente, pasará a Cancelado sin anular ni reembolsar los pagos existentes. Si está Entregado o Cancelado, conservará ese estado.') }}</p>
                          </div>
                      </div>

                    @error('status')
                        <p class="text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <flux:button wire:click="$set('showDeleteConfirmation', false)" type="button" variant="ghost" tooltip="{{ __('Cerrar sin retirar el pedido') }}">
                            {{ __('Cancelar') }}
                        </flux:button>
                          <flux:button wire:click="deleteOrder" type="button" variant="danger" class="marleni-danger-button" tooltip="{{ __('Confirmar el retiro al Historial') }}">
                              {{ __('Retirar pedido') }}
                        </flux:button>
                    </div>
                </div>
             </flux:modal>
             @endcan
</div>
