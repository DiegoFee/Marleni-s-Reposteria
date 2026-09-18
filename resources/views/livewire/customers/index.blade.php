<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Validate('string|max:100')]
    public string $search = '';
    public bool $showForm = false;
    public ?int $editingCustomerId = null;
    public string $fullName = '';
    public string $phone = '';
    public bool $showDeleteConfirmation = false;
    public ?int $deletingCustomerId = null;

    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return Customer::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('full_name')
            ->paginate(10);
    }

    public function updatedSearch(): void
    {
        $this->validateOnly('search');
        $this->resetPage();
        unset($this->customers);
    }

    public function startCreating(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function editCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->editingCustomerId = $customer->getKey();
        $this->fullName = $customer->full_name;
        $this->phone = $customer->phone;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function saveCustomer(): void
    {
        $this->fullName = trim($this->fullName);
        $this->phone = trim($this->phone);

        $customer = $this->editingCustomerId === null
            ? null
            : Customer::query()->findOrFail($this->editingCustomerId);

        $validated = $this->validate([
            'fullName' => [
                'required',
                'string',
                'max:100',
                Rule::unique(Customer::class, 'full_name')->ignore($customer),
            ],
            'phone' => [
                'required',
                'digits:8',
                Rule::unique(Customer::class, 'phone')->ignore($customer),
            ],
        ]);

        $attributes = [
            'full_name' => $validated['fullName'],
            'phone' => $validated['phone'],
        ];

        if ($customer === null) {
            Customer::query()->create($attributes);
            session()->flash('status', 'Cliente registrado correctamente.');
        } else {
            $customer->update($attributes);
            session()->flash('status', 'Cliente actualizado correctamente.');
        }

        $this->resetForm();
        unset($this->customers);
    }

    public function requestDelete(int $customerId): void
    {
        Customer::query()->findOrFail($customerId);

        $this->deletingCustomerId = $customerId;
        $this->showDeleteConfirmation = true;
        $this->resetValidation();
    }

    public function cancelDelete(): void
    {
        $this->showDeleteConfirmation = false;
        $this->deletingCustomerId = null;
        $this->resetValidation();
    }

    public function deleteCustomer(): void
    {
        $customer = Customer::query()->findOrFail($this->deletingCustomerId);

        $hasPendingOrders = $customer->orders()
            ->withTrashed()
            ->where('status', OrderStatus::Pending->value)
            ->exists();
        $hasRegisteredPayments = $customer->orders()
            ->withTrashed()
            ->whereHas('payments', fn (Builder $query): Builder => $query->where('status', PaymentStatus::Registered->value))
            ->exists();

        if ($hasPendingOrders || $hasRegisteredPayments) {
            $this->cancelDelete();
            $this->addError('deleteCustomer', 'No se puede borrar el cliente porque tiene pedidos pendientes o pagos por resolver.');

            return;
        }

        $customer->delete();
        $this->cancelDelete();
        unset($this->customers);
        session()->flash('status', 'Cliente borrado correctamente.');
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingCustomerId = null;
        $this->fullName = '';
        $this->phone = '';
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                    {{ __('Administración') }}
                </p>
                <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                    {{ __('Clientes') }}
                </h1>
                <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                    {{ __('Busca, registra y actualiza los datos de contacto.') }}
                </p>
            </div>

            <flux:button wire:click="startCreating" variant="primary" icon="plus" tooltip="{{ __('Abrir formulario para registrar un cliente') }}">
                {{ __('Nuevo cliente') }}
            </flux:button>
        </header>

        <section @class([
            'grid min-w-0 w-full gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,24rem)]' => $showForm,
            'flex min-w-0 w-full flex-col gap-6' => ! $showForm,
        ])>
            <div class="order-2 flex min-w-0 flex-col gap-4 lg:order-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="{{ __('Buscar cliente') }}"
                    placeholder="{{ __('Nombre o teléfono') }}"
                    type="search"
                    autocomplete="off"
                />

                @error('deleteCustomer')
                    <p class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/70 dark:bg-rose-950/30 dark:text-rose-200">{{ $message }}</p>
                @enderror

                <div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    @if ($this->customers->isEmpty())
                        <div class="p-8 text-center">
                            <p class="font-semibold text-brand-950 dark:text-brand-50">{{ __('No hay clientes para mostrar.') }}</p>
                            <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Prueba otra búsqueda o registra un cliente nuevo.') }}</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[36rem] table-fixed divide-y divide-brand-200 text-left text-sm dark:divide-brand-800">
                                <thead class="bg-brand-50 text-xs uppercase tracking-wide text-brand-700 dark:bg-brand-950/60 dark:text-brand-200">
                                    <tr>
                                        <th class="px-5 py-3 font-semibold" scope="col">{{ __('Nombre') }}</th>
                                        <th class="px-5 py-3 font-semibold" scope="col">{{ __('Teléfono') }}</th>
                                        <th class="px-5 py-3 text-right font-semibold" scope="col">{{ __('Acciones') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-100 dark:divide-brand-800/80">
                                    @foreach ($this->customers as $customer)
                                        <tr wire:key="customer-{{ $customer->id }}" class="align-middle">
                                            <td class="px-5 py-4 font-medium text-brand-950 dark:text-brand-50">{{ $customer->full_name }}</td>
                                            <td class="px-5 py-4 text-brand-700 dark:text-brand-200">{{ $customer->phone }}</td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    <flux:button wire:click="editCustomer({{ $customer->id }})" variant="ghost" size="sm" tooltip="{{ __('Editar los datos de este cliente') }}">
                                                        {{ __('Editar') }}
                                                    </flux:button>
                                                    <flux:button wire:click="requestDelete({{ $customer->id }})" variant="danger" size="sm" class="marleni-danger-button" tooltip="{{ __('Borrar este cliente si no tiene acciones pendientes') }}">
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
                            {{ $this->customers->links() }}
                        </div>
                    @endif
                </div>
            </div>

            @if ($showForm)
                <section class="order-1 h-fit min-w-0 w-full max-w-full rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50 lg:order-2">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">
                            {{ $editingCustomerId === null ? __('Nuevo cliente') : __('Editar cliente') }}
                        </h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Completa el nombre y teléfono de contacto.') }}</p>
                    </div>

                    <form wire:submit="saveCustomer" class="mt-6 flex min-w-0 w-full flex-col gap-4">
                        <flux:input wire:model="fullName" label="{{ __('Nombre completo') }}" name="fullName" maxlength="100" required autofocus class="min-w-0 w-full" />
                        <flux:input wire:model="phone" label="{{ __('Teléfono') }}" name="phone" type="tel" inputmode="numeric" maxlength="8" required class="min-w-0 w-full" />

                        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <flux:button wire:click="cancelForm" type="button" variant="ghost" tooltip="{{ __('Cerrar el formulario sin guardar') }}">
                                {{ __('Cancelar') }}
                            </flux:button>
                            <flux:button type="submit" variant="primary" tooltip="{{ __('Guardar los datos del cliente') }}">
                                {{ __('Guardar cliente') }}
                            </flux:button>
                        </div>
                    </form>
                </section>
            @endif
        </section>

        <flux:modal wire:model="showDeleteConfirmation" focusable class="max-w-lg border border-brand-200 bg-brand-50 dark:border-brand-700 dark:bg-brand-950">
            <div class="flex flex-col gap-5 text-base">
                <div class="flex items-start gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-800 text-xl font-bold text-white shadow-lg shadow-brand-900/20">!</div>
                    <div>
                        <flux:heading size="lg" class="font-display text-brand-950 dark:text-brand-50">{{ __('¿Borrar cliente?') }}</flux:heading>
                        <p class="mt-2 text-base leading-6 text-brand-700 dark:text-brand-200">{{ __('Se ocultará del catálogo.') }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <flux:button wire:click="cancelDelete" type="button" variant="ghost" tooltip="{{ __('Cerrar sin borrar el cliente') }}">
                        {{ __('Cancelar') }}
                    </flux:button>
                    <flux:button wire:click="deleteCustomer" type="button" variant="danger" class="marleni-danger-button" tooltip="{{ __('Confirmar el borrado del cliente') }}">
                        {{ __('Borrar') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
</div>
