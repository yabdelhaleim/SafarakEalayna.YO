<?php

namespace App\Support\Finance;

/**
 * يسمح بتعديل accounts.balance فقط ضمن عمق مسموح (حركات الدفتر المعتمدة).
 */
final class LedgerBalanceMutationGuard
{
    private static int $depth = 0;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        ++self::$depth;
        try {
            return $callback();
        } finally {
            --self::$depth;
        }
    }

    public static function isAllowed(): bool
    {
        return self::$depth > 0;
    }
}
