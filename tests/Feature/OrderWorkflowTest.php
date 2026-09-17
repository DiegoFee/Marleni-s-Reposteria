<?php

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Livewire\Volt\Volt;

function orderFormData(Customer $customer, CakeCategory $category, BasePrice $basePrice): array
{
    return [
        'customerId' => $customer->id,
        'captureMode' => CaptureMode::Standard->value,
        'cakeCategoryId' => $category->id,
        'basePriceId' => $basePrice->id,
        'agreedPrice' => '180.00',
        'deliveryAt' => now()->addDays(2)->format('Y-m-d H:i:s'),
    ];
}

test('standard orders preserve an overridden price and record their initial history', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    $component = Volt::test('orders.create');
    foreach (orderFormData($customer, $category, $basePrice) as $property => $value) {
        $component->set($property, $value);
    }

    $component
        ->set('depositAmount', '50.00')
        ->call('save')
        ->assertHasNoErrors();

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->capture_mode)->toBe(CaptureMode::Standard);
    expect($order->customer_id)->toBe($customer->id);
    expect($order->cake_category_id)->toBe($category->id);
    expect($order->base_price_id)->toBe($basePrice->id);
    expect($order->agreed_price)->toBe('180.00');
    expect($order->delivery_at)->not->toBeNull();
    expect($order->created_by)->toBe($user->id);
    expect($order->order_number)->toStartWith('PED-');
    expect($order->status->value)->toBe('pending');
    expect(Payment::query()->where('order_id', $order->id)->where('payment_type', PaymentType::Deposit->value)->where('status', PaymentStatus::Registered->value)->value('amount'))->toBe('50.00');
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::OrderCreated->value)->exists())->toBeTrue();
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::PaymentRegistered->value)->exists())->toBeTrue();
});

test('custom orders require a description and keep catalog references empty', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel de chocolate con decoracion floral')
        ->set('agreedPrice', '275.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasNoErrors();

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->created_by)->toBe($user->id);
    expect($order->customer_id)->toBe($customer->id);
    expect($order->capture_mode)->toBe(CaptureMode::Custom);
    expect($order->cake_category_id)->toBeNull();
    expect($order->base_price_id)->toBeNull();
    expect($order->cake_description)->toBe('Pastel de chocolate con decoracion floral');
    expect($order->agreed_price)->toBe('275.00');
    expect($order->delivery_at)->not->toBeNull();
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::OrderCreated->value)->exists())->toBeTrue();
});

test('order validation rejects invalid modality combinations and excessive deposits', function () {
    $this->seed(CatalogSeeder::class);
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeCategoryId', $category->id)
        ->set('basePriceId', $basePrice->id)
        ->set('agreedPrice', '100.00')
        ->set('deliveryAt', now()->addDay()->format('Y-m-d H:i:s'))
        ->set('depositAmount', '101.00')
        ->call('save')
        ->assertHasErrors(['cakeCategoryId', 'basePriceId', 'cakeDescription', 'depositAmount']);

    expect(Order::query()->count())->toBe(0);
});

test('standard orders require catalog references for their modality', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Standard->value)
        ->set('agreedPrice', '100.00')
        ->set('deliveryAt', now()->addDay()->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors(['cakeCategoryId', 'basePriceId']);

    expect(Order::query()->count())->toBe(0);
});

test('custom orders reject descriptions that are empty after trimming', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $this->actingAs($user);

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', '   ')
        ->set('agreedPrice', '275.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors('cakeDescription');

    expect(Order::query()->count())->toBe(0);
});

test('order forms reject amounts above the database precision', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();
    $this->actingAs($user);

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Standard->value)
        ->set('cakeCategoryId', $category->id)
        ->set('basePriceId', $basePrice->id)
        ->set('agreedPrice', '100000000.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors('agreedPrice');

    expect(Order::query()->count())->toBe(0);
});

test('inactive catalog entries cannot be selected for standard orders', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    $category = CakeCategory::factory()->inactive()->create();
    $basePrice = BasePrice::factory()->inactive()->create();

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Standard->value)
        ->set('cakeCategoryId', $category->id)
        ->set('basePriceId', $basePrice->id)
        ->set('agreedPrice', '100.00')
        ->set('deliveryAt', now()->addDay()->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors(['cakeCategoryId', 'basePriceId']);
});

test('order detail displays history and audits controlled edits', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    $component = Volt::test('orders.create');
    foreach (orderFormData($customer, $category, $basePrice) as $property => $value) {
        $component->set($property, $value);
    }
    $component->call('save');
    $order = Order::query()->latest('id')->firstOrFail();

    Volt::test('orders.show', ['order' => $order])
        ->assertSee($order->order_number)
        ->assertSee('Historial del pedido')
        ->assertSee('Pedido creado')
        ->assertSee('Se registro el pedido y su estado pendiente.')
        ->call('startEditing')
        ->set('agreedPrice', '200.00')
        ->set('status', 'delivered')
        ->call('saveChanges')
        ->assertHasNoErrors()
        ->assertSee('Pedido actualizado')
        ->assertSee('Estado actualizado');

    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::OrderUpdated->value)->exists())->toBeTrue();
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::OrderStatusChanged->value)->exists())->toBeTrue();
    expect($order->refresh()->status->value)->toBe('delivered');
    expect($order->agreed_price)->toBe('200.00');
    expect($user->fresh())->not->toBeNull();
});

test('order price cannot be reduced below registered payments during editing', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    $component = Volt::test('orders.create');
    foreach (orderFormData($customer, $category, $basePrice) as $property => $value) {
        $component->set($property, $value);
    }
    $component->set('depositAmount', '100.00')->call('save');
    $order = Order::query()->latest('id')->firstOrFail();

    Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('agreedPrice', '99.99')
        ->call('saveChanges')
        ->assertHasErrors('agreedPrice');

    expect($order->refresh()->agreed_price)->toBe('180.00');
    expect($user->fresh())->not->toBeNull();
});

test('order details escape customer and cake text before rendering', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create([
        'full_name' => 'Cliente <script>xss()</script>',
    ]);
    $order = Order::factory()
        ->custom()
        ->for($customer)
        ->for($user, 'createdBy')
        ->create([
            'cake_description' => 'Pastel <img src=x onerror=x>',
        ]);
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->assertSeeHtml('Cliente &lt;script&gt;xss()&lt;/script&gt;')
        ->assertSeeHtml('Pastel &lt;img src=x onerror=x&gt;')
        ->assertDontSeeHtml('<script>xss()</script>')
        ->assertDontSeeHtml('<img src=x onerror=x>');
});
