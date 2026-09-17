<?php

namespace App\Services\Notifications;

use RuntimeException;

final class NotificationDeliveryException extends RuntimeException
{
    public function __construct(public readonly int $providerStatus)
    {
        parent::__construct('El proveedor rechazo el recordatorio.');
    }
}
