<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Deterministic money helpers using BCMath (scale 2 for currency amounts).
 *
 * Rounding: half-up via bcmath string arithmetic at the given scale.
 * Rates (tax/discount %) use scale 4 intermediate, then round to scale 2 for amounts.
 */
final class Money
{
    public const SCALE = 2;

    public const RATE_SCALE = 4;

    public static function normalize(string|int|float $value, int $scale = self::SCALE): string
    {
        if (is_float($value)) {
            // Avoid binary float artifacts by formatting first.
            $value = number_format($value, $scale + 4, '.', '');
        }

        $normalized = bcadd((string) $value, '0', $scale);

        return $normalized;
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcmul(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        if (bccomp(self::normalize($b, $scale), '0', $scale) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function compare(string $a, string $b, int $scale = self::SCALE): int
    {
        return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function max(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::compare($a, $b, $scale) >= 0
            ? self::normalize($a, $scale)
            : self::normalize($b, $scale);
    }

    public static function min(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::compare($a, $b, $scale) <= 0
            ? self::normalize($a, $scale)
            : self::normalize($b, $scale);
    }

    public static function isNegative(string $amount, int $scale = self::SCALE): bool
    {
        return self::compare($amount, '0', $scale) < 0;
    }

    /**
     * Percentage of an amount: amount × (rate / 100), rounded to money scale.
     */
    public static function percentOf(string $amount, string $ratePercent): string
    {
        $amount = self::normalize($amount, self::SCALE);
        $rate = self::normalize($ratePercent, self::RATE_SCALE);
        $factor = bcdiv($rate, '100', 8);

        return bcmul($amount, $factor, self::SCALE);
    }
}
