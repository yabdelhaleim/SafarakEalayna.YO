<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        if ($name === null || $name === '') {
            return null;
        }

        return $this->ensureClearingAccountExists($name, $key, 'income');
    }

    public function incomeContraIdForFlightBooking(): ?int
    {
        $fromFlightConfig = config('flight_accounting.ledger_clearing_account_name');
        if (is_string($fromFlightConfig) && $fromFlightConfig !== '') {
            $id = $this->ensureClearingAccountExists($fromFlightConfig, 'flight', 'income');
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

        if ($name === null || $name === '') {
            return null;
        }

        return $this->ensureClearingAccountExists($name, $key, 'expense');
    }

    /**
     * @param  'flight_system'|'flight_carrier'|'fawry'  $key
     */
    public function prepaidAccountId(string $key): int
    {
        $name = config("accounting.clearing.prepaid.{$key}");
        if (! is_string($name) || $name === '') {
            throw new \RuntimeException(
                'حساب الرصيد المسبق غير مُعرّف في config/accounting.php للمفتاح «'.$key.'».'
            );
        }

        return $this->ensurePrepaidAccountExists($name, $key);
    }

    /**
     * @return array<int, string> account_id => prepaid key
     */
    public function prepaidAccountIdMap(): array
    {
        $prepaidNames = config('accounting.clearing.prepaid', []);
        if (! is_array($prepaidNames) || $prepaidNames === []) {
            return [];
        }

        $nameToKey = [];
        foreach ($prepaidNames as $key => $name) {
            if (is_string($name) && $name !== '') {
                $nameToKey[$name] = (string) $key;
            }
        }

        if ($nameToKey === []) {
            return [];
        }

        $accounts = Account::query()
            ->whereIn('name', array_keys($nameToKey))
            ->where('is_active', true)
            ->get(['id', 'name']);

        $map = [];
        foreach ($accounts as $account) {
            $key = $nameToKey[$account->name] ?? null;
            if ($key !== null) {
                $map[(int) $account->id] = $key;
            }
        }

        foreach ($nameToKey as $name => $key) {
            if (! in_array($key, $map, true)) {
                $id = $this->prepaidAccountId($key);
                $map[$id] = $key;
            }
        }

        return $map;
    }

    public function isPrepaidAccountId(int $accountId): bool
    {
        return isset($this->prepaidAccountIdMap()[$accountId]);
    }

    public function treasuryOperationsContraAccountId(): int
    {
        $name = config('accounting.clearing.treasury_operations');
        if (! is_string($name) || $name === '') {
            throw new \RuntimeException(
                'حساب ضبط حركات الخزينة غير مُعرّف في config/accounting.php.'
            );
        }

        return $this->ensureClearingAccountExists($name, 'general', 'treasury_operations');
    }

    protected function normalizeModuleKey(string|TransactionModule|null $module): string
    {
        if ($module instanceof TransactionModule) {
            return $module === TransactionModule::HajjUmra ? 'hajj_umra' : $module->value;
        }

        $v = strtolower((string) $module);

        return match ($v) {            '', 'general' => 'general',            default => $v,        };    }    protected function accountIdByName(?string $name): ?int    {        if ($name === null || $name === '') {            return null;        }        return Account::query()            ->where('name', $name)            ->where('is_active', true)            ->value('id');    }    protected function ensureClearingAccountExists(string $name, string $moduleKey, string $type): int    {        $id = $this->accountIdByName($name);        if ($id !== null) {            return $id;        }        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($name, $moduleKey, $type) {            $account = Account::query()->firstOrCreate(                ['name' => $name],                [                    'type' => AccountType::Owner,  // owner = حساب داخلي لا يظهر في الخزائن                    'balance' => 0,                    'currency' => 'EGP',                    'is_active' => true,                    'owner_type' => Account::OWNER_TYPE_OWNER,                    'module_type' => AccountModuleDivision::resolveModuleTypeKey(null, $moduleKey),                    'is_module_vault' => false,                    'notes' => "حساب إقفال تلقائي للموديول: {$moduleKey} ({$type})",                    'created_by' => Auth::id() ?? 1,                ]            );            Log::info('Clearing account automatically created', [                'name' => $name,                'id' => $account->id,                'module' => $moduleKey,                'type' => $type,            ]);            return $account->id;
        }));
    }

    protected function ensurePrepaidAccountExists(string $name, string $key): int
    {
        $id = $this->accountIdByName($name);
        if ($id !== null) {
            return $id;
        }

        $moduleType = 'office';
        if (str_starts_with($key, 'flight')) {
            $moduleType = 'flights';
        } elseif ($key === 'fawry') {
            $moduleType = 'fawry';
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($name, $key, $moduleType) {
            $account = Account::query()->firstOrCreate(
                ['name' => $name],
                [
                    'type' => AccountType::Owner,  // owner = حساب داخلي لا يظهر في الخزائن
                    'balance' => 0,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => $moduleType,
                    'is_module_vault' => false,
                    'notes' => "حساب رصيد مسبق (أصل): {$key}",
                    'created_by' => Auth::id() ?? 1,
                ]
            );

            Log::info('Prepaid account automatically created', [
                'name' => $name,
                'id' => $account->id,
                'key' => $key,
            ]);

            return $account->id;
        }));
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
