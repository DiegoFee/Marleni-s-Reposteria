<?php

use Livewire\Volt\Component;

new class extends Component {}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Apariencia')" :subheading="__('Actualiza la apariencia de tu cuenta')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun" title="{{ __('Usar el modo claro') }}">{{ __('Claro') }}</flux:radio>
            <flux:radio value="dark" icon="moon" title="{{ __('Usar el modo oscuro') }}">{{ __('Oscuro') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop" title="{{ __('Usar la apariencia del sistema operativo') }}">{{ __('Sistema') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</div>
