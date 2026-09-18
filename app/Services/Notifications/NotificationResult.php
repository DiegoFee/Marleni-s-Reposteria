<?php

namespace App\Services\Notifications;

final readonly class NotificationResult
{
    public function __construct(public string $providerMessageId) {}
}
