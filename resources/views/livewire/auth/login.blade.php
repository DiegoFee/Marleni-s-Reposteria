<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string|max:60|alpha_dash')]
    public string $username = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Gestiona una solicitud de autenticación.
     */
    public function login(): void
    {
        $this->username = Str::lower(trim($this->username));
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['username' => $this->username, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Verifica que la solicitud de autenticación no esté limitada por frecuencia.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Obtiene la clave de limitación de frecuencia de autenticación.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->username).'|'.request()->ip());
    }
}; ?>

<div class="flex flex-col gap-7 font-sans text-base">
    <x-auth-header title="Bienvenida a tu panel" description="Ingresa tus credenciales para continuar" />

    <!-- Estado de la sesión -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6 rounded-3xl border border-brand-200/80 bg-white/75 p-6 text-base shadow-xl shadow-brand-900/5 backdrop-blur-sm sm:p-8 dark:border-brand-800 dark:bg-brand-900/55 dark:shadow-black/20">
        <!-- Nombre de usuario -->
        <flux:input wire:model="username" label="{{ __('Nombre de usuario') }}" type="text" name="username" required autofocus autocomplete="username" placeholder="admin" class="text-base" />

        <!-- Contraseña -->
        <flux:input
            wire:model="password"
            label="{{ __('Contraseña') }}"
            type="password"
            name="password"
            required
            autocomplete="current-password"
            placeholder="{{ __('Contraseña') }}"
            class="text-base"
        />

        <!-- Recordarme -->
        <flux:checkbox wire:model="remember" label="{{ __('Recordarme') }}" title="{{ __('Mantener la sesión iniciada en este dispositivo') }}" />

        <div class="flex justify-center pt-1">
            <flux:button
                variant="primary"
                type="submit"
                class="min-w-44 justify-center rounded-xl px-8 py-3 text-base font-semibold shadow-lg shadow-brand-700/25 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-brand-700/30"
                tooltip="{{ __('Ingresar al sistema') }}"
            >
                {{ __('Ingresar') }}
            </flux:button>
        </div>
    </form>

</div>
