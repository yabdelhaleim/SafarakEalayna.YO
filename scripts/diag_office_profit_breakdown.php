<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  تشخيص قسم المكتب — ميزان المراجعة + أرباح الموديولات + مصدر الفجوة
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  يركّز فقط على قسم المكتب (office division):
 *      modules: bus, fawry, online, wallet_transfer, general
 *      + المصروفات الإدارية (rent, salaries, withdrawals)
 *
 *  يُكتشف:
 *      [1]  بيئة التشغيل
 *      [2]  المعادلة المحاسبية الأساسية (Σ debit = Σ credit لكل tx)
 *      [3]  معاملات يتيمة (transactions من غير account_entries)
 *      [4]  أرباح موديولات المكتب (bus, online, fawry, wallet)
 *      [5]  دخل قسم المكتب من transactions (income)
 *      [6]  مصروفات قسم المكتب من transactions (expense)
 *      [7]  P&L تقرير (ProfitLossReportService — 'office')
 *      [8]  الميزان المحاسبي (TreasuryService::getOfficeTrialBalance)
 *      [9]  تفكيك الفجوة (variance) — كل بند لحاله
 *      [10] الحسابات اللي balance ≠ Σ entries (ghost balance)
 *      [11] أرصدة العملاء/الموردين في المكتب (receivables/payables)
 *      [12] معاملات الـ Walk-in Fawry (بدون client_id)
 *      [13] المصروفات التشغيلية بالتفصيل (rent, salaries, other)
 *
 *  تشغيل:
 *      php scripts/diag_office_profit_breakdown.php
 *
 *  أو من tinker:
 *      php artisan tinker
 *      > require 'scripts/diag_office_profit_breakdown.php';
 *
 *  الإخراج: نص منظّم + JSON في storage/logs/diag_office_<timestamp>.json
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$timestamp = date('Ymd_His');
$logFile = __DIR__ . "/../storage/logs/diag_office_{$timestamp}.json";

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'app_env'   => config('app.env'),
    'db_connection' => config('database.default'),
    'db_database'   => config('database.connections.'.config('database.default').'.database'),
    'db_host'       => config('database.connections.'.config('database.default').'.host'),
];

// ──────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────
$line = function (string $label, $value, ?string $unit = null) use (&$report) {
    $str = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) :
        (is_float($value) ? number_format($value, 2) : (string) $value);
    $unitStr = $unit ? " $unit" : '';
    echo str_pad($label, 60, ' ', STR_PAD_RIGHT) . " : {$str}{$unitStr}\n";
    $report['lines'][] = ['label' => $label, 'value' => $value, 'unit' => $unit];
};

$section = function (string $title) use (&$report) {
    echo "\n╔══════════════════════════════════════════════════════════════════════════╗\n";
    echo "║ " . str_pad($title, 73, ' ', STR_PAD_BOTH) . " ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
    $report['sections'][] = $title;
};

// ═════════════════════════════════════════════════════════════════════════
// [1] بيئة التشغيل
// ═════════════════════════════════════════════════════════════════════════
$section('[1] بيئة التشغيل');
$line('APP_ENV', $report['app_env']);
$line('DB_CONNECTION', $report['db_connection']);
$line('DB_DATABASE', $report['db_database']);
$line('DB_HOST', $report['db_host'] ?? 'N/A');

if ($report['app_env'] === 'production') {
    echo "\n  ⚠️  أنت على production. كل الـ queries قراءة فقط.\n";
}

// ═════════════════════════════════════════════════════════════════════════
// [2] المعادلة المحاسبية الأساسية (Σ debit == Σ credit)
// ═════════════════════════════════════════════════════════════════════════
$section('[2] المعادلة المحاسبية — Σ debit == Σ credit لكل transaction');

