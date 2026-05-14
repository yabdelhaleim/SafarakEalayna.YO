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
}
