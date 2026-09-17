<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
