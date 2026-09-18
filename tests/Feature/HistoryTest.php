<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Livewire\Volt\Volt;

test('history keeps a delivered order after it is deleted from orders', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $order = Order::factory()->delivered()->for($customer)->for($user, 'createdBy')->create();
    $payment = Payment::factory()->for($order)->for($user, 'registeredBy')->create();
    $activityLog = ActivityLog::factory()->for($order)->for($payment)->for($user, 'actorUser')->create();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('deleteOrder')
        ->assertRedirect(route('orders.index', absolute: false));

    $this->assertSoftDeleted($order);

    Volt::test('orders.show', ['order' => $order])
        ->assertSee('Historial del pedido')
        ->assertSee('Historial de pagos')
        ->assertDontSee('Editar pedido');

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    Volt::test('history.index')
        ->assertSee($order->order_number)
        ->assertSee('Eliminado de Pedidos')
        ->call('viewOrder', $order->id)
        ->assertSet('showDetailModal', true)
        ->assertSee('Historial de pagos')
        ->call('closeDetail')
        ->call('requestDelete', $order->id)
        ->call('deleteOrder')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    $this->assertDatabaseMissing('activity_logs', ['id' => $activityLog->id]);
    $this->assertDatabaseHas('order_deletion_audits', [
        'order_number' => $order->order_number,
        'actor_user_id' => $user->id,
    ]);
});

test('history opens a delivered order detail without leaving the module', function () {
    $user = User::factory()->create();
    $order = Order::factory()->delivered()->for($user, 'createdBy')->create();
    Payment::factory()->for($order)->for($user, 'registeredBy')->create();
    $this->actingAs($user);
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    Volt::test('history.index')
        ->assertSee($order->order_number)
        ->assertSee('Ver detalle')
        ->call('viewOrder', $order->id)
        ->assertSet('showDetailModal', true)
        ->assertSee('Resumen financiero')
        ->assertSee('Historial de pagos')
        ->assertSee('Historial del pedido')
        ->call('closeDetail')
        ->assertSet('showDetailModal', false)
        ->assertDontSee('Resumen financiero');
});

test('history includes archived pending orders without exposing active pending orders', function () {
    $user = User::factory()->create();
    $activeOrder = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-ACTIVO',
    ]);
    $archivedOrder = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-ARCHIVADO',
    ]);
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $archivedOrder])
        ->call('deleteOrder')
        ->assertRedirect(route('orders.index', absolute: false));

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    Volt::test('history.index')
        ->assertSee($archivedOrder->order_number)
        ->assertSee('Eliminado de Pedidos')
        ->assertDontSee($activeOrder->order_number);
});

test('history requires recent password confirmation before it can be opened', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('history.index'))
        ->assertRedirect(route('password.confirm', absolute: false));
});

test('history cannot permanently delete an order without recent password confirmation', function () {
    $user = User::factory()->create();
    $order = Order::factory()->delivered()->for($user, 'createdBy')->create();
    $this->actingAs($user);

    Volt::test('history.index')
        ->call('requestDelete', $order->id)
        ->call('deleteOrder')
        ->assertRedirect(route('password.confirm', absolute: false));

    $this->assertModelExists($order);
    $this->assertDatabaseMissing('order_deletion_audits', [
        'order_number' => $order->order_number,
    ]);
});
