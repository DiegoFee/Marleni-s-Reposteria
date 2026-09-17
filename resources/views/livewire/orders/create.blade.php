<?php

use App\Enums\CaptureMode;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Services\Orders\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $customerId = null;
    #[Validate('string|max:100')]
    public string $customerSearch = '';
    public bool $showCustomerForm = false;
    public string $newCustomerName = '';
    public string $newCustomerPhone = '';
    public string $captureMode = 'standard';
    public string $cakeCategoryId = '';
    public string $basePriceId = '';
    public string $cakeDescription = '';
    public string $agreedPrice = '';
    public string $deliveryAt = '';
    public string $depositAmount = '0';

    public function mount(): void
    {
        $this->deliveryAt = now()->addDay()->format('Y-m-d\TH:i');
    }

    #[Computed]
    public function customerOptions(): Collection
    {
        $search = mb_substr(trim($this->customerSearch), 0, 100);

        if ($search === '' || $this->customerId !== null) {
            return collect();
        }

        return Customer::query()
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('full_name')
            ->limit(8)
            ->get();
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

    public function updatedCaptureMode(string $captureMode): void
    {
        if ($captureMode === CaptureMode::Custom->value) {
            $this->cakeCategoryId = '';
            $this->basePriceId = '';
            return;
        }

        $this->cakeDescription = '';
    }

    public function updatedCustomerSearch(): void
    {
        $this->validateOnly('customerSearch');
        unset($this->customerOptions);
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

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->customerId = $customer->getKey();
        $this->customerSearch = $this->customerLabel($customer);
        $this->showCustomerForm = false;
        unset($this->customerOptions);
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->customerSearch = '';
        unset($this->customerOptions);
    }

    public function createCustomer(): void
    {
        $this->newCustomerName = trim($this->newCustomerName);
        $this->newCustomerPhone = trim($this->newCustomerPhone);

        $validated = $this->validate([
            'newCustomerName' => ['required', 'string', 'max:150'],
            'newCustomerPhone' => ['required', 'string', 'max:25'],
        ]);

        $attributes = [
            'full_name' => $validated['newCustomerName'],
            'phone' => $validated['newCustomerPhone'],
        ];

        $customer = Customer::query()->firstOrCreate($attributes);

        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
        $this->resetValidation();
        $this->selectCustomer($customer->getKey());
    }

    public function save(): void
    {
        $this->cakeDescription = trim($this->cakeDescription);
        $this->agreedPrice = trim($this->agreedPrice);
        $this->deliveryAt = trim($this->deliveryAt);
        $this->depositAmount = trim($this->depositAmount);

        $validated = $this->validate($this->orderRules());

        $order = app(OrderService::class)->create([
            'customer_id' => (int) $validated['customerId'],
            'capture_mode' => CaptureMode::from($validated['captureMode']),
            'cake_category_id' => blank($validated['cakeCategoryId']) ? null : (int) $validated['cakeCategoryId'],
            'base_price_id' => blank($validated['basePriceId']) ? null : (int) $validated['basePriceId'],
            'cake_description' => blank($validated['cakeDescription']) ? null : trim($validated['cakeDescription']),
            'agreed_price' => $validated['agreedPrice'],
            'delivery_at' => $validated['deliveryAt'],
            'deposit_amount' => blank($validated['depositAmount']) ? '0' : $validated['depositAmount'],
        ], Auth::user());

        session()->flash('status', 'Pedido registrado correctamente.');

        $this->redirect(route('orders.show', $order), navigate: true);
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
            'agreedPrice' => ['required', 'numeric', 'decimal:0,2', 'max:99999999.99', 'gt:0'],
            'deliveryAt' => ['required', 'date'],
            'depositAmount' => ['nullable', 'numeric', 'decimal:0,2', 'max:99999999.99', 'min:0', 'lte:agreedPrice'],
        ];
    }

    private function customerLabel(Customer $customer): string
    {
        return $customer->full_name.' · '.$customer->phone;
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-3">
            <a href="{{ route('orders.index') }}" wire:navigate class="text-sm font-semibold text-accent hover:underline">← {{ __('Volver a pedidos') }}</a>
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                    {{ __('Pedidos') }}
                </p>
                <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                    {{ __('Nuevo pedido') }}
                </h1>
                <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                    {{ __('Registra el cliente, la modalidad, el precio pactado y la fecha de entrega.') }}
                </p>
            </div>
        </header>

        <form wire:submit="save" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]">
            <div class="flex flex-col gap-6">
                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Cliente') }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Busca un cliente existente o registra uno sin salir del pedido.') }}</p>
                    </div>

                    <div class="mt-5 flex flex-col gap-4">
                        <flux:input
                            wire:model.live.debounce.300ms="customerSearch"
                            label="{{ __('Buscar por nombre o telefono') }}"
                            placeholder="{{ __('Escribe para buscar') }}"
                            type="search"
                            autocomplete="off"
                        />

                        @if ($this->customerOptions->isNotEmpty())
                            <div class="-mt-2 overflow-hidden rounded-xl border border-brand-200 bg-white dark:border-brand-700 dark:bg-brand-900">
                                @foreach ($this->customerOptions as $customer)
                                    <button
                                        wire:click="selectCustomer({{ $customer->id }})"
                                        type="button"
                                        class="flex min-h-11 w-full flex-col gap-1 border-b border-brand-100 px-4 py-3 text-left last:border-0 hover:bg-brand-50 dark:border-brand-800 dark:hover:bg-brand-950"
                                    >
                                        <span class="font-medium text-brand-950 dark:text-brand-50">{{ $customer->full_name }}</span>
                                        <span class="text-sm text-brand-600 dark:text-brand-300">{{ $customer->phone }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if ($customerId !== null)
                            <div class="flex items-center justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm dark:border-emerald-900/60 dark:bg-emerald-950/30">
                                <div>
                                    <p class="font-semibold text-emerald-950 dark:text-emerald-50">{{ __('Cliente seleccionado') }}</p>
                                    <p class="mt-1 text-emerald-800 dark:text-emerald-100">{{ $customerSearch }}</p>
                                </div>
                                <flux:button wire:click="clearCustomer" type="button" variant="ghost" size="sm">
                                    {{ __('Cambiar') }}
                                </flux:button>
                            </div>
                        @else
                            <flux:button wire:click="$set('showCustomerForm', true)" type="button" variant="ghost" icon="user-plus">
                                {{ __('Registrar cliente nuevo') }}
                            </flux:button>
                        @endif

                        @if ($showCustomerForm)
                            <div class="grid gap-4 rounded-xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-700 dark:bg-brand-950/50 sm:grid-cols-2">
                                <flux:input wire:model="newCustomerName" label="{{ __('Nombre completo') }}" name="newCustomerName" />
                                <flux:input wire:model="newCustomerPhone" label="{{ __('Telefono') }}" name="newCustomerPhone" type="tel" />
                                <div class="flex flex-col gap-2 sm:col-span-2 sm:flex-row sm:justify-end">
                                    <flux:button wire:click="$set('showCustomerForm', false)" type="button" variant="ghost">
                                        {{ __('Cancelar') }}
                                    </flux:button>
                                    <flux:button wire:click="createCustomer" type="button" variant="primary">
                                        {{ __('Usar este cliente') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Datos del pastel') }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Elige una opcion del catalogo o describe un pedido personalizado.') }}</p>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
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
                            <flux:textarea wire:model="cakeDescription" label="{{ __('Descripcion del pastel') }}" placeholder="{{ __('Describe el diseno, sabor o detalles solicitados') }}" rows="4" class="md:col-span-2" required />
                        @endif
                    </div>
                </section>
            </div>

            <aside class="flex h-fit flex-col gap-6">
                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Condiciones del pedido') }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('El precio pactado conserva el acuerdo aunque cambie el catalogo.') }}</p>
                    </div>

                    <div class="mt-5 flex flex-col gap-4">
                        <flux:input wire:model="agreedPrice" label="{{ __('Precio pactado') }}" type="number" min="0.01" step="0.01" prefix="Q" required />
                        <flux:input wire:model="deliveryAt" label="{{ __('Fecha y hora de entrega') }}" type="datetime-local" required />
                        <flux:input wire:model="depositAmount" label="{{ __('Anticipo inicial') }}" type="number" min="0" step="0.01" prefix="Q" />
                        <p class="text-xs leading-5 text-brand-600 dark:text-brand-300">{{ __('El anticipo es opcional y no puede superar el precio pactado.') }}</p>
                    </div>
                </section>

                <section class="rounded-2xl border border-brand-200 bg-brand-50 p-6 dark:border-brand-800 dark:bg-brand-950/50">
                    <p class="text-sm leading-6 text-brand-800 dark:text-brand-100">{{ __('Al guardar se creara el pedido con estado pendiente y se registrara su historial inicial.') }}</p>
                    <flux:button type="submit" variant="primary" class="mt-5 w-full">
                        {{ __('Guardar pedido') }}
                    </flux:button>
                </section>
            </aside>
        </form>
</div>
