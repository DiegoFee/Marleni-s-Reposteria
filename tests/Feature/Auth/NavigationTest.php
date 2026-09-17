<?php

use App\Models\Order;
use App\Models\User;

test('guests are redirected to login from every internal route', function () {
    $internalRoutes = [
        route('dashboard'),
        route('orders.index'),
        route('orders.create'),
        route('customers.index'),
        route('catalogs.index'),
        route('settings.profile'),
        route('settings.password'),
        route('settings.appearance'),
        url('/settings'),
    ];

    foreach ($internalRoutes as $internalRoute) {
        $this->get($internalRoute)->assertRedirectToRoute('login');
    }
});

test('authenticated users can visit every navigation destination', function () {
    $this->actingAs(User::factory()->create());

    $navigationRoutes = [
        'dashboard',
        'orders.index',
        'orders.create',
        'customers.index',
        'catalogs.index',
        'settings.profile',
        'settings.password',
        'settings.appearance',
    ];

    foreach ($navigationRoutes as $navigationRoute) {
        $this->get(route($navigationRoute))->assertOk();
    }
});

test('order details remain protected by authentication', function () {
    $order = Order::factory()->create();

    $this->get(route('orders.show', $order))->assertRedirectToRoute('login');

    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order))
        ->assertOk();
});

test('root redirects authenticated users to the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertRedirectToRoute('dashboard');
});

test('dashboard displays the administrative navigation labels', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('dashboard'));

    $response
        ->assertSee('Panel')
        ->assertSee('Pedidos')
        ->assertSee('Clientes')
        ->assertSee('Catálogos')
        ->assertSee(route('orders.index'), false)
        ->assertSee(route('customers.index'), false)
        ->assertSee(route('catalogs.index'), false)
        ->assertSee(route('logout'), false);
});
