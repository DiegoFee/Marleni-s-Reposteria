<?php

use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

beforeEach(function (): void {
    config()->set('services.notifications.enabled', false);
});

test('standard orders preserve an overridden price and record their initial history', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
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

test('order creation displays the localized required customer message', function () {
    $this->actingAs(User::factory()->owner()->create());

    Volt::test('orders.create')
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel personalizado')
        ->set('agreedPrice', '275.00')
        ->call('save')
        ->assertHasErrors('customerId')
        ->assertSee('El campo cliente es obligatorio.');

    expect(Order::query()->count())->toBe(0);
});

test('new orders reject delivery times in the past including an elapsed current minute', function () {
    $this->seed(CatalogSeeder::class);
    $this->travelTo(now()->setTime(12, 0, 30));
    $this->actingAs(User::factory()->owner()->create());
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();
    $invalidDeliveryTimes = [
        now()->subMinute()->format('Y-m-d\\TH:i'),
        now()->format('Y-m-d\\TH:i'),
    ];

    foreach ($invalidDeliveryTimes as $deliveryAt) {
        $component = Volt::test('orders.create');
        foreach (orderFormData($customer, $category, $basePrice) as $property => $value) {
            $component->set($property, $value);
        }

        $component
            ->set('deliveryAt', $deliveryAt)
            ->call('save')
            ->assertHasErrors('deliveryAt')
            ->assertSee('La fecha y hora de entrega no puede ser anterior al momento actual.');
    }

    expect(Order::query()->count())->toBe(0);
    expect(Payment::query()->count())->toBe(0);
    expect(ActivityLog::query()->count())->toBe(0);
});

test('order service accepts a new delivery at the exact current moment', function () {
    $this->travelTo(now()->setTime(12, 0));
    $actor = User::factory()->owner()->create();
    $customer = Customer::factory()->create();

    $order = app(OrderService::class)->create([
        'customer_id' => $customer->getKey(),
        'capture_mode' => CaptureMode::Custom,
        'cake_category_id' => null,
        'base_price_id' => null,
        'cake_description' => 'Pastel personalizado',
        'agreed_price' => '275.00',
        'delivery_at' => now(),
        'deposit_amount' => '0',
    ], $actor);

    expect($order->delivery_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseCount('orders', 1);
    expect(Payment::query()->count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::OrderCreated->value,
    ]);
});

test('order service stores date objects in the configured Guatemala timezone', function () {
    $this->travelTo('2026-10-13 12:00:00');
    $actor = User::factory()->owner()->create();
    $customer = Customer::factory()->create();

    $order = app(OrderService::class)->create([
        'customer_id' => $customer->getKey(),
        'capture_mode' => CaptureMode::Custom,
        'cake_category_id' => null,
        'base_price_id' => null,
        'cake_description' => 'Pastel personalizado',
        'agreed_price' => '275.00',
        'delivery_at' => new DateTimeImmutable('2026-10-13T18:00:00+00:00'),
        'deposit_amount' => '0',
    ], $actor);

    expect($order->delivery_at->format('Y-m-d H:i:s'))->toBe('2026-10-13 12:00:00');
});

test('order form accepts a delivery at the exact current moment', function () {
    $this->seed(CatalogSeeder::class);
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();
    $component = Volt::test('orders.create');

    foreach (orderFormData($customer, $category, $basePrice) as $property => $value) {
        $component->set($property, $value);
    }

    $component
        ->set('deliveryAt', now()->format('Y-m-d\\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('orders', 1);
    expect(Order::query()->firstOrFail()->delivery_at->equalTo(now()))->toBeTrue();
});

test('money inputs present two decimals on blur without rounding invalid precision', function () {
    $this->actingAs(User::factory()->owner()->create());
    $component = Volt::test('orders.create');

    foreach ([
        '0' => '0.00',
        '85' => '85.00',
        '1234.5' => '1234.50',
        '1234.567' => '1234.567',
    ] as $enteredAmount => $formattedAmount) {
        $component
            ->set('agreedPrice', $enteredAmount)
            ->call('formatAgreedPrice')
            ->assertSet('agreedPrice', $formattedAmount);
    }

    $component
        ->set('depositAmount', '0')
        ->call('formatDepositAmount')
        ->assertSet('depositAmount', '0.00');

    $order = Order::factory()->create();

    Volt::test('orders.show', ['order' => $order])
        ->set('paymentAmount', '1234.5')
        ->call('formatPaymentAmount')
        ->assertSet('paymentAmount', '1234.50');
});

test('saving an order persists its new customer and related records together', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    $component = Volt::test('orders.create')
        ->set('showCustomerForm', true)
        ->set('customerContact.fullName', '  María   del Carmen Pérez  ')
        ->set('customerContact.phone', ' 5361 - 6569 ');

    expect(Customer::query()->count())->toBe(0);

    $component
        ->set('captureMode', CaptureMode::Standard->value)
        ->set('cakeCategoryId', $category->id)
        ->set('basePriceId', $basePrice->id)
        ->set('agreedPrice', '180.00')
        ->set('deliveryAt', now()->addDays(2)->format('Y-m-d H:i:s'))
        ->set('depositAmount', '50.00')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::query()->firstOrFail();
    $order = Order::query()->firstOrFail();

    expect($customer->full_name)->toBe('María del Carmen Pérez');
    expect($customer->phone)->toBe('53616569');
    expect($order->customer_id)->toBe($customer->getKey());
    expect($order->created_by)->toBe($user->getKey());
    expect(Payment::query()->where('order_id', $order->getKey())->where('payment_type', PaymentType::Deposit->value)->value('amount'))->toBe('50.00');
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::OrderCreated->value)->exists())->toBeTrue();
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::PaymentRegistered->value)->exists())->toBeTrue();
});

