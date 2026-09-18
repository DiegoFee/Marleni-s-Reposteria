<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramReminderChannel implements NotificationChannel
{
    public function send(string $recipient, string $message, string $notificationKey): NotificationResult
    {
        $token = (string) config('services.notifications.telegram.bot_token');

        if (trim($token) === '' || trim($recipient) === '') {
            throw new RuntimeException('El canal de Telegram no esta configurado.');
        }

        $response = $this->client()->post('bot'.$token.'/sendMessage', [
            'chat_id' => $recipient,
            'text' => $message,
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new NotificationDeliveryException(
                providerStatus: $response->status(),
                retryAfter: $response->status() === 429
                    ? (int) $response->json('parameters.retry_after', 0)
                    : null,
            );
        }

        $providerMessageId = data_get($response->json(), 'result.message_id');

        if ($providerMessageId === null) {
            throw new RuntimeException('Telegram devolvio una respuesta sin identificador de mensaje.');
        }

        return new NotificationResult((string) $providerMessageId);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.notifications.telegram.api_url'), '/'))
            ->connectTimeout((int) config('services.notifications.telegram.connect_timeout'))
            ->timeout((int) config('services.notifications.telegram.timeout'));
    }
}
