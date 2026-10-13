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

    #[Validate('required|string|max:255')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Funcion: login()
     *
     * Descripcion: Valida las credenciales, limita intentos fallidos y regenera la sesion cuando la autenticacion es correcta.
     *
     * Version: 1.0
     */
    public function login(): void
    {
        $this->username = Str::lower(trim($this->username));
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['username' => $this->username, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey());

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Funcion: ensureIsNotRateLimited()
     *
     * Descripcion: Bloquea nuevos intentos y emite el evento Lockout cuando se supera el limite permitido.
     *
     * Version: 1.0
     */
    protected function ensureIsNotRateLimited(): void
    {
        $throttleKey = $this->throttleKey();
        $ipThrottleKey = $this->ipThrottleKey();

        if (! RateLimiter::tooManyAttempts($throttleKey, 5)
            && ! RateLimiter::tooManyAttempts($ipThrottleKey, 15)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn(
            RateLimiter::tooManyAttempts($throttleKey, 5) ? $throttleKey : $ipThrottleKey,
        );

        throw ValidationException::withMessages([
            'username' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Funcion: throttleKey()
     *
     * Descripcion: Combina el usuario y la direccion IP en una clave normalizada para limitar intentos por origen.
     *
     * Version: 1.0
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->username).'|'.request()->ip());
    }

    protected function ipThrottleKey(): string
    {
        return 'login-ip|'.request()->ip();
    }
}; ?>

<div class="flex flex-col gap-7 font-sans text-base">
    <x-auth-header title="Bienvenida a tu panel" description="Ingresa tus credenciales para continuar" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6 rounded-3xl border border-brand-200/80 bg-white/75 p-6 text-base shadow-xl shadow-brand-900/5 backdrop-blur-sm sm:p-8 dark:border-brand-800 dark:bg-brand-900/55 dark:shadow-black/20">
        <flux:input wire:model="username" label="{{ __('Nombre de usuario') }}" type="text" name="username" required autofocus autocomplete="username" placeholder="Usuario" class="text-base" />

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
