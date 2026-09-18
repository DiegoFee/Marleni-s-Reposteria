<?php

use App\Enums\ActivityEventType;
use App\Enums\ReminderWindow;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Notifications\ReminderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    config()->set([
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'telegram',
        'services.notifications.telegram.chat_id' => 'recipient-for-test',
        'services.notifications.telegram.connect_timeout' => 1,
        'services.notifications.telegram.timeout' => 2,
        'services.notifications.telegram.api_url' => 'https://api.telegram.test',
        'services.notifications.telegram.bot_token' => 'telegram-token-for-test',
    ]);
});

test('registers the reminder command every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'orders:send-reminders'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->timezone)->toBe('America/Guatemala');
});

test('sends audited reminders for both windows and excludes closed or expired orders', function () {
    $this->travelTo('2026-09-16 10:00:00');

    $orderIn48Hours = Order::factory()->custom()->create([
        'agreed_price' => '275.00',
        'delivery_at' => now()->addHours(30),
    ]);
    $orderIn24Hours = Order::factory()->create([
        'agreed_price' => '150.00',
        'delivery_at' => now()->addHours(12),
    ]);
    $deliveredOrder = Order::factory()->delivered()->create(['delivery_at' => now()->addHours(12)]);
    $cancelledOrder = Order::factory()->cancelled()->create(['delivery_at' => now()->addHours(12)]);
    $expiredOrder = Order::factory()->create(['delivery_at' => now()->subMinute()]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 12345],
        ]),
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], '________________________________')
        && str_contains($request['text'], 'RECORDATORIO DE PREPARACIÓN - 48 horas'));
    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], '- Pedido: '.$orderIn48Hours->order_number)
        && str_contains($request['text'], $orderIn48Hours->customer->full_name)
        && str_contains($request['text'], '- Saldo pendiente: Q 275.00'));

    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $orderIn48Hours->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'notification_channel' => 'telegram',
        'reminder_window' => ReminderWindow::Hours48->value,
        'notification_key' => 'telegram:'.$orderIn48Hours->getKey().':48_hours',
        'provider_message_id' => '12345',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $orderIn24Hours->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'notification_channel' => 'telegram',
        'reminder_window' => ReminderWindow::Hours24->value,
        'notification_key' => 'telegram:'.$orderIn24Hours->getKey().':24_hours',
        'provider_message_id' => '12345',
    ]);

    foreach ([$deliveredOrder, $cancelledOrder, $expiredOrder] as $excludedOrder) {
        $this->assertDatabaseMissing('activity_logs', [
            'order_id' => $excludedOrder->getKey(),
            'event_type' => ActivityEventType::NotificationSent->value,
        ]);
    }

    Volt::test('orders.show', ['order' => $orderIn24Hours->refresh()])
        ->assertSee('Recordatorio enviado')
        ->assertSee('Recordatorio confirmado.');
});

