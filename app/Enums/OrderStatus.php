<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Held = 'held';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Voided = 'voided';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return in_array($this, [self::Cancelled, self::Voided, self::Refunded], true);
    }

    public function isRefundable(): bool
    {
        return in_array($this, [self::Completed, self::PartiallyRefunded], true);
    }
}