test('order service validates a new customer contact before creating related records', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    expect(fn () => app(OrderService::class)->create([
        'customer_id' => null,
        'capture_mode' => CaptureMode::Standard,
        'cake_category_id' => $category->getKey(),
        'base_price_id' => $basePrice->getKey(),
        'cake_description' => null,
        'agreed_price' => '180.00',
        'delivery_at' => now()->addDays(2),
        'deposit_amount' => '50.00',
    ], $user, [
        'full_name' => 'Juan',
        'phone' => '5361a569',
    ]))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('order creation rolls back when a customer contact becomes duplicate during the transaction', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();
    $duplicateInserted = false;

    Customer::creating(function () use (&$duplicateInserted): void {
        if ($duplicateInserted) {
            return;
        }

        $duplicateInserted = true;

        DB::table('customers')->insert([
            'full_name' => 'Cliente de carrera',
            'phone' => '55554321',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => app(OrderService::class)->create([
        'customer_id' => null,
        'capture_mode' => CaptureMode::Standard,
        'cake_category_id' => $category->getKey(),
        'base_price_id' => $basePrice->getKey(),
        'cake_description' => null,
        'agreed_price' => '180.00',
        'delivery_at' => now()->addDays(2),
        'deposit_amount' => '50.00',
    ], $user, [
        'full_name' => 'Cliente nuevo',
        'phone' => '55554321',
    ]))->toThrow(ValidationException::class, 'El valor de teléfono ya está registrado.');

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('activity_logs', 0);
});

test('a failed initial deposit insert rolls back its customer order and history', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();

    Payment::creating(function (Payment $payment): void {
        throw new RuntimeException('The initial deposit insert failed.');
    });

    expect(fn () => app(OrderService::class)->create([
        'customer_id' => null,
        'capture_mode' => CaptureMode::Standard,
        'cake_category_id' => $category->getKey(),
        'base_price_id' => $basePrice->getKey(),
        'cake_description' => null,
        'agreed_price' => '180.00',
        'delivery_at' => now()->addDays(2),
        'deposit_amount' => '50.00',
    ], $user, [
        'full_name' => 'Cliente para rollback',
        'phone' => '55554321',
    ]))->toThrow(RuntimeException::class, 'The initial deposit insert failed.');

    expect(Customer::query()->count())->toBe(0);
    expect(Order::query()->count())->toBe(0);
    expect(Payment::query()->count())->toBe(0);
    expect(ActivityLog::query()->count())->toBe(0);
});

test('sends the order summary after registering a new order', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    $category = CakeCategory::query()->firstOrFail();
    $basePrice = BasePrice::query()->firstOrFail();
    config()->set([
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'telegram',
        'services.notifications.telegram.chat_id' => 'order-summary-recipient',
        'services.notifications.telegram.api_url' => 'https://api.telegram.test',
        'services.notifications.telegram.bot_token' => 'order-summary-token',
    ]);

    Http::preventStrayRequests();
    $outerTransactionLevel = DB::transactionLevel();
    $summaryWasSentAfterCommit = false;
    Http::fake([
        'https://api.telegram.test/*' => function (Request $request) use (&$summaryWasSentAfterCommit, $outerTransactionLevel) {
            $summaryWasSentAfterCommit = DB::transactionLevel() === $outerTransactionLevel
                && Order::query()->exists();

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => 7001],
            ]);
        },
    ]);

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Standard->value)
        ->set('cakeCategoryId', $category->id)
        ->set('basePriceId', $basePrice->id)
        ->set('agreedPrice', '180.00')
        ->set('deliveryAt', now()->addDays(2)->format('Y-m-d H:i:s'))
        ->set('depositAmount', '50.00')
        ->call('save')
        ->assertHasNoErrors();

    expect($summaryWasSentAfterCommit)->toBeTrue();

    $order = Order::query()->latest('id')->firstOrFail();
    $notificationKey = 'telegram:'.$order->getKey().':order_created_summary';

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'order-summary-recipient'
        && str_contains($request['text'], '________________________________')
        && str_contains($request['text'], 'NUEVO PEDIDO REGISTRADO')
        && str_contains($request['text'], '- Pedido: '.$order->order_number)
        && str_contains($request['text'], $customer->full_name)
        && str_contains($request['text'], '- Teléfono: '.$customer->phone)
        && str_contains($request['text'], '- Precio pactado: Q 180.00')
        && str_contains($request['text'], '- Pagado: Q 50.00')
        && str_contains($request['text'], '- Saldo pendiente: Q 130.00'));

    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'notification_channel' => 'telegram',
        'reminder_window' => null,
        'notification_key' => $notificationKey,
        'provider_message_id' => '7001',
    ]);
    $sentNotification = ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationSent->value)
        ->firstOrFail();

    expect($sentNotification->details)->toMatchArray([
        'notification_type' => 'order_created_summary',
    ]);

    Volt::test('orders.show', ['order' => $order])
        ->assertSee('Resumen del pedido enviado a la administradora.');
});

