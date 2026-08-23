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
     * Per-currency expense contra resolver (Phase 7 / multi-currency visa).
     *
     * Resolution order:
     *   1. If `accounting.clearing.expense_per_currency.{module}.{currency}` is
     *      configured AND the account exists, return its id.
     *   2. Otherwise, fall back to the single-currency
     *      `expenseContraIdForModule($module)` (legacy behaviour).
     *
     * The account for the chosen currency is created lazily on first call —
     * the legacy EGP clearing account is reused as-is and is NOT duplicated.
     * For non-EGP currencies, a new account is provisioned automatically.
     */
    public function expenseContraIdForModuleAndCurrency(
        string|TransactionModule|null $module,
        ?string $currency
    ): ?int {
        $key = $this->normalizeModuleKey($module);
        $currency = $this->normalizeCurrency($currency);

        $perCurrency = config("accounting.clearing.expense_per_currency.{$key}.{$currency}");
        if (is_string($perCurrency) && $perCurrency !== '') {
            // Honour an explicit per-currency override. The account row may
            // already exist (e.g. seeded by ops); reuse it without forcing a
            // new lazy creation if a different legacy clearing exists for the
            // same currency.
            $existing = $this->accountIdByName($perCurrency);
            if ($existing !== null) {
                return $existing;
            }

            // If the per-currency name matches the legacy single-currency
            // clearing name, prefer the legacy lookup so we never duplicate
            // the historical EGP account.
            $legacyName = config("accounting.clearing.expense.{$key}");
            if (is_string($legacyName) && $legacyName === $perCurrency) {
                return $this->expenseContraIdForModule($module);
            }

            // Avoid duplicate provisioning: if the legacy clearing already
            // exists AND its denomination matches the requested currency,
            // reuse it. This protects ops data that pre-dates the
            // per-currency layer (e.g. the historical "إقفال تكاليف التأشيرات"
            // row is EGP-denominated and must remain the EGP bucket).
            if (is_string($legacyName) && $legacyName !== '' && $currency === 'EGP') {
                $legacyId = $this->accountIdByName($legacyName);
                if ($legacyId !== null) {
                    return $legacyId;
                }
            }

            return $this->ensureClearingAccountExists($perCurrency, $key, 'expense', $currency);
        }

        return $this->expenseContraIdForModule($module);
    }

    /**
     * Per-currency income contra resolver (Phase 7 / multi-currency visa).
     *
     * Mirror of {@see self::expenseContraIdForModuleAndCurrency()} for income.
     */
    public function incomeContraIdForModuleAndCurrency(
        string|TransactionModule|null $module,
        ?string $currency
    ): ?int {
        $key = $this->normalizeModuleKey($module);
        $currency = $this->normalizeCurrency($currency);

        $perCurrency = config("accounting.clearing.income_per_currency.{$key}.{$currency}");
        if (is_string($perCurrency) && $perCurrency !== '') {
            $existing = $this->accountIdByName($perCurrency);
            if ($existing !== null) {
                return $existing;
            }

            $legacyName = config("accounting.clearing.income.{$key}");
            if (is_string($legacyName) && $legacyName === $perCurrency) {
                return $this->incomeContraIdForModule($module);
            }

            // Avoid duplicate provisioning: if the legacy clearing already
            // exists AND its denomination matches the requested currency,
            // reuse it. This protects ops data that pre-dates the
            // per-currency layer (e.g. the historical "إقفال إيرادات التأشيرات"
            // row is EGP-denominated and must remain the EGP bucket).
            if (is_string($legacyName) && $legacyName !== '' && $currency === 'EGP') {
                $legacyId = $this->accountIdByName($legacyName);
                if ($legacyId !== null) {
                    return $legacyId;
                }
            }

            return $this->ensureClearingAccountExists($perCurrency, $key, 'income', $currency);
        }

        return $this->incomeContraIdForModule($module);
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

    /**
     * Returns the unified AR (Accounts Receivable) account used to track
     * مديونيات عملاء فوري غير مسجلين (walk-in Fawry clients).
     *
     * The account is created lazily on first call with:
     *  - type = Customer (subject AR mirror — visible in receivables report)
     *  - module_type = 'fawry' (specific module per AccountModuleContract
     *    Subject rule; divisions 'office'/'tourism' are RESERVED for
     *    liquidity vaults)
     *  - is_module_vault = false
     *  - owner_type = OWNER_TYPE_OWNER
     *
     * Per-client debt is sourced from `fawry_transactions` columns
     * (selling_price - amount) grouped by client_name. This single account
     * aggregates only the running balance across all walk-in transactions.
     *
     * Legacy walk-in transactions (created before this method existed)
     * did NOT post to this account — they credited the settlement account
     * directly. The debt for those is read from the columns instead of
     * the GL.
     */
    public function fawryWalkInArAccountId(): int
    {
        $name = 'ذمم عملاء فوري غير مسجلين';

        $existing = Account::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($name) {
            $account = Account::query()->firstOrCreate(
                ['name' => $name],
                [
                    'type' => AccountType::Customer,
                    'balance' => 0,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'fawry',
                    'is_module_vault' => false,
                    'notes' => 'حساب AR تلقائي لمعاملات فوري للعملاء غير المسجلين (walk-in). '
                        .'تُحفظ المديونية مقسمة على مستوى اسم العميل في fawry_transactions.',
                    'created_by' => Auth::id() ?? 1,
                ]
            );

            Log::info('Fawry walk-in AR account automatically created', [
                'name' => $account->name,
                'id' => $account->id,
                'module_type' => $account->module_type,
            ]);

            return (int) $account->id;
        }));
    }

    /**
     * Returns the unified AR (Accounts Receivable) account used to track
     * مديونيات عملاء الخدمات الإلكترونية غير مسجلين (walk-in Online clients).
     *
     * Mirrors `fawryWalkInArAccountId()` — the account is created lazily on
     * first call with:
     *  - type = Customer (subject AR mirror — visible in receivables report)
     *  - module_type = 'online' (specific module per AccountModuleContract
     *    Subject rule; divisions 'office'/'tourism' are RESERVED for
     *    liquidity vaults)
     *  - is_module_vault = false
     *  - owner_type = OWNER_TYPE_OWNER
     */
    public function onlineWalkInArAccountId(): int
    {
        $name = 'ذمم عملاء الخدمات الإلكترونية غير مسجلين';

        $existing = Account::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($name) {
            $account = Account::query()->firstOrCreate(
                ['name' => $name],
                [
                    'type' => AccountType::Customer,
                    'balance' => 0,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'online',
                    'is_module_vault' => false,
                    'notes' => 'حساب AR تلقائي لمعاملات الخدمات الإلكترونية للعملاء غير المسجلين (walk-in). '
                        .'تُحفظ المديونية مقسمة على مستوى اسم العميل في online_transactions.',
                    'created_by' => Auth::id() ?? 1,
                ]
            );

            Log::info('Online walk-in AR account automatically created', [
                'name' => $account->name,
                'id' => $account->id,
                'module_type' => $account->module_type,
            ]);

            return (int) $account->id;
        }));
    }

    /**
     * FIN-2 (2026-08-23) — Sales-pending-receivable contra for Flight bookings.
     *
     * When a flight booking is created on credit (no immediate payment), the
     * customer AR is debited via a transfer whose source is THIS account
     * (instead of `incomeContraIdForFlightBooking()`). Because the source is
     * NOT in `incomeClearing`, `ProfitLossReportService::classify()` returns
     * `null` for that transfer — so the dashboard does NOT count the unpaid
     * sale as realised revenue. Revenue is recognised only when cash arrives
     * via `FlightBookingService::addPayment()`.
     *
     * Owning account type is `AccountType::Owner` ("حساب داخلي") — internal
     * system account, not visible in cashboxes, not user-controlled.
     */
    public function pendingSalesReceivableIdForFlight(): ?int
    {
        $name = config('accounting.clearing.sales_pending_receivable.flight');
        if (! is_string($name) || $name === '') {
            return null;
        }

        return $this->ensureClearingAccountExists($name, 'flight', 'pending_sales_receivable');
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

    /**
     * Canonicalise a currency code: trim, uppercase, default to EGP.
     * Used by the per-currency clearing resolver to match config keys.
     */
    protected function normalizeCurrency(?string $currency): string
    {
        $v = strtoupper(trim((string) ($currency ?? '')));

        return $v !== '' ? $v : 'EGP';
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

    protected function ensureClearingAccountExists(string $name, string $moduleKey, string $type, ?string $currency = null): int
    {
        $id = $this->accountIdByName($name);
        if ($id !== null) {
            return $id;
        }

        // Phase 7: honour the per-currency denomination when provided. Defaults
        // to EGP to preserve legacy behaviour for callers that haven't opted
        // into the new per-currency config.
        $denomination = $this->normalizeCurrency($currency);

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($name, $moduleKey, $type, $denomination) {
            $account = Account::query()->firstOrCreate(
                ['name' => $name],
                [
                    'type' => AccountType::Owner,  // owner = حساب داخلي لا يظهر في الخزائن
                    'balance' => 0,
                    'currency' => $denomination,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => AccountModuleDivision::resolveModuleTypeKey(null, $moduleKey),
                    'is_module_vault' => false,
                    'notes' => "حساب إقفال تلقائي للموديول: {$moduleKey} ({$type}) — {$denomination}",
                    'created_by' => Auth::id() ?? 1,
                ]
            );

            Log::info('Clearing account automatically created', [
                'name' => $name,
                'id' => $account->id,
                'module' => $moduleKey,
                'type' => $type,
                'currency' => $denomination,
            ]);

            return $account->id;
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
