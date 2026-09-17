<div x-data>
    <button
        type="button"
        x-on:click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'"
        aria-label="{{ __('Cambiar tema') }}"
        title="{{ __('Cambiar tema') }}"
        class="inline-flex size-10 items-center justify-center rounded-xl border border-brand-200 bg-white/70 text-brand-700 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-400 hover:bg-brand-100 hover:text-brand-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-brand-800 dark:bg-brand-900/80 dark:text-brand-200 dark:hover:border-brand-500 dark:hover:bg-brand-800 dark:hover:text-brand-50"
    >
        <svg x-cloak x-show="$flux.appearance !== 'dark'" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="3.25" />
            <path stroke-linecap="round" d="M12 2.75v1.5M12 19.75v1.5M21.25 12h-1.5M4.25 12h-1.5M18.54 5.46l-1.06 1.06M6.52 17.48l-1.06 1.06M18.54 18.54l-1.06-1.06M6.52 6.52 5.46 5.46" />
        </svg>
        <svg x-cloak x-show="$flux.appearance === 'dark'" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.35 15.2A8.25 8.25 0 0 1 8.8 3.65 8.26 8.26 0 1 0 20.35 15.2Z" />
        </svg>
    </button>
</div>
