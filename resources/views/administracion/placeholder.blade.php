<x-layouts.app>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-2">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                {{ __('Administracion') }}
            </p>
            <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                {{ $title }}
            </h1>
            <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                {{ $description }}
            </p>
        </header>

        <section class="rounded-2xl border border-dashed border-brand-300 bg-white/70 p-8 text-center shadow-sm dark:border-brand-700 dark:bg-brand-900/30">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                {{ __('Proximamente') }}
            </p>
            <p class="mt-3 text-brand-800 dark:text-brand-100">
                {{ __('Esta seccion estara disponible en una fase posterior.') }}
            </p>
            <a href="{{ route('dashboard') }}" wire:navigate class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-accent px-4 py-2 text-sm font-semibold text-accent-foreground transition hover:opacity-90" title="{{ __('Volver al panel de control') }}">
                {{ __('Volver al panel') }}
            </a>
        </section>
    </div>
</x-layouts.app>
