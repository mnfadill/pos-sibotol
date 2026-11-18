<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic using integer minor units (1/100 of the currency unit).
 *
 * Never use floats for money. Database DECIMAL(15,2) values arrive as strings and are
 * converted with bcmath; all rounding is HALF-UP (commercial rounding).
 */
final class Money
{
    public static function toMinor(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $value = is_float($amount) ? number_format($amount, 6, '.', '') : trim((string) $amount);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid monetary amount [{$value}].");
        }

        return self::roundHalfUp(bcmul($value, '100', 6));
    }

    public static function toDecimal(int $minor): string
    {
        $negative = $minor < 0;
        $abs = abs($minor);
        $formatted = intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$formatted : $formatted;
    }

    /** Multiply a minor-unit amount by a decimal quantity (e.g. "1.500"). */
    public static function multiply(int $minor, string $quantity): int
    {
        return self::roundHalfUp(bcmul((string) $minor, $quantity, 6));
    }

    /** Percentage of a minor-unit amount, e.g. percentOf(10000, "11.00") = 1100. */
    public static function percentOf(int $minor, string $percent): int
    {
        return self::roundHalfUp(bcdiv(bcmul((string) $minor, $percent, 6), '100', 6));
    }

    private static function roundHalfUp(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $rounded = bcadd(ltrim($value, '-'), '0.5', 0); // scale 0 truncates => half-up on the absolute value

        return $negative ? -(int) $rounded : (int) $rounded;
    }
}
