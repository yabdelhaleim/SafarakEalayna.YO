<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\AuditLog;
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
        // جلب كل الحسابات النقدية والبنكية والمحافظ والبريد للمكتب
        $accounts = Account::whereIn('owner_type', ['office', 'owner'])
            ->whereIn('type', [\App\Enums\AccountType::Cashbox, \App\Enums\AccountType::Bank, \App\Enums\AccountType::Wallet, \App\Enums\AccountType::Post, \App\Enums\AccountType::Treasury])
            ->active()
            ->get();

        $modules = [
            'flight' => ['label' => 'الطيران', 'accounts' => []],
            'bus' => ['label' => 'الباصات', 'accounts' => []],
            'visa' => ['label' => 'التأشيرات', 'accounts' => []],
            'hajj_umra' => ['label' => 'الحج والعمرة', 'accounts' => []],
            'online' => ['label' => 'الخدمات الإلكترونية', 'accounts' => []],
            'general' => ['label' => 'الخزينة العامة', 'accounts' => []],
        ];

        foreach ($accounts as $account) {
            $m = $account->module ?: ($account->module_type ?: 'general');
            $category = $this->getModuleCategory($m, $account->module_type);
            
            if (!isset($modules[$m])) {
                $modules[$m] = [
                    'label' => $this->getModuleLabel($m),
                    'category' => $category,
                    'accounts' => []
                ];
            } else {
                $modules[$m]['category'] = $category;
            }
            
            $modules[$m]['accounts'][] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'balance' => (float) $account->balance,
                'currency' => $account->currency,
                'is_vault' => $account->is_module_vault,
            ];
        }

        // تصفية الموديولات الفارغة
        $filtered = array_filter($modules, fn($mod) => count($mod['accounts']) > 0);

        // إضافة آخر 10 عمليات تحويل مالي لزيادة الاحترافية
        $recentTransfers = \App\Models\Transaction::where('type', \App\Enums\TransactionType::Transfer->value)
            ->with(['fromAccount', 'toAccount', 'createdBy'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'amount' => (float) $t->amount,
                'currency' => $t->fromAccount?->currency ?? 'EGP',
                'from_account' => $t->fromAccount?->name,
                'to_account' => $t->toAccount?->name,
                'date' => $t->created_at ? $t->created_at->format('Y-m-d') : '-',
                'notes' => $t->notes,
                'user' => $t->createdBy?->name,
            ]);

        return [
            'modules' => $filtered,
            'recent_transfers' => $recentTransfers,
            'stats' => [
                'total_liquidity' => $accounts->sum('balance'),
                'accounts_count' => $accounts->count(),
                'modules_count' => count($filtered),
            ]
        ];
    }

    /**
     * جلب الحسابات الخاصة بموديول معين فقط (طرق الدفع)
     */
    public function getModuleAccounts(string $module): array
    {
        return Account::where('module', $module)
            ->whereIn('type', [\App\Enums\AccountType::Cashbox, \App\Enums\AccountType::Bank, \App\Enums\AccountType::Wallet, \App\Enums\AccountType::Post, \App\Enums\AccountType::Treasury])
            ->active()
            ->get()
            ->map(fn($acc) => [
                'id' => $acc->id,
                'name' => $acc->name,
                'type' => $acc->type->value,
                'type_label' => $acc->type->label(),
                'balance' => (float) $acc->balance,
                'currency' => $acc->currency,
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

            // تسجيل العملية في Audit
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

    /**
     * الحصول على اسم الموديول بشكل مقروء
     */
    private function getModuleLabel(string $module): string
    {
        $labels = [
            'flight' => 'الطيران',
            'bus' => 'الباصات',
            'visa' => 'التأشيرات',
            'visas' => 'التأشيرات',
            'hajj_umra' => 'الحج والعمرة',
            'online' => 'الخدمات الإلكترونية',
            'general' => 'الخزينة العامة',
            'tourism' => 'السياحة',
            'office' => 'المكتب العام',
            'fawry' => 'فوري',
            'wallet' => 'محافظ إلكترونية',
        ];

        return $labels[$module] ?? ucfirst(str_replace('_', ' ', $module));
    }

    /**
     * تحديد القسم (سياحة أم مكتب) بناءً على الموديول
     */
    private function getModuleCategory(string $module, ?string $moduleType): string
    {
        $tourismModules = ['flight', 'visa', 'visas', 'hajj_umra', 'tourism', 'flights'];
        
        if (in_array($module, $tourismModules)) {
            return 'tourism';
        }

        if (in_array($moduleType, ['tourism', 'flights'])) {
            return 'tourism';
        }

        return 'office';
    }
}
