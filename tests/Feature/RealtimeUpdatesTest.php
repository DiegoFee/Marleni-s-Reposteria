<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Livewire\Volt\Volt;

test('data views poll Laravel every five seconds', function () {
    $this->actingAs(User::factory()->owner()->create());

    foreach (['customers.index', 'dashboard', 'history.index', 'orders.create', 'orders.index'] as $page) {
        Volt::test($page)->assertSeeHtml('wire:poll.5s');
    }

    $order = Order::factory()->create();

    Volt::test('orders.show', ['order' => $order])
        ->assertSeeHtml('wire:poll.5s="refreshRealtimeData"');
});

test('the customer list reflects database changes on its next poll', function () {
    $this->actingAs(User::factory()->owner()->create());
    $customer = Customer::factory()->create(['full_name' => 'Nombre anterior']);
    $component = Volt::test('customers.index')->assertSee('Nombre anterior');

    $customer->update(['full_name' => 'Nombre actualizado']);

    $component
        ->call('$refresh')
        ->assertSee('Nombre actualizado')
        ->assertDontSee('Nombre anterior');
});

test('customer options in order creation include customers added since the last poll', function () {
    $this->actingAs(User::factory()->owner()->create());
    $component = Volt::test('orders.create')->set('customerSearch', 'María')->assertDontSee('María López');

    Customer::factory()->create(['full_name' => 'María López']);

    $component
        ->call('$refresh')
        ->assertSee('María López');
});

test('the order list includes orders created since the last poll', function () {
    $actor = User::factory()->owner()->create();
    $this->actingAs($actor);
    $component = Volt::test('orders.index')->assertDontSee('PED-TIEMPO-REAL');

    Order::factory()->for($actor, 'createdBy')->create(['order_number' => 'PED-TIEMPO-REAL']);

    $component
        ->call('$refresh')
        ->assertSee('PED-TIEMPO-REAL');
});

test('the dashboard reflects orders created since the last poll', function () {
    $actor = User::factory()->owner()->create();
    $this->actingAs($actor);
    $component = Volt::test('dashboard')->assertDontSee('PED-PANEL-POLL');

    Order::factory()->for($actor, 'createdBy')->create(['order_number' => 'PED-PANEL-POLL']);

    $component
        ->call('$refresh')
        ->assertSee('PED-PANEL-POLL');
});

test('the history list reflects archived orders on its next poll', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->for($actor, 'createdBy')->create(['order_number' => 'PED-HISTORIAL-POLL']);
    $this->actingAs($actor);
    $component = Volt::test('history.index')->assertDontSee('PED-HISTORIAL-POLL');

    $order->delete();

    $component
        ->call('$refresh')
        ->assertSee('PED-HISTORIAL-POLL');
});

test('order detail polling preserves payment input and refreshes totals', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->for($actor, 'createdBy')->create(['agreed_price' => '150.00']);
    $this->actingAs($actor);
    $component = Volt::test('orders.show', ['order' => $order])
        ->set('paymentAmount', '20.00')
        ->set('paymentNotes', 'Borrador local');

    Payment::factory()->for($order)->for($actor, 'registeredBy')->create(['amount' => '50.00']);

    $component
        ->call('refreshRealtimeData')
        ->assertSee('Q 50.00')
        ->assertSet('paymentAmount', '20.00')
        ->assertSet('paymentNotes', 'Borrador local');
});

test('order detail polling preserves local edits and blocks saving stale data', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->custom()->for($actor, 'createdBy')->create([
        'cake_description' => 'Descripción original',
    ]);
    $this->actingAs($actor);
    $component = Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('cakeDescription', 'Borrador local');

    $order->update(['cake_description' => 'Descripción remota']);

    $component
        ->call('refreshRealtimeData')
        ->assertSet('cakeDescription', 'Borrador local')
        ->assertSet('hasRemoteChanges', true)
        ->assertSee('Otro usuario actualizó información relacionada.')
        ->call('saveChanges')
        ->assertHasErrors('status');

    expect($order->refresh()->cake_description)->toBe('Descripción remota');
});

test('order detail polling warns when the linked customer changes during editing', function () {
    $actor = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['full_name' => 'Cliente original']);
    $order = Order::factory()->custom()->for($customer)->for($actor, 'createdBy')->create();
    $this->actingAs($actor);
    $component = Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('cakeDescription', 'Borrador local');

    $customer->update(['full_name' => 'Cliente actualizado']);

    $component
        ->call('refreshRealtimeData')
        ->assertSet('cakeDescription', 'Borrador local')
        ->assertSet('hasRemoteChanges', true)
        ->assertSee('Otro usuario actualizó información relacionada.');
});

test('order detail polling warns when a payment changes during editing', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->custom()->for($actor, 'createdBy')->create(['agreed_price' => '150.00']);
    $this->actingAs($actor);
    $component = Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('cakeDescription', 'Borrador local');

    Payment::factory()->for($order)->for($actor, 'registeredBy')->create(['amount' => '50.00']);

    $component
        ->call('refreshRealtimeData')
        ->assertSet('cakeDescription', 'Borrador local')
        ->assertSet('hasRemoteChanges', true)
        ->assertSee('Otro usuario actualizó información relacionada.');
});

test('order detail blocks a stale save even if the next poll has not run yet', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->custom()->for($actor, 'createdBy')->create([
        'cake_description' => 'Descripción original',
    ]);
    $this->actingAs($actor);
    $component = Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('cakeDescription', 'Borrador local');

    $order->update(['cake_description' => 'Descripción remota']);

    $component
        ->call('saveChanges')
        ->assertHasErrors('status');

    expect($order->refresh()->cake_description)->toBe('Descripción remota');
});

test('order detail rejects save requests without an active edit session', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->custom()->for($actor, 'createdBy')->create([
        'cake_description' => 'Descripción original',
    ]);
    $this->actingAs($actor);

    Volt::test('orders.show', ['order' => $order])
        ->set('cakeDescription', 'Cambio no autorizado por la interfaz')
        ->call('saveChanges')
        ->assertHasErrors('status');

    expect($order->refresh()->cake_description)->toBe('Descripción original');
});

test('deleted orders redirect to the order list on the next detail poll', function () {
    $actor = User::factory()->owner()->create();
    $order = Order::factory()->for($actor, 'createdBy')->create();
    $this->actingAs($actor);
    $component = Volt::test('orders.show', ['order' => $order]);

    $order->forceDelete();

    $component
        ->call('refreshRealtimeData')
        ->assertRedirect(route('orders.index'));
});
