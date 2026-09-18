<?php

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\NotificationMessageType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderDeletionService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Validate('string|max:100')]
    public string $search = '';
    public bool $showDetailModal = false;
    public ?int $viewingOrderId = null;
    public bool $showDeleteConfirmation = false;
    public ?int $deletingOrderId = null;

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return $this->historyOrdersQuery()
            ->with(['customer'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function (Builder $query) use ($search): void {
                            $query
                                ->where('full_name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('delivery_at')
            ->latest('id')
            ->paginate(10);
    }

    #[Computed]
    public function selectedOrder(): ?Order
    {
        if ($this->viewingOrderId === null) {
            return null;
        }

        return $this->historyOrdersQuery()
            ->with([
                'customer',
                'cakeCategory',
                'basePrice',
                'payments.registeredBy',
                'payments.voidedBy',
                'activityLogs' => function (HasMany $query): void {
                    $query->with('actorUser')->latest('created_at');
                },
            ])
            ->withSum([
                'payments as registered_payments_total' => fn (Builder $query): Builder => $query->where('status', PaymentStatus::Registered->value),
            ], 'amount')
            ->find($this->viewingOrderId);
    }

    public function updatedSearch(): void
    {
        $this->validateOnly('search');
        $this->resetPage();
        unset($this->orders);
    }

    public function updatedShowDetailModal(bool $showDetailModal): void
    {
        if (! $showDetailModal) {
            $this->viewingOrderId = null;
            unset($this->selectedOrder);
        }
    }

    public function updatedShowDeleteConfirmation(bool $showDeleteConfirmation): void
    {
        if (! $showDeleteConfirmation) {
            $this->deletingOrderId = null;
        }
    }

    public function viewOrder(int $orderId): void
    {
        $this->historyOrdersQuery()
            ->findOrFail($orderId);

        $this->viewingOrderId = $orderId;
        $this->showDetailModal = true;
        unset($this->selectedOrder);
    }

    public function closeDetail(): void
    {
        $this->showDetailModal = false;
        $this->viewingOrderId = null;
        unset($this->selectedOrder);
    }

    public function requestDelete(int $orderId): void
    {
        $this->historyOrdersQuery()
            ->findOrFail($orderId);

        $this->deletingOrderId = $orderId;
        $this->showDeleteConfirmation = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteConfirmation = false;
        $this->deletingOrderId = null;
    }

    public function deleteOrder(OrderDeletionService $orderDeletionService): void
    {
        if (! $this->ensurePasswordConfirmation()) {
            return;
        }

        $order = $this->historyOrdersQuery()
            ->findOrFail($this->deletingOrderId);

        $actor = Auth::user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $orderDeletionService->forceDelete($order, $actor);
        $this->cancelDelete();
        unset($this->orders);
        session()->flash('status', 'Pedido eliminado definitivamente del historial.');
    }

    public function formatDeliveryDate(?\Carbon\CarbonInterface $deliveryAt): string
    {
        return $deliveryAt?->format('d/m/Y H:i') ?? '—';
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

    public function statusBadgeColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Pending => 'amber',
            OrderStatus::Delivered => 'green',
            OrderStatus::Cancelled => 'zinc',
        };
    }

    public function eventLabel(ActivityEventType $eventType, ?ActivityLog $activityLog = null): string
    {
        return match ($eventType) {
            ActivityEventType::OrderCreated => __('Pedido creado'),
            ActivityEventType::OrderUpdated => __('Pedido actualizado'),
            ActivityEventType::OrderStatusChanged => __('Estado actualizado'),
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
            ActivityEventType::OrderStatusChanged => __('Cambio de ').$this->orderStatusLabel((string) ($activityLog->details['from'] ?? '')).__(' a ').$this->orderStatusLabel((string) ($activityLog->details['to'] ?? '')),
            ActivityEventType::PaymentRegistered => $this->paymentTypeActivitySummary($activityLog),
            ActivityEventType::PaymentVoided => __('Pago de Q ').($activityLog->details['amount'] ?? '0.00').' '.__('anulado: ').($activityLog->details['void_reason'] ?? ''),
            ActivityEventType::NotificationSent => ($activityLog->details['notification_type'] ?? null) === NotificationMessageType::OrderCreatedSummary->value
                ? __('Resumen del pedido enviado a la administradora.')
                : __('Recordatorio confirmado.'),
            ActivityEventType::NotificationFailed => ($activityLog->details['notification_type'] ?? null) === NotificationMessageType::OrderCreatedSummary->value
                ? __('No se pudo enviar el resumen del pedido.')
                : __('No se pudo enviar el recordatorio.'),
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

    public function registeredPaymentsTotal(): string
    {
        return (string) ($this->selectedOrder?->getAttribute('registered_payments_total') ?? '0.00');
    }

    public function pendingBalance(): string
    {
        if ($this->selectedOrder === null) {
            return '0.00';
        }

        $balance = BigDecimal::of((string) $this->selectedOrder->agreed_price)
            ->minus($this->money($this->registeredPaymentsTotal()));

        return ($balance->isNegative() ? BigDecimal::of('0') : $balance)
            ->toScale(2)
            ->toString();
    }

    public function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public function formatDate(?\Carbon\CarbonInterface $date): string
    {
        return $date?->format('d/m/Y H:i') ?? '—';
    }

    private function paymentTypeActivitySummary(ActivityLog $activityLog): string
    {
        $paymentType = PaymentType::tryFrom((string) ($activityLog->details['payment_type'] ?? PaymentType::Deposit->value));
        $label = $paymentType === null ? __('Pago') : $this->paymentTypeLabel($paymentType);

        return $label.__(' de Q ').($activityLog->details['amount'] ?? '0.00');
    }

    private function money(mixed $amount): BigDecimal
    {
        return is_numeric($amount) ? BigDecimal::of((string) $amount) : BigDecimal::of('0');
    }

    private function historyOrdersQuery(): Builder
    {
        return Order::query()
            ->withTrashed()
            ->where(function (Builder $query): void {
                $query
                    ->where('status', OrderStatus::Delivered->value)
                    ->orWhereNotNull('orders.deleted_at');
            });
    }

    private function ensurePasswordConfirmation(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        if ($confirmedAt > 0 && now()->timestamp - $confirmedAt <= (int) config('auth.password_timeout')) {
            return true;
        }

        session()->put('url.intended', route('history.index', absolute: false));
        $this->redirect(route('password.confirm', absolute: false), navigate: true);

        return false;
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                {{ __('Administración') }}
            </p>
            <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                {{ __('Historial') }}
            </h1>
            <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                {{ __('Consulta los pedidos entregados y los pedidos archivados hasta eliminarlos definitivamente.') }}
            </p>
        </div>
    </header>

    <section class="flex flex-col gap-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            label="{{ __('Buscar en el historial') }}"
            placeholder="{{ __('Número, nombre o teléfono') }}"
            type="search"
            autocomplete="off"
        />

        <div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                @if ($this->orders->isEmpty())
                    <div class="p-8 text-center">
                    <p class="font-semibold text-brand-950 dark:text-brand-50">{{ __('No hay pedidos entregados o archivados para mostrar.') }}</p>
                    <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Los pedidos aparecerán aquí cuando tengan estado Entregado o se archiven desde Pedidos.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[68rem] table-fixed divide-y divide-brand-200 text-left text-sm dark:divide-brand-800">
                        <thead class="bg-brand-50 text-xs uppercase tracking-wide text-brand-700 dark:bg-brand-950/60 dark:text-brand-200">
                            <tr>
                                <th class="px-5 py-3 font-semibold" scope="col">{{ __('Pedido') }}</th>
                                <th class="px-5 py-3 font-semibold" scope="col">{{ __('Cliente') }}</th>
                                <th class="px-5 py-3 font-semibold" scope="col">{{ __('Entrega') }}</th>
                                <th class="px-5 py-3 font-semibold" scope="col">{{ __('Estado') }}</th>
                                <th class="px-5 py-3 text-right font-semibold" scope="col">{{ __('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-100 dark:divide-brand-800/80">
                            @foreach ($this->orders as $order)
                                <tr wire:key="history-order-{{ $order->id }}" class="align-middle">
                                    <td class="px-5 py-4">
                                        <button type="button" wire:click="viewOrder({{ $order->id }})" class="font-semibold text-accent hover:underline" title="{{ __('Abrir el detalle completo del pedido') }}">
                                            {{ $order->order_number }}
                                        </button>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-medium text-brand-950 dark:text-brand-50">{{ $order->customer->full_name }}</p>
                                        <p class="mt-1 text-xs text-brand-600 dark:text-brand-300">{{ $order->customer->phone }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-brand-700 dark:text-brand-200">{{ $this->formatDeliveryDate($order->delivery_at) }}</td>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap gap-2">
                                            <flux:badge color="{{ $this->statusBadgeColor($order->status) }}">{{ $this->statusLabel($order->status) }}</flux:badge>
                                            @if ($order->trashed())
                                                <flux:badge color="zinc">{{ __('Eliminado de Pedidos') }}</flux:badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <flux:button wire:click="viewOrder({{ $order->id }})" variant="ghost" size="sm" tooltip="{{ __('Ver todos los datos del pedido') }}">
                                                {{ __('Ver detalle') }}
                                            </flux:button>
                                            <flux:button wire:click="requestDelete({{ $order->id }})" variant="danger" size="sm" class="marleni-danger-button" tooltip="{{ __('Eliminar definitivamente este pedido del historial') }}">
                                                {{ __('Borrar') }}
                                            </flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-brand-200 px-5 py-4 dark:border-brand-800">
                    {{ $this->orders->links() }}
                </div>
            @endif
        </div>
    </section>

    <flux:modal wire:model="showDetailModal" focusable class="max-w-5xl border border-brand-200 bg-brand-50 dark:border-brand-700 dark:bg-brand-950">
        @if ($this->selectedOrder)
            <div class="flex max-h-[80vh] flex-col gap-6 overflow-y-auto p-1 text-base">
                <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Detalle del pedido') }}</p>
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="font-display text-2xl font-semibold text-brand-950 dark:text-brand-50">{{ $this->selectedOrder->order_number }}</h2>
                            <flux:badge color="{{ $this->statusBadgeColor($this->selectedOrder->status) }}">{{ $this->statusLabel($this->selectedOrder->status) }}</flux:badge>
                            @if ($this->selectedOrder->trashed())
                                <flux:badge color="zinc">{{ __('Eliminado de Pedidos') }}</flux:badge>
                            @endif
                        </div>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Creado el') }} {{ $this->formatDate($this->selectedOrder->created_at) }}</p>
                    </div>
                </header>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                    <section class="rounded-2xl border border-brand-200 bg-white p-5 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Cliente') }}</p>
                        <h3 class="mt-2 text-xl font-semibold text-brand-950 dark:text-brand-50">{{ $this->selectedOrder->customer->full_name }}</h3>
                        <p class="mt-1 text-sm text-brand-700 dark:text-brand-200">{{ $this->selectedOrder->customer->phone }}</p>

                        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ __('Modalidad') }}</dt>
                                <dd class="mt-1 font-medium text-brand-950 dark:text-brand-50">{{ $this->captureModeLabel($this->selectedOrder->capture_mode) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ __('Entrega') }}</dt>
                                <dd class="mt-1 font-medium text-brand-950 dark:text-brand-50">{{ $this->formatDate($this->selectedOrder->delivery_at) }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ __('Pastel') }}</dt>
                                <dd class="mt-1 font-medium text-brand-950 dark:text-brand-50">
                                    @if ($this->selectedOrder->capture_mode === CaptureMode::Standard)
                                        {{ $this->selectedOrder->cakeCategory?->name }} · Q {{ $this->formatMoney($this->selectedOrder->basePrice?->amount) }}
                                    @else
                                        {{ $this->selectedOrder->cake_description }}
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="rounded-2xl border border-brand-200 bg-white p-5 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">{{ __('Resumen financiero') }}</p>
                        <dl class="mt-5 flex flex-col gap-4">
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-brand-700 dark:text-brand-200">{{ __('Precio pactado') }}</dt>
                                <dd class="font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($this->selectedOrder->agreed_price) }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-brand-700 dark:text-brand-200">{{ __('Total pagado') }}</dt>
                                <dd class="font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($this->registeredPaymentsTotal()) }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 border-t border-brand-100 pt-4 text-sm dark:border-brand-800">
                                <dt class="font-semibold text-brand-700 dark:text-brand-200">{{ __('Saldo pendiente') }}</dt>
                                <dd class="text-lg font-semibold text-accent">Q {{ $this->formatMoney($this->pendingBalance()) }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <section class="rounded-2xl border border-brand-200 bg-white p-5 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Historial de pagos') }}</h3>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Los pagos anulados se conservan y no afectan el saldo.') }}</p>
                    </div>

                    @if ($this->selectedOrder->payments->isEmpty())
                        <p class="mt-5 text-sm text-brand-700 dark:text-brand-200">{{ __('Todavía no hay pagos registrados.') }}</p>
                    @else
                        <div class="mt-5 flex flex-col divide-y divide-brand-100 dark:divide-brand-800">
                            @foreach ($this->selectedOrder->payments as $payment)
                                <article wire:key="history-payment-{{ $payment->id }}" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:badge color="{{ $this->paymentTypeBadgeColor($payment->payment_type) }}">{{ $this->paymentTypeLabel($payment->payment_type) }}</flux:badge>
                                            <flux:badge color="{{ $payment->status === PaymentStatus::Registered ? 'green' : 'zinc' }}">{{ $this->paymentStatusLabel($payment->status) }}</flux:badge>
                                        </div>
                                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Fecha del pago') }}: {{ $this->formatDate($payment->paid_at) }}</p>
                                        @if ($payment->notes)
                                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ $payment->notes }}</p>
                                        @endif
                                        @if ($payment->status === PaymentStatus::Voided)
                                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Motivo') }}: {{ $payment->void_reason }}</p>
                                        @endif
                                    </div>
                                    <p class="shrink-0 text-lg font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($payment->amount) }}</p>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="rounded-2xl border border-brand-200 bg-white p-5 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Historial del pedido') }}</h3>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('La creación y los cambios relevantes se registran con su responsable.') }}</p>
                    </div>

                    @if ($this->selectedOrder->activityLogs->isEmpty())
                        <p class="mt-5 text-sm text-brand-700 dark:text-brand-200">{{ __('Todavía no hay eventos registrados.') }}</p>
                    @else
                        <ol class="mt-5 flex flex-col divide-y divide-brand-100 dark:divide-brand-800">
                            @foreach ($this->selectedOrder->activityLogs as $activityLog)
                                <li wire:key="history-activity-{{ $activityLog->id }}" class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div>
                                        <p class="font-semibold text-brand-950 dark:text-brand-50">{{ $this->eventLabel($activityLog->event_type, $activityLog) }}</p>
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

                <div class="flex justify-end">
                    <flux:button wire:click="closeDetail" type="button" variant="ghost">{{ __('Cerrar') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model="showDeleteConfirmation" focusable class="max-w-lg border border-brand-200 bg-brand-50 dark:border-brand-700 dark:bg-brand-950">
        <div class="flex flex-col gap-5 text-base">
            <div class="flex items-start gap-4">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-800 text-xl font-bold text-white shadow-lg shadow-brand-900/20">!</div>
                <div>
                    <flux:heading size="lg" class="font-display text-brand-950 dark:text-brand-50">{{ __('¿Borrar del historial?') }}</flux:heading>
                    <p class="mt-2 text-base leading-6 text-brand-700 dark:text-brand-200">{{ __('Se eliminarán el pedido, sus pagos y eventos. No se puede deshacer.') }}</p>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <flux:button wire:click="cancelDelete" type="button" variant="ghost" tooltip="{{ __('Cerrar sin eliminar el pedido') }}">
                    {{ __('Cancelar') }}
                </flux:button>
                <flux:button wire:click="deleteOrder" type="button" variant="danger" class="marleni-danger-button" tooltip="{{ __('Confirmar la eliminación definitiva') }}">
                    {{ __('Borrar') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
