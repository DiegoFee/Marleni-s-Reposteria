<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel as NotificationChannelContract;
use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReminderWindow;
use App\Models\ActivityLog;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class ReminderService
{
    public function __construct(private NotificationChannelContract $notificationChannel) {}

    public function sendDueReminders(): int
    {
        if (! (bool) config('services.notifications.enabled')) {
            return 0;
        }

        $recipient = trim((string) config('services.notifications.recipient'));

        if ($recipient === '') {
            throw new RuntimeException('El destinatario de recordatorios no esta configurado.');
        }

        $channel = $this->configuredChannel();
        $now = now();
        $sentCount = 0;

        foreach (ReminderWindow::cases() as $reminderWindow) {
            $sentCount += $this->sendForWindow($reminderWindow, $now, $recipient, $channel);
        }

        return $sentCount;
    }

    private function sendForWindow(
        ReminderWindow $reminderWindow,
        CarbonInterface $now,
        string $recipient,
        NotificationChannelEnum $channel,
    ): int {
        $sentCount = 0;

        foreach ($this->ordersForWindow($reminderWindow, $now) as $order) {
            $notificationKey = $this->notificationKey($order, $reminderWindow);

            if ($this->sendReminder($order, $reminderWindow, $channel, $recipient, $notificationKey)) {
                $sentCount++;
            }
        }

        return $sentCount;
    }

    /**
     * @return Collection<int, Order>
     */
    private function ordersForWindow(ReminderWindow $reminderWindow, CarbonInterface $now): Collection
    {
        $from = $reminderWindow === ReminderWindow::Hours24
            ? $now->copy()
            : $now->copy()->addHours(24);
        $to = $now->copy()->addHours($reminderWindow === ReminderWindow::Hours24 ? 24 : 48);

        $query = Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->with([
                'customer:id,full_name,phone',
                'cakeCategory:id,name',
            ])
            ->withSum([
                'payments as registered_payments_total' => fn (Builder $query): Builder => $query->where('status', PaymentStatus::Registered->value),
            ], 'amount');

        if ($reminderWindow === ReminderWindow::Hours24) {
            $query->where('delivery_at', '>=', $from)->where('delivery_at', '<', $to);
        } else {
            $query->where('delivery_at', '>=', $from)->where('delivery_at', '<=', $to);
        }

        return $query->orderBy('delivery_at')->orderBy('id')->get();
    }

    private function sendReminder(
        Order $order,
        ReminderWindow $reminderWindow,
        NotificationChannelEnum $channel,
        string $recipient,
        string $notificationKey,
    ): bool {
        $lock = Cache::lock('order-reminder:'.$notificationKey, 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            if (ActivityLog::query()->where('notification_key', $notificationKey)->exists()) {
                return false;
            }

            try {
                $result = $this->notificationChannel->send(
                    recipient: $recipient,
                    message: $this->messageFor($order),
                    notificationKey: $notificationKey,
                );
            } catch (Throwable $exception) {
                $this->recordFailure($order, $reminderWindow, $channel, $notificationKey, $exception);

                return false;
            }

            $this->recordSuccess($order, $reminderWindow, $channel, $notificationKey, $result);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function recordSuccess(
        Order $order,
        ReminderWindow $reminderWindow,
        NotificationChannelEnum $channel,
        string $notificationKey,
        NotificationResult $result,
    ): void {
        $order->activityLogs()->create([
            'event_type' => ActivityEventType::NotificationSent,
            'notification_channel' => $channel,
            'reminder_window' => $reminderWindow,
            'notification_key' => $notificationKey,
            'provider_message_id' => $result->providerMessageId,
            'details' => [
                'delivery_at' => $order->delivery_at->toIso8601String(),
            ],
            'created_at' => now(),
        ]);
    }

    private function recordFailure(
        Order $order,
        ReminderWindow $reminderWindow,
        NotificationChannelEnum $channel,
        string $notificationKey,
        Throwable $exception,
    ): void {
        $details = [
            'notification_key' => $notificationKey,
            'failure_type' => $exception instanceof ConnectionException
                ? 'connection_error'
                : ($exception instanceof NotificationDeliveryException ? 'provider_rejected' : 'unexpected_error'),
            'provider_status' => $exception instanceof NotificationDeliveryException
                ? $exception->providerStatus
                : null,
        ];

        $order->activityLogs()->create([
            'event_type' => ActivityEventType::NotificationFailed,
            'notification_channel' => $channel,
            'reminder_window' => $reminderWindow,
            'notification_key' => null,
            'details' => array_filter($details, static fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);
    }

    private function configuredChannel(): NotificationChannelEnum
    {
        return NotificationChannelEnum::from((string) config('services.notifications.channel'));
    }

    private function notificationKey(Order $order, ReminderWindow $reminderWindow): string
    {
        return 'order-'.$order->getKey().'-'.$reminderWindow->value;
    }

    private function messageFor(Order $order): string
    {
        $deliveryAt = $order->delivery_at
            ->timezone((string) config('app.timezone'))
            ->format('d/m/Y H:i');

        return implode(PHP_EOL, [
            'Recordatorio de preparacion',
            'Pedido: '.$order->order_number,
            'Cliente: '.$order->customer->full_name,
            'Pastel: '.$this->cakeLabel($order),
            'Entrega: '.$deliveryAt,
            'Saldo pendiente: Q '.$this->pendingBalance($order),
        ]);
    }

    private function cakeLabel(Order $order): string
    {
        return $order->capture_mode === CaptureMode::Custom
            ? (string) $order->cake_description
            : (string) ($order->cakeCategory?->name ?? 'Pastel no especificado');
    }

    private function pendingBalance(Order $order): string
    {
        $registeredTotal = BigDecimal::of((string) ($order->getAttribute('registered_payments_total') ?? '0'));
        $balance = BigDecimal::of((string) $order->agreed_price)->minus($registeredTotal);

        return ($balance->isNegative() ? BigDecimal::of('0') : $balance)
            ->toScale(2)
            ->toString();
    }
}
