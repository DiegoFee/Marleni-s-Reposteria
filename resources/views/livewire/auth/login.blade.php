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
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Gestiona una solicitud de autenticación.
     */
    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
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
            'email' => __('auth.throttle', [
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
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}; ?>

<div class="flex flex-col gap-7">
    <x-auth-header title="Bienvenida a tu panel" description="Ingresa tus credenciales para continuar" />

    <!-- Estado de la sesión -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6 rounded-3xl border border-brand-200/80 bg-white/75 p-5 shadow-xl shadow-brand-900/5 backdrop-blur-sm sm:p-7 dark:border-brand-800 dark:bg-brand-900/55 dark:shadow-black/20">
        <!-- Correo electrónico -->
        <flux:input wire:model="email" label="{{ __('Email address') }}" type="email" name="email" required autofocus autocomplete="email" placeholder="email@example.com" />

        <!-- Contraseña -->
        <div class="relative">
            <flux:input
                wire:model="password"
                label="{{ __('Password') }}"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                placeholder="{{ __('Password') }}"
            />

            @if (Route::has('password.request'))
                <x-text-link class="absolute right-0 top-0" href="{{ route('password.request') }}" title="{{ __('Solicitar un enlace para restablecer la contraseña') }}">
                    {{ __('Forgot your password?') }}
                </x-text-link>
            @endif
        </div>

        <!-- Recordarme -->
        <flux:checkbox wire:model="remember" label="{{ __('Remember me') }}" title="{{ __('Mantener la sesión iniciada en este dispositivo') }}" />

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full" tooltip="{{ __('Ingresar al sistema') }}">{{ __('Ingresar') }}</flux:button>
        </div>
    </form>

</div>
