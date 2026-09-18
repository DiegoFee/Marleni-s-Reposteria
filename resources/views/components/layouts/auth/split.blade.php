<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-brand-50 antialiased dark:bg-brand-950">
        <main class="grid min-h-svh lg:grid-cols-[minmax(0,1.12fr)_minmax(27rem,0.88fr)]">
            <section class="relative isolate flex min-h-[38svh] overflow-hidden bg-brand-900 text-white lg:min-h-svh">
                <video class="absolute inset-0 size-full object-cover opacity-75 motion-reduce:hidden" autoplay muted loop playsinline preload="metadata" aria-hidden="true">
                    <source media="(max-width: 1023px)" src="{{ asset('videos/promocionMOVIL.mp4') }}" type="video/mp4" />
                    <source src="{{ asset('videos/promocionPC.mp4') }}" type="video/mp4" />
                </video>
                <div class="absolute inset-0 bg-gradient-to-br from-brand-950/90 via-brand-900/45 to-brand-700/35"></div>
                <div class="absolute -end-20 -top-24 size-72 rounded-full border border-white/20 bg-brand-300/10 blur-sm"></div>
                <div class="absolute -bottom-28 -start-24 size-80 rounded-full border border-brand-200/20 bg-brand-500/20 blur-sm"></div>

                <div class="relative z-10 flex w-full flex-col justify-between gap-10 p-6 sm:p-10 lg:p-14">
                    <a href="{{ route('home') }}" class="w-fit" wire:navigate title="{{ __('Volver a la página principal') }}">
                        <x-app-logo inverse />
                    </a>

                    <div class="max-w-xl">
                        <h1 class="max-w-lg text-4xl leading-tight text-white sm:text-5xl lg:text-6xl">
                            {{ __('Cada pedido empieza con una historia dulce.') }}
                        </h1>
                        <p class="mt-5 max-w-md text-sm leading-7 text-brand-100/90 sm:text-base">
                            {{ __('Organiza clientes, pedidos y entregas desde un espacio creado para trabajar con calma.') }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="relative flex min-h-[62svh] flex-col bg-brand-50 px-6 py-8 dark:bg-brand-950 sm:px-10 lg:min-h-svh lg:px-16 lg:py-10">
                <div class="flex justify-end">
                    <x-theme-toggle />
                </div>

                <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-8 py-8">
                    {{ $slot }}
                </div>
            </section>
        </main>
        @fluxScripts
    </body>
</html>
