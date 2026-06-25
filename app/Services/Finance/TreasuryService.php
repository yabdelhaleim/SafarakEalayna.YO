<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Setting\PrintSettingService;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\UnifiedLiquidityGrouper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TreasuryService
{
    /** ذمم مدينة حقيقية في معادلة الميزان (غير مُدرجة في إجمالي الأرصدة كأصول مسبقة الدفع). */
    private const TRIAL_BALANCE_RECEIVABLE_ENTITY_TYPES = ['customer', 'flight_group'];

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

            $balance = (float) $account->balance;
            $rate = $this->getAveragePurchaseRate($account->currency);
            $balanceEgp = $balance * $rate;

            $modules[$moduleKey]['accounts'][] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'balance' => $balance,
                'balance_egp' => round($balanceEgp, 2),
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
            'office_trial_balance' => $this->getOfficeTrialBalance(),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array{office: Collection<int, Account>, tourism: Collection<int, Account>}
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
     * @param  Collection<int, Account>  $accounts
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

            $type = $account->type instanceof AccountType
                ? $account->type->value
                : (string) $account->type;
            $balance = (float) $account->balance;
            $rate = $this->getAveragePurchaseRate($account->currency);
            $balanceEgp = $balance * $rate;

            $categories[$category]['total_liquidity'] += $balanceEgp;
            $categories[$category]['accounts_count']++;

            match ($type) {
                'bank' => $categories[$category]['total_banks'] += $balanceEgp,
                'cashbox' => $categories[$category]['total_cashbox'] += $balanceEgp,
                'wallet' => $categories[$category]['total_wallets'] += $balanceEgp,
                'post' => $categories[$category]['total_post'] += $balanceEgp,
                'treasury' => $categories[$category]['total_treasury'] += $balanceEgp,
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

        // Fallback to currencies table managed in admin settings
        $dbCurrency = DB::table('currencies')
            ->where('is_active', true)
            ->whereRaw('upper(code) = ?', [$currency])
            ->first();

        if ($dbCurrency && (float) $dbCurrency->exchange_rate > 0) {
            return (float) $dbCurrency->exchange_rate;
        }

        return 1.0;
    }

    /**
     * حساب إجمالي الأرباح لجميع العمليات الحية (مؤكدة/مكتملة)
     */
    /**
     * حساب إجمالي الأرباح حسب القسم (tourism أو office).
     * يجب تمرير القسم دائماً لضمان عدم خلط أرباح الأقسام في المعادلة المحاسبية.
     */
    public function calculateDynamicProfits(string $division = 'tourism'): float
    {
        if ($division === 'tourism') {
            // طيران + حج وعمرة + تأشيرات فقط
            $flightProfits = DB::table('flight_bookings')
                ->whereNull('deleted_at')
                ->whereNotIn('status', [
                    'CANCELLED', 'REFUNDED', 'PENDING',
                    'cancelled', 'refunded', 'pending',
                    'PARTIALLY_REFUNDED', 'partially_refunded',
                ])
                ->get()
                ->sum(function ($booking) {
                    $hasB2cRefund = DB::table('flight_refunds')
                        ->where('flight_booking_id', $booking->id)
                        ->exists();

                    if ($hasB2cRefund) {
                        return (float) DB::table('flight_refunds')
                            ->where('flight_booking_id', $booking->id)
                            ->sum('office_penalty');
                    }

                    return (float) $booking->profit;
                });

            $hajjUmraProfits = DB::table('hajj_umra_bookings')
                ->whereIn('status', ['confirmed', 'completed', 'in_progress'])
                ->whereNull('deleted_at')
                ->sum('profit');

            $visaProfits = DB::table('visa_bookings')
                ->whereIn('status', ['approved', 'issued', 'submitted', 'under_review', 'completed'])
                ->whereNull('deleted_at')
                ->sum('profit');

            return (float) ($flightProfits + $hajjUmraProfits + $visaProfits);
        }

        // Office: باص + فوري + أونلاين + محافظ — نفس منطق السياحة: نحسب كل العمليات النشطة وليس الملغاة فقط
        $busProfits = DB::table('bus_bookings')
            ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
            ->whereNull('deleted_at')
            ->sum('profit');

        $onlineProfits = DB::table('online_transactions')
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->whereNull('deleted_at')
            ->sum('profit');

        $fawryProfits = DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->sum('profit');

        $walletProfits = DB::table('wallet_transactions')
            ->whereNull('deleted_at')
            ->sum('service_fee');

        return (float) ($busProfits + $onlineProfits + $fawryProfits + $walletProfits);
    }

    /**
     * صافي أرباح القسم لميزان المراجعة: إيرادات العمليات − المصروفات التشغيلية.
     * السياحة: جداول الحجوزات + مصروفات الدفتر.
     * المكتب: القيد المزدوج (P&L) عند وجود حركات دفترية، وإلا جداول العمليات كاحتياط.
     */
    public function calculateDivisionNetProfits(string $division): float
    {
        if ($division === 'office') {
            $report = app(ProfitLossReportService::class)->report([
                'category' => 'office',
            ]);

            if (($report['meta']['transactions_included'] ?? 0) > 0) {
                return round((float) $report['netProfit'], 2);
            }
        }

        $grossProfits = $this->calculateDynamicProfits($division);
        $operatingExpenses = $this->calculateOperatingExpenses($division);

        return round($grossProfits - $operatingExpenses, 2);
    }

    /**
     * حساب إجمالي المصروفات التشغيلية للقسم (EGP)
     */
    public function calculateOperatingExpenses(string $division = 'tourism'): float
    {
        $expenseClearingIds = array_keys(app(LedgerClearingAccounts::class)->moduleAccountMaps()['expense']);
        $liquidityTypes = AccountModuleDivision::LIQUIDITY_TYPES;

        $divisionModules = $division === 'tourism'
            ? AccountModuleDivision::TOURISM
            : AccountModuleDivision::OFFICE;

        $query = DB::table('transactions as t')
            ->leftJoin('accounts as to_acc', 't.to_account_id', '=', 'to_acc.id')
            ->leftJoin('accounts as from_acc', 't.from_account_id', '=', 'from_acc.id')
            ->leftJoin('transfers as tr', 't.id', '=', 'tr.transaction_id')
            ->whereIn('from_acc.type', $liquidityTypes)
            ->whereIn('from_acc.module_type', $divisionModules)
            ->whereNull('t.related_type')
            ->where(function ($q) use ($expenseClearingIds) {
                $q->where('t.type', 'expense')
                    ->orWhere('to_acc.type', 'expense')
                    ->orWhereIn('t.to_account_id', $expenseClearingIds);
            });

        $total = 0.0;
        foreach ($query->select(['t.amount', 'tr.converted_amount', 'tr.from_currency', 'tr.to_currency'])->cursor() as $tx) {
            $amount = (float) $tx->amount;
            if (isset($tx->converted_amount) && (float) $tx->converted_amount > 0) {
                $fromCurrency = strtoupper((string) ($tx->from_currency ?? ''));
                $toCurrency = strtoupper((string) ($tx->to_currency ?? ''));
                if ($toCurrency === 'EGP') {
                    $amount = (float) $tx->converted_amount;
                } elseif ($fromCurrency === 'EGP') {
                    $amount = (float) $tx->amount;
                }
            }
            $total += $amount;
        }

        return $total;
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

        // 2. Total Balances (إجمالي الأرصدة للموديولات) — أصول مسبقة الدفع فقط (رصيد موجب)
        $flightSystems = DB::table('flight_systems')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where('balance', '>', 0)
            ->get();
        $flightSystemsTotal = $flightSystems->sum(fn ($sys) => (float) $sys->balance * $this->getAveragePurchaseRate($sys->currency));

        $flightCarriers = DB::table('flight_carriers')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where('balance', '>', 0)
            ->get();
        $flightCarriersTotal = $flightCarriers->sum(fn ($car) => (float) $car->balance * $this->getAveragePurchaseRate($car->currency));

        $airlineAccounts = DB::table('airline_accounts')->where('balance', '>', 0)->get();
        $airlineAccountsTotal = $airlineAccounts->sum(fn ($air) => (float) $air->balance * $this->getAveragePurchaseRate($air->currency));

        $flightTotalBalances = $flightSystemsTotal + $flightCarriersTotal + $airlineAccountsTotal;

        $hajjUmraSuppliersTotal = DB::table('accounts')
            ->where('type', 'supplier')
            ->where('module_type', 'hajj_umra')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->get()
            ->sum(fn ($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

        $visaAgentsTotal = DB::table('accounts')
            ->where('type', 'supplier')
            ->where('module_type', 'visas')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->get()
            ->sum(fn ($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

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

        $totalLiquidity = $tourismLiquidityAccounts->sum(fn ($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

        // تمرير 'tourism' صراحةً لضمان عدم احتساب ذمم المكتب في ميزان السياحة
        $receivablesPayables = $this->calculateReceivablesAndPayables('tourism');
        $dueToUs = $receivablesPayables['due_to_us'];
        $dueFromUs = $receivablesPayables['due_from_us'];

        // 6. Capital Equation Calculations
        $currentCapital = ($totalBalances + $totalLiquidity + $dueToUs) - $dueFromUs;

        $printSettingService = app(PrintSettingService::class);
        $baseCapital = (float) ($printSettingService->get()->base_capital ?? 1000000.00);

        // الأرباح الإجمالية (قبل خصم المصروفات) والمصروفات التشغيلية بشكل منفصل
        $grossProfits = round($this->calculateDynamicProfits('tourism'), 2);
        $operatingExpenses = round($this->calculateOperatingExpenses('tourism'), 2);

        // تمرير 'tourism' صراحةً لضمان عدم احتساب أرباح المكتب في ميزان السياحة
        $profits = $this->calculateDivisionNetProfits('tourism');

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
            'gross_profits' => $grossProfits,
            'operating_expenses' => $operatingExpenses,
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
    /**
     * المستحق لنا / علينا مفلتراً حسب القسم (tourism أو office).
     * يجب تمرير القسم دائماً لضمان عدم خلط ذمم الأقسام في المعادلة المحاسبية.
     *
     * @return array{due_to_us: float, due_from_us: float}
     */
    public function calculateReceivablesAndPayables(string $division = 'tourism'): array
    {
        $filters = ['department' => $division];
        $debtsReport = app(FinancialReportService::class)->getDebtsReport($filters);
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
                $entityType = (string) ($item['entity_type'] ?? '');
                // الأرصدة الموجبة للموردين/الشركات/الخطوط مُدرجة في total_balances كأصول مسبقة الدفع
                if (! in_array($entityType, self::TRIAL_BALANCE_RECEIVABLE_ENTITY_TYPES, true)) {
                    continue;
                }
                $dueToUs += $egp;
            } else {
                $dueFromUs += $egp;
            }
        }

        if ($division === 'tourism') {
            $dueFromUs += $this->sumNegativeTourismPrepaidBalances();
        }

        return [
            'due_to_us' => round($dueToUs, 2),
            'due_from_us' => round($dueFromUs, 2),
        ];
    }

    /**
     * مستحقات سالبة على أنظمة/ناقلين/خطوط الطيران (غير مُدرجة في تقرير الديون).
     */
    private function sumNegativeTourismPrepaidBalances(): float
    {
        $total = 0.0;

        $systems = DB::table('flight_systems')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where('balance', '<', 0)
            ->get(['balance', 'currency']);

        foreach ($systems as $row) {
            $total += abs((float) $row->balance) * $this->getAveragePurchaseRate((string) $row->currency);
        }

        $carriers = DB::table('flight_carriers')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where('balance', '<', 0)
            ->get(['balance', 'currency']);

        foreach ($carriers as $row) {
            $total += abs((float) $row->balance) * $this->getAveragePurchaseRate((string) $row->currency);
        }

        $airlines = DB::table('airline_accounts')
            ->where('balance', '<', 0)
            ->get(['balance', 'currency']);

        foreach ($airlines as $row) {
            $total += abs((float) $row->balance) * $this->getAveragePurchaseRate((string) ($row->currency ?? 'EGP'));
        }

        return round($total, 2);
    }

    /**
     * ميزان حسابات قسم المكتب (باص + فوري + أونلاين + محافظ + عام).
     * المعادلة: currentCapital = (totalBalances + totalLiquidity + dueToUs) - dueFromUs
     * expectedCapital = baseCapital + grossProfits - operatingExpenses
     */
    public function getOfficeTrialBalance(): array
    {
        // 1. إجمالي السيولة — حسابات المكتب فقط (نقدي، محافظ، بنوك، خزائن)
        $officeLiquidityAccounts = DB::table('accounts')
            ->whereIn('module_type', AccountModuleDivision::OFFICE)
            ->whereIn('type', ['cashbox', 'wallet', 'post', 'bank', 'treasury'])
            ->where('is_active', true)
            ->where('name', 'not like', '%عميل%')
            ->where('name', 'not like', '%شركة%')
            ->where('name', 'not like', '%مورد%')
            ->where('name', 'not like', '%إقفال%')
            ->where('name', 'not like', '%(نظام)%')
            ->where('name', 'not like', '%ذممة%')
            ->where('name', 'not like', '%رصيد مسبق%')
            ->get();

        $totalLiquidity = $officeLiquidityAccounts->sum(fn ($acc) => (float) $acc->balance * $this->getAveragePurchaseRate($acc->currency));

        // 2. الأصول — حسابات شركات الباص (رصيد موجب) + أرصدة ماكينات فوري النشطة
        $busCompanyTotal = (float) DB::table('accounts')
            ->where('type', 'supplier')
            ->where('module_type', 'bus')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->sum('balance');

        $fawryMachinesTotal = (float) DB::table('fawry_machines')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->sum('balance');

        $totalBalances = $busCompanyTotal + $fawryMachinesTotal;

        // 3. الأرباح — عرض إجمالي/مصروفات للواجهة؛ صافي الميزان عبر calculateDivisionNetProfits (أولوية P&L للمكتب)
        $grossProfits = round($this->calculateDynamicProfits('office'), 2);
        $operatingExpenses = round($this->calculateOperatingExpenses('office'), 2);
        $profits = $this->calculateDivisionNetProfits('office');

        // 4. الذمم المدينة والدائنة — المكتب فقط
        $receivablesPayables = $this->calculateReceivablesAndPayables('office');
        $dueToUs = $receivablesPayables['due_to_us'];
        $dueFromUs = $receivablesPayables['due_from_us'];

        // 5. المعادلة المحاسبية
        $currencies = ['USD', 'SAR', 'KWD', 'EUR'];
        $rates = [];
        foreach ($currencies as $curr) {
            $rates[$curr] = $this->getAveragePurchaseRate($curr);
        }

        $currentCapital = ($totalBalances + $totalLiquidity + $dueToUs) - $dueFromUs;
        $printSettingService = app(PrintSettingService::class);
        $baseCapital = (float) ($printSettingService->get()->office_base_capital ?? 0.00);
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
                'bus_company_balances' => $busCompanyTotal,
                'fawry_machine_balances' => $fawryMachinesTotal,
            ],
            'total_balances' => round($totalBalances, 2),
            'total_liquidity' => round($totalLiquidity, 2),
            'due_to_us' => $dueToUs,
            'due_from_us' => $dueFromUs,
            'current_capital' => round($currentCapital, 2),
            'base_capital' => $baseCapital,
            'gross_profits' => $grossProfits,
            'operating_expenses' => $operatingExpenses,
            'profits' => round($profits, 2),
            'expected_capital' => round($expectedCapital, 2),
            'variance' => round($variance, 2),
            'status' => $status,
        ];
    }

    /**
     * ميزان الحسابات الموحد للشركة ككل (السياحة + المكتب)
     */
    public function getConsolidatedTrialBalance(): array
    {
        $tourism = $this->getTrialBalance();
        $office = $this->getOfficeTrialBalance();

        $totalBalances = (float) $tourism['total_balances'] + (float) $office['total_balances'];
        $totalLiquidity = (float) $tourism['total_liquidity'] + (float) $office['total_liquidity'];
        $dueToUs = (float) $tourism['due_to_us'] + (float) $office['due_to_us'];
        $dueFromUs = (float) $tourism['due_from_us'] + (float) $office['due_from_us'];

        $currentCapital = ($totalBalances + $totalLiquidity + $dueToUs) - $dueFromUs;
        $baseCapital = (float) $tourism['base_capital'] + (float) $office['base_capital'];
        $grossProfits = (float) $tourism['gross_profits'] + (float) $office['gross_profits'];
        $operatingExpenses = (float) $tourism['operating_expenses'] + (float) $office['operating_expenses'];
        $profits = (float) $tourism['profits'] + (float) $office['profits'];
        $expectedCapital = $baseCapital + $profits;
        $variance = $currentCapital - $expectedCapital;

        $status = 'متساوية';
        if ($variance > 0.01) {
            $status = 'يوجد زيادة';
        } elseif ($variance < -0.01) {
            $status = 'يوجد عجز';
        }

        return [
            'rates' => $tourism['rates'] ?? [],
            'details' => [
                'flight_balances' => $tourism['details']['flight_balances'] ?? 0.0,
                'hajj_umra_balances' => $tourism['details']['hajj_umra_balances'] ?? 0.0,
                'visa_balances' => $tourism['details']['visa_balances'] ?? 0.0,
                'bus_company_balances' => $office['details']['bus_company_balances'] ?? 0.0,
                'fawry_machine_balances' => $office['details']['fawry_machine_balances'] ?? 0.0,
            ],
            'total_balances' => round($totalBalances, 2),
            'total_liquidity' => round($totalLiquidity, 2),
            'due_to_us' => round($dueToUs, 2),
            'due_from_us' => round($dueFromUs, 2),
            'current_capital' => round($currentCapital, 2),
            'base_capital' => round($baseCapital, 2),
            'gross_profits' => round($grossProfits, 2),
            'operating_expenses' => round($operatingExpenses, 2),
            'profits' => round($profits, 2),
            'expected_capital' => round($expectedCapital, 2),
            'variance' => round($variance, 2),
            'status' => $status,
        ];
    }
}
