@props([
    'inverse' => false,
])

@php
    $labelClasses = $inverse ? 'text-white' : 'text-brand-900 dark:text-brand-50';
@endphp

<div {{ $attributes->class('flex min-w-0 items-center gap-3') }}>
    <picture class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-white/90 p-1 shadow-sm ring-1 ring-brand-200/70 dark:bg-brand-100 dark:ring-brand-300/40">
        <source media="(max-width: 767px)" srcset="{{ asset('images/logoMOVIL.png') }}" />
        <img src="{{ asset('images/logoPC.png') }}" alt="" aria-hidden="true" class="size-full object-contain" loading="eager" />
    </picture>

    <span class="min-w-0 truncate font-display text-lg font-semibold tracking-tight {{ $labelClasses }}">
        {{ config('app.name') }}
    </span>
</div>
