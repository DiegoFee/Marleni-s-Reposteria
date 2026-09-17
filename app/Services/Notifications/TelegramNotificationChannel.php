<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramNotificationChannel implements NotificationChannel
{
    public function send(string $recipient, string $message, string $notificationKey): NotificationResult
    {
        $token = (string) config('services.notifications.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('El canal de Telegram no esta configurado.');
        }

        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => $notificationKey])
            ->post('bot'.$token.'/sendMessage', [
                'chat_id' => $recipient,
                'text' => $message,
            ]);

        if (! $response->successful()) {
            throw new NotificationDeliveryException($response->status());
        }

        $providerMessageId = data_get($response->json(), 'result.message_id');

        return new NotificationResult($providerMessageId === null ? null : (string) $providerMessageId);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.notifications.telegram.api_url'), '/'))
            ->connectTimeout((int) config('services.notifications.connect_timeout'))
            ->timeout((int) config('services.notifications.timeout'));
    }
}
