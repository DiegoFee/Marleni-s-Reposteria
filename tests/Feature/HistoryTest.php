<?php

use App\Enums\ActivityEventType;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Livewire\Volt\Volt;

test('retiring a delivered order keeps its state, payments, and history until permanent deletion', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $order = Order::factory()->delivered()->for($customer)->for($user, 'createdBy')->create();
    $payment = Payment::factory()->for($order)->for($user, 'registeredBy')->create();
    $activityLog = ActivityLog::factory()->for($order)->for($payment)->for($user, 'actorUser')->create();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('deleteOrder')
        ->assertRedirect(route('orders.index', absolute: false));

    $this->assertSoftDeleted($order);

    expect(Order::withTrashed()->findOrFail($order->getKey())->status)->toBe(OrderStatus::Delivered);

    Volt::test('orders.show', ['order' => $order])
        ->assertSee('Historial del pedido')
        ->assertSee('Historial de pagos')
        ->assertDontSee('Editar pedido');

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    Volt::test('history.index')
        ->assertSee($order->order_number)
        ->assertSee('Retirado de Pedidos')
        ->call('viewOrder', $order->id)
        ->assertSet('showDetailModal', true)
        ->assertSee('Retirado de Pedidos')
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
    $user = User::factory()->assistant()->create();
    $order = Order::factory()->delivered()->for($user, 'createdBy')->create();
    Payment::factory()->for($order)->for($user, 'registeredBy')->create();
    $this->actingAs($user);

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

test('retiring a pending order cancels it and keeps it in history without exposing active pending orders', function () {
    $user = User::factory()->owner()->create();
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

    $retiredOrder = Order::withTrashed()->findOrFail($archivedOrder->getKey());
    $statusEvent = ActivityLog::query()
        ->where('order_id', $archivedOrder->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value)
        ->sole();

    expect($retiredOrder->status)->toBe(OrderStatus::Cancelled);
    expect($statusEvent->details)->toMatchArray([
        'from' => OrderStatus::Pending->value,
        'to' => OrderStatus::Cancelled->value,
        'reason' => 'retired_to_history',
    ]);

    Volt::test('history.index')
        ->assertSee($archivedOrder->order_number)
        ->assertSee('Retirado de Pedidos')
        ->assertDontSee($activeOrder->order_number)
        ->call('viewOrder', $archivedOrder->getKey())
        ->assertSee('Pedido retirado al Historial')
        ->assertSee('al retirar el pedido al Historial.');
});

test('retiring an already cancelled order keeps its status and does not add a status-change event', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->cancelled()->for($user, 'createdBy')->create();
    $existingActivity = ActivityLog::factory()->for($order)->for($user, 'actorUser')->create();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('deleteOrder')
        ->assertRedirect(route('orders.index', absolute: false));

    $retiredOrder = Order::withTrashed()->findOrFail($order->getKey());

    expect($retiredOrder->status)->toBe(OrderStatus::Cancelled);
    $this->assertSoftDeleted($order);
    $this->assertModelExists($existingActivity);
    $this->assertDatabaseMissing('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::OrderStatusChanged->value,
    ]);
});

test('history is accessible without recent password confirmation', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);

    $this->get(route('history.index'))
        ->assertOk()
        ->assertSee('Historial');
});

test('history cannot permanently delete an order without recent password confirmation', function () {
    $user = User::factory()->owner()->create();
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