test('keeps a new order when the summary provider rejects the message', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    config()->set([
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'telegram',
        'services.notifications.telegram.chat_id' => 'order-summary-recipient',
        'services.notifications.telegram.api_url' => 'https://api.telegram.test',
        'services.notifications.telegram.bot_token' => 'order-summary-token',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => Http::response(['ok' => false], 400),
    ]);

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel de chocolate')
        ->set('agreedPrice', '275.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasNoErrors();

    $order = Order::query()->latest('id')->firstOrFail();
    $failure = ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationFailed->value)
        ->firstOrFail();

    expect($failure->notification_key)->toBe('telegram:'.$order->getKey().':order_created_summary');
    expect($failure->details)->toMatchArray([
        'notification_type' => 'order_created_summary',
        'failure_type' => 'provider_rejected',
        'provider_status' => 400,
    ]);
    expect(Order::query()->count())->toBe(1);
});

test('retries a failed order summary only after an explicit operator request', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $customer = Customer::factory()->create();
    $attempts = 0;
    config()->set([
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'telegram',
        'services.notifications.telegram.chat_id' => 'order-summary-recipient',
        'services.notifications.telegram.api_url' => 'https://api.telegram.test',
        'services.notifications.telegram.bot_token' => 'order-summary-token',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response(['ok' => false], 400)
                : Http::response(['ok' => true, 'result' => ['message_id' => 7002]]);
        },
    ]);

    Volt::test('orders.create')
        ->set('customerId', $customer->id)
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel de chocolate')
        ->set('agreedPrice', '275.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasNoErrors();

    $order = Order::query()->latest('id')->firstOrFail();
    expect($attempts)->toBe(1);

    $this->travel(1)->hour();
    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(1);

    $this->artisan('orders:send-reminders --retry-failed')->assertSuccessful();

    Http::assertSentCount(2);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'notification_key' => 'telegram:'.$order->getKey().':order_created_summary',
        'provider_message_id' => '7002',
    ]);
});

