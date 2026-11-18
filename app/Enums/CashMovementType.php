<?php

declare(strict_types=1);

namespace App\Enums;

enum CashMovementType: string
{
    case PaidIn = 'paid_in';
    case PaidOut = 'paid_out';
}