$unbalancedTx = DB::select("
    SELECT t.id, t.type, t.module, t.amount, t.currency,
           COALESCE(SUM(e.debit), 0)  AS total_debit,
           COALESCE(SUM(e.credit), 0) AS total_credit,
           ABS(COALESCE(SUM(e.debit),0) - COALESCE(SUM(e.credit),0)) AS diff
    FROM transactions t
    LEFT JOIN account_entries e ON e.transaction_id = t.id
    GROUP BY t.id, t.type, t.module, t.amount, t.currency
    HAVING diff > 0.01
    ORDER BY diff DESC
    LIMIT 20
");

$totalTx = DB::table('transactions')->count();
$unbalancedCount = count($unbalancedTx);
$line('إجمالي المعاملات', number_format($totalTx));
$line('معاملات غير متوازنة', $unbalancedCount, 'transaction');

if ($unbalancedCount > 0) {
    echo "\n  --- كل المعاملات غير المتوازنة (التفاصيل الكاملة) ---\n";
    foreach ($unbalancedTx as $tx) {
        if (! in_array($tx->module, ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])) {
            continue;
        }
        printf(
            "    tx#%d | %-8s | %-15s | %-12s %-8s | debit=%s | credit=%s | diff=%s\n",
            $tx->id,
            $tx->type,
            $tx->module,
            number_format($tx->amount, 2),
            $tx->currency,
            number_format($tx->total_debit, 2),
            number_format($tx->total_credit, 2),
            number_format($tx->diff, 2)
        );
    }
    echo "\n  --- تفاصيل الـ from/to/notes/related لكل معاملة غير متوازنة ---\n";
    foreach ($unbalancedTx as $tx) {
        if (! in_array($tx->module, ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])) {
            continue;
        }
        $full = DB::table('transactions')->where('id', $tx->id)->first();
        if ($full) {
            printf(
                "    tx#%d | %s | %s | amount=%s | from=%s | to=%s | related=%s#%s | by=%s | notes=%s\n",
                $full->id,
                $full->type,
                $full->module,
                number_format($full->amount, 2),
                $full->from_account_id ?? 'NULL',
                $full->to_account_id ?? 'NULL',
                $full->related_type ?? 'NULL',
                $full->related_id ?? 'NULL',
                $full->created_by,
                mb_substr((string) ($full->notes ?? ''), 0, 60)
            );
        }
    }
}
$report['unbalanced_count'] = $unbalancedCount;
$report['unbalanced_tx'] = $unbalancedTx;

// ═════════════════════════════════════════════════════════════════════════
// [3] معاملات يتيمة (transactions من غير entries)
// ═════════════════════════════════════════════════════════════════════════
$section('[3] معاملات يتيمة (transactions من غير account_entries)');

$orphanByModule = DB::select("
    SELECT t.module, COUNT(*) AS cnt
    FROM transactions t
    LEFT JOIN account_entries e ON e.transaction_id = t.id
    WHERE e.id IS NULL
    GROUP BY t.module
");
$totalOrphan = 0;
echo "  module            | orphan count\n";
echo "  ------------------|-------------\n";
foreach ($orphanByModule as $row) {
    printf("  %-17s | %d\n", $row->module, $row->cnt);
    if (in_array($row->module, ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])) {
        $totalOrphan += $row->cnt;
    }
}
$line('يتيمات قسم المكتب (المجموع)', $totalOrphan);
$report['orphan_by_module'] = $orphanByModule;

// ═════════════════════════════════════════════════════════════════════════
// [4] أرباح موديولات المكتب (من الجداول مباشرة)
// ═════════════════════════════════════════════════════════════════════════
$section('[4] أرباح موديولات المكتب (من الجداول مباشرة)');

$officeModules = [];

// 4.1) Bus
$busProfit = (float) DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->sum('profit');
$busCount = DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->count();
$officeModules['bus'] = ['profit' => $busProfit, 'count' => $busCount];

// 4.2) Online
$onlineProfit = (float) DB::table('online_transactions')
    ->whereNotIn('status', ['cancelled', 'failed'])
    ->whereNull('deleted_at')
    ->sum('profit');
$onlineCount = DB::table('online_transactions')
    ->whereNotIn('status', ['cancelled', 'failed'])
    ->whereNull('deleted_at')
    ->count();
$officeModules['online'] = ['profit' => $onlineProfit, 'count' => $onlineCount];

// 4.3) Fawry
$fawryProfit = (float) DB::table('fawry_transactions')
    ->whereNull('deleted_at')
    ->sum('profit');
$fawryCount = DB::table('fawry_transactions')
    ->whereNull('deleted_at')
    ->count();