test('custom orders require a description and keep catalog references empty', function () {
    $user = User::factory()->owner()->create();
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
    $this->actingAs(User::factory()->owner()->create());
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
        ->assertHasErrors(['cakeCategoryId', 'basePriceId', 'cakeDescription', 'depositAmount'])
        ->assertSee('El anticipo inicial no puede ser mayor al precio pactado.');

    expect(Order::query()->count())->toBe(0);
});

test('standard orders require catalog references for their modality', function () {
    $this->actingAs(User::factory()->owner()->create());
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
    $user = User::factory()->owner()->create();
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

test('an invalid order does not persist its staged new customer', function () {
    $this->actingAs(User::factory()->owner()->create());

    Volt::test('orders.create')
        ->set('showCustomerForm', true)
        ->set('customerContact.fullName', 'Cliente sin pedido')
        ->set('customerContact.phone', '55555678')
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel personalizado')
        ->set('agreedPrice', '100000000.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors('agreedPrice');

    expect(Customer::query()->count())->toBe(0);
    expect(Order::query()->count())->toBe(0);
});

test('order creation rejects a free-text customer that was not selected or registered', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);

    Volt::test('orders.create')
        ->set('customerSearch', 'Cliente que no existe')
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel personalizado')
        ->set('agreedPrice', '275.00')
        ->set('deliveryAt', now()->addDays(3)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors('customerId');

    expect(Order::query()->count())->toBe(0);
});

test('order forms reject amounts above the database precision', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
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
        ->assertHasErrors('agreedPrice')
        ->assertSee('El precio pactado no puede superar Q 99,999,999.99.');

    expect(Order::query()->count())->toBe(0);
});

test('order forms reject amounts with more than two decimal places', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $this->actingAs($user);

    Volt::test('orders.create')
        ->set('customerId', $customer->getKey())
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel personalizado')
        ->set('agreedPrice', '275.001')
        ->call('save')
        ->assertHasErrors('agreedPrice')
        ->assertSee('El precio pactado debe tener como máximo dos decimales.');

    expect(Order::query()->count())->toBe(0);
});

test('creating and editing orders use the same maximum price validation message', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $this->actingAs($user);

    Volt::test('orders.create')
        ->set('customerId', $customer->getKey())
        ->set('captureMode', CaptureMode::Custom->value)
        ->set('cakeDescription', 'Pastel personalizado')
        ->set('agreedPrice', '100000000.00')
        ->call('save')
        ->assertHasErrors('agreedPrice')
        ->assertSee('El precio pactado no puede superar Q 99,999,999.99.');

    expect(Order::query()->count())->toBe(0);

    $order = Order::factory()->custom()->for($user, 'createdBy')->create();

    Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('agreedPrice', '100000000.00')
        ->call('saveChanges')
        ->assertHasErrors('agreedPrice')
        ->assertSee('El precio pactado no puede superar Q 99,999,999.99.');

    expect($order->refresh()->agreed_price)->toBe('150.00');
});

test('order service rejects prices with more than two decimal places', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();

    expect(fn () => app(OrderService::class)->create([
        'customer_id' => $customer->getKey(),
        'capture_mode' => CaptureMode::Custom,
        'cake_category_id' => null,
        'base_price_id' => null,
        'cake_description' => 'Pastel personalizado',
        'agreed_price' => '275.001',
        'delivery_at' => now()->addDay(),
        'deposit_amount' => '0',
    ], $user))->toThrow(ValidationException::class, 'El precio pactado debe tener como máximo dos decimales.');

    expect(Order::query()->count())->toBe(0);
    expect(Payment::query()->count())->toBe(0);
});

