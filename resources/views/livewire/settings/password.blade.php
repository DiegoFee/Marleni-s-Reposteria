<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Actualiza la contraseña del usuario autenticado.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Actualizar contraseña')" :subheading="__('Usa una contraseña larga y aleatoria para mantener tu cuenta segura')">
        <form wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                id="update_password_current_passwordpassword"
                label="{{ __('Contraseña actual') }}"
                type="password"
                name="current_password"
                required
                autocomplete="current-password"
            />
            <flux:input
                wire:model="password"
                id="update_password_password"
                label="{{ __('Nueva contraseña') }}"
                type="password"
                name="password"
                required
                autocomplete="new-password"
            />
            <flux:input
                wire:model="password_confirmation"
                id="update_password_password_confirmation"
                label="{{ __('Confirmar contraseña') }}"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" tooltip="{{ __('Guardar la nueva contraseña') }}">{{ __('Guardar') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="password-updated">
                    {{ __('Guardado.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
