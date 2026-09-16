<div
    wire:loading.delay
    class="pointer-events-none fixed inset-x-0 top-0 z-50 h-1 overflow-hidden bg-brand-100 dark:bg-brand-900"
    role="status"
    aria-live="polite"
>
    <div class="h-full w-1/3 animate-pulse bg-brand-600 dark:bg-brand-300"></div>
    <span class="sr-only">{{ __('Procesando solicitud...') }}</span>
</div>
