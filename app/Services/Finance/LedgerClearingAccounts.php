<?php

namespace App\Services\Finance;

use App\Enums\TransactionModule;
use App\Models\Account;

/**
 * Resolves contra (clearing / suspense) GL accounts used for strictly balanced postings.
 *
 * Accounts are identified by immutable Arabic labels seeded in migrations.
 */
class LedgerClearingAccounts
{
    public function incomeContraIdForModule(string|TransactionModule|null $module): ?int
    {
        $key = $this->normalizeModuleKey($module);
        $name = config("accounting.clearing.income.{$key}")
            ?? config('accounting.clearing.income.general');

        return $this->accountIdByName($name);
    }

    public function incomeContraIdForFlightBooking(): ?int
    {
        $fromFlightConfig = config('flight_accounting.ledger_clearing_account_name');
        if (is_string($fromFlightConfig) && $fromFlightConfig !== '') {
            $id = $this->accountIdByName($fromFlightConfig);
            if ($id !== null) {
                return $id;
            }
        }

        return $this->incomeContraIdForModule(TransactionModule::Flight);
    }

    public function expenseContraIdForModule(string|TransactionModule|null $module): ?int
    {
        $key = $this->normalizeModuleKey($module);
        $name = config("accounting.clearing.expense.{$key}")
            ?? config('accounting.clearing.expense.general');

        return $this->accountIdByName($name);
    }

    public function treasuryOperationsContraAccountId(): int
    {
        $name = config('accounting.clearing.treasury_operations');
        $id = $this->accountIdByName($name);
        if ($id === null) {
            throw new \RuntimeException(
                'حساب ضبط حركات الخزينة غير مُعرّف. شغّل migrations البذور أو عدّل config/accounting.php — الاسم المتوقع: '
                .(is_string($name) ? $name : '')
            );
        }

        return $id;
    }

    protected function normalizeModuleKey(string|TransactionModule|null $module): string
    {
        if ($module instanceof TransactionModule) {
            return $module === TransactionModule::HajjUmra ? 'hajj_umra' : $module->value;
        }

        $v = strtolower((string) $module);

        return match ($v) {
            '', 'general' => 'general',
            default => $v,
        };
    }

    protected function accountIdByName(?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        return Account::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->value('id');
    }

    /**
     * Resolve all income/expense clearing account IDs in a single query (no response caching).
     *
     * @return array{income: array<int, string>, expense: array<int, string>}
     */
    public function moduleAccountMaps(): array
    {
        $incomeNames = config('accounting.clearing.income', []);
        $expenseNames = config('accounting.clearing.expense', []);

        $nameToModule = [];
        foreach ($incomeNames as $module => $name) {
            if (is_string($name) && $name !== '') {
                $nameToModule[$name] = ['kind' => 'income', 'module' => $this->normalizeModuleKey($module)];
            }
        }
        foreach ($expenseNames as $module => $name) {
            if (is_string($name) && $name !== '') {
                $nameToModule[$name] = ['kind' => 'expense', 'module' => $this->normalizeModuleKey($module)];
            }
        }

        $flightName = config('flight_accounting.ledger_clearing_account_name');
        if (is_string($flightName) && $flightName !== '') {
            $nameToModule[$flightName] = ['kind' => 'income', 'module' => TransactionModule::Flight->value];
        }

        if ($nameToModule === []) {
            return ['income' => [], 'expense' => []];
        }

        $accounts = Account::query()
            ->whereIn('name', array_keys($nameToModule))
            ->where('is_active', true)
            ->get(['id', 'name']);

        $income = [];
        $expense = [];
        foreach ($accounts as $account) {
            $meta = $nameToModule[$account->name] ?? null;
            if ($meta === null) {
                continue;
            }
            if ($meta['kind'] === 'income') {
                $income[(int) $account->id] = $meta['module'];
            } else {
                $expense[(int) $account->id] = $meta['module'];
            }
        }

        return ['income' => $income, 'expense' => $expense];
    }
}
