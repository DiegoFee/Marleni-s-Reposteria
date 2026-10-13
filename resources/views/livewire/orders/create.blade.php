<?php

use App\Enums\CaptureMode;
use App\Livewire\Forms\CustomerContactForm;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Models\Order;
use App\Services\MoneyValidation;
use App\Services\Orders\OrderService;
use App\Livewire\Forms\OrderFormRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $customerId = null;
    #[Validate('string|max:100')]
    public string $customerSearch = '';
    public bool $showCustomerForm = false;
    public bool $newCustomerSelected = false;
    public CustomerContactForm $customerContact;
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

        if ($search === '' || $this->customerId !== null || $this->newCustomerSelected) {
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
        if ($this->newCustomerSelected) {
            $this->newCustomerSelected = false;
        }

        if ($this->customerId !== null) {
            $selectedCustomer = Customer::query()->find($this->customerId);

            if ($selectedCustomer === null || $this->customerLabel($selectedCustomer) !== $this->customerSearch) {
                $this->customerId = null;
            }
        }

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

        $this->customerContact->reset();
        $this->customerContact->resetValidation();
        $this->customerId = $customer->getKey();
        $this->newCustomerSelected = false;
        $this->customerSearch = $this->customerLabel($customer);
        $this->showCustomerForm = false;
        unset($this->customerOptions);
    }

    public function clearCustomer(): void
    {
        if ($this->newCustomerSelected) {
            $this->newCustomerSelected = false;
            $this->customerSearch = '';
            $this->showCustomerForm = true;
            unset($this->customerOptions);

            return;
        }

        $this->customerId = null;
        $this->customerSearch = '';
        $this->showCustomerForm = false;
        $this->customerContact->reset();
        $this->customerContact->resetValidation();
        unset($this->customerOptions);
    }

    public function selectNewCustomer(): void
    {
        Gate::authorize('create', Customer::class);

        $customerAttributes = $this->customerContact->validatedAttributes();
        $this->customerId = null;
        $this->newCustomerSelected = true;
        $this->customerSearch = $customerAttributes['full_name'].' · '.$customerAttributes['phone'];
        $this->showCustomerForm = false;
        $this->resetValidation();
        unset($this->customerOptions);
    }

    public function save(): void
    {
        Gate::authorize('create', Order::class);

        $this->cakeDescription = trim($this->cakeDescription);
        $this->agreedPrice = trim($this->agreedPrice);
        $this->deliveryAt = trim($this->deliveryAt);
        $this->depositAmount = trim($this->depositAmount);

        $newCustomerAttributes = null;

        if ($this->isCreatingNewCustomer()) {
            Gate::authorize('create', Customer::class);
            $newCustomerAttributes = $this->customerContact->validatedAttributes();
        }

        $validated = $this->validate(OrderFormRules::order(
            captureMode: $this->captureMode,
            newCustomer: $this->isCreatingNewCustomer(),
            includeDeposit: true,
        ));

        $orderData = [
            'customer_id' => $newCustomerAttributes === null ? (int) $validated['customerId'] : null,
            'capture_mode' => CaptureMode::from($validated['captureMode']),
            'cake_category_id' => blank($validated['cakeCategoryId']) ? null : (int) $validated['cakeCategoryId'],
            'base_price_id' => blank($validated['basePriceId']) ? null : (int) $validated['basePriceId'],
            'cake_description' => blank($validated['cakeDescription']) ? null : trim($validated['cakeDescription']),
            'agreed_price' => $validated['agreedPrice'],
            'delivery_at' => $validated['deliveryAt'],
            'deposit_amount' => blank($validated['depositAmount']) ? '0' : $validated['depositAmount'],
        ];

        $order = app(OrderService::class)->create($orderData, Auth::user(), $newCustomerAttributes);

        session()->flash('status', 'Pedido registrado correctamente.');

        $this->redirect(route('orders.show', $order), navigate: true);
    }

    private function isCreatingNewCustomer(): bool
    {
        return $this->newCustomerSelected || ($this->showCustomerForm && $this->customerId === null);
    }

    private function customerLabel(Customer $customer): string
    {
        return $customer->full_name.' · '.$customer->phone;
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-3">
            <a href="{{ route('orders.index') }}" wire:navigate class="text-sm font-semibold text-accent hover:underline" title="{{ __('Volver al listado de pedidos') }}">← {{ __('Volver a pedidos') }}</a>
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
                            label="{{ __('Buscar por nombre o teléfono') }}"
                            placeholder="{{ __('Escribe para buscar') }}"
                            type="search"
                            autocomplete="off"
                            :readonly="$customerId !== null || $newCustomerSelected"
                        />

                        @if ($this->customerOptions->isNotEmpty())
                            <div class="-mt-2 overflow-hidden rounded-xl border border-brand-200 bg-white dark:border-brand-700 dark:bg-brand-900">
                                @foreach ($this->customerOptions as $customer)
                                    <button
                                        wire:click="selectCustomer({{ $customer->id }})"
                                        type="button"
                                        title="{{ __('Seleccionar este cliente para el pedido') }}"
                                        class="flex min-h-11 w-full flex-col gap-1 border-b border-brand-100 px-4 py-3 text-left last:border-0 hover:bg-brand-50 dark:border-brand-800 dark:hover:bg-brand-950"
                                    >
                                        <span class="font-medium text-brand-950 dark:text-brand-50">{{ $customer->full_name }}</span>
                                        <span class="text-sm text-brand-600 dark:text-brand-300">{{ $customer->phone }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if ($customerId !== null || $newCustomerSelected)
                            <div class="flex items-center justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm dark:border-emerald-900/60 dark:bg-emerald-950/30">
                                <div>
                                    <p class="font-semibold text-emerald-950 dark:text-emerald-50">{{ $newCustomerSelected ? __('Cliente nuevo para el pedido') : __('Cliente seleccionado') }}</p>
                                    <p class="mt-1 text-emerald-800 dark:text-emerald-100">{{ $customerSearch }}</p>
                                </div>
                                <flux:button wire:click="clearCustomer" type="button" variant="ghost" size="sm" tooltip="{{ __('Quitar el cliente seleccionado y elegir otro') }}">
                                    {{ __('Cambiar') }}
                                </flux:button>
                            </div>
                        @else
                            <flux:button wire:click="$set('showCustomerForm', true)" type="button" variant="ghost" icon="user-plus" tooltip="{{ __('Registrar un cliente nuevo sin salir del pedido') }}">
                                {{ __('Registrar cliente nuevo') }}
                            </flux:button>
                        @endif

                        @if ($showCustomerForm)
                            <div class="grid gap-4 rounded-xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-700 dark:bg-brand-950/50 sm:grid-cols-2">
                                <flux:input wire:model="customerContact.fullName" label="{{ __('Nombre completo') }}" name="customerContact.fullName" maxlength="100" required />
                                <flux:input wire:model="customerContact.phone" label="{{ __('Teléfono') }}" name="customerContact.phone" type="tel" inputmode="numeric" maxlength="8" required />
                                <p class="text-sm text-brand-700 dark:text-brand-200 sm:col-span-2">{{ __('El cliente se guardará junto con el pedido al pulsar “Guardar pedido”.') }}</p>
                                <div class="flex flex-col gap-2 sm:col-span-2 sm:flex-row sm:justify-end">
                                    <flux:button wire:click="$set('showCustomerForm', false)" type="button" variant="ghost" tooltip="{{ __('Cerrar el formulario del cliente') }}">
                                        {{ __('Cancelar') }}
                                    </flux:button>
                                    <flux:button wire:click="selectNewCustomer" type="button" variant="primary" tooltip="{{ __('Preparar este cliente para guardarlo junto con el pedido') }}">
                                        {{ __('Usar este cliente') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif

                        @error('customerId')
                            <p class="text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>
                </section>

                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Datos del pastel') }}</h2>
                         <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Elige una opción del catálogo o describe un pedido personalizado.') }}</p>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <flux:select wire:model.live="captureMode" label="{{ __('Modalidad') }}" required>
                                <flux:select.option value="standard">{{ __('Estándar') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Personalizado') }}</flux:select.option>
                        </flux:select>

                        @if ($captureMode === CaptureMode::Standard->value)
                                <flux:select wire:model="cakeCategoryId" label="{{ __('Categoría') }}" placeholder="{{ __('Selecciona una categoría') }}" required>
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
                            <flux:textarea wire:model="cakeDescription" label="{{ __('Descripción del pastel') }}" placeholder="{{ __('Describe el diseño, sabor o detalles solicitados') }}" rows="4" class="md:col-span-2" required />
                        @endif
                    </div>
                </section>
            </div>

            <aside class="flex h-fit flex-col gap-6">
                <section class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">{{ __('Condiciones del pedido') }}</h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('El precio pactado conserva el acuerdo aunque cambie el catálogo.') }}</p>
                    </div>

                    <div class="mt-5 flex flex-col gap-4">
                        <flux:input wire:model="agreedPrice" label="{{ __('Precio pactado') }}" type="number" min="0.01" max="{{ MoneyValidation::MAX_AMOUNT }}" step="0.01" prefix="Q" required />
                        <flux:input wire:model="deliveryAt" label="{{ __('Fecha y hora de entrega') }}" type="datetime-local" required />
                        <flux:input wire:model="depositAmount" label="{{ __('Anticipo inicial') }}" type="number" min="0" max="{{ MoneyValidation::MAX_AMOUNT }}" step="0.01" prefix="Q" />
                        <p class="text-xs leading-5 text-brand-600 dark:text-brand-300">{{ __('El anticipo es opcional y no puede superar el precio pactado.') }}</p>
                    </div>
                </section>

                <section class="rounded-2xl border border-brand-200 bg-brand-50 p-6 dark:border-brand-800 dark:bg-brand-950/50">
                    <p class="text-sm leading-6 text-brand-800 dark:text-brand-100">{{ __('Al guardar se creará el pedido con estado pendiente y se registrará su historial inicial.') }}</p>
                    <flux:button type="submit" variant="primary" class="mt-5 w-full" tooltip="{{ __('Guardar el pedido con la información indicada') }}">
                        {{ __('Guardar pedido') }}
                    </flux:button>
                </section>
            </aside>
        </form>
</div>
