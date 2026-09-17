<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body
        x-data="marleniSidebar"
        x-bind:class="{ 'is-sidebar-resizing': isResizing }"
        class="min-h-screen bg-brand-50 text-brand-950 dark:bg-brand-950 dark:text-brand-50"
    >
        <flux:sidebar
            collapsible="mobile"
            sticky
            data-marleni-sidebar
            x-bind:style="'--sidebar-width: ' + sidebarWidth + 'px'"
            x-bind:class="{ 'marleni-sidebar-hidden': sidebarHidden }"
            class="relative border-r border-brand-200 bg-white/95 shadow-xl shadow-brand-900/5 backdrop-blur dark:border-brand-800 dark:bg-brand-950/95 dark:shadow-black/20"
        >
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('dashboard') }}" class="min-w-0" wire:navigate title="{{ __('Abrir el panel de control') }}">
                    <x-app-logo />
                </a>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        x-on:click="hideSidebar"
                        class="hidden size-10 items-center justify-center rounded-xl text-brand-600 transition hover:bg-brand-100 hover:text-brand-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 lg:inline-flex dark:text-brand-300 dark:hover:bg-brand-800 dark:hover:text-brand-50"
                        aria-label="{{ __('Ocultar menu lateral') }}"
                        title="{{ __('Ocultar menu lateral') }}"
                    >
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                    <flux:sidebar.toggle class="lg:hidden" icon="x-mark" aria-label="{{ __('Cerrar menu lateral') }}" title="{{ __('Cerrar menu lateral') }}" />
                </div>
            </div>

            <div
                class="marleni-sidebar-resize-handle hidden lg:block"
                role="separator"
                aria-orientation="vertical"
                aria-label="{{ __('Ajustar ancho del menu lateral') }}"
                title="{{ __('Arrastra para ajustar el ancho del menu lateral') }}"
                tabindex="0"
                :aria-valuemin="minimumWidth"
                :aria-valuemax="maximumWidth"
                :aria-valuenow="sidebarWidth"
                x-on:pointerdown="startResize($event)"
                x-on:pointermove.window="resize($event)"
                x-on:pointerup.window="stopResize()"
                x-on:pointercancel.window="stopResize()"
                x-on:keydown.arrow-left.prevent="resizeBy(-16)"
                x-on:keydown.arrow-right.prevent="resizeBy(16)"
            ></div>

            <flux:navlist variant="outline">
                <flux:navlist.group heading="{{ __('Administracion') }}" class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate title="{{ __('Abrir el panel de control') }}">{{ __('Panel') }}</flux:navlist.item>
                    <flux:navlist.item icon="folder-git-2" :href="route('orders.index')" :current="request()->routeIs('orders.*')" wire:navigate title="{{ __('Consultar y gestionar pedidos') }}">{{ __('Pedidos') }}</flux:navlist.item>
                    <flux:navlist.item icon="users" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate title="{{ __('Consultar y gestionar clientes') }}">{{ __('Clientes') }}</flux:navlist.item>
                    <flux:navlist.item icon="book-open-text" :href="route('catalogs.index')" :current="request()->routeIs('catalogs.*')" wire:navigate title="{{ __('Consultar catalogos activos') }}">{{ __('Catalogos') }}</flux:navlist.item>
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

            <!-- Menú de usuario de escritorio -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                    title="{{ __('Abrir el menú de usuario') }}"
                />

                <flux:menu class="w-[220px]">
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
                        <flux:menu.item href="{{ route('settings.profile') }}" icon="cog" wire:navigate title="{{ __('Abrir la configuración del perfil') }}">{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" title="{{ __('Cerrar la sesión actual') }}">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <div class="pointer-events-none fixed inset-y-0 start-0 z-40 hidden w-12 items-center lg:flex">
            <button
                type="button"
                x-cloak
                x-show="sidebarHidden"
                x-on:dblclick="showSidebar"
                x-on:keydown.enter.prevent="showSidebar"
                x-on:keydown.space.prevent="showSidebar"
                class="pointer-events-auto inline-flex size-10 items-center justify-center rounded-e-2xl border border-s-0 border-brand-200 bg-white text-brand-700 shadow-lg shadow-brand-900/10 transition hover:w-12 hover:bg-brand-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-brand-800 dark:bg-brand-900 dark:text-brand-200 dark:hover:bg-brand-800"
                aria-label="{{ __('Mostrar menu lateral') }}"
                title="{{ __('Haz doble clic para mostrar el menu lateral') }}"
            >
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14 6-6 6 6 6M8 12h10" />
                </svg>
            </button>
        </div>

        <!-- Menú de usuario móvil -->
        <flux:header class="border-b border-brand-200 bg-white/95 shadow-sm backdrop-blur lg:hidden dark:border-brand-800 dark:bg-brand-950/95">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="{{ __('Abrir menu lateral') }}" title="{{ __('Abrir menu lateral') }}" />

            <a href="{{ route('dashboard') }}" class="ml-2 min-w-0 max-w-[13rem]" wire:navigate title="{{ __('Abrir el panel de control') }}">
                <x-app-logo />
            </a>

            <flux:spacer />

            <x-theme-toggle />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                    title="{{ __('Abrir el menú de usuario') }}"
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
                        <flux:menu.item href="{{ route('settings.profile') }}" icon="cog" wire:navigate title="{{ __('Abrir la configuración del perfil') }}">{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" title="{{ __('Cerrar la sesión actual') }}">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
