<?php

use App\Enums\ActivityEventType;
use App\Enums\ReminderWindow;
use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    config()->set([
        'services.notifications.enabled' => true,
        'services.notifications.channel' => 'telegram',
        'services.notifications.recipient' => 'recipient-for-test',
        'services.notifications.connect_timeout' => 1,
        'services.notifications.timeout' => 2,
        'services.notifications.telegram.api_url' => 'https://api.telegram.test',
        'services.notifications.telegram.bot_token' => 'telegram-token-for-test',
    ]);
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
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Idempotency-Key',
        'order-'.$orderIn48Hours->getKey().'-48_hours',
    ) && str_contains($request['text'], $orderIn48Hours->order_number)
        && str_contains($request['text'], $orderIn48Hours->customer->full_name)
        && str_contains($request['text'], 'Saldo pendiente: Q 275.00'));

    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $orderIn48Hours->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'notification_channel' => 'telegram',
        'reminder_window' => ReminderWindow::Hours48->value,
        'notification_key' => 'order-'.$orderIn48Hours->getKey().'-48_hours',
        'provider_message_id' => '12345',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'order_id' => $orderIn24Hours->getKey(),
        'event_type' => ActivityEventType::NotificationSent->value,
        'notification_channel' => 'telegram',
        'reminder_window' => ReminderWindow::Hours24->value,
        'notification_key' => 'order-'.$orderIn24Hours->getKey().'-24_hours',
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

test('audits a provider failure and allows a later retry without duplicate confirmation', function () {
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

    expect($failure->notification_key)->toBeNull();
    expect($failure->details['notification_key'])->toBe('order-'.$order->getKey().'-24_hours');
    expect($failure->details['failure_type'])->toBe('provider_rejected');
    expect($failure->details['provider_status'])->toBe(503);
    expect(json_encode($failure->details))->not->toContain('telegram-token-for-test');

    $this->artisan('orders:send-reminders')->assertSuccessful();

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
        'notification_key' => 'order-'.$order->getKey().'-24_hours',
        'provider_message_id' => '98765',
    ]);
});

test('sends through the configured WhatsApp adapter and records its provider id', function () {
    config()->set([
        'services.notifications.channel' => 'whatsapp',
        'services.notifications.whatsapp.api_url' => 'https://graph.facebook.test/v20.0',
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