$officeModules['fawry'] = ['profit' => $fawryProfit, 'count' => $fawryCount];

// 4.4) Wallet (service_fee)
$walletProfit = (float) DB::table('wallet_transactions')
    ->whereNull('deleted_at')
    ->sum('service_fee');
$walletCount = DB::table('wallet_transactions')
    ->whereNull('deleted_at')
    ->count();
$officeModules['wallet'] = ['profit' => $walletProfit, 'count' => $walletCount];

$totalOfficeProfit = 0;
foreach ($officeModules as $mod => $data) {
    $label = str_pad("[{$mod}]", 12, ' ');
    $line("  {$label} عدد العمليات", $data['count']);
    $line("  {$label} إجمالي الربح", $data['profit'], 'EGP');
    $totalOfficeProfit += $data['profit'];
}
$line('المجموع الكلي لأرباح المكتب', $totalOfficeProfit, 'EGP');
$report['office_module_profits'] = $officeModules;
$report['total_office_profit'] = $totalOfficeProfit;

// ═════════════════════════════════════════════════════════════════════════
// [5] دخل قسم المكتب من transactions (income)
// ═════════════════════════════════════════════════════════════════════════
$section('[5] دخل قسم المكتب (income transactions)');

$incomeByModule = DB::table('transactions')
    ->select('module', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
    ->where('type', 'income')
    ->whereIn('module', ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])
    ->groupBy('module')
    ->get();

$totalIncome = 0;
echo "  module            | tx count | total income (EGP)\n";
echo "  ------------------|----------|-------------------\n";
foreach ($incomeByModule as $row) {
    printf("  %-17s | %8d | %s\n", $row->module, $row->cnt, number_format($row->total, 2));
    $totalIncome += $row->total;
}
$line('إجمالي دخل قسم المكتب', $totalIncome, 'EGP');
$report['income_by_module'] = $incomeByModule;
$report['total_income_office'] = $totalIncome;

// ═════════════════════════════════════════════════════════════════════════
// [6] مصروفات قسم المكتب (expense transactions)
// ═════════════════════════════════════════════════════════════════════════
$section('[6] مصروفات قسم المكتب (expense transactions)');

$expenseByModule = DB::table('transactions')
    ->select('module', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
    ->where('type', 'expense')
    ->whereIn('module', ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])
    ->groupBy('module')
    ->get();

$totalExpense = 0;
echo "  module            | tx count | total expense (EGP)\n";
echo "  ------------------|----------|--------------------\n";
foreach ($expenseByModule as $row) {
    printf("  %-17s | %8d | %s\n", $row->module, $row->cnt, number_format($row->total, 2));
    $totalExpense += $row->total;
}
$line('إجمالي مصروفات قسم المكتب', $totalExpense, 'EGP');
$report['expense_by_module'] = $expenseByModule;
$report['total_expense_office'] = $totalExpense;

// ═════════════════════════════════════════════════════════════════════════
// [7] P&L تقرير (ProfitLossReportService — 'office')
// ═════════════════════════════════════════════════════════════════════════
$section('[7] P&L تقرير (ProfitLossReportService — office)');

try {
    $reportService = app(\App\Services\Reports\ProfitLossReportService::class);
    $plReport = $reportService->report(['category' => 'office']);
    $line('صافي ربح المكتب (P&L)', $plReport['netProfit'] ?? 0, 'EGP');
    $line('إجمالي الإيرادات', $plReport['totalRevenue'] ?? 0, 'EGP');
    $line('إجمالي المصروفات', $plReport['totalExpenses'] ?? 0, 'EGP');
    $line('transactions_included', $plReport['meta']['transactions_included'] ?? 'N/A');
    if (! empty($plReport['revenueRows'])) {
        echo "\n  --- بنود الإيرادات ---\n";
        foreach ($plReport['revenueRows'] as $r) {
            printf("    %-30s | %s\n", $r['label'] ?? '', number_format($r['amount'] ?? 0, 2));
        }
    }
    if (! empty($plReport['expenseRows'])) {
        echo "\n  --- بنود المصروفات ---\n";
        foreach ($plReport['expenseRows'] as $r) {
            printf("    %-30s | %s\n", $r['label'] ?? '', number_format($r['amount'] ?? 0, 2));
        }
    }
    $report['pl_office'] = $plReport;
} catch (\Throwable $e) {
    echo "  ⚠️  P&L office failed: " . $e->getMessage() . "\n";
    $report['pl_office_error'] = $e->getMessage();
}

