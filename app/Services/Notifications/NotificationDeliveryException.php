<?php

namespace App\Services\Notifications;

use RuntimeException;

final class NotificationDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly int $providerStatus,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct('El proveedor rechazo la notificacion.');
    }
}
