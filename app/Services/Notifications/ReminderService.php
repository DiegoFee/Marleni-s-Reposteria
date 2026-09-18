<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel as NotificationChannelContract;
use App\Enums\ActivityEventType;
use App\Enums\CaptureMode;
use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Enums\NotificationMessageType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReminderWindow;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class ReminderService
{
    private const MESSAGE_SEPARATOR = '________________________________';

    private const RATE_LIMIT_RETRY_DELAY_MINIMUM_SECONDS = 60;

    public function __construct(private NotificationChannelContract $notificationChannel) {}

    public function sendDueReminders(bool $retryFailed = false): int
    {
        if (! (bool) config('services.notifications.enabled')) {
            return 0;
        }

        $channel = $this->configuredChannel();
        $recipient = $this->recipientFor($channel);

        if ($recipient === '') {
            throw new RuntimeException('El destinatario de recordatorios no esta configurado.');
        }

        $now = now((string) config('app.timezone'));
        $sentCount = $this->retryFailedNotifications($channel, $recipient, $now, $retryFailed);

        foreach (ReminderWindow::cases() as $reminderWindow) {
            $sentCount += $this->sendForWindow($reminderWindow, $now, $recipient, $channel);
        }

        return $sentCount;
    }

    public function sendOrderCreatedSummary(Order $order): bool
    {
        if (! (bool) config('services.notifications.enabled')) {
            return false;
        }

        try {
            $channel = $this->configuredChannel();
            $recipient = $this->recipientFor($channel);
            $messageType = NotificationMessageType::OrderCreatedSummary;
            $notificationKey = $this->notificationKey($order, $channel, $messageType);

            if ($recipient === '') {
                $this->recordFailure(
                    order: $order,
                    channel: $channel,
                    notificationKey: $notificationKey,
                    messageType: $messageType,
                    exception: new RuntimeException('El destinatario de recordatorios no esta configurado.'),
                );

                return false;
            }

            return $this->sendNotification(
                order: $order,
                channel: $channel,
                recipient: $recipient,
                notificationKey: $notificationKey,
                message: $this->orderCreatedSummaryFor($order),
                messageType: $messageType,
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function sendForWindow(
        ReminderWindow $reminderWindow,
        CarbonInterface $now,
        string $recipient,
        NotificationChannelEnum $channel,
    ): int {
        $sentCount = 0;
        $messageType = NotificationMessageType::Reminder;

        foreach ($this->ordersForWindow($reminderWindow, $now) as $order) {
            $notificationKey = $this->notificationKey($order, $channel, $messageType, $reminderWindow);

            if ($this->sendNotification(
                order: $order,
                channel: $channel,
                recipient: $recipient,
                notificationKey: $notificationKey,
                message: $this->messageFor($order, $reminderWindow),
                messageType: $messageType,
                reminderWindow: $reminderWindow,
            )) {
                $sentCount++;
            }
        }

        return $sentCount;
    }

    private function retryFailedNotifications(
        NotificationChannelEnum $channel,
        string $recipient,
        CarbonInterface $now,
        bool $forceRetry,
    ): int {
        $sentCount = 0;

        $failedNotifications = ActivityLog::query()
            ->where('event_type', ActivityEventType::NotificationFailed->value)
            ->where('notification_channel', $channel->value)
            ->whereNotNull('notification_key')
            ->whereNotExists(function (QueryBuilder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('activity_logs as confirmations')
                    ->whereColumn('confirmations.order_id', 'activity_logs.order_id')
                    ->whereColumn('confirmations.notification_key', 'activity_logs.notification_key')
                    ->where('confirmations.event_type', ActivityEventType::NotificationSent->value);
            })
            ->with(['order.customer', 'order.cakeCategory', 'order.payments'])
            ->get();

        foreach ($failedNotifications as $failure) {
            $order = $failure->order;

            if ($order === null) {
                continue;
            }

            $messageType = NotificationMessageType::tryFrom((string) data_get($failure->details, 'notification_type'));

            if ($messageType === null) {
                continue;
            }

            $reminderWindow = $messageType === NotificationMessageType::Reminder
                ? $failure->reminder_window
                : null;

            if ($messageType === NotificationMessageType::Reminder) {
                if ($reminderWindow === null || ! $this->isReminderWindowActive($order, $reminderWindow, $now)) {
                    continue;
                }
            }

            $message = $messageType === NotificationMessageType::Reminder
                ? $this->messageFor($order, $reminderWindow)
                : $this->orderCreatedSummaryFor($order);

            if ($this->sendNotification(
                order: $order,
                channel: $channel,
                recipient: $recipient,
                notificationKey: (string) $failure->notification_key,
                message: $message,
                messageType: $messageType,
                reminderWindow: $reminderWindow,
                forceRetry: $forceRetry,
            )) {
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

        $query->where('delivery_at', '>=', $from)->where('delivery_at', '<', $to);

        return $query->orderBy('delivery_at')->orderBy('id')->get();
    }

    private function sendNotification(
        Order $order,
        NotificationChannelEnum $channel,
        string $recipient,
        string $notificationKey,
        string $message,
        NotificationMessageType $messageType,
        ?ReminderWindow $reminderWindow = null,
        bool $forceRetry = false,
    ): bool {
        if (! $forceRetry && ! $this->shouldAttemptNotification($order, $notificationKey)) {
            return false;
        }

        $lock = Cache::lock('order-reminder:'.$notificationKey, 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            if (ActivityLog::query()
                ->where('event_type', ActivityEventType::NotificationSent->value)
                ->where('notification_key', $notificationKey)
                ->exists()) {
                return false;
            }

            try {
                $result = $this->notificationChannel->send(
                    recipient: $recipient,
                    message: $message,
                    notificationKey: $notificationKey,
                );
            } catch (Throwable $exception) {
                $this->recordFailure(
                    order: $order,
                    channel: $channel,
                    notificationKey: $notificationKey,
                    messageType: $messageType,
                    exception: $exception,
                    reminderWindow: $reminderWindow,
                );

                return false;
            }

            $this->recordSuccess(
                order: $order,
                channel: $channel,
                notificationKey: $notificationKey,
                messageType: $messageType,
                result: $result,
                reminderWindow: $reminderWindow,
            );

            return true;
        } finally {
            $lock->release();
        }
    }

    private function recordSuccess(
        Order $order,
        NotificationChannelEnum $channel,
        string $notificationKey,
        NotificationMessageType $messageType,
        NotificationResult $result,
        ?ReminderWindow $reminderWindow = null,
    ): void {
        $order->activityLogs()->create([
            'event_type' => ActivityEventType::NotificationSent,
            'notification_channel' => $channel,
            'reminder_window' => $reminderWindow,
            'notification_key' => $notificationKey,
            'provider_message_id' => $result->providerMessageId,
            'details' => [
                'notification_type' => $messageType->value,
                'delivery_at' => $order->delivery_at->toIso8601String(),
            ],
            'created_at' => now(),
        ]);
    }

    private function recordFailure(
        Order $order,
        NotificationChannelEnum $channel,
        string $notificationKey,
        NotificationMessageType $messageType,
        Throwable $exception,
        ?ReminderWindow $reminderWindow = null,
    ): void {
        $now = now();
        $lastFailure = ActivityLog::query()
            ->where('order_id', $order->getKey())
            ->where('event_type', ActivityEventType::NotificationFailed->value)
            ->where('notification_key', $notificationKey)
            ->latest('created_at')
            ->first();

        $nextAttemptAt = $this->nextAttemptAt($exception);
        $details = [
            'notification_key' => $notificationKey,
            'notification_type' => $messageType->value,
            'failure_type' => $exception instanceof ConnectionException
                ? 'connection_error'
                : ($exception instanceof NotificationDeliveryException ? 'provider_rejected' : 'unexpected_error'),
            'provider_status' => $exception instanceof NotificationDeliveryException
                ? $exception->providerStatus
                : null,
            'retry_after' => $exception instanceof NotificationDeliveryException
                ? $exception->retryAfter
                : null,
            'automatic_retry' => $nextAttemptAt !== null,
            'attempts' => ((int) data_get($lastFailure?->details, 'attempts', 0)) + 1,
            'next_attempt_at' => $nextAttemptAt?->toIso8601String(),
        ];

        $failureData = [
            'notification_channel' => $channel,
            'reminder_window' => $reminderWindow,
            'notification_key' => $notificationKey,
            'details' => array_filter($details, static fn (mixed $value): bool => $value !== null),
            'created_at' => $now,
        ];

        if ($lastFailure !== null) {
            $lastFailure->update($failureData);

            return;
        }

        $order->activityLogs()->create([
            'event_type' => ActivityEventType::NotificationFailed,
            ...$failureData,
        ]);
    }

    private function configuredChannel(): NotificationChannelEnum
    {
        $channel = NotificationChannelEnum::tryFrom((string) config('services.notifications.channel'));

        if ($channel === null) {
            throw new RuntimeException('El canal de recordatorios no es valido.');
        }

        return $channel;
    }

    private function recipientFor(NotificationChannelEnum $channel): string
    {
        return trim((string) match ($channel) {
            NotificationChannelEnum::Telegram => config('services.notifications.telegram.chat_id'),
            NotificationChannelEnum::Whatsapp => config('services.notifications.whatsapp.recipient'),
        });
    }

    private function notificationKey(
        Order $order,
        NotificationChannelEnum $channel,
        NotificationMessageType $messageType,
        ?ReminderWindow $reminderWindow = null,
    ): string {
        $suffix = match ($messageType) {
            NotificationMessageType::Reminder => $reminderWindow?->value,
            NotificationMessageType::OrderCreatedSummary => $messageType->value,
        };

        if ($suffix === null) {
            throw new RuntimeException('La ventana del recordatorio no esta configurada.');
        }

        return $channel->value.':'.$order->getKey().':'.$suffix;
    }

    private function shouldAttemptNotification(Order $order, string $notificationKey): bool
    {
        $failure = ActivityLog::query()
            ->where('order_id', $order->getKey())
            ->where('event_type', ActivityEventType::NotificationFailed->value)
            ->where('notification_key', $notificationKey)
            ->latest('created_at')
            ->first();

        if ($failure === null) {
            return true;
        }

        if (! (bool) data_get($failure->details, 'automatic_retry', false)) {
            return false;
        }

        $nextAttemptAt = data_get($failure->details, 'next_attempt_at');

        if (blank($nextAttemptAt)) {
            return (bool) data_get($failure->details, 'automatic_retry', false);
        }

        try {
            return now()->greaterThanOrEqualTo(Carbon::parse((string) $nextAttemptAt));
        } catch (Throwable) {
            return true;
        }
    }

    private function nextAttemptAt(Throwable $exception): ?CarbonInterface
    {
        if (! $exception instanceof NotificationDeliveryException || $exception->providerStatus !== 429) {
            return null;
        }

        return now()->addSeconds(max(
            self::RATE_LIMIT_RETRY_DELAY_MINIMUM_SECONDS,
            $exception->retryAfter ?? 0,
        ));
    }

    private function isReminderWindowActive(
        Order $order,
        ReminderWindow $reminderWindow,
        CarbonInterface $now,
    ): bool {
        if ($order->status !== OrderStatus::Pending) {
            return false;
        }

        $from = $reminderWindow === ReminderWindow::Hours24
            ? $now->copy()
            : $now->copy()->addHours(24);
        $to = $now->copy()->addHours($reminderWindow === ReminderWindow::Hours24 ? 24 : 48);

        return $order->delivery_at->greaterThanOrEqualTo($from)
            && $order->delivery_at->lessThan($to);
    }

    private function messageFor(Order $order, ReminderWindow $reminderWindow): string
    {
        return implode(PHP_EOL, [
            self::MESSAGE_SEPARATOR,
            'RECORDATORIO DE PREPARACIÓN - '.$this->windowLabel($reminderWindow),
            self::MESSAGE_SEPARATOR,
            '- Pedido: '.$order->order_number,
            '- Cliente: '.$order->customer->full_name,
            '- Pastel: '.$this->cakeLabel($order),
            '- Entrega: '.$this->deliveryLabel($order),
            self::MESSAGE_SEPARATOR,
            '- Saldo pendiente: Q '.$this->pendingBalance($order),
            self::MESSAGE_SEPARATOR,
        ]);
    }

    private function orderCreatedSummaryFor(Order $order): string
    {
        $registeredPayments = $this->registeredPaymentsTotal($order)->toScale(2)->toString();

        return implode(PHP_EOL, [
            self::MESSAGE_SEPARATOR,
            'NUEVO PEDIDO REGISTRADO',
            self::MESSAGE_SEPARATOR,
            '- Pedido: '.$order->order_number,
            '- Cliente: '.$order->customer->full_name,
            '- Teléfono: '.$order->customer->phone,
            '- Pastel: '.$this->cakeLabel($order),
            '- Entrega: '.$this->deliveryLabel($order),
            self::MESSAGE_SEPARATOR,
            '- Precio pactado: Q '.(string) $order->agreed_price,
            '- Pagado: Q '.$registeredPayments,
            '- Saldo pendiente: Q '.$this->pendingBalance($order),
            '- Estado: Pendiente',
            self::MESSAGE_SEPARATOR,
        ]);
    }

    private function deliveryLabel(Order $order): string
    {
        return $order->delivery_at
            ->timezone((string) config('app.timezone'))
            ->format('d/m/Y H:i');
    }

    private function windowLabel(ReminderWindow $reminderWindow): string
    {
        return $reminderWindow === ReminderWindow::Hours24 ? '24 horas' : '48 horas';
    }

    private function cakeLabel(Order $order): string
    {
        return $order->capture_mode === CaptureMode::Custom
            ? (string) $order->cake_description
            : (string) ($order->cakeCategory?->name ?? 'Pastel no especificado');
    }

    private function pendingBalance(Order $order): string
    {
        $registeredTotal = $this->registeredPaymentsTotal($order);
        $balance = BigDecimal::of((string) $order->agreed_price)->minus($registeredTotal);

        return ($balance->isNegative() ? BigDecimal::of('0') : $balance)
            ->toScale(2)
            ->toString();
    }

    private function registeredPaymentsTotal(Order $order): BigDecimal
    {
        $registeredPaymentsTotal = $order->getAttribute('registered_payments_total');

        if ($registeredPaymentsTotal !== null) {
            return BigDecimal::of((string) $registeredPaymentsTotal);
        }

        if ($order->relationLoaded('payments')) {
            return $order->payments->reduce(
                function (BigDecimal $total, Payment $payment): BigDecimal {
                    return $payment->status === PaymentStatus::Registered
                        ? $total->plus((string) $payment->amount)
                        : $total;
                },
                BigDecimal::of('0'),
            );
        }

        return BigDecimal::of((string) Payment::query()
            ->where('order_id', $order->getKey())
            ->where('status', PaymentStatus::Registered->value)
            ->sum('amount'));
    }
}
