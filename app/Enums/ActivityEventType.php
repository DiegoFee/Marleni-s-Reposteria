<?php

namespace App\Enums;

enum ActivityEventType: string
{
    case OrderCreated = 'order_created';
    case OrderUpdated = 'order_updated';
    case OrderStatusChanged = 'order_status_changed';
    case PaymentRegistered = 'payment_registered';
    case PaymentVoided = 'payment_voided';
    case NotificationSent = 'notification_sent';
    case NotificationFailed = 'notification_failed';
}
