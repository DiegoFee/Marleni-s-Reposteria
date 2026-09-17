<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to the login page', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Panel de control')
        ->assertSee('Proximas 24 horas')
        ->assertSee('De 24 a 48 horas');
});

test('dashboard separates pending deliveries at the twenty-four and forty-eight hour boundaries', function () {
    $this->travelTo('2026-09-16 10:00:00');

    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $urgentOrder = Order::factory()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-URGENTE',
        'agreed_price' => '180.00',
        'delivery_at' => now()->addHours(12),
    ]);
    $boundaryOrder = Order::factory()->custom()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-LIMITE-24',
        'cake_description' => 'Pastel de chocolate con decoracion floral',
        'delivery_at' => now()->addHours(24),
    ]);
    $lastWindowOrder = Order::factory()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-LIMITE-48',
        'delivery_at' => now()->addHours(48),
    ]);

    $this->actingAs($user);

    $component = Volt::test('dashboard');
    $urgentOrder->load('cakeCategory');

    $component
        ->assertSeeHtml('wire:key="urgent-order-'.$urgentOrder->id.'"')
        ->assertDontSeeHtml('wire:key="urgent-order-'.$boundaryOrder->id.'"')
        ->assertDontSeeHtml('wire:key="urgent-order-'.$lastWindowOrder->id.'"')
        ->assertSeeHtml('wire:key="upcoming-order-'.$boundaryOrder->id.'"')
        ->assertSeeHtml('wire:key="upcoming-order-'.$lastWindowOrder->id.'"')
        ->assertDontSeeHtml('wire:key="upcoming-order-'.$urgentOrder->id.'"')
        ->assertSeeInOrder([
            'Proximas 24 horas',
            $urgentOrder->order_number,
            'De 24 a 48 horas',
            $boundaryOrder->order_number,
            $lastWindowOrder->order_number,
        ])
        ->assertSee($customer->full_name)
        ->assertSee($customer->phone)
        ->assertSee($urgentOrder->cakeCategory->name)
        ->assertSee($boundaryOrder->cake_description)
        ->assertSee('Q 180.00')
        ->assertSee($urgentOrder->delivery_at->format('d/m/Y H:i'))
        ->assertSee('Ver detalle')
        ->assertSeeHtml('href="'.route('orders.show', $urgentOrder).'"');
});

test('dashboard calculates active balances and excludes closed orders', function () {
    $this->travelTo('2026-09-16 10:00:00');

    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $pendingOrder = Order::factory()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-SALDO',
        'agreed_price' => '200.00',
        'delivery_at' => now()->subHour(),
    ]);
    $deliveredOrder = Order::factory()->delivered()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-CERRADO-ENTREGADO',
        'delivery_at' => now()->addHours(12),
    ]);
    $cancelledOrder = Order::factory()->cancelled()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-CERRADO-CANCELADO',
        'delivery_at' => now()->addHours(12),
    ]);
    $paidOrder = Order::factory()->for($customer)->for($user, 'createdBy')->create([
        'order_number' => 'PED-SALDADO',
        'agreed_price' => '100.00',
        'delivery_at' => now()->addDays(5),
    ]);

    Payment::factory()->for($pendingOrder)->for($user, 'registeredBy')->create(['amount' => '50.00']);
    Payment::factory()->for($pendingOrder)->for($user, 'registeredBy')->voided()->create(['amount' => '100.00']);
    Payment::factory()->for($deliveredOrder)->for($user, 'registeredBy')->create(['amount' => '10.00']);
    Payment::factory()->for($cancelledOrder)->for($user, 'registeredBy')->create(['amount' => '10.00']);
    Payment::factory()->for($paidOrder)->for($user, 'registeredBy')->create(['amount' => '100.00']);

    $this->actingAs($user);

    Volt::test('dashboard')
        ->assertSeeInOrder([
            $pendingOrder->order_number,
            'Precio',
            'Q 200.00',
            'Total pagado',
            'Q 50.00',
            'Saldo',
            'Q 150.00',
        ])
        ->assertDontSee($deliveredOrder->order_number)
        ->assertDontSee($cancelledOrder->order_number)
        ->assertDontSee($paidOrder->order_number);
});
