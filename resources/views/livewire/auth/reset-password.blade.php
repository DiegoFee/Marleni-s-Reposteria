<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Inicializa el componente.
     */
    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    /**
     * Restablece la contraseña del usuario indicado.
     */
    public function resetPassword(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        // Intentamos restablecer la contraseña. Si tiene éxito, actualizamos la
        // contraseña del usuario y la guardamos en la base de datos. De lo
        // contrario, procesamos el error y devolvemos la respuesta.
        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // Si la contraseña se restableció correctamente, redirigimos al usuario a
        // la vista autenticada principal. Si ocurre un error, lo devolvemos al
        // origen con el mensaje correspondiente.
        if ($status != Password::PasswordReset) {
            $this->addError('email', __($status));

            return;
        }

        Session::flash('status', __($status));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Reset password" description="Please enter your new password below" />

    <!-- Estado de la sesión -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="resetPassword" class="flex flex-col gap-6">
        <!-- Correo electrónico -->
        <div class="grid gap-2">
            <flux:input wire:model="email" id="email" label="{{ __('Email') }}" type="email" name="email" required autocomplete="email" />
        </div>

        <!-- Contraseña -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password"
                id="password"
                label="{{ __('Password') }}"
                type="password"
                name="password"
                required
                autocomplete="new-password"
                 placeholder="{{ __('Password') }}"
            />
        </div>

        <!-- Confirmar contraseña -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password_confirmation"
                id="password_confirmation"
                label="{{ __('Confirm password') }}"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                 placeholder="{{ __('Confirm password') }}"
            />
        </div>

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full" tooltip="{{ __('Guardar la nueva contraseña') }}">
                {{ __('Reset password') }}
            </flux:button>
        </div>
    </form>
</div>
