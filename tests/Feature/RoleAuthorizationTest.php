<?php

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\OrderDeletionService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    config()->set('services.notifications.enabled', false);
});

test('both roles can consult records while write abilities remain exclusive to the owner', function () {
    $owner = User::factory()->owner()->create();
    $assistant = User::factory()->assistant()->create();
    $pendingOrder = Order::factory()->for($owner, 'createdBy')->create();
    $deliveredOrder = Order::factory()->delivered()->for($owner, 'createdBy')->create();
    $archivedOrder = Order::factory()->for($owner, 'createdBy')->create();
    $archivedOrder->delete();
    $customer = Customer::factory()->create();

    expect($assistant->role)->toBe(UserRole::Assistant);
    expect($owner->role)->toBe(UserRole::Owner);
    expect($assistant->can('viewAny', Order::class))->toBeTrue();
    expect($assistant->can('view', $pendingOrder))->toBeTrue();
    expect($assistant->can('viewAny', Customer::class))->toBeTrue();
    expect($assistant->can('view', $customer))->toBeTrue();
    expect($assistant->can('changeStatus', $pendingOrder))->toBeTrue();
    expect($assistant->can('changeStatus', $deliveredOrder))->toBeFalse();
    expect($assistant->can('changeStatus', $archivedOrder))->toBeFalse();
    expect($assistant->can('create', Order::class))->toBeFalse();
    expect($assistant->can('update', $pendingOrder))->toBeFalse();
    expect($assistant->can('registerPayment', $pendingOrder))->toBeFalse();
    expect($assistant->can('voidPayment', $pendingOrder))->toBeFalse();
    expect($assistant->can('delete', $pendingOrder))->toBeFalse();
    expect($assistant->can('forceDelete', $deliveredOrder))->toBeFalse();
    expect($assistant->can('create', Customer::class))->toBeFalse();
    expect($assistant->can('update', $customer))->toBeFalse();
    expect($assistant->can('delete', $customer))->toBeFalse();
    expect($assistant->can('manage-accounts'))->toBeFalse();
    expect($assistant->can('modify-catalogs'))->toBeFalse();

    expect($owner->can('create', Order::class))->toBeTrue();
    expect($owner->can('update', $pendingOrder))->toBeTrue();
    expect($owner->can('registerPayment', $pendingOrder))->toBeTrue();
    expect($owner->can('voidPayment', $pendingOrder))->toBeTrue();
    expect($owner->can('delete', $pendingOrder))->toBeTrue();
    expect($owner->can('changeStatus', $pendingOrder))->toBeTrue();
    expect($owner->can('forceDelete', $deliveredOrder))->toBeTrue();
    expect($owner->can('forceDelete', $archivedOrder))->toBeTrue();
    expect($owner->can('create', Customer::class))->toBeTrue();
    expect($owner->can('update', $customer))->toBeTrue();
    expect($owner->can('delete', $customer))->toBeTrue();
    expect($owner->can('manage-accounts'))->toBeTrue();
    expect($owner->can('modify-catalogs'))->toBeTrue();
});

test('the configured administrative account becomes owner without changing its password or promoting other users', function () {
    config()->set('admin.username', 'configured-owner');
    config()->set('admin.name', 'Marleni');
    $admin = User::factory()->assistant()->create(['username' => 'configured-owner']);
    $otherUser = User::factory()->assistant()->create();
    $originalPasswordHash = $admin->password;

    (new AdminUserSeeder)->run();

    expect($admin->fresh()->role)->toBe(UserRole::Owner);
    expect($admin->fresh()->password)->toBe($originalPasswordHash);
    expect($otherUser->fresh()->role)->toBe(UserRole::Assistant);
});

test('an assistant status change ignores unrelated component payload and records the acting user', function () {
    $owner = User::factory()->owner()->create();
    $assistant = User::factory()->assistant()->create();
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->for($owner, 'createdBy')->create();
    $originalDeliveryAt = $order->delivery_at->copy();
    $this->actingAs($assistant);

    Volt::test('orders.show', ['order' => $order])
        ->set('customerId', $otherCustomer->getKey())
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('agreedPrice', '1.00')
        ->set('deliveryAt', now()->addMonth()->format('Y-m-d\TH:i'))
        ->set('cakeDescription', 'Dato no autorizado')
        ->call('requestStatusChange', OrderStatus::Cancelled->value)
        ->call('confirmStatusChange')
        ->assertHasNoErrors();

    $order->refresh();
    $statusEvent = ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value)
        ->sole();

    expect($order->status)->toBe(OrderStatus::Cancelled);
    expect($order->customer_id)->toBe($customer->getKey());
    expect($order->capture_mode)->toBe(CaptureMode::Standard);
    expect($order->agreed_price)->toBe('150.00');
    expect($order->delivery_at->equalTo($originalDeliveryAt))->toBeTrue();
    expect($order->created_by)->toBe($owner->getKey());
    expect($statusEvent->actor_user_id)->toBe($assistant->getKey());
    expect($statusEvent->details)->toMatchArray([
        'from' => OrderStatus::Pending->value,
        'to' => OrderStatus::Cancelled->value,
    ]);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('activity_logs', 1);
});

