<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decimal quantity helpers (DECIMAL(15,3)) backed by bcmath.
 */
final class Quantity
{
    public const SCALE = 3;

    public static function normalize(string|int|float $value): string
    {
        $string = is_float($value) ? number_format($value, self::SCALE, '.', '') : trim((string) $value);

        return bcadd($string, '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        $normB = self::normalize($b);
        if (self::isZero($normB)) {
            return '0.000';
        }

        return bcdiv(self::normalize($a), $normB, self::SCALE);
    }

    public static function negate(string $a): string
    {
        return bcmul(self::normalize($a), '-1', self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return bccomp(self::normalize($a), '0', self::SCALE) === 0;
    }

    public static function isNegative(string $a): bool
    {
        return bccomp(self::normalize($a), '0', self::SCALE) < 0;
    }

    public static function maxZero(string $a): string
    {
        return self::isNegative($a) ? '0.000' : self::normalize($a);
    }
}
