<?php

use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Validate('string|max:100')]
    public string $search = '';

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return Order::query()
            ->with(['customer', 'cakeCategory', 'basePrice'])
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
            ->latest()
            ->paginate(10);
    }

    public function updatedSearch(): void
    {
        $this->validateOnly('search');
        $this->resetPage();
        unset($this->orders);
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

    public function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public function formatDeliveryDate(?\Carbon\CarbonInterface $deliveryAt): string
    {
        return $deliveryAt?->format('d/m/Y H:i') ?? '—';
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                    {{ __('Administración') }}
                </p>
                <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                    {{ __('Pedidos') }}
                </h1>
                <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                    {{ __('Consulta pedidos y abre su detalle para revisar la trazabilidad.') }}
                </p>
            </div>

            <flux:button href="{{ route('orders.create') }}" wire:navigate variant="primary" icon="plus" tooltip="{{ __('Abrir el formulario para registrar un pedido') }}">
                {{ __('Nuevo pedido') }}
            </flux:button>
        </header>

        <section class="flex flex-col gap-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="{{ __('Buscar pedido') }}"
                placeholder="{{ __('Número, nombre o teléfono') }}"
                type="search"
                autocomplete="off"
            />

            <div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                @if ($this->orders->isEmpty())
                    <div class="p-8 text-center">
                        <p class="font-semibold text-brand-950 dark:text-brand-50">{{ __('No hay pedidos para mostrar.') }}</p>
                        <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Registra un pedido nuevo o prueba otra búsqueda.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[64rem] table-fixed divide-y divide-brand-200 text-left text-sm dark:divide-brand-800">
                            <thead class="bg-brand-50 text-xs uppercase tracking-wide text-brand-700 dark:bg-brand-950/60 dark:text-brand-200">
                                <tr>
                                    <th class="px-5 py-3 font-semibold" scope="col">{{ __('Pedido') }}</th>
                                    <th class="px-5 py-3 font-semibold" scope="col">{{ __('Cliente') }}</th>
                                    <th class="px-5 py-3 font-semibold" scope="col">{{ __('Entrega') }}</th>
                                    <th class="px-5 py-3 font-semibold" scope="col">{{ __('Precio') }}</th>
                                    <th class="px-5 py-3 font-semibold" scope="col">{{ __('Estado') }}</th>
                                    <th class="px-5 py-3 text-right font-semibold" scope="col">{{ __('Acciones') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-100 dark:divide-brand-800/80">
                                @foreach ($this->orders as $order)
                                    <tr wire:key="order-{{ $order->id }}" class="align-middle">
                                        <td class="px-5 py-4">
                                            <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-semibold text-accent hover:underline" title="{{ __('Abrir el detalle del pedido') }}">
                                                {{ $order->order_number }}
                                            </a>
                                            <p class="mt-1 text-xs text-brand-600 dark:text-brand-300">{{ $this->captureModeLabel($order->capture_mode) }}</p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <p class="font-medium text-brand-950 dark:text-brand-50">{{ $order->customer->full_name }}</p>
                                            <p class="mt-1 text-xs text-brand-600 dark:text-brand-300">{{ $order->customer->phone }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-brand-700 dark:text-brand-200">{{ $this->formatDeliveryDate($order->delivery_at) }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 font-medium text-brand-950 dark:text-brand-50">Q {{ $this->formatMoney($order->agreed_price) }}</td>
                                        <td class="px-5 py-4">
                                            <flux:badge color="{{ $order->status === OrderStatus::Pending ? 'amber' : ($order->status === OrderStatus::Delivered ? 'green' : 'zinc') }}">
                                                {{ $this->statusLabel($order->status) }}
                                            </flux:badge>
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <flux:button href="{{ route('orders.show', $order) }}" wire:navigate variant="ghost" size="sm" tooltip="{{ __('Abrir el detalle y el historial del pedido') }}">
                                                {{ __('Ver detalle') }}
                                            </flux:button>
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
</div>
