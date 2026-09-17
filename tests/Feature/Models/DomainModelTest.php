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
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

test('domain models expose the approved relationships and casts', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $category = CakeCategory::factory()->create();
    $basePrice = BasePrice::factory()->create();
    $order = Order::factory()
        ->for($customer)
        ->for($user, 'createdBy')
        ->for($category, 'cakeCategory')
        ->for($basePrice, 'basePrice')
        ->create();
    $payment = Payment::factory()
        ->for($order)
        ->for($user, 'registeredBy')
        ->create();
    $activityLog = ActivityLog::factory()
        ->for($order)
        ->for($payment)
        ->for($user, 'actorUser')
        ->create();

    expect($order->customer->is($customer))->toBeTrue();
    expect($order->createdBy->is($user))->toBeTrue();
    expect($order->cakeCategory->is($category))->toBeTrue();
    expect($order->basePrice->is($basePrice))->toBeTrue();
    expect($order->payments->contains($payment))->toBeTrue();
    expect($order->activityLogs->contains($activityLog))->toBeTrue();
    expect($order->capture_mode)->toBe(CaptureMode::Standard);
    expect($order->status)->toBe(OrderStatus::Pending);
    expect($payment->payment_type)->toBe(PaymentType::Deposit);
    expect($payment->status)->toBe(PaymentStatus::Registered);
    expect($activityLog->event_type)->toBe(ActivityEventType::OrderCreated);
    expect($activityLog->details)->toBe(['source' => 'test']);
    expect($user->ordersCreated->contains($order))->toBeTrue();
    expect($user->paymentsRegistered->contains($payment))->toBeTrue();
    expect($user->activityLogs->contains($activityLog))->toBeTrue();
});

test('catalog seeder provides approved active catalogs that can be deactivated', function () {
    $this->seed(CatalogSeeder::class);

    expect(CakeCategory::query()->orderBy('id')->pluck('name')->all())->toBe([
        'Pasteles Fríos',
        'Tres Leches',
        'Pasteles Secos',
    ]);
    expect(BasePrice::query()->orderBy('id')->pluck('amount')->map(fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''))->all())->toBe([
        '85.00',
        '150.00',
        '165.00',
        '175.00',
        '225.00',
        '325.00',
    ]);

    $category = CakeCategory::query()->firstOrFail();
    $category->update(['is_active' => false]);
    $this->seed(CatalogSeeder::class);

    expect($category->refresh()->is_active)->toBeFalse();
});

test('catalog names and base price amounts are unique', function () {
    CakeCategory::factory()->create(['name' => 'Pasteles Fríos']);
    BasePrice::factory()->create(['amount' => '150.00']);

    expect(fn () => CakeCategory::factory()->create(['name' => 'Pasteles Fríos']))
        ->toThrow(QueryException::class);
    expect(fn () => BasePrice::factory()->create(['amount' => '150.00']))
        ->toThrow(QueryException::class);
});

test('order numbers are unique', function () {
    Order::factory()->create(['order_number' => 'ORD-0001-TEST']);

    expect(fn () => Order::factory()->create(['order_number' => 'ORD-0001-TEST']))
        ->toThrow(QueryException::class);
});

test('foreign keys prevent deleting a customer with orders', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create();

    expect(fn () => $customer->delete())->toThrow(QueryException::class);
});

test('notification keys are unique when present', function () {
    $notificationKey = 'order-1-48_hours';
    ActivityLog::factory()->create(['notification_key' => $notificationKey]);

    expect(fn () => ActivityLog::factory()->create(['notification_key' => $notificationKey]))
        ->toThrow(QueryException::class);
});

test('admin seeder uses configured credentials and hashes the password', function () {
    config()->set([
        'admin.name' => 'Administradora de Prueba',
        'admin.email' => 'admin@example.test',
        'admin.password' => 'temporary-password',
    ]);

    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

    expect($admin->name)->toBe('Administradora de Prueba');
    expect(Hash::check('temporary-password', $admin->password))->toBeTrue();
    expect($admin->email_verified_at)->not->toBeNull();
});
