<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WhatsAppNotificationChannel implements NotificationChannel
{
    public function send(string $recipient, string $message, string $notificationKey): NotificationResult
    {
        $accessToken = (string) config('services.notifications.whatsapp.access_token');
        $phoneNumberId = (string) config('services.notifications.whatsapp.phone_number_id');

        if ($accessToken === '' || $phoneNumberId === '') {
            throw new RuntimeException('El canal de WhatsApp no esta configurado.');
        }

        $response = $this->client()
            ->withToken($accessToken)
            ->post($phoneNumberId.'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

        if (! $response->successful()) {
            throw new NotificationDeliveryException($response->status());
        }

        $providerMessageId = data_get($response->json(), 'messages.0.id');

        if ($providerMessageId === null) {
            throw new RuntimeException('WhatsApp devolvio una respuesta sin identificador de mensaje.');
        }

        return new NotificationResult((string) $providerMessageId);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.notifications.whatsapp.api_url'), '/'))
            ->connectTimeout((int) config('services.notifications.whatsapp.connect_timeout'))
            ->timeout((int) config('services.notifications.whatsapp.timeout'));
    }
}
