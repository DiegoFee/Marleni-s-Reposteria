@props([
    'status' => null,
])

@if ($status)
    <div
        {{ $attributes->merge(['class' => 'rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100']) }}
        role="status"
        aria-live="polite"
    >
        {{ __($status) }}
    </div>
@endif