// ═════════════════════════════════════════════════════════════════════════
// [8] الميزان المحاسبي (TreasuryService::getOfficeTrialBalance)
// ═════════════════════════════════════════════════════════════════════════
$section('[8] الميزان المحاسبي (TreasuryService::getOfficeTrialBalance)');

$officeTb = null;
try {
    $treasury = app(\App\Services\Finance\TreasuryService::class);
    $officeTb = $treasury->getOfficeTrialBalance();
    $line('إجمالي السيولة', $officeTb['total_liquidity'] ?? 0, 'EGP');
    $line('أرصدة شركات الباص (موجبة)', $officeTb['details']['bus_company_balances'] ?? 0, 'EGP');
    $line('أرصدة ماكينات فوري', $officeTb['details']['fawry_machine_balances'] ?? 0, 'EGP');
    $line('إجمالي الأصول (total_balances)', $officeTb['total_balances'] ?? 0, 'EGP');
    $line('المستحق لنا (dueToUs)', $officeTb['due_to_us'] ?? 0, 'EGP');
    $line('المستحق علينا (dueFromUs)', $officeTb['due_from_us'] ?? 0, 'EGP');
    $line('current_capital', $officeTb['current_capital'] ?? 0, 'EGP');
    $line('base_capital', $officeTb['base_capital'] ?? 0, 'EGP');
    $line('gross_profits', $officeTb['gross_profits'] ?? 0, 'EGP');
    $line('operating_expenses', $officeTb['operating_expenses'] ?? 0, 'EGP');
    $line('profits (net)', $officeTb['profits'] ?? 0, 'EGP');
    $line('expected_capital', $officeTb['expected_capital'] ?? 0, 'EGP');
    $line('variance (الفجوة)', $officeTb['variance'] ?? 0, 'EGP');
    $line('الحالة', $officeTb['status'] ?? 'N/A');
    $report['office_trial_balance'] = $officeTb;
} catch (\Throwable $e) {
    echo "  ⚠️  office trial balance failed: " . $e->getMessage() . "\n";
    $report['office_tb_error'] = $e->getMessage();
}

// ═════════════════════════════════════════════════════════════════════════
// [9] تفكيك الفجوة (variance breakdown)
// ═════════════════════════════════════════════════════════════════════════
$section('[9] تفكيك الفجوة (variance breakdown)');

if ($officeTb) {
    $currentCapital = (float) $officeTb['current_capital'];
    $expectedCapital = (float) $officeTb['expected_capital'];
    $variance = (float) $officeTb['variance'];
    $baseCapital = (float) $officeTb['base_capital'];
    $profits = (float) $officeTb['profits'];
    $grossProfits = (float) $officeTb['gross_profits'];
    $operatingExpenses = (float) $officeTb['operating_expenses'];
    $totalLiquidity = (float) $officeTb['total_liquidity'];
    $totalBalances = (float) $officeTb['total_balances'];
    $dueToUs = (float) $officeTb['due_to_us'];
    $dueFromUs = (float) $officeTb['due_from_us'];

    echo "  المعادلة:\n";
    echo "    current_capital = (total_balances + total_liquidity + dueToUs) - dueFromUs\n";
    printf("    (%.2f + %.2f + %.2f) - %.2f = %.2f\n",
        $totalBalances, $totalLiquidity, $dueToUs, $dueFromUs, $currentCapital);
    echo "\n  expected_capital = base_capital + profits\n";
    printf("    %.2f + %.2f = %.2f\n", $baseCapital, $profits, $expectedCapital);
    echo "\n  variance = current_capital - expected_capital\n";
    printf("    %.2f - %.2f = %.2f\n", $currentCapital, $expectedCapital, $variance);

    echo "\n  --- الـ sub-check: هل gross_profits == مجموع أرباح الموديولات؟ ---\n";
    printf("    gross_profits (من TreasuryService)  = %.2f\n", $grossProfits);
    printf("    مجموع أرباح الموديولات (من الجداول) = %.2f\n", $totalOfficeProfit);
    printf("    الفرق                              = %.2f\n", $grossProfits - $totalOfficeProfit);

    echo "\n  --- الـ sub-check: هل operating_expenses == مجموع expense tx؟ ---\n";
    printf("    operating_expenses (من TreasuryService) = %.2f\n", $operatingExpenses);
    printf("    مجموع expense tx (من الجداول)          = %.2f\n", $totalExpense);
    printf("    الفرق                                   = %.2f\n", $operatingExpenses - $totalExpense);
}