test('order service rejects initial deposits with more than two decimal places', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();

    expect(fn () => app(OrderService::class)->create([
        'customer_id' => $customer->getKey(),
        'capture_mode' => CaptureMode::Custom,
        'cake_category_id' => null,
        'base_price_id' => null,
        'cake_description' => 'Pastel personalizado',
        'agreed_price' => '275.00',
        'delivery_at' => now()->addDay(),
        'deposit_amount' => '0.001',
    ], $user))->toThrow(ValidationException::class, 'El anticipo debe tener como máximo dos decimales.');

    expect(Order::query()->count())->toBe(0);
    expect(Payment::query()->count())->toBe(0);
});

test('inactive catalog entries cannot be selected for standard orders', function () {
    $this->actingAs(User::factory()->owner()->create());
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
    $user = User::factory()->owner()->create();
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
    app(PaymentService::class)->register($order, [
        'amount' => '180.00',
        'paid_at' => now(),
    ], $user);

    Volt::test('orders.show', ['order' => $order])
        ->assertSee($order->order_number)
        ->assertSee('Historial del pedido')
        ->assertSee('Pedido creado')
        ->assertSee('Se registró el pedido y su estado pendiente.')
        ->call('startEditing')
        ->assertSee('¿Retirar pedido al Historial?')
        ->set('deliveryAt', now()->addDays(4)->format('Y-m-d H:i:s'))
        ->call('saveChanges')
        ->call('requestStatusChange', 'delivered')
        ->call('confirmStatusChange')
        ->assertHasNoErrors()
        ->assertSee('Pedido actualizado')
        ->assertSee('Estado actualizado');

    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::OrderUpdated->value)->exists())->toBeTrue();
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::OrderStatusChanged->value)->exists())->toBeTrue();
    expect($order->refresh()->status->value)->toBe('delivered');
    expect($order->agreed_price)->toBe('180.00');
    expect($user->fresh())->not->toBeNull();
});

test('pending orders reject reprogramming to the current minute after its seconds have passed', function () {
    $this->travelTo('2026-10-13 12:00:30');
    $user = User::factory()->owner()->create();
    $order = Order::factory()
        ->custom()
        ->for($user, 'createdBy')
        ->create(['delivery_at' => now()->addDay()]);
    $originalDeliveryAt = $order->delivery_at->copy();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('deliveryAt', now()->format('Y-m-d\\TH:i'))
        ->assertSeeHtml('min="2026-10-13T12:01"')
        ->call('saveChanges')
        ->assertHasErrors('deliveryAt')
        ->assertSee('La fecha y hora de entrega no puede ser anterior al momento actual.');

    expect($order->refresh()->delivery_at->equalTo($originalDeliveryAt))->toBeTrue();
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::OrderUpdated->value)->exists())->toBeFalse();
});

test('order service rejects reprogramming a pending order into the past', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->owner()->create();
    $order = Order::factory()->custom()->for($user, 'createdBy')->create(['delivery_at' => now()->addDay()]);

    expect(fn () => app(OrderService::class)->update($order, [
        'customer_id' => $order->customer_id,
        'capture_mode' => CaptureMode::Custom,
        'cake_category_id' => null,
        'base_price_id' => null,
        'cake_description' => $order->cake_description,
        'agreed_price' => (string) $order->agreed_price,
        'delivery_at' => now()->subMinute(),
    ], $user))->toThrow(ValidationException::class, 'La fecha y hora de entrega no puede ser anterior al momento actual.');

    expect($order->refresh()->delivery_at->isFuture())->toBeTrue();
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::OrderUpdated->value)->exists())->toBeFalse();
});

