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
            'trial_balance' => $this->getTrialBalance(),
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

    /**
     * الحصول على متوسط سعر الشراء للعملة من حجوزات الطيران
     */
    public function getAveragePurchaseRate(string $currency): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'EGP' || empty($currency)) {
            return 1.0;
        }

        // Try to calculate average purchase rate from flight bookings
        $sumEgp = DB::table('flight_bookings')
            ->where('foreign_currency', $currency)
            ->whereNull('deleted_at')
            ->sum('purchase_price_egp');

        $sumForeign = DB::table('flight_bookings')
            ->where('foreign_currency', $currency)
            ->whereNull('deleted_at')
            ->sum('purchase_price_foreign');

        if ($sumForeign > 0) {
            return (float) ($sumEgp / $sumForeign);
        }

        // Fallback to latest exchange rate
        $latestRate = DB::table('exchange_rates')
            ->where('from_currency', $currency)
            ->where('to_currency', 'EGP')
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->value('rate');

        if ($latestRate) {
            return (float) $latestRate;
        }

        return 1.0;
    }

    /**
     * حساب إجمالي الأرباح لجميع العمليات الحية (مؤكدة/مكتملة)
     */
    public function calculateDynamicProfits(): float
    {
        // 1. Flight profits (status confirmed)
        $flightProfits = DB::table('flight_bookings')
            ->whereIn('status', ['CONFIRMED'])
            ->whereNull('deleted_at')
            ->get()
            ->sum(function($booking) {
                $currency = $booking->currency ?: 'EGP';
                $rate = $this->getAveragePurchaseRate($currency);
                return (float) ($booking->profit * $rate);
            });

        // 2. Hajj & Umrah profits
        $hajjUmraProfits = DB::table('hajj_umra_bookings')
            ->whereIn('status', ['confirmed', 'completed', 'in_progress'])
            ->whereNull('deleted_at')
            ->get()
            ->sum(function($booking) {
                $currency = $booking->currency ?: 'EGP';
                $rate = $this->getAveragePurchaseRate($currency);
                return (float) ($booking->profit * $rate);
            });

        // 3. Visa profits
        $visaProfits = DB::table('visa_bookings')
            ->whereIn('status', ['approved', 'issued', 'submitted', 'under_review', 'completed'])
            ->whereNull('deleted_at')
            ->get()
            ->sum(function($booking) {
                $currency = $booking->currency ?: 'EGP';
                $rate = $this->getAveragePurchaseRate($currency);
                return (float) ($booking->profit * $rate);
            });

        // 4. Bus profits
        $busProfits = DB::table('bus_bookings')
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereNull('deleted_at')
            ->get()
            ->sum(function($booking) {
                return (float) $booking->profit;
            });

        // 5. Online profits
        $onlineProfits = DB::table('online_transactions')
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->sum('profit');

        // 6. Fawry profits
        $fawryProfits = DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->sum('profit');

        return (float) ($flightProfits + $hajjUmraProfits + $visaProfits + $busProfits + $onlineProfits + $fawryProfits);
    }

    /**
     * الحصول على ميزان الحسابات (جرد لحظي) وتحليل رأس المال
     */
    public function getTrialBalance(): array
    {
        // 1. Average purchase rates of foreign currencies
        $currencies = ['USD', 'SAR', 'KWD', 'EUR'];
        $rates = [];
        foreach ($currencies as $curr) {
            $rates[$curr] = $this->getAveragePurchaseRate($curr);
        }

        // 2. Total Balances (إجمالي الأرصدة للموديولات)
        $flightSystems = DB::table('flight_systems')->where('is_active', 1)->whereNull('deleted_at')->get();
        $flightSystemsTotal = $flightSystems->sum(fn($sys) => (float) $sys->balance * $this->getAveragePurchaseRate($sys->currency));

        $flightCarriers = DB::table('flight_carriers')->where('is_active', 1)->whereNull('deleted_at')->get();
        $flightCarriersTotal = $flightCarriers->sum(fn($car) => (float) $car->balance * $this->getAveragePurchaseRate($car->currency));

        $airlineAccounts = DB::table('airline_accounts')->get();
        $airlineAccountsTotal = $airlineAccounts->sum(fn($air) => (float) $air->balance * $this->getAveragePurchaseRate($air->currency));

        $flightTotalBalances = $flightSystemsTotal + $flightCarriersTotal + $airlineAccountsTotal;

        $hajjUmraSuppliersTotal = DB::table('accounts')
            ->where('type', 'supplier')
            ->where('module_type', 'hajj_umra')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->get()
            ->sum(fn($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

        $visaAgentsTotal = DB::table('accounts')
            ->where('type', 'supplier')
            ->where('module_type', 'visas')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->get()
            ->sum(fn($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

        $totalBalances = $flightTotalBalances + $hajjUmraSuppliersTotal + $visaAgentsTotal;

        // 3. Total Liquidity (إجمالي السيولة - Tourism category)
        $tourismLiquidityAccounts = DB::table('accounts')
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->whereIn('type', ['cashbox', 'wallet', 'post', 'bank', 'treasury'])
            ->where('is_active', true)
            ->where('name', 'not like', '%عميل%')
            ->where('name', 'not like', '%شركة%')
            ->where('name', 'not like', '%مورد%')
            ->where('name', 'not like', '%إقفال%')
            ->where('name', 'not like', '%(نظام)%')
            ->where('name', 'not like', '%ذممة%')
            ->get();

        $totalLiquidity = $tourismLiquidityAccounts->sum(fn($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

        $receivablesPayables = $this->calculateReceivablesAndPayables();
        $dueToUs = $receivablesPayables['due_to_us'];
        $dueFromUs = $receivablesPayables['due_from_us'];

        // 6. Capital Equation Calculations
        $currentCapital = ($totalBalances + $totalLiquidity + $dueToUs) - $dueFromUs;

        $printSettingService = app(\App\Services\Setting\PrintSettingService::class);
        $baseCapital = (float) ($printSettingService->get()->base_capital ?? 1000000.00);

        $profits = $this->calculateDynamicProfits();

        $expectedCapital = $baseCapital + $profits;
        $variance = $currentCapital - $expectedCapital;

        $status = 'متساوية';
        if ($variance > 0.01) {
            $status = 'يوجد زيادة';
        } elseif ($variance < -0.01) {
            $status = 'يوجد عجز';
        }

        return [
            'rates' => $rates,
            'details' => [
                'flight_balances' => $flightTotalBalances,
                'hajj_umra_balances' => $hajjUmraSuppliersTotal,
                'visa_balances' => $visaAgentsTotal,
            ],
            'total_balances' => $totalBalances,
            'total_liquidity' => $totalLiquidity,
            'due_to_us' => $dueToUs,
            'due_from_us' => $dueFromUs,
            'current_capital' => $currentCapital,
            'base_capital' => $baseCapital,
            'profits' => $profits,
            'expected_capital' => $expectedCapital,
            'variance' => $variance,
            'status' => $status,
        ];
    }

    /**
     * المستحق لنا / علينا من تقرير الديون الموحد (عملاء، موردين، شركات، مجموعات…).
     *
     * @return array{due_to_us: float, due_from_us: float}
     */
    public function calculateReceivablesAndPayables(): array
    {
        $debtsReport = app(\App\Services\Reports\FinancialReportService::class)->getDebtsReport([]);
        $dueToUs = 0.0;
        $dueFromUs = 0.0;

        foreach ($debtsReport['items'] as $item) {
            $balance = (float) ($item['balance'] ?? 0);
            if ($balance === 0.0) {
                continue;
            }

            $currency = strtoupper((string) ($item['currency'] ?? 'EGP'));
            $rate = $currency === 'EGP' ? 1.0 : $this->getAveragePurchaseRate($currency);
            $egp = abs($balance) * $rate;

            if ($balance > 0) {
                $dueToUs += $egp;
            } else {
                $dueFromUs += $egp;
            }
        }

        return [
            'due_to_us' => round($dueToUs, 2),
            'due_from_us' => round($dueFromUs, 2),
        ];
    }
}
