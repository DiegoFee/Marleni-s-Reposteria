<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');

    Volt::route('pedidos', 'orders.index')->name('orders.index');
    Volt::route('pedidos/crear', 'orders.create')->name('orders.create');
    Volt::route('pedidos/{order}', 'orders.show')->name('orders.show');

    Volt::route('clientes', 'customers.index')->name('customers.index');

    Route::view('catalogos', 'administracion.placeholder', [
        'title' => 'Catálogos',
        'description' => 'Consulta las categorías y los precios base activos.',
    ])->name('catalogs.index');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