// ═════════════════════════════════════════════════════════════════════════
// [10] حسابات الـ balance ≠ Σ entries (ghost balance)
// ═════════════════════════════════════════════════════════════════════════
$section('[10] حسابات الـ balance ≠ Σ entries (ghost balance)');

$ghostAccounts = DB::select("
    SELECT * FROM (
        SELECT a.id, a.name, a.type, a.module_type, a.currency, a.balance AS stored_balance,
               COALESCE(SUM(e.debit), 0)  AS sum_debit,
               COALESCE(SUM(e.credit), 0) AS sum_credit,
               (COALESCE(SUM(e.debit),0) - COALESCE(SUM(e.credit),0)) AS entries_balance,
               a.balance - (COALESCE(SUM(e.debit),0) - COALESCE(SUM(e.credit),0)) AS diff
        FROM accounts a
        LEFT JOIN account_entries e ON e.account_id = a.id
        WHERE a.is_active = 1
        GROUP BY a.id, a.name, a.type, a.module_type, a.currency, a.balance
    ) AS sub
    WHERE ABS(diff) > 0.01
    ORDER BY ABS(diff) DESC
    LIMIT 25
");
$line('عدد حسابات الـ ghost balance', count($ghostAccounts));
if (count($ghostAccounts) > 0) {
    echo "\n  acc#  | name (28ch)                       | type         | module_type         | stored       | entries_bal  | diff\n";
    echo "  -----|------------------------------------|--------------|---------------------|--------------|--------------|----------\n";
    foreach ($ghostAccounts as $acc) {
        printf(
            "  %-5d| %-34s | %-12s | %-19s | %12s | %12s | %s\n",
            $acc->id,
            mb_substr((string) $acc->name, 0, 32),
            $acc->type,
            $acc->module_type ?? 'NULL',
            number_format($acc->stored_balance, 2),
            number_format($acc->entries_balance, 2),
            number_format($acc->diff, 2)
        );
    }
}
$report['ghost_accounts'] = $ghostAccounts;

// ═════════════════════════════════════════════════════════════════════════
// [11] أرصدة العملاء/الموردين في المكتب (receivables/payables)
// ═════════════════════════════════════════════════════════════════════════
$section('[11] أرصدة العملاء/الموردين في المكتب');

$customerTotals = DB::table('accounts')
    ->select(
        'type',
        'module_type',
        DB::raw('SUM(balance) as total_balance'),
        DB::raw('COUNT(*) as cnt')
    )
    ->whereIn('type', ['customer', 'supplier', 'flight_group'])
    ->whereIn('module_type', ['office', 'bus', 'fawry', 'online', 'wallet_transfer', 'general'])
    ->where('is_active', 1)
    ->groupBy('type', 'module_type')
    ->get();

echo "  type      | module_type        | count | total balance (EGP)\n";
echo "  -----------|--------------------|-------|--------------------\n";
foreach ($customerTotals as $row) {
    printf(
        "  %-10s | %-18s | %5d | %s\n",
        $row->type,
        $row->module_type ?? 'NULL',
        $row->cnt,
        number_format($row->total_balance, 2)
    );
}
$report['customer_supplier_totals'] = $customerTotals;

// ═════════════════════════════════════════════════════════════════════════
// [12] معاملات الـ Walk-in Fawry (بدون client_id)
// ═════════════════════════════════════════════════════════════════════════
$section('[12] معاملات الـ Walk-in Fawry (بدون client_id)');

$walkinFawry = DB::select("
    SELECT
        COUNT(*)                                                     AS cnt,
        SUM(selling_price)                                           AS total_selling,
        SUM(amount)                                                  AS total_paid,
        SUM(selling_price - amount)                                  AS total_remaining
    FROM fawry_transactions
    WHERE client_id IS NULL
      AND deleted_at IS NULL
");
$w = $walkinFawry[0] ?? null;
if ($w) {
    $line('عدد معاملات walk-in fawry', $w->cnt);
    $line('إجمالي price', $w->total_selling, 'EGP');
    $line('إجمالي مدفوع', $w->total_paid, 'EGP');
    $line('إجمالي متبقي (دين)', $w->total_remaining, 'EGP');
}
$report['walkin_fawry'] = $walkinFawry;

// ═════════════════════════════════════════════════════════════════════════
// [13] المصروفات التشغيلية بالتفصيل (rent, salaries, other)
// ═════════════════════════════════════════════════════════════════════════
$section('[13] المصروفات التشغيلية بالتفصيل');

$expensesDetailed = DB::table('transactions')
    ->select('module', 'related_type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
    ->where('type', 'expense')
    ->whereIn('module', ['general', 'office'])
    ->groupBy('module', 'related_type')
    ->orderByDesc('total')
    ->get();

echo "  module    | related_type                    | count | total\n";
echo "  -----------|---------------------------------|-------|----------\n";
foreach ($expensesDetailed as $row) {
    printf(
        "  %-10s | %-31s | %5d | %s\n",
        $row->module,
        $row->related_type ?? 'NULL',
        $row->cnt,
        number_format($row->total, 2)
    );
}
$report['expenses_detailed'] = $expensesDetailed;

// ═════════════════════════════════════════════════════════════════════════
// [14] معاملات refund في المكتب (استردادات)
// ═════════════════════════════════════════════════════════════════════════
$section('[14] معاملات refund في المكتب');

$refundsByModule = DB::table('transactions')
    ->select('module', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
    ->where('type', 'refund')
    ->whereIn('module', ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])
    ->groupBy('module')
    ->get();

foreach ($refundsByModule as $row) {
    printf("  %-17s | %5d | %s\n", $row->module, $row->cnt, number_format($row->total, 2));
}
$report['refunds_by_module'] = $refundsByModule;

// ═════════════════════════════════════════════════════════════════════════
// [15] save JSON
// ═════════════════════════════════════════════════════════════════════════
$section('[15] حفظ JSON');
file_put_contents($logFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$line('JSON saved to', $logFile);

// ═════════════════════════════════════════════════════════════════════════
// [16] تحقيقات إضافية — تحقق من tx#303 + bus profit discrepancy
// ═════════════════════════════════════════════════════════════════════════
$section('[16] تحقيقات إضافية');

// 16.1) تفاصيل tx#303 والفرق الموجود في entries
$rows = DB::select("
    SELECT e.id AS entry_id, e.account_id, e.debit, e.credit, e.balance_after, e.notes
    FROM account_entries e
    WHERE e.transaction_id = 303
");
echo "  --- entries لمعاملة tx#303 ---\n";
echo "  entry_id | account_id | debit       | credit      | balance_after | notes\n";
foreach ($rows as $r) {
    printf(
        "  %-8d | %-10d | %-11s | %-11s | %-13s | %s\n",
        $r->entry_id,
        $r->account_id,
        number_format($r->debit, 2),
        number_format($r->credit, 2),
        number_format($r->balance_after, 2),
        mb_substr((string) ($r->notes ?? ''), 0, 40)
    );
}

// 16.2) تفاصيل الحسابات from=26, to=27
$acc26 = DB::table('accounts')->where('id', 26)->first();
$acc27 = DB::table('accounts')->where('id', 27)->first();
echo "\n  --- تفاصيل الحسابات اللي اشتركت في tx#303 ---\n";
echo "  account #26: name={$acc26->name} | type={$acc26->type} | module_type={$acc26->module_type} | balance={$acc26->balance} | currency={$acc26->currency}\n";
echo "  account #27: name={$acc27->name} | type={$acc27->type} | module_type={$acc27->module_type} | balance={$acc27->balance} | currency={$acc27->currency}\n";

// 16.3) bus_bookings profit vs income−expense (using actual columns: total_price, paid_amount)
$busProfitSum = (float) DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->sum('profit');

$busTotalPrice = (float) DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->sum('total_price');

$busPaidAmount = (float) DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->sum('paid_amount');

$busDebtSum = $busTotalPrice - $busPaidAmount;

$busProfitZero = DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->where('profit', '<=', 0)
    ->count();

$busProfitPositive = DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->where('profit', '>', 0)
    ->count();

$busAllCount = DB::table('bus_bookings')
    ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
    ->whereNull('deleted_at')
    ->count();

echo "\n  --- bus_bookings: profit column vs total_price/paid_amount ---\n";
printf("  عدد الحجوزات النشطة                : %d\n", $busAllCount);
printf("  عدد الحجوزات بـ profit > 0         : %d\n", $busProfitPositive);
printf("  عدد الحجوزات بـ profit <= 0        : %d\n", $busProfitZero);
printf("  Σ total_price (سعر البيع)          : %s EGP\n", number_format($busTotalPrice, 2));
printf("  Σ paid_amount (المدفوع)             : %s EGP\n", number_format($busPaidAmount, 2));
printf("  Σ profit (الربح المسجّل)           : %s EGP\n", number_format($busProfitSum, 2));
printf("  إجمالي الدين (total − paid)        : %s EGP\n", number_format($busDebtSum, 2));
printf("  الدخل الفعلي (income tx)           : %s EGP\n", number_format($totalIncome, 2));
printf("  المصروف الفعلي (expense tx)        : %s EGP\n", number_format($totalExpense, 2));
printf("  الصافي الفعلي (income − expense)   : %s EGP\n", number_format($totalIncome - $totalExpense, 2));
printf("  الفرق (profit column vs actual)    : %s EGP\n", number_format($busProfitSum - ($totalIncome - $totalExpense), 2));

// 16.4) P&L Breakdown — نرى revenueRows/expenseRows
echo "\n  --- P&L detailed rows (revenue) ---\n";
try {
    $plReport2 = $reportService->report(['category' => 'office']);
    if (! empty($plReport2['revenueRows'])) {
        foreach ($plReport2['revenueRows'] as $r) {
            printf("    %-30s | %s\n", $r['label'] ?? '?', number_format($r['amount'] ?? 0, 2));
        }
    } else {
        echo "    (فارغ — ده السبب إن total revenue = 0)\n";
    }
    echo "\n  --- P&L detailed rows (expense) ---\n";
    if (! empty($plReport2['expenseRows'])) {
        foreach ($plReport2['expenseRows'] as $r) {
            printf("    %-30s | %s\n", $r['label'] ?? '?', number_format($r['amount'] ?? 0, 2));
        }
    }
} catch (\Throwable $e) {
    echo "  ⚠️  P&L error: " . $e->getMessage() . "\n";
}

// 16.5) مقارنة الأرباح الحقيقية (income - expense) من transactions
$busNetActual = $totalIncome - $totalExpense;
echo "\n  --- الأرباح الفعلية حسب transactions ---\n";
printf("  Income (bus)                : %s EGP\n", number_format($totalIncome, 2));
printf("  Expense (bus+office)        : %s EGP\n", number_format($totalExpense, 2));
printf("  الصافي حسب transactions    : %s EGP\n", number_format($busNetActual, 2));
printf("  الـ profits (من P&L)        : %s EGP\n", number_format(10172.80, 2));
printf("  الفرق                      : %s EGP\n", number_format($busNetActual - 10172.80, 2));

echo "\n✅ Done. كل النتيجة في: {$logFile}\n";
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  📋 التشخيص/الخطوات الجاية:\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  1. لو [10] ghost balance > 0 → في حسابات فيها balance مش متطابق مع entries\n";
echo "  2. لو [2] unbalanced tx > 0 → في معاملات debit ≠ credit\n";
echo "  3. لو [9] gross_profits ≠ مجموع أرباح الموديولات → الـ TB بيحسب غلط\n";
echo "  4. لو [9] operating_expenses ≠ مجموع expense tx → المصروفات ناقصة/زائدة\n";
echo "  5. لو [9] variance ≠ 0 → في فلوس 'ضاعت' (المعادلة مش بتتساوى)\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
