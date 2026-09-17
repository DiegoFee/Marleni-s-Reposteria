<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('pedidos', 'administracion.placeholder', [
        'title' => 'Pedidos',
        'description' => 'Registra y consulta los pedidos de la pastelería.',
    ])->name('orders.index');

    Route::view('clientes', 'administracion.placeholder', [
        'title' => 'Clientes',
        'description' => 'Administra los datos de contacto de tus clientes.',
    ])->name('customers.index');

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
