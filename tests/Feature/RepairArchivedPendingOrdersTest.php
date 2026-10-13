<?php

use App\Enums\ActivityEventType;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Livewire\Volt\Volt;

test('repair simulation lists archived pending orders without changing data', function () {
    $user = User::factory()->owner()->create();
    $archivedPendingOrder = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-SIMULACION',
    ]);
    $archivedPendingOrder->delete();
    $deletedAt = $archivedPendingOrder->deleted_at;
    $activePendingOrder = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-ACTIVO',
    ]);

    $this->artisan('orders:repair-archived-pending')
        ->expectsOutputToContain('Simulación: 1 pedido(s) pasarían de Pendiente a Cancelado.')
        ->expectsOutputToContain('PED-SIMULACION')
        ->assertSuccessful();

    $archivedPendingOrder->refresh();

    expect($archivedPendingOrder->status)->toBe(OrderStatus::Pending);
    expect($archivedPendingOrder->deleted_at->equalTo($deletedAt))->toBeTrue();
    expect($activePendingOrder->refresh()->status)->toBe(OrderStatus::Pending);
    $this->assertDatabaseMissing('activity_logs', [
        'order_id' => $archivedPendingOrder->getKey(),
        'event_type' => ActivityEventType::OrderStatusChanged->value,
    ]);
});

test('repair applies only to archived pending orders and records an idempotent status event', function () {
    $user = User::factory()->owner()->create();
    $archivedPendingOrder = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-PENDIENTE-ARCHIVADO',
    ]);
    $archivedPendingOrder->delete();
    $deletedAt = $archivedPendingOrder->deleted_at;
    $activePendingOrder = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-PENDIENTE-ACTIVO',
    ]);
    $archivedPendingWithPayment = Order::factory()->for($user, 'createdBy')->create([
        'order_number' => 'PED-ARCHIVADO-CON-PAGO',
    ]);
    Payment::factory()->for($archivedPendingWithPayment)->for($user, 'registeredBy')->create();
    $archivedPendingWithPayment->delete();
    $archivedDeliveredOrder = Order::factory()->delivered()->for($user, 'createdBy')->create([
        'order_number' => 'PED-ENTREGADO-ARCHIVADO',
    ]);
    $archivedDeliveredOrder->delete();
    $archivedCancelledOrder = Order::factory()->cancelled()->for($user, 'createdBy')->create([
        'order_number' => 'PED-CANCELADO-ARCHIVADO',
    ]);
    $archivedCancelledOrder->delete();

    $this->artisan('orders:repair-archived-pending', ['--apply' => true])
        ->expectsOutputToContain('Pedidos regularizados: 1.')
        ->expectsOutputToContain('Se omitieron 1 pedido(s) con pagos activos')
        ->assertSuccessful();

    $repairedOrder = Order::withTrashed()->findOrFail($archivedPendingOrder->getKey());
    $statusEvents = ActivityLog::query()
        ->where('order_id', $archivedPendingOrder->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value);
    $statusEvent = $statusEvents->sole();

    expect($repairedOrder->status)->toBe(OrderStatus::Cancelled);
    expect($repairedOrder->deleted_at->equalTo($deletedAt))->toBeTrue();
    expect($statusEvent->actor_user_id)->toBeNull();
    expect($statusEvent->details)->toMatchArray([
        'from' => OrderStatus::Pending->value,
        'to' => OrderStatus::Cancelled->value,
        'reason' => 'archived_pending_repair',
        'archived_at' => $deletedAt->toIso8601String(),
    ]);

    $this->actingAs($user);
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    Volt::test('history.index')
        ->call('viewOrder', $repairedOrder->getKey())
        ->assertSee('Pedido archivado regularizado')
        ->assertSee('Responsable: Sistema');

    expect($activePendingOrder->refresh()->status)->toBe(OrderStatus::Pending);
    expect(Order::withTrashed()->findOrFail($archivedPendingWithPayment->getKey())->status)->toBe(OrderStatus::Pending);
    expect(Order::withTrashed()->findOrFail($archivedDeliveredOrder->getKey())->status)->toBe(OrderStatus::Delivered);
    expect(Order::withTrashed()->findOrFail($archivedCancelledOrder->getKey())->status)->toBe(OrderStatus::Cancelled);

    $this->artisan('orders:repair-archived-pending', ['--apply' => true])
        ->expectsOutputToContain('No hay pedidos corregibles; 1 pedido(s) archivado(s) tienen pagos activos')
        ->assertSuccessful();

    expect(ActivityLog::query()
        ->where('order_id', $archivedPendingOrder->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value)
        ->count())->toBe(1);
});
