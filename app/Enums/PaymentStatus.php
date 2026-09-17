<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Registered = 'registered';
    case Voided = 'voided';
}
