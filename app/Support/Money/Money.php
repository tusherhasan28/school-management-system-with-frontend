<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Exact money arithmetic in integer paisa (1 taka = 100 paisa).
 * DECIMAL(12,2) strings from the database go in, formatted strings come out;
 * floats are never involved.
 */
final class Money
{
    public static function parse(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $value = str_replace([',', ' '], '', trim($value));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not an amount with up to two decimals: {$value}");
        }

        $paisa = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$paisa : $paisa;
    }

    public static function format(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** $percent (e.g. "12.5") of $paisa, rounded half up to the paisa. */
    public static function percentOf(int $paisa, string $percent): int
    {
        $p = self::parse($percent); // hundredths of a percent

        return intdiv(2 * $paisa * $p + 10000, 20000);
    }

    /** Sum of DECIMAL strings. */
    public static function sum(iterable $values): int
    {
        $total = 0;

        foreach ($values as $v) {
            $total += self::parse($v === null ? null : (string) $v);
        }

        return $total;
    }
}
