<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-brand-50 text-brand-950 dark:bg-brand-950 dark:text-brand-50">
        <flux:header container class="border-b border-brand-200 bg-white dark:border-brand-800 dark:bg-brand-950">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <a href="{{ route('dashboard') }}" class="ml-2 mr-5 flex items-center gap-2 lg:ml-0" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="layout-grid" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Panel') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="mr-1.5 space-x-0.5 py-0!">
                <flux:tooltip content="{{ __('Buscar') }}" position="bottom">
                    <flux:navbar.item class="!h-10 [&>div>svg]:size-5" icon="magnifying-glass" href="#" label="{{ __('Buscar') }}" />
                </flux:tooltip>
            </flux:navbar>

            <!-- Desktop User Menu -->
            <flux:dropdown position="top" align="end">
                <flux:profile
                    class="cursor-pointer"
                    :initials="auth()->user()->initials()"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="{{ route('settings.profile') }}" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar stashable sticky class="border-r border-brand-200 bg-white lg:hidden dark:border-brand-800 dark:bg-brand-950">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="ml-1 flex items-center gap-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group heading="{{ __('Administracion') }}">
                    <flux:navlist.item icon="layout-grid" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Panel') }}
                    </flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <div class="border-t border-brand-200 px-2 py-4 dark:border-brand-800">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">
                    {{ __('Gestion interna') }}
                </p>
                <p class="mt-1 text-sm text-brand-700 dark:text-brand-200">
                    {{ __('Pedidos y recordatorios') }}
                </p>
            </div>
        </flux:sidebar>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
