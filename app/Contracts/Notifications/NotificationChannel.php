<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\NotificationResult;

interface NotificationChannel
{
    public function send(string $recipient, string $message, string $notificationKey): NotificationResult;
}
