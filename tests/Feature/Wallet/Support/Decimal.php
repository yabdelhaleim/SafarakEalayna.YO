<?php

namespace Tests\Feature\Wallet\Support;

/**
 * Exact decimal arithmetic for the audit. Never use binary float for expected
 * values. All amounts round to 2 decimal places at IO boundary.
 *
 * Per the application's database schema:
 *   - accounts.balance        = decimal(15, 2)
 *   - account_entries.debit   = decimal(15, 2)
 *   - account_entries.credit  = decimal(15, 2)
 *   - transactions.amount     = decimal(15, 2)
 *   - transfers.amount        = decimal(15, 2)
 *   - transfers.exchange_rate = decimal(10, 6)
 *
 * Treat any difference of >= 0.01 as a real financial finding unless an
 * explanatory rule exists.
 */
final class Decimal
{
    /** Half-away-from-zero rounding to N decimals using bcmath (PHP_INT_MAX-safe). */
    public static function round(string|int|float $value, int $scale = 2): string
    {
        $value = (string) $value;
        if ($value === '' || $value === null) {
            return $scale > 0 ? '0.'.str_repeat('0', $scale) : '0';
        }
        $negative = str_starts_with($value, '-');
        $abs = $negative ? substr($value, 1) : $value;
        // Construct a half-unit "0.000...5" with `scale` digits after the point.
        // For scale=2 the offset is 0.005; for scale=4 it is 0.00005; etc.
        $offset = $scale > 0
            ? '0.'.str_repeat('0', $scale).'5'
            : '0.5';
        $rounded = bcadd($abs, $offset, $scale);

        return $negative && $rounded !== '0' && (! str_contains($rounded, '-'))
            ? '-'.$rounded
            : $rounded;
    }

    /** Add; returns a string at the chosen scale. */
    public static function add(string|int|float ...$values): string
    {
        $acc = '0';
        foreach ($values as $v) {
            $acc = bcadd($acc, (string) $v, 4);
        }

        return self::round($acc);
    }

    /** Subtract: a - b. */
    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return self::round(bcsub((string) $a, (string) $b, 4));
    }

    /** Multiply. */
    public static function mul(string|int|float $a, string|int|float $b, int $scale = 4): string
    {
        return self::round(bcmul((string) $a, (string) $b, $scale));
    }

    /** Compare: returns -1 / 0 / 1. */
    public static function cmp(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::round((string) $a), self::round((string) $b), 4);
    }

    /** Equality with strict scale=2 equality. */
    public static function equals(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) === 0;
    }

    /** 0.01 == 1 cent. Use ONLY for human-readable error messages, not comparisons. */
    public static function diff(string|int|float $a, string|int|float $b): string
    {
        return self::sub((string) $a, (string) $b);
    }

    /**
     * Apply a fee schedule. Given base amount and a percentage (e.g. 2.5 means
     * 2.5%), compute fee = round(base * pct / 100, 2). Uses bcmath.
     */
    public static function feeOnAmount(string|int|float $amount, string|int|float $percent): string
    {
        $pctMul100 = self::mul((string) $amount, (string) $percent, 6);

        return self::round(bcdiv($pctMul100, '100', 6));
    }
}
