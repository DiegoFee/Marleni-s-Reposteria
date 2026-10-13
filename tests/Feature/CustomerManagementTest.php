<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Volt\Volt;

test('authenticated users can search customers by name or phone', function () {
    $this->actingAs(User::factory()->create());
    Customer::factory()->create([
        'full_name' => 'Ana Lopez',
        'phone' => '55551234',
    ]);
    Customer::factory()->create([
        'full_name' => 'Carlos Perez',
        'phone' => '55555678',
    ]);

    Volt::test('customers.index')
        ->set('search', 'Ana')
        ->assertSee('Ana Lopez')
        ->assertDontSee('Carlos Perez')
        ->set('search', '55555678')
        ->assertSee('Carlos Perez');
});

test('customers can be created from the customer page', function () {
    $this->actingAs(User::factory()->owner()->create());

    Volt::test('customers.index')
        ->call('startCreating')
        ->set('customerContact.fullName', '  Maria Garcia  ')
        ->set('customerContact.phone', ' 55551111 ')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect(Customer::query()->where('full_name', 'Maria Garcia')->where('phone', '55551111')->exists())->toBeTrue();
});

test('order creation stages a normalized customer contact without persisting it', function () {
    $this->actingAs(User::factory()->owner()->create());

    Volt::test('orders.create')
        ->set('showCustomerForm', true)
        ->set('customerContact.fullName', '  María López  ')
        ->set('customerContact.phone', ' 55551234 ')
        ->call('selectNewCustomer')
        ->assertHasNoErrors()
        ->assertSet('showCustomerForm', false)
        ->assertSet('newCustomerSelected', true)
        ->assertSet('customerSearch', 'María López · 55551234');

    expect(Customer::query()->count())->toBe(0);
});

test('order creation rejects a customer contact that is already in use', function () {
    $this->actingAs(User::factory()->owner()->create());
    Customer::factory()->create([
        'full_name' => 'Cliente existente',
        'phone' => '55550000',
    ]);

    Volt::test('orders.create')
        ->set('showCustomerForm', true)
        ->set('customerContact.fullName', 'Otro cliente')
        ->set('customerContact.phone', '55550000')
        ->call('selectNewCustomer')
        ->assertHasErrors('customerContact.phone');

    expect(Customer::query()->count())->toBe(1);
});

test('customers can be updated from the customer page', function () {
    $this->actingAs(User::factory()->owner()->create());
    $customer = Customer::factory()->create([
        'full_name' => 'Nombre anterior',
        'phone' => '55552222',
    ]);

    Volt::test('customers.index')
        ->call('editCustomer', $customer->id)
        ->set('customerContact.fullName', 'Nombre actualizado')
        ->set('customerContact.phone', '55553333')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect($customer->refresh()->full_name)->toBe('Nombre actualizado');
    expect($customer->phone)->toBe('55553333');
});

test('customers can be saved without changing their existing contact values', function () {
    $this->actingAs(User::factory()->owner()->create());
    $customer = Customer::factory()->create([
        'full_name' => 'Contacto sin cambios',
        'phone' => '55554444',
    ]);

    Volt::test('customers.index')
        ->call('editCustomer', $customer->getKey())
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect($customer->refresh()->full_name)->toBe('Contacto sin cambios');
    expect($customer->phone)->toBe('55554444');
});

test('customer forms reject values that are empty after trimming', function () {
    $this->actingAs(User::factory()->owner()->create());

    Volt::test('customers.index')
        ->call('startCreating')
        ->set('customerContact.fullName', '   ')
        ->set('customerContact.phone', '   ')
        ->call('saveCustomer')
        ->assertHasErrors(['customerContact.fullName', 'customerContact.phone']);

    expect(Customer::query()->count())->toBe(0);
});

test('customer searches reject unbounded input', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('customers.index')
        ->set('search', str_repeat('a', 101))
        ->assertHasErrors('search');
});

test('customer forms require unique names and eight digit phone numbers', function () {
    $this->actingAs(User::factory()->owner()->create());
    Customer::factory()->create([
        'full_name' => 'Cliente existente',
        'phone' => '55550000',
    ]);

    Volt::test('customers.index')
        ->call('startCreating')
        ->set('customerContact.fullName', 'Cliente existente')
        ->set('customerContact.phone', '1234567')
        ->call('saveCustomer')
        ->assertHasErrors(['customerContact.fullName', 'customerContact.phone']);
});
