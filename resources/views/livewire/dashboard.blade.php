<?php

use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    #[Computed]
    public function pendingOrders(): Collection
    {
        return Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->with([
                'customer:id,full_name,phone',
                'cakeCategory:id,name',
            ])
            ->withSum([
                'payments as registered_payments_total' => fn (Builder $query): Builder => $query->where('status', PaymentStatus::Registered->value),
            ], 'amount')
            ->orderBy('delivery_at')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function urgentOrders(): Collection
    {
        return $this->ordersWithinWindow(0, 24, false);
    }

    #[Computed]
    public function upcomingOrders(): Collection
    {
        return $this->ordersWithinWindow(24, 48, true);
    }

    #[Computed]
    public function pendingBalanceOrders(): Collection
    {
        return $this->pendingOrders->filter(fn (Order $order): bool => BigDecimal::of($this->pendingBalance($order))->isPositive());
    }

    public function registeredPaymentsTotal(Order $order): string
    {
        return (string) ($order->getAttribute('registered_payments_total') ?? '0.00');
    }

    public function pendingBalance(Order $order): string
    {
        $balance = BigDecimal::of((string) $order->agreed_price)
            ->minus($this->registeredPaymentsTotal($order));

        return ($balance->isNegative() ? BigDecimal::of('0') : $balance)
            ->toScale(2)
            ->toString();
    }

    public function cakeLabel(Order $order): string
    {
        return $order->capture_mode === CaptureMode::Custom
            ? (string) $order->cake_description
            : (string) ($order->cakeCategory?->name ?? __('Categoria no disponible'));
    }

    public function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public function formatDeliveryDate(?CarbonInterface $deliveryAt): string
    {
        return $deliveryAt?->format('d/m/Y H:i') ?? '—';
    }

    private function ordersWithinWindow(int $fromHours, int $toHours, bool $includeStart): Collection
    {
        $now = now();
        $from = $now->copy()->addHours($fromHours);
        $to = $now->copy()->addHours($toHours);

        return $this->pendingOrders->filter(function (Order $order) use ($from, $to, $includeStart): bool {
            if ($includeStart) {
                return $order->delivery_at->greaterThanOrEqualTo($from)
                    && $order->delivery_at->lessThanOrEqualTo($to);
            }

            return $order->delivery_at->greaterThanOrEqualTo($from)
                && $order->delivery_at->lessThan($to);
        });
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                {{ __('Administracion') }}
            </p>
            <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                {{ __('Panel de control') }}
            </h1>
            <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                {{ __('Revisa lo que debes preparar y cobrar durante las proximas 48 horas.') }}
            </p>
        </div>

        <flux:button href="{{ route('orders.create') }}" wire:navigate variant="primary" icon="plus">
            {{ __('Nuevo pedido') }}
        </flux:button>
    </header>

    <section class="grid gap-6 lg:grid-cols-2" aria-label="{{ __('Entregas proximas') }}">
        <article class="rounded-2xl border border-rose-200 bg-rose-50 p-6 shadow-sm dark:border-rose-900/70 dark:bg-rose-950/30">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-rose-700 dark:text-rose-300">
                        {{ __('Prioridad alta') }}
                    </p>
                    <h2 class="text-xl font-semibold text-rose-950 dark:text-rose-50" id="urgent-orders-heading">
                        {{ __('Proximas 24 horas') }}
                    </h2>
                    <p class="text-sm text-rose-800 dark:text-rose-200">
                        {{ __('Pedidos pendientes que requieren preparacion inmediata.') }}
                    </p>
                </div>
                <flux:badge color="red">{{ $this->urgentOrders->count() }}</flux:badge>
            </div>

            @if ($this->urgentOrders->isEmpty())
                <p class="mt-6 rounded-xl border border-dashed border-rose-300 p-5 text-sm text-rose-800 dark:border-rose-800 dark:text-rose-200">
                    {{ __('No hay entregas pendientes en esta ventana.') }}
                </p>
            @else
                <div class="mt-6 flex flex-col divide-y divide-rose-200 dark:divide-rose-900/70">
                    @foreach ($this->urgentOrders as $order)
                        <article wire:key="urgent-order-{{ $order->id }}" class="flex flex-col gap-4 py-5 first:pt-0 last:pb-0">
                            <div class="flex flex-col gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-semibold text-accent hover:underline">
                                        {{ $order->order_number }}
                                    </a>
                                    <flux:badge color="amber">{{ __('Pendiente') }}</flux:badge>
                                </div>
                                <p class="text-sm font-medium text-rose-950 dark:text-rose-50">{{ $order->customer->full_name }}</p>
                                <p class="text-sm text-rose-800 dark:text-rose-200">{{ $order->customer->phone }}</p>
                                <p class="text-sm text-rose-800 dark:text-rose-200">{{ $this->cakeLabel($order) }}</p>
                            </div>

                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-rose-700 dark:text-rose-300">{{ __('Entrega') }}</dt>
                                    <dd class="mt-1 font-semibold text-rose-950 dark:text-rose-50">
                                        <time datetime="{{ $order->delivery_at->toIso8601String() }}">{{ $this->formatDeliveryDate($order->delivery_at) }}</time>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-rose-700 dark:text-rose-300">{{ __('Precio') }}</dt>
                                    <dd class="mt-1 font-semibold text-rose-950 dark:text-rose-50">Q {{ $this->formatMoney($order->agreed_price) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-rose-700 dark:text-rose-300">{{ __('Total pagado') }}</dt>
                                    <dd class="mt-1 font-semibold text-rose-950 dark:text-rose-50">Q {{ $this->formatMoney($this->registeredPaymentsTotal($order)) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-rose-700 dark:text-rose-300">{{ __('Saldo') }}</dt>
                                    <dd class="mt-1 font-semibold text-rose-950 dark:text-rose-50">Q {{ $this->formatMoney($this->pendingBalance($order)) }}</dd>
                                </div>
                            </dl>

                            <flux:button href="{{ route('orders.show', $order) }}" wire:navigate variant="ghost" size="sm" class="self-start">
                                {{ __('Ver detalle') }}
                            </flux:button>
                        </article>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm dark:border-amber-900/70 dark:bg-amber-950/30">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-300">
                        {{ __('Siguiente ventana') }}
                    </p>
                    <h2 class="text-xl font-semibold text-amber-950 dark:text-amber-50" id="upcoming-orders-heading">
                        {{ __('De 24 a 48 horas') }}
                    </h2>
                    <p class="text-sm text-amber-800 dark:text-amber-200">
                        {{ __('Pedidos pendientes para organizar la preparacion siguiente.') }}
                    </p>
                </div>
                <flux:badge color="amber">{{ $this->upcomingOrders->count() }}</flux:badge>
            </div>

            @if ($this->upcomingOrders->isEmpty())
                <p class="mt-6 rounded-xl border border-dashed border-amber-300 p-5 text-sm text-amber-800 dark:border-amber-800 dark:text-amber-200">
                    {{ __('No hay entregas pendientes en esta ventana.') }}
                </p>
            @else
                <div class="mt-6 flex flex-col divide-y divide-amber-200 dark:divide-amber-900/70">
                    @foreach ($this->upcomingOrders as $order)
                        <article wire:key="upcoming-order-{{ $order->id }}" class="flex flex-col gap-4 py-5 first:pt-0 last:pb-0">
                            <div class="flex flex-col gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-semibold text-accent hover:underline">
                                        {{ $order->order_number }}
                                    </a>
                                    <flux:badge color="amber">{{ __('Pendiente') }}</flux:badge>
                                </div>
                                <p class="text-sm font-medium text-amber-950 dark:text-amber-50">{{ $order->customer->full_name }}</p>
                                <p class="text-sm text-amber-800 dark:text-amber-200">{{ $order->customer->phone }}</p>
                                <p class="text-sm text-amber-800 dark:text-amber-200">{{ $this->cakeLabel($order) }}</p>
                            </div>

                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-amber-700 dark:text-amber-300">{{ __('Entrega') }}</dt>
                                    <dd class="mt-1 font-semibold text-amber-950 dark:text-amber-50">
                                        <time datetime="{{ $order->delivery_at->toIso8601String() }}">{{ $this->formatDeliveryDate($order->delivery_at) }}</time>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-amber-700 dark:text-amber-300">{{ __('Precio') }}</dt>
                                    <dd class="mt-1 font-semibold text-amber-950 dark:text-amber-50">Q {{ $this->formatMoney($order->agreed_price) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-amber-700 dark:text-amber-300">{{ __('Total pagado') }}</dt>
                                    <dd class="mt-1 font-semibold text-amber-950 dark:text-amber-50">Q {{ $this->formatMoney($this->registeredPaymentsTotal($order)) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-amber-700 dark:text-amber-300">{{ __('Saldo') }}</dt>
                                    <dd class="mt-1 font-semibold text-amber-950 dark:text-amber-50">Q {{ $this->formatMoney($this->pendingBalance($order)) }}</dd>
                                </div>
                            </dl>

                            <flux:button href="{{ route('orders.show', $order) }}" wire:navigate variant="ghost" size="sm" class="self-start">
                                {{ __('Ver detalle') }}
                            </flux:button>
                        </article>
                    @endforeach
                </div>
            @endif
        </article>
    </section>

    <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50" aria-labelledby="pending-balances-heading">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                    {{ __('Cobros pendientes') }}
                </p>
                <h2 id="pending-balances-heading" class="text-xl font-semibold text-brand-950 dark:text-brand-50">
                    {{ __('Pedidos con saldo pendiente') }}
                </h2>
                <p class="text-sm text-brand-700 dark:text-brand-200">
                    {{ __('El saldo se calcula con el precio pactado y los pagos registrados activos.') }}
                </p>
            </div>
            <flux:badge color="blue">{{ $this->pendingBalanceOrders->count() }}</flux:badge>
        </div>

        @if ($this->pendingBalanceOrders->isEmpty())
            <p class="mt-6 rounded-xl border border-dashed border-brand-300 p-5 text-sm text-brand-700 dark:border-brand-700 dark:text-brand-200">
                {{ __('No hay pedidos pendientes de cobro.') }}
            </p>
        @else
            <div class="mt-6 flex flex-col divide-y divide-brand-100 dark:divide-brand-800">
                @foreach ($this->pendingBalanceOrders as $order)
                    <article wire:key="balance-order-{{ $order->id }}" class="flex flex-col gap-4 py-5 first:pt-0 last:pb-0">
                        <div class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-semibold text-accent hover:underline">
                                    {{ $order->order_number }}
                                </a>
                                <flux:badge color="amber">{{ __('Pendiente') }}</flux:badge>
                            </div>
                            <p class="text-sm font-medium text-brand-950 dark:text-brand-50">{{ $order->customer->full_name }}</p>
                            <p class="text-sm text-brand-700 dark:text-brand-200">{{ $order->customer->phone }} · {{ $this->cakeLabel($order) }}</p>
                        </div>

                        <dl class="grid gap-3 text-sm sm:grid-cols-4">
                            <div>
                                <dt class="text-brand-600 dark:text-brand-300">{{ __('Entrega') }}</dt>
                                <dd class="mt-1 font-semibold text-brand-950 dark:text-brand-50">
                                    <time datetime="{{ $order->delivery_at->toIso8601String() }}">{{ $this->formatDeliveryDate($order->delivery_at) }}</time>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-brand-600 dark:text-brand-300">{{ __('Precio') }}</dt>
                                <dd class="mt-1 font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($order->agreed_price) }}</dd>
                            </div>
                            <div>
                                <dt class="text-brand-600 dark:text-brand-300">{{ __('Total pagado') }}</dt>
                                <dd class="mt-1 font-semibold text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($this->registeredPaymentsTotal($order)) }}</dd>
                            </div>
                            <div>
                                <dt class="text-brand-600 dark:text-brand-300">{{ __('Saldo') }}</dt>
                                <dd class="mt-1 font-semibold text-accent">Q {{ $this->formatMoney($this->pendingBalance($order)) }}</dd>
                            </div>
                        </dl>

                        <flux:button href="{{ route('orders.show', $order) }}" wire:navigate variant="ghost" size="sm" class="self-start">
                            {{ __('Ver detalle') }}
                        </flux:button>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
