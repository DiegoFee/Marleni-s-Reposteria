@if ($errors->any())
    <div
        {{ $attributes->merge(['class' => 'rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-100']) }}
        role="alert"
        aria-live="assertive"
    >
        <p class="font-semibold">{{ __('Revisa los datos antes de continuar.') }}</p>

        <ul class="mt-2 list-disc space-y-1 ps-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
