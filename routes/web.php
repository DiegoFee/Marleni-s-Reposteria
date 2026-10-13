<?php

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'auth.session'])->group(function () {
    Volt::route('dashboard', 'dashboard')
        ->can('view-dashboard')
        ->name('dashboard');

    Volt::route('pedidos', 'orders.index')
        ->can('viewAny', Order::class)
        ->name('orders.index');
    Volt::route('pedidos/crear', 'orders.create')
        ->can('create', Order::class)
        ->name('orders.create');
    Volt::route('pedidos/{order}', 'orders.show')
        ->withTrashed()
        ->name('orders.show');

    Volt::route('clientes', 'customers.index')
        ->can('viewAny', Customer::class)
        ->name('customers.index');

    Route::view('catalogos', 'administracion.placeholder', [
        'title' => 'Catálogos',
        'description' => 'Consulta las categorías y los precios base activos.',
    ])->can('view-catalogs')->name('catalogs.index');

    Volt::route('historial', 'history.index')
        ->can('viewAny', Order::class)
        ->name('history.index');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
