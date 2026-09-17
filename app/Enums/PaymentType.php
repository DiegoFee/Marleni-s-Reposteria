<?php

namespace App\Enums;

enum PaymentType: string
{
    case Deposit = 'deposit';
    case Partial = 'partial';
    case Settlement = 'settlement';
}
