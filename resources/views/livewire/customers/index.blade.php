<?php

use App\Models\Customer;
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
    public bool $showForm = false;
    public ?int $editingCustomerId = null;
    public string $fullName = '';
    public string $phone = '';

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

        $validated = $this->validate([
            'fullName' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:25'],
        ]);

        $attributes = [
            'full_name' => $validated['fullName'],
            'phone' => $validated['phone'],
        ];

        if ($this->editingCustomerId === null) {
            Customer::query()->firstOrCreate($attributes);
            session()->flash('status', 'Cliente registrado correctamente.');
        } else {
            Customer::query()->findOrFail($this->editingCustomerId)->update($attributes);
            session()->flash('status', 'Cliente actualizado correctamente.');
        }

        $this->resetForm();
        unset($this->customers);
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
                    {{ __('Administracion') }}
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
            'grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,24rem)]' => $showForm,
            'flex flex-col gap-6' => ! $showForm,
        ])>
            <div class="flex flex-col gap-4">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="{{ __('Buscar cliente') }}"
                    placeholder="{{ __('Nombre o telefono') }}"
                    type="search"
                    autocomplete="off"
                />

                <div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    @if ($this->customers->isEmpty())
                        <div class="p-8 text-center">
                            <p class="font-semibold text-brand-950 dark:text-brand-50">{{ __('No hay clientes para mostrar.') }}</p>
                            <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Prueba otra busqueda o registra un cliente nuevo.') }}</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[36rem] table-fixed divide-y divide-brand-200 text-left text-sm dark:divide-brand-800">
                                <thead class="bg-brand-50 text-xs uppercase tracking-wide text-brand-700 dark:bg-brand-950/60 dark:text-brand-200">
                                    <tr>
                                        <th class="px-5 py-3 font-semibold" scope="col">{{ __('Nombre') }}</th>
                                        <th class="px-5 py-3 font-semibold" scope="col">{{ __('Telefono') }}</th>
                                        <th class="px-5 py-3 text-right font-semibold" scope="col">{{ __('Acciones') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-100 dark:divide-brand-800/80">
                                    @foreach ($this->customers as $customer)
                                        <tr wire:key="customer-{{ $customer->id }}" class="align-middle">
                                            <td class="px-5 py-4 font-medium text-brand-950 dark:text-brand-50">{{ $customer->full_name }}</td>
                                            <td class="px-5 py-4 text-brand-700 dark:text-brand-200">{{ $customer->phone }}</td>
                                            <td class="px-5 py-4 text-right">
                                                <flux:button wire:click="editCustomer({{ $customer->id }})" variant="ghost" size="sm" tooltip="{{ __('Editar los datos de este cliente') }}">
                                                    {{ __('Editar') }}
                                                </flux:button>
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
                <section class="h-fit rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-lg font-semibold text-brand-950 dark:text-brand-50">
                            {{ $editingCustomerId === null ? __('Nuevo cliente') : __('Editar cliente') }}
                        </h2>
                        <p class="text-sm text-brand-700 dark:text-brand-200">{{ __('Completa el nombre y telefono de contacto.') }}</p>
                    </div>

                    <form wire:submit="saveCustomer" class="mt-6 flex flex-col gap-4">
                        <flux:input wire:model="fullName" label="{{ __('Nombre completo') }}" name="fullName" required autofocus />
                        <flux:input wire:model="phone" label="{{ __('Telefono') }}" name="phone" type="tel" required />

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
</div>
