<?php

declare(strict_types=1);

namespace App\Enums;

enum StockAdjustmentType: string
{
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Expired = 'expired';
    case Correction = 'correction';
    case Opname = 'opname';

    /** Shrinkage types always remove stock; the submitted quantity is a positive amount to deduct. */
    public function isShrinkage(): bool
    {
        return in_array($this, [self::Damaged, self::Lost, self::Expired], true);
    }

    /** @return list<string> */
    public static function manualValues(): array
    {
        return [self::Damaged->value, self::Lost->value, self::Expired->value, self::Correction->value];
    }
}
