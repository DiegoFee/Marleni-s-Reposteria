<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Livewire\Volt\Volt;

test('customer deletion is rejected while the customer has a pending order', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create();

    Volt::test('customers.index')
        ->call('requestDelete', $customer->id)
        ->call('deleteCustomer')
        ->assertHasErrors('deleteCustomer');

    $this->assertNotSoftDeleted($customer);
});

test('customer deletion keeps completed orders available', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    $order = Order::factory()->delivered()->for($customer)->create();

    Volt::test('customers.index')
        ->call('requestDelete', $customer->id)
        ->call('deleteCustomer')
        ->assertHasNoErrors();

    $this->assertSoftDeleted($customer);
    expect($order->refresh()->customer->is($customer->refresh()))->toBeTrue();
});
