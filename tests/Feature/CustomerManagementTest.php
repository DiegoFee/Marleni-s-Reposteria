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
        ->assertSee('Carlos Perez')
        ->set('search', '5555-5678')
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

test('customer contact normalization preserves accents, fictional numbers, and leading zeros', function () {
    $this->actingAs(User::factory()->owner()->create());
    $contacts = [
        ['  José   Núñez  ', ' 5361 - 6569 ', 'José Núñez', '53616569'],
        ['María del Carmen Pérez', '0000-0000', 'María del Carmen Pérez', '00000000'],
        ['Ana López', '11111111', 'Ana López', '11111111'],
        ['Juan Pablo', '01234567', 'Juan Pablo', '01234567'],
    ];
    $component = Volt::test('customers.index');

    foreach ($contacts as [$enteredName, $enteredPhone, $expectedName, $expectedPhone]) {
        $component
            ->call('startCreating')
            ->set('customerContact.fullName', $enteredName)
            ->set('customerContact.phone', $enteredPhone)
            ->call('saveCustomer')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('full_name', $expectedName)->firstOrFail();

        expect($customer->phone)->toBe($expectedPhone);
    }

    $customer = Customer::query()->where('full_name', 'José Núñez')->firstOrFail();

    expect($customer->phoneFormatted())->toBe('5361-6569');
    expect($customer->phoneInternational())->toBe('+50253616569');
});

test('customer contact rejects one-word names and invalid phone formats without rewriting them', function () {
    $this->actingAs(User::factory()->owner()->create());
    $component = Volt::test('customers.index');

    $component
        ->call('startCreating')
        ->set('customerContact.fullName', 'Juan')
        ->set('customerContact.phone', '53616569')
        ->call('saveCustomer')
        ->assertHasErrors('customerContact.fullName')
        ->assertSee('Ingresa el nombre y al menos un apellido. Se permiten tildes y ñ.');

    foreach (['1234567', '123456789', '5361a569', '٥٣٦١٦٥٦٩'] as $index => $phone) {
        $component
            ->call('startCreating')
            ->set('customerContact.fullName', 'Cliente de prueba '.($index + 1))
            ->set('customerContact.phone', $phone)
            ->call('saveCustomer')
            ->assertHasErrors('customerContact.phone')
            ->assertSee('El teléfono debe contener exactamente ocho dígitos del 0 al 9.');
    }

    expect(Customer::query()->count())->toBe(0);
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
        ->assertSet('customerSearch', 'María López · 5555-1234');

    expect(Customer::query()->count())->toBe(0);
});

test('order creation rejects a customer contact that is already in use', function () {
    $this->actingAs(User::factory()->owner()->create());
    Customer::factory()->create([
        'full_name' => 'Cliente existente',
        'phone' => '55550000',
    ]);

    $component = Volt::test('orders.create')
        ->set('showCustomerForm', true)
        ->set('customerContact.fullName', 'Otro cliente')
        ->set('customerContact.phone', '55550000')
        ->call('selectNewCustomer')
        ->assertHasErrors('customerContact.phone')
        ->assertSee('El valor de teléfono ya está registrado.');

    expect(substr_count($component->html(true), 'El valor de teléfono ya está registrado.'))->toBe(1);

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

test('customer forms explain duplicate names and phone numbers', function () {
    $this->actingAs(User::factory()->owner()->create());
    Customer::factory()->create([
        'full_name' => 'Cliente existente',
        'phone' => '55550000',
    ]);

    $component = Volt::test('customers.index')
        ->call('startCreating')
        ->set('customerContact.fullName', 'Cliente existente')
        ->set('customerContact.phone', '55550000')
        ->call('saveCustomer')
        ->assertHasErrors(['customerContact.fullName', 'customerContact.phone'])
        ->assertSee('El valor de nombre completo ya está registrado.')
        ->assertSee('El valor de teléfono ya está registrado.');

    expect(substr_count($component->html(true), 'El valor de nombre completo ya está registrado.'))->toBe(1);
    expect(substr_count($component->html(true), 'El valor de teléfono ya está registrado.'))->toBe(1);
});
