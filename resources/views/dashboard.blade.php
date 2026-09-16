<x-layouts.app>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-8">
        <header class="flex flex-col gap-2">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                {{ __('Administracion') }}
            </p>
            <h1 class="text-3xl font-semibold tracking-tight text-brand-950 dark:text-brand-50">
                {{ __('Panel de control') }}
            </h1>
            <p class="max-w-2xl text-sm text-brand-700 dark:text-brand-200">
                {{ __('Bienvenida al sistema administrativo de Marleni\'s Reposteria.') }}
            </p>
        </header>

        <section class="grid gap-4 md:grid-cols-3" aria-label="{{ __('Resumen inicial') }}">
            <article class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                <p class="text-sm font-medium text-brand-600 dark:text-brand-300">{{ __('Pedidos') }}</p>
                <p class="mt-3 text-3xl font-semibold text-brand-950 dark:text-brand-50">0</p>
                <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Aqui apareceran los pedidos pendientes.') }}</p>
            </article>

            <article class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                <p class="text-sm font-medium text-brand-600 dark:text-brand-300">{{ __('Clientes') }}</p>
                <p class="mt-3 text-3xl font-semibold text-brand-950 dark:text-brand-50">0</p>
                <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Aqui apareceran los clientes registrados.') }}</p>
            </article>

            <article class="rounded-2xl border border-brand-200 bg-white p-6 shadow-sm dark:border-brand-800 dark:bg-brand-900/50">
                <p class="text-sm font-medium text-brand-600 dark:text-brand-300">{{ __('Catalogos') }}</p>
                <p class="mt-3 text-3xl font-semibold text-brand-950 dark:text-brand-50">0</p>
                <p class="mt-2 text-sm text-brand-700 dark:text-brand-200">{{ __('Aqui apareceran los catalogos activos.') }}</p>
            </article>
        </section>

        <section class="rounded-2xl border border-dashed border-brand-300 bg-white/70 p-8 text-center shadow-sm dark:border-brand-700 dark:bg-brand-900/30">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                {{ __('Proximamente') }}
            </p>
            <p class="mt-3 text-brand-800 dark:text-brand-100">
                {{ __('La base tecnica del sistema administrativo esta lista.') }}
            </p>
        </section>
    </div>
</x-layouts.app>
