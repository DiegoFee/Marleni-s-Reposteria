@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2 text-center">
    <h1 class="text-xl font-medium text-brand-950 dark:text-brand-50">{{ __($title) }}</h1>
    <p class="text-center text-sm text-brand-700 dark:text-brand-200">{{ __($description) }}</p>
</div>
