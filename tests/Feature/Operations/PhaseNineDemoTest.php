<?php

use App\Enums\ActivityEventType;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

test('the phase nine demo authenticates, shows the balance, and sends a simulated reminder', function () {
    config()->set([
        'admin.username' => 'fase9_demo',
        'admin.password' => 'fase9-demo-password',
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'telegram',
        'services.notifications.telegram.chat_id' => 'demo-recipient',
        'services.notifications.telegram.api_url' => 'https://api.telegram.test',
        'services.notifications.telegram.bot_token' => 'demo-token',
    ]);
    $this->travelTo('2026-09-17 10:00:00');

    $this->seed([CatalogSeeder::class, AdminUserSeeder::class, DemoDataSeeder::class]);

    $admin = User::query()->where('username', 'fase9_demo')->firstOrFail();
    $order = Order::query()->whereHas('customer', fn ($query) => $query->where('phone', '55500009'))->firstOrFail();

    expect(number_format((float) Payment::query()->where('order_id', $order->getKey())->sum('amount'), 2, '.', ''))->toBe('125.00');
    expect($order->agreed_price)->toBe('225.00');
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::OrderCreated->value)->exists())->toBeTrue();

    Volt::test('auth.login')
        ->set('username', $admin->username)
        ->set('password', 'fase9-demo-password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))
        ->assertSee('Cliente de Demostración Fase 9')
        ->assertSee('Q 100.00');

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 9001],
        ]),
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], $order->order_number)
        && str_contains($request['text'], 'Saldo pendiente: Q 100.00'));
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'provider_message_id' => '9001',
    ]);
});

test('database backups are scheduled daily in production without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'app:backup-database'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 2 * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->environments)->toContain('production');
});

test('database backup refuses non-mariadb connections', function () {
    $this->artisan('app:backup-database')->assertFailed();
});

test('database backup refuses a directory inside public before creating it', function () {
    $backupPath = public_path('fase9-backups-'.uniqid());
    $originalDatabaseConfig = [
        'database.default' => config('database.default'),
        'database.connections.mariadb.driver' => config('database.connections.mariadb.driver'),
        'database.connections.mariadb.database' => config('database.connections.mariadb.database'),
    ];

    config()->set([
        'database.default' => 'mariadb',
        'database.connections.mariadb.driver' => 'mariadb',
        'database.connections.mariadb.database' => 'test',
    ]);

    try {
        $this->artisan('app:backup-database', ['--path' => $backupPath])->assertFailed();
    } finally {
        config()->set($originalDatabaseConfig);
    }

    expect(is_dir($backupPath))->toBeFalse();
});