test('does not contact the provider when reminders are disabled', function () {
    config()->set('services.notifications.enabled', false);
    Order::factory()->create(['delivery_at' => now()->addHours(12)]);

    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.test/*' => Http::response(['ok' => true])]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertNothingSent();
    expect(ActivityLog::query()->where('event_type', ActivityEventType::NotificationSent->value)->count())->toBe(0);
});

test('resolves the notification service safely when notifications are disabled and the channel is invalid', function () {
    config()->set([
        'services.notifications.enabled' => false,
        'services.notifications.channel' => 'invalid',
    ]);

    expect(app(ReminderService::class))->toBeInstanceOf(ReminderService::class);
});

test('fails fast when notifications are enabled with an invalid channel', function () {
    config()->set([
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'invalid',
    ]);

    expect(fn () => app(ReminderService::class))
        ->toThrow(RuntimeException::class);
});

test('does not send a confirmed reminder twice for the same order and window', function () {
    $this->travelTo('2026-09-16 10:00:00');
    $order = Order::factory()->create(['delivery_at' => now()->addHours(12)]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 12345],
        ]),
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();
    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(1);
    expect(ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationSent->value)
        ->count())->toBe(1);
});

test('audits a provider failure and only retries it when explicitly requested', function () {
    $this->travelTo('2026-09-16 10:00:00');
    $order = Order::factory()->create(['delivery_at' => now()->addHours(12)]);
    $attempts = 0;

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => function (Request $request) use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response([], 503)
                : Http::response(['ok' => true, 'result' => ['message_id' => 98765]]);
        },
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    $failure = ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationFailed->value)
        ->firstOrFail();

    expect($failure->notification_key)->toBe('telegram:'.$order->getKey().':24_hours');
    expect($failure->details['notification_key'])->toBe('telegram:'.$order->getKey().':24_hours');
    expect($failure->details['failure_type'])->toBe('provider_rejected');
    expect($failure->details['provider_status'])->toBe(503);
    expect($failure->details['attempts'])->toBe(1);
    expect(json_encode($failure->details))->not->toContain('telegram-token-for-test');

    $this->travel(5)->minutes();
    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(1);

    $this->artisan('orders:send-reminders --retry-failed')->assertSuccessful();

    Http::assertSentCount(2);
    expect(ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationFailed->value)
        ->count())->toBe(1);
    expect(ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationSent->value)
        ->count())->toBe(1);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'notification_key' => 'telegram:'.$order->getKey().':24_hours',
        'provider_message_id' => '98765',
    ]);
});

test('uses exact reminder boundaries and only registered payments in the balance', function () {
    $this->travelTo('2026-09-16 10:00:00');

    $orderAtNow = Order::factory()->create([
        'agreed_price' => '300.00',
        'delivery_at' => now(),
    ]);
    Payment::factory()->for($orderAtNow)->create(['amount' => '75.00']);
    Payment::factory()->voided()->for($orderAtNow)->create(['amount' => '100.00']);

    $orderAt24Hours = Order::factory()->create(['delivery_at' => now()->addHours(24)]);
    $orderAt48Hours = Order::factory()->create(['delivery_at' => now()->addHours(48)]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 12345],
        ]),
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Saldo pendiente: Q 225.00'));
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $orderAtNow->getKey(),
        'reminder_window' => ReminderWindow::Hours24->value,
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $orderAt24Hours->getKey(),
        'reminder_window' => ReminderWindow::Hours48->value,
    ]);
    $this->assertDatabaseMissing('activity_logs', [
        'order_id' => $orderAt48Hours->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
    ]);
});

test('automatically retries a rate-limited notification after the provider delay', function () {
    $this->travelTo('2026-09-16 10:00:00');
    $order = Order::factory()->create(['delivery_at' => now()->addHours(12)]);
    $attempts = 0;

    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response([
                    'ok' => false,
                    'description' => 'bot token must not be persisted',
                    'parameters' => ['retry_after' => 0],
                ], 429)
                : Http::response(['ok' => true, 'result' => ['message_id' => 2468]]);
        },
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(1);
    $failure = ActivityLog::query()
        ->where('order_id', $order->getKey())
        ->where('event_type', ActivityEventType::NotificationFailed->value)
        ->firstOrFail();

    expect($failure->notification_key)->toBe('telegram:'.$order->getKey().':24_hours');
    expect($failure->details)->toMatchArray([
        'failure_type' => 'provider_rejected',
        'provider_status' => 429,
        'retry_after' => 0,
    ]);
    expect(json_encode($failure->details))->not->toContain('bot token must not be persisted');

    $this->travel(1)->minute();
    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSentCount(2);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'provider_message_id' => '2468',
    ]);
});

test('sends an administrative Telegram test message only when enabled', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.test/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 456],
        ]),
    ]);

    $this->artisan('notifications:telegram-test')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'recipient-for-test'
        && $request['text'] === implode(PHP_EOL, [
            '________________________________',
            'PRUEBA DE CONEXIÓN DE TELEGRAM',
            '________________________________',
            '- Marleni\'s Repostería',
            '________________________________',
        ]));

    config()->set('services.notifications.enabled', false);

    $this->artisan('notifications:telegram-test')->assertFailed();
});

test('sends through the configured WhatsApp adapter and records its provider id', function () {
    config()->set([
        'services.notifications.channel' => 'whatsapp',
        'services.notifications.whatsapp.api_url' => 'https://graph.facebook.test/v20.0',
        'services.notifications.whatsapp.recipient' => 'recipient-for-test',
        'services.notifications.whatsapp.connect_timeout' => 1,
        'services.notifications.whatsapp.timeout' => 2,
        'services.notifications.whatsapp.access_token' => 'whatsapp-token-for-test',
        'services.notifications.whatsapp.phone_number_id' => 'phone-number-for-test',
    ]);

    $order = Order::factory()->create(['delivery_at' => now()->addHours(12)]);

    Http::preventStrayRequests();
    Http::fake([
        'https://graph.facebook.test/*' => Http::response([
            'messages' => [['id' => 'wamid.test-123']],
        ]),
    ]);

    $this->artisan('orders:send-reminders')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.test/v20.0/phone-number-for-test/messages'
        && $request->hasHeader('Authorization', 'Bearer whatsapp-token-for-test')
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === 'recipient-for-test'
        && $request['type'] === 'text'
        && str_contains($request['text']['body'], $order->order_number));
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $order->getKey(),
        'notification_channel' => 'whatsapp',
        'provider_message_id' => 'wamid.test-123',
    ]);
});
