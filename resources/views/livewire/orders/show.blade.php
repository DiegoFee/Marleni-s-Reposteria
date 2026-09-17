<?php

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
    public string $status = '';

    public function mount(Order $order): void
    {
        $this->loadOrder($order);
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

    public function startEditing(): void
    {
        $this->fillForm();
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->loadOrder($this->order->fresh());
        $this->editing = false;
        $this->resetValidation();
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
        $validated = $this->validate($this->orderRules());

        $this->order = app(OrderService::class)->update($this->order, [
            'customer_id' => (int) $validated['customerId'],
            'capture_mode' => CaptureMode::from($validated['captureMode']),
            'cake_category_id' => blank($validated['cakeCategoryId']) ? null : (int) $validated['cakeCategoryId'],
            'base_price_id' => blank($validated['basePriceId']) ? null : (int) $validated['basePriceId'],
            'cake_description' => blank($validated['cakeDescription']) ? null : trim($validated['cakeDescription']),
            'agreed_price' => $validated['agreedPrice'],
            'delivery_at' => $validated['deliveryAt'],
            'status' => OrderStatus::from($validated['status']),
        ], Auth::user());

        $this->editing = false;
        $this->loadOrder($this->order);
        unset($this->activityLogs);
        session()->flash('status', 'Pedido actualizado correctamente.');
    }

    public function captureModeLabel(CaptureMode $captureMode): string
    {
        return match ($captureMode) {
            CaptureMode::Standard => __('Estandar'),
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

    public function statusBadgeColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Pending => 'amber',
            OrderStatus::Delivered => 'green',
            OrderStatus::Cancelled => 'zinc',
        };
    }

    public function eventLabel(ActivityEventType $eventType): string
    {
        return match ($eventType) {
            ActivityEventType::OrderCreated => __('Pedido creado'),
            ActivityEventType::OrderUpdated => __('Pedido actualizado'),
            ActivityEventType::OrderStatusChanged => __('Estado actualizado'),
            ActivityEventType::PaymentRegistered => __('Anticipo registrado'),
            ActivityEventType::PaymentVoided => __('Pago anulado'),
            ActivityEventType::NotificationSent => __('Recordatorio enviado'),
            ActivityEventType::NotificationFailed => __('Recordatorio fallido'),
        };
    }

    public function activitySummary(ActivityLog $activityLog): string
    {
        return match ($activityLog->event_type) {
            ActivityEventType::OrderCreated => __('Se registro el pedido y su estado pendiente.'),
            ActivityEventType::OrderUpdated => __('Campos actualizados: ').implode(', ', $activityLog->details['fields'] ?? []),
            ActivityEventType::OrderStatusChanged => __('Cambio de ').($activityLog->details['from'] ?? '').__(' a ').($activityLog->details['to'] ?? ''),
            ActivityEventType::PaymentRegistered => __('Anticipo de Q ').($activityLog->details['amount'] ?? '0.00'),
            ActivityEventType::PaymentVoided => __('Pago anulado.'),
            ActivityEventType::NotificationSent => __('Recordatorio confirmado.'),
            ActivityEventType::NotificationFailed => __('No se pudo enviar el recordatorio.'),
        };
    }

    public function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public function formatDate(?\Carbon\CarbonInterface $date): string
    {
        return $date?->format('d/m/Y H:i') ?? '—';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function orderRules(): array
    {
        $isStandard = $this->captureMode === CaptureMode::Standard->value;

        return [
            'customerId' => ['required', 'integer', Rule::exists('customers', 'id')],
            'captureMode' => ['required', Rule::enum(CaptureMode::class)],
            'cakeCategoryId' => $isStandard
                ? ['required', 'integer', Rule::exists('cake_categories', 'id')->where(fn (QueryBuilder $query) => $query->where('is_active', true))]
                : ['nullable', 'prohibited'],
            'basePriceId' => $isStandard
                ? ['required', 'integer', Rule::exists('base_prices', 'id')->where(fn (QueryBuilder $query) => $query->where('is_active', true))]
                : ['nullable', 'prohibited'],
            'cakeDescription' => $isStandard
                ? ['nullable', 'string', 'max:500']
                : ['required', 'string', 'max:500'],
            'agreedPrice' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'deliveryAt' => ['required', 'date'],
            'status' => ['required', Rule::enum(OrderStatus::class)],
        ];
    }

    private function loadOrder(Order $order): void
    {
        $this->order = $order->load([
            'customer',
            'cakeCategory',
            'basePrice',
            'payments.registeredBy',
            'activityLogs.actorUser',
        ]);
        $this->fillForm();
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
        $this->status = $this->order->status->value;
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3">
                <a href="{{ route('orders.index') }}" wire:navigate class="text-sm font-semibold text-accent hover:underline">← {{ __('Volver a pedidos') }}</a>
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Detalle del pedido') }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">{{ $order->order_number }}</h1>
                        <flux:badge color="{{ $this->statusBadgeColor($order->status) }}">{{ $this->statusLabel($order->status) }}</flux:badge>
                    </div>
                    <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Creado el') }} {{ $this->formatDate($order->created_at) }}</p>
                </div>
            </div>

            @if (! $editing)
                <flux:button wire:click="startEditing" variant="primary" icon="pencil-square">
                    {{ __('Editar pedido') }}
                </flux:button>
            @endif
        </header>

        @if ($editing)
            <form wire:submit="saveChanges" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]">
                <div class="flex flex-col gap-6">
                    <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                        <div class="flex flex-col gap-2">
                            <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Datos editables') }}</h2>
                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Los cambios relevantes quedaran visibles en el historial.') }}</p>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <flux:select wire:model="customerId" label="{{ __('Cliente') }}" required>
                                @foreach ($this->customers as $customer)
                                    <flux:select.option value="{{ $customer->id }}">{{ $customer->full_name }} · {{ $customer->phone }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model.live="captureMode" label="{{ __('Modalidad') }}" required>
                                <flux:select.option value="standard">{{ __('Estandar') }}</flux:select.option>
                                <flux:select.option value="custom">{{ __('Personalizado') }}</flux:select.option>
                            </flux:select>

                            @if ($captureMode === CaptureMode::Standard->value)
                                <flux:select wire:model="cakeCategoryId" label="{{ __('Categoria') }}" placeholder="{{ __('Selecciona una categoria') }}" required>
                                    @foreach ($this->activeCategories as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model.live="basePriceId" label="{{ __('Precio base') }}" placeholder="{{ __('Selecciona un precio') }}" required>
                                    @foreach ($this->activeBasePrices as $basePrice)
                                        <flux:select.option value="{{ $basePrice->id }}">Q {{ number_format((float) $basePrice->amount, 2, '.', ',') }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:textarea wire:model="cakeDescription" label="{{ __('Descripcion del pastel') }}" rows="4" class="md:col-span-2" required />
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
                            <flux:input wire:model="agreedPrice" label="{{ __('Precio pactado') }}" type="number" min="0.01" step="0.01" prefix="Q" required />
                            <flux:input wire:model="deliveryAt" label="{{ __('Fecha y hora de entrega') }}" type="datetime-local" required />
                            <flux:select wire:model="status" label="{{ __('Estado') }}" required>
                                <flux:select.option value="pending">{{ __('Pendiente') }}</flux:select.option>
                                <flux:select.option value="delivered">{{ __('Entregado') }}</flux:select.option>
                                <flux:select.option value="cancelled">{{ __('Cancelado') }}</flux:select.option>
                            </flux:select>
                        </div>
                    </section>

                    <section class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <flux:button wire:click="cancelEditing" type="button" variant="ghost">{{ __('Cancelar') }}</flux:button>
                        <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    </section>
                </aside>
            </form>
        @else
            @php
                $registeredPayments = $order->payments->where('status', PaymentStatus::Registered);
            @endphp

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50 lg:col-span-2">
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Cliente') }}</p>
                        <h2 class="text-xl font-semibold text-brand-950 dark:text-brand-50">{{ $order->customer->full_name }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ $order->customer->phone }}</p>
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
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Resumen') }}</p>
                    <dl class="mt-5 flex flex-col gap-4">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-brand-700 dark:text-brand-200">{{ __('Precio pactado') }}</dt>
                            <dd class="font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($order->agreed_price) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-brand-700 dark:text-brand-200">{{ __('Anticipo registrado') }}</dt>
                            <dd class="font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($registeredPayments->sum('amount')) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-5 text-xs leading-5 text-brand-600 dark:text-brand-300">{{ __('Los abonos posteriores se habilitaran en la fase de pagos.') }}</p>
                </section>
            </div>

            <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                <div class="flex flex-col gap-2">
                    <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Historial del pedido') }}</h2>
                    <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('La creacion y los cambios relevantes se registran con su responsable.') }}</p>
                </div>

                @if ($this->activityLogs->isEmpty())
                    <p class="mt-6 text-sm text-brand-700 dark:text-brand-200">{{ __('Todavia no hay eventos registrados.') }}</p>
                @else
                    <ol class="mt-6 flex flex-col divide-y divide-brand-100 dark:divide-brand-800">
                        @foreach ($this->activityLogs as $activityLog)
                            <li wire:key="activity-{{ $activityLog->id }}" class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <div>
                                    <p class="font-semibold text-brand-950 dark:text-brand-50">{{ $this->eventLabel($activityLog->event_type) }}</p>
                                    <p class="mt-1 text-sm text-brand-700 dark:text-brand-200">{{ $this->activitySummary($activityLog) }}</p>
                                    @if ($activityLog->actorUser)
                                        <p class="mt-2 text-xs text-brand-600 dark:text-brand-300">{{ __('Responsable') }}: {{ $activityLog->actorUser->name }}</p>
                                    @endif
                                </div>
                                <time class="shrink-0 text-xs text-brand-600 dark:text-brand-300">{{ $this->formatDate($activityLog->created_at) }}</time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif
</div>
