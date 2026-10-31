<?php

namespace App\Modules\Exams\Grading;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for marks, percentages and grade points.
 *
 * Values are held as integers in hundredths ("12.50" => 1250), so every
 * result is reproducible on any server (no floats, no bcmath dependency).
 */
final class Hundredths
{
    public static function parse(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $value = trim($value);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not a decimal with up to two places: {$value}");
        }

        $result = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$result : $result;
    }

    public static function format(int $hundredths): string
    {
        $sign = $hundredths < 0 ? '-' : '';
        $abs = abs($hundredths);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Percentage of $part in $whole, in hundredths of a percent, rounded down. */
    public static function percentage(int $part, int $whole): int
    {
        if ($whole <= 0 || $part <= 0) {
            return 0;
        }

        return intdiv($part * 10000, $whole);
    }

    /**
     * $numerator / $denominator for a value already in hundredths, kept to
     * $decimals places (0–2) using the given rounding mode.
     */
    public static function divide(int $numerator, int $denominator, int $decimals, string $mode): int
    {
        if ($denominator <= 0) {
            return 0;
        }

        $factor = 10 ** (2 - max(0, min(2, $decimals)));
        $den = $denominator * $factor;

        return match ($mode) {
            'half_up' => intdiv(2 * $numerator + $den, 2 * $den) * $factor,
            default => intdiv($numerator, $den) * $factor, // truncate
        };
    }

    /** Round a mark (hundredths) to a whole mark. */
    public static function roundMark(int $value, string $mode): int
    {
        return match ($mode) {
            'half_up' => intdiv($value + 50, 100) * 100,
            'ceil' => intdiv($value + 99, 100) * 100,
            default => $value,
        };
    }
}
