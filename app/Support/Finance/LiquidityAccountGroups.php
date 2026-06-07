<?php

namespace App\Support\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * Groups liquidity Account models for Vue treasury screens.
 * Collection::where('type', 'wallet') fails when type is cast to AccountType enum.
 */
final class LiquidityAccountGroups
{
    public static function typeValue(Account|array $account): string
    {
        if ($account instanceof Account) {
            $type = $account->type;

            return $type instanceof AccountType ? $type->value : (string) $type;
        }

        $type = $account['type'] ?? '';

        return $type instanceof AccountType ? $type->value : (string) $type;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array{wallets: Collection, banks: Collection, cashboxes: Collection, treasuries: Collection, post: Collection}
     */
    public static function group(Collection $accounts): array
    {
        $grouped = $accounts->groupBy(fn (Account $account): string => self::typeValue($account));

        return [
            'wallets' => $grouped->get(AccountType::Wallet->value, collect())->values(),
            'banks' => $grouped->get(AccountType::Bank->value, collect())->values(),
            'cashboxes' => $grouped->get(AccountType::Cashbox->value, collect())->values(),
            'treasuries' => $grouped->get(AccountType::Treasury->value, collect())->values(),
            'post' => $grouped->get(AccountType::Post->value, collect())->values(),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array{count: int, balance: float}
     */
    public static function countAndBalance(Collection $accounts, AccountType ...$types): array
    {
        $values = array_map(fn (AccountType $type): string => $type->value, $types);
        $filtered = $accounts->filter(
            fn (Account $account): bool => in_array(self::typeValue($account), $values, true)
        );

        return [
            'count' => $filtered->count(),
            'balance' => (float) $filtered->sum('balance'),
        ];
    }
}