test('an assistant can mark a fully paid pending order delivered', function () {
    $owner = User::factory()->owner()->create();
    $assistant = User::factory()->assistant()->create();
    $order = Order::factory()->for($owner, 'createdBy')->create(['agreed_price' => '150.00']);
    app(PaymentService::class)->register($order, [
        'amount' => '150.00',
        'paid_at' => now(),
    ], $owner);
    $this->actingAs($assistant);

    Volt::test('orders.show', ['order' => $order])
        ->call('requestStatusChange', OrderStatus::Delivered->value)
        ->call('confirmStatusChange')
        ->assertHasNoErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);
    expect(ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value)
        ->value('actor_user_id'))->toBe($assistant->getKey());
});

test('an assistant cannot cancel a pending order with an active payment', function () {
    $owner = User::factory()->owner()->create();
    $assistant = User::factory()->assistant()->create();
    $order = Order::factory()->for($owner, 'createdBy')->create();
    $payment = app(PaymentService::class)->register($order, [
        'amount' => '50.00',
        'paid_at' => now(),
    ], $owner);
    $this->actingAs($assistant);

    Volt::test('orders.show', ['order' => $order])
        ->call('requestStatusChange', OrderStatus::Cancelled->value)
        ->call('confirmStatusChange')
        ->assertHasErrors('status');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    expect($payment->refresh()->status->value)->toBe('registered');
    expect(ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value)
        ->exists())->toBeFalse();
});

test('assistant calls to protected Livewire actions are forbidden before any write', function () {
    $assistant = User::factory()->assistant()->create();
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->for($owner, 'createdBy')->create();
    $historyOrder = Order::factory()->delivered()->for($owner, 'createdBy')->create();
    $this->actingAs($assistant);

    Volt::test('orders.create')->call('selectNewCustomer')->assertForbidden();
    Volt::test('orders.create')->call('save')->assertForbidden();
    Volt::test('customers.index')->call('startCreating')->assertForbidden();
    Volt::test('customers.index')->call('editCustomer', $customer->getKey())->assertForbidden();
    Volt::test('customers.index')->call('saveCustomer')->assertForbidden();
    Volt::test('customers.index')->call('requestDelete', $customer->getKey())->assertForbidden();
    Volt::test('customers.index')
        ->set('deletingCustomerId', $customer->getKey())
        ->call('deleteCustomer')
        ->assertForbidden();

    foreach (['startEditing', 'saveChanges', 'savePayment', 'requestPaymentVoid', 'voidPayment', 'deleteOrder'] as $action) {
        Volt::test('orders.show', ['order' => $order])
            ->call($action, ...($action === 'requestPaymentVoid' ? [1] : []))
            ->assertForbidden();
    }

    Volt::test('history.index')
        ->set('deletingOrderId', $historyOrder->getKey())
        ->call('deleteOrder')
        ->assertForbidden();

    $this->assertModelExists($customer);
    $this->assertModelExists($order);
    $this->assertNotSoftDeleted($order);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('activity_logs', 0);
    $this->assertDatabaseCount('order_deletion_audits', 0);
});

test('protected order, payment, and deletion services reject an assistant actor', function () {
    $assistant = User::factory()->assistant()->create();
    $owner = User::factory()->owner()->create();
    $order = Order::factory()->for($owner, 'createdBy')->create();
    $payment = Payment::factory()->for($order)->for($owner, 'registeredBy')->create();
    $orderService = app(OrderService::class);
    $paymentService = app(PaymentService::class);
    $deletionService = app(OrderDeletionService::class);

    expect(fn () => $orderService->create([], $assistant))->toThrow(AuthorizationException::class);
    expect(fn () => $orderService->update($order, [], $assistant))->toThrow(AuthorizationException::class);
    expect(fn () => $paymentService->register($order, [], $assistant))->toThrow(AuthorizationException::class);
    expect(fn () => $paymentService->void($payment, 'No autorizado', $assistant))->toThrow(AuthorizationException::class);
    expect(fn () => $deletionService->delete($order, $assistant))->toThrow(AuthorizationException::class);
    expect(fn () => $deletionService->forceDelete($order, $assistant))->toThrow(AuthorizationException::class);

    $this->assertModelExists($order);
    $this->assertModelExists($payment);
    $this->assertNotSoftDeleted($order);
    $this->assertDatabaseCount('activity_logs', 0);
    $this->assertDatabaseCount('order_deletion_audits', 0);
});

test('assistant screens show consultation and allowed status controls without owner actions', function () {
    $assistant = User::factory()->assistant()->create();
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->for($assistant, 'createdBy')->create();
    $this->actingAs($assistant);

    $this->get(route('dashboard'))->assertSee('Asistente');

    Volt::test('customers.index')
        ->assertSee('Modo consulta')
        ->assertSee('Solo consulta')
        ->assertDontSee('Nuevo cliente')
        ->assertDontSee('Editar');

    Volt::test('orders.index')
        ->assertSee('Tu rol permite consultar pedidos')
        ->assertDontSee('Nuevo pedido');

    Volt::test('orders.show', ['order' => $order])
        ->assertSee('Cambiar estado')
        ->assertSee('Marcar entregado')
        ->assertDontSee('Editar pedido')
        ->assertDontSee('Registrar pago')
        ->assertDontSee('Retirar pedido');
});

test('mass assignment cannot promote an assistant through user profile attributes', function () {
    $assistant = User::factory()->create();

    expect($assistant->role)->toBe(UserRole::Assistant);

    $assistant->update([
        'name' => 'Nombre actualizado',
        'role' => UserRole::Owner->value,
    ]);

    expect($assistant->fresh()->name)->toBe('Nombre actualizado');
    expect($assistant->fresh()->role)->toBe(UserRole::Assistant);
});
