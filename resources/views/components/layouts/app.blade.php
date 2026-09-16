<x-layouts.app.sidebar>
    <flux:main class="min-h-svh bg-brand-50 px-4 py-6 text-brand-950 sm:px-6 lg:px-8 dark:bg-brand-950 dark:text-brand-50">
        <x-loading-indicator />
        <x-page-status :status="session('status')" class="mb-4" />
        <x-validation-errors class="mb-4" />

        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
