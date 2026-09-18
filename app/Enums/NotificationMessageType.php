<?php

namespace App\Enums;

enum NotificationMessageType: string
{
    case Reminder = 'reminder';
    case OrderCreatedSummary = 'order_created_summary';
}
