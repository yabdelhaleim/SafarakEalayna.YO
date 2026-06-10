<?php

namespace App\Services\Finance;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\UnifiedLiquidityGrouper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TreasuryService
{
    /**
     * الحصول على رصيد خزينة معينة
     */
    public function getBalance(int $accountId): array
    {
        $account = Account::findOrFail($accountId);

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'balance' => $account->balance,
            'currency' => $account->currency,
        ];
    }

    /**
     * نظرة عامة احترافية على الخزينة العامة (مقسمة حسب الموديول)
     */
    public function getTreasuryOverview(): array
    {
        $accounts = Account::query()
            ->tap(fn ($q) => AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->active()
            ->orderBy('module_type')
            ->orderBy('name')
            ->get();

        $modules = [];

        foreach ($accounts as $account) {
            $moduleKey = AccountModuleDivision::resolveModuleTypeKey($account->module_type, $account->module);
            $category = AccountModuleDivision::divisionForModuleType($moduleKey);

            if (! isset($modules[$moduleKey])) {
                $modules[$moduleKey] = [
                    'label' => AccountModuleDivision::moduleLabel($moduleKey),
                    'category' => $category,
                    'accounts' => [],
                ];
            }

            $modules[$moduleKey]['accounts'][] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'balance' => (float) $account->balance,
                'currency' => $account->currency,
                'module_type' => $account->module_type,
                'is_vault' => $account->is_module_vault,
            ];
        }

        $filtered = array_filter($modules, fn ($mod) => count($mod['accounts']) > 0);

        $recentTransfers = Transaction::where('type', TransactionType::Transfer->value)
            ->with(['fromAccount', 'toAccount', 'createdBy'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'amount' => (float) $t->amount,
                'currency' => $t->fromAccount?->currency ?? 'EGP',
                'from_account' => $t->fromAccount?->name,
                'to_account' => $t->toAccount?->name,
                'date' => $t->created_at ? $t->created_at->format('Y-m-d') : '-',
                'notes' => $t->notes,
                'user' => $t->createdBy?->name,
            ]);

        $statsByCategory = $this->buildStatsByCategory($accounts);
        $accountsByCategory = $this->splitAccountsByCategory($accounts);
        $unifiedByCategory = [
            'office' => UnifiedLiquidityGrouper::group($accountsByCategory['office']),
            'tourism' => UnifiedLiquidityGrouper::group($accountsByCategory['tourism']),
        ];

        return [
            'modules' => $filtered,
            'unified_by_category' => $unifiedByCategory,
            'recent_transfers' => $recentTransfers,
            'stats' => [
                'by_category' => $statsByCategory,
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     * @return array{office: \Illuminate\Support\Collection<int, Account>, tourism: \Illuminate\Support\Collection<int, Account>}
     */
    protected function splitAccountsByCategory($accounts): array
    {
        $split = [
            'office' => collect(),
            'tourism' => collect(),
        ];

        foreach ($accounts as $account) {
            $moduleKey = AccountModuleDivision::resolveModuleTypeKey($account->module_type, $account->module);
            $category = AccountModuleDivision::divisionForModuleType($moduleKey);
            if (! isset($split[$category])) {
                $category = 'office';
            }
            $split[$category]->push($account);
        }

        return $split;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     * @return array<string, array<string, float|int>>
     */
    protected function buildStatsByCategory($accounts): array
    {
        $empty = fn (): array => [
            'total_liquidity' => 0.0,
            'accounts_count' => 0,
            'total_banks' => 0.0,
            'total_cashbox' => 0.0,
            'total_wallets' => 0.0,
            'total_post' => 0.0,
            'total_treasury' => 0.0,
        ];

        $categories = [
            'office' => $empty(),
            'tourism' => $empty(),
        ];

        foreach ($accounts as $account) {
            $moduleKey = AccountModuleDivision::resolveModuleTypeKey($account->module_type, $account->module);
            $category = AccountModuleDivision::divisionForModuleType($moduleKey);
            if (! isset($categories[$category])) {
                $category = 'office';
            }

            $type = $account->type instanceof \App\Enums\AccountType
                ? $account->type->value
                : (string) $account->type;
            $balance = (float) $account->balance;

            $categories[$category]['total_liquidity'] += $balance;
            $categories[$category]['accounts_count']++;

            match ($type) {
                'bank' => $categories[$category]['total_banks'] += $balance,
                'cashbox' => $categories[$category]['total_cashbox'] += $balance,
                'wallet' => $categories[$category]['total_wallets'] += $balance,
                'post' => $categories[$category]['total_post'] += $balance,
                'treasury' => $categories[$category]['total_treasury'] += $balance,
                default => null,
            };
        }

        return $categories;
    }

    /**
     * جلب الحسابات الخاصة بموديول معين فقط (طرق الدفع)
     */
    public function getModuleAccounts(string $module): array
    {
        $query = Account::query()
            ->tap(fn ($q) => AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->active();

        AccountModuleDivision::applyModuleFilter($query, $module);

        return $query
            ->orderBy('name')
            ->get()
            ->map(fn ($acc) => [
                'id' => $acc->id,
                'name' => $acc->name,
                'type' => $acc->type->value,
                'type_label' => $acc->type->label(),
                'balance' => (float) $acc->balance,
                'currency' => $acc->currency,
                'module_type' => $acc->module_type,
            ])
            ->toArray();
    }

    /**
     * الحصول على كل أرصدة المالك
     */
    public function getOwnerTreasuryBalances(): array
    {
        $accounts = Account::owner('owner')->active()->get();
        $balances = [];

        foreach ($accounts as $account) {
            $balances[] = [
                'account_id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'balance' => $account->balance,
            ];
        }

        return $balances;
    }

    /**
     * الحصول على كل أرصدة المكتب
     */
    public function getOfficeTreasuryBalances(): array
    {
        $accounts = Account::owner('office')->active()->get();
        $balances = [];

        foreach ($accounts as $account) {
            $balances[] = [
                'account_id' => $account->id,
                'name' => $account->name,
                'balance' => $account->balance,
                'currency' => $account->currency,
            ];
        }

        return $balances;
    }

    /**
     * إغلاق الدرج (End of Day)
     */
    public function closeDrawer(int $drawerAccountId, float $countedCash, string $notes): array
    {
        return DB::transaction(function () use ($drawerAccountId, $countedCash, $notes) {
            $account = Account::findOrFail($drawerAccountId);

            $systemTotal = $account->balance;
            $discrepancy = $countedCash - $systemTotal;

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'close_drawer',
                'model_type' => Account::class,
                'model_id' => $account->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'old_values' => ['system_total' => $systemTotal],
                'new_values' => ['counted_cash' => $countedCash, 'discrepancy' => $discrepancy],
                'notes' => $notes,
            ]);

            return [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'system_total' => $systemTotal,
                'counted_cash' => $countedCash,
                'discrepancy' => $discrepancy,
                'status' => $discrepancy === 0 ? 'balanced' : 'discrepancy',
            ];
        });
    }
}
