<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    /**
     * Funcion: updateProfileInformation()
     *
     * Descripcion: Valida y guarda el nombre del usuario y emite un evento para actualizar la interfaz.
     *
     * Version: 1.0
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $user->update($validated);

        $this->dispatch('profile-updated', name: $user->name);
    }

}; ?>

<section class="w-full">
    @include('partials.settings-heading')

     <x-settings.layout :heading="__('Perfil')" :subheading="__('Actualiza el nombre que se muestra dentro del sistema')">
         <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
             <flux:input wire:model="name" label="{{ __('Nombre') }}" type="text" name="name" required autofocus autocomplete="name" />

             <flux:input label="{{ __('Nombre de usuario') }}" value="{{ auth()->user()->username }}" readonly />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                     <flux:button variant="primary" type="submit" class="w-full" tooltip="{{ __('Guardar los cambios del perfil') }}">{{ __('Guardar') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                     {{ __('Guardado.') }}
                </x-action-message>
            </div>
        </form>
     </x-settings.layout>
</section>