test('editing an overdue order without rescheduling preserves its delivery time', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->owner()->create();
    $originalDeliveryAt = now()->subDay()->setTime(11, 45, 37);
    $order = Order::factory()
        ->custom()
        ->for($user, 'createdBy')
        ->create(['delivery_at' => $originalDeliveryAt]);
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->set('cakeDescription', 'Pastel actualizado sin cambiar entrega')
        ->call('saveChanges')
        ->assertHasNoErrors();

    $updatedOrder = $order->refresh();

    expect($updatedOrder->delivery_at->equalTo($originalDeliveryAt))->toBeTrue();
    expect($updatedOrder->cake_description)->toBe('Pastel actualizado sin cambiar entrega');
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::OrderUpdated->value)->exists())->toBeTrue();
});

test('order price cannot be reduced below registered payments during editing', function () {
    $this->seed(CatalogSeeder::class);
    $user = User::factory()->owner()->create();
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
    $user = User::factory()->owner()->create();
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

test('orders cannot be marked delivered until the agreed price is fully paid', function () {
    $owner = User::factory()->owner()->create();
    $assistant = User::factory()->assistant()->create();
    $order = Order::factory()->for($owner, 'createdBy')->create();
    $this->actingAs($assistant);

    Volt::test('orders.show', ['order' => $order])
        ->call('requestStatusChange', 'delivered')
        ->call('confirmStatusChange')
        ->assertHasErrors('status');

    expect($order->refresh()->status->value)->toBe('pending');
});

test('terminal orders cannot be edited after delivery', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->delivered()->for($user, 'createdBy')->create();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('startEditing')
        ->assertHasErrors('status')
        ->call('saveChanges')
        ->assertHasErrors('status');

    expect($order->refresh()->status->value)->toBe('delivered');
});

test('terminal status changes require confirmation before saving', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->for($user, 'createdBy')->create();
    $this->actingAs($user);

    $component = Volt::test('orders.show', ['order' => $order])
        ->call('requestStatusChange', 'cancelled');

    $component
        ->assertSet('showStatusConfirmation', true)
        ->assertSet('pendingStatus', 'cancelled')
        ->call('cancelStatusChange')
        ->assertSet('showStatusConfirmation', false)
        ->assertSet('pendingStatus', null);

    expect($order->refresh()->status->value)->toBe('pending');
});

test('orders with active payments cannot be cancelled', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->for($user, 'createdBy')->create();
    $this->actingAs($user);

    app(PaymentService::class)->register($order, [
        'amount' => '50.00',
        'paid_at' => now(),
    ], $user);

    Volt::test('orders.show', ['order' => $order->refresh()])
        ->call('requestStatusChange', 'cancelled')
        ->call('confirmStatusChange')
        ->assertHasErrors('status');

    expect($order->refresh()->status->value)->toBe('pending');
});

test('retiring pending orders without active payments cancels them while preserving payment history', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->for($user, 'createdBy')->create();
    $payment = Payment::factory()->voided()->for($order)->for($user, 'registeredBy')->create();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('deleteOrder')
        ->assertRedirect(route('orders.index', absolute: false));

    $this->assertSoftDeleted($order);
    $this->assertDatabaseHas('payments', ['id' => $payment->id]);

    $retiredOrder = Order::withTrashed()->findOrFail($order->getKey());
    $statusEvent = ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::OrderStatusChanged->value)
        ->sole();

    expect($retiredOrder->status)->toBe(OrderStatus::Cancelled);
    expect($statusEvent->actor_user_id)->toBe($user->getKey());
    expect($statusEvent->details)->toMatchArray([
        'from' => OrderStatus::Pending->value,
        'to' => OrderStatus::Cancelled->value,
        'reason' => 'retired_to_history',
    ]);
});

test('retiring a pending order with active payments cancels it and preserves the payment', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->for($user, 'createdBy')->create();
    $payment = Payment::factory()->for($order)->for($user, 'registeredBy')->create();
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->call('deleteOrder')
        ->assertRedirect(route('orders.index', absolute: false));

    $retiredOrder = Order::withTrashed()->findOrFail($order->getKey());

    expect($retiredOrder->status)->toBe(OrderStatus::Cancelled);
    $this->assertSoftDeleted($order);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Registered);
    $this->assertModelExists($payment);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::OrderStatusChanged->value,
    ]);
});
