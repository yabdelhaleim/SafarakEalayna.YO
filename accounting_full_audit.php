<?php
/**
 * ============================================================
 * تدقيق محاسبي شامل v2 — SafarakEalayna
 * يشمل: الميزان الحسابي، التقارير، الديون، المديونية،
 *        ضخ بيانات حقيقية، تحقق من كل الموديولات
 * Schema محدّث حسب قاعدة البيانات الفعلية
 * ============================================================
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Finance\AccountingService;
use App\Services\Finance\TrialBalanceExportService;
use App\Services\Reports\FinancialReportService;

// ============================================================
// مساعدات العرض
// ============================================================
$passed = 0; $failed = 0; $warnings = 0; $log = [];

function section(string $title): void {
    echo "\n" . str_repeat('=', 65) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 65) . "\n";
}
function ok(string $msg, $detail = null): void {
    global $passed, $log;
    $passed++;
    $d = $detail !== null ? " [{$detail}]" : '';
    echo "  [OK] {$msg}{$d}\n";
    $log[] = ['status' => 'PASS', 'msg' => $msg, 'detail' => $detail];
}
function fail(string $msg, $detail = null): void {
    global $failed, $log;
    $failed++;
    $d = $detail !== null ? " [{$detail}]" : '';
    echo "  [FAIL] {$msg}{$d}\n";
    $log[] = ['status' => 'FAIL', 'msg' => $msg, 'detail' => $detail];
}
function warn(string $msg, $detail = null): void {
    global $warnings, $log;
    $warnings++;
    $d = $detail !== null ? " [{$detail}]" : '';
    echo "  [WARN] {$msg}{$d}\n";
    $log[] = ['status' => 'WARN', 'msg' => $msg, 'detail' => $detail];
}
function info(string $msg): void {
    echo "  [INFO] {$msg}\n";
}

// ============================================================
// 0. الاتصال بقاعدة البيانات وفحص الجداول
// ============================================================
section('0. CONNECTIVITY & TABLE CHECK');
try {
    DB::getPdo();
    ok('MySQL connected', DB::getDatabaseName());
    $version = DB::select('SELECT VERSION() as v')[0]->v ?? 'unknown';
    info("MySQL v{$version}");

    $requiredTables = [
        'accounts', 'account_entries', 'transactions',
        'customers', 'treasuries', 'treasury_transactions',
        'flight_carriers', 'flight_bookings',
        'visa_bookings', 'bus_bookings', 'bus_companies',
        'hajj_umra_bookings', 'programs',
    ];
    foreach ($requiredTables as $tbl) {
        try {
            $cnt = DB::table($tbl)->count();
            ok("Table [{$tbl}]", "{$cnt} rows");
        } catch (\Exception $e) {
            fail("Table [{$tbl}] MISSING", $e->getMessage());
        }
    }
} catch (\Exception $e) {
    fail('DB CONNECTION FAILED', $e->getMessage());
    exit(1);
}

// ============================================================
// 1. دليل الحسابات
// ============================================================
section('1. CHART OF ACCOUNTS');
try {
    $accounts = DB::table('accounts')->whereNull('deleted_at')->get();
    $total = $accounts->count();
    $total > 0 ? ok("Total accounts: {$total}") : fail("No accounts found - run seeders!");

    $byType = $accounts->groupBy('type');
    foreach ($byType as $type => $accs) {
        info("Type [{$type}]: " . $accs->count() . " accounts | Total balance: " .
             number_format($accs->sum('balance'), 2) . " EGP");
    }

    // الأنواع المطلوبة حسب enum الفعلي
    $criticalTypes = ['bank', 'cashbox', 'revenue', 'expense', 'customer', 'supplier'];
    foreach ($criticalTypes as $type) {
        $cnt = $accounts->where('type', $type)->count();
        $cnt > 0 ? ok("Accounts type [{$type}]: {$cnt}") : warn("No accounts of type [{$type}]");
    }

    $negatives = $accounts->where('balance', '<', -0.01)->where('type', '!=', 'expense');
    $negatives->count() > 0
        ? warn("Accounts with negative balance (non-expense): " . $negatives->count())
        : ok("No unexpected negative balances");

} catch (\Exception $e) {
    fail('Chart of accounts check error', $e->getMessage());
}

// ============================================================
// 2. الميزان المحاسبي
// ============================================================
section('2. TRIAL BALANCE (MIZAAN)');
try {
    $entries = DB::table('account_entries')
        ->selectRaw('SUM(debit) as td, SUM(credit) as tc, COUNT(*) as cnt')
        ->first();

    $td = round((float)($entries->td ?? 0), 2);
    $tc = round((float)($entries->tc ?? 0), 2);
    $diff = round(abs($td - $tc), 2);

    info("Total Debit:  " . number_format($td, 2) . " EGP");
    info("Total Credit: " . number_format($tc, 2) . " EGP");
    info("Entry Count:  " . ($entries->cnt ?? 0));

    $diff <= 0.01
        ? ok("Trial balance BALANCED", "Diff: {$diff}")
        : fail("Trial balance UNBALANCED!", "Diff: {$diff} EGP");

    // مزامنة account.balance مع SUM(entries)
    $desyncs = DB::select("
        SELECT a.id, a.name, a.balance as stored_bal,
               COALESCE(SUM(ae.debit) - SUM(ae.credit), 0) as computed_bal,
               ABS(a.balance - COALESCE(SUM(ae.debit) - SUM(ae.credit), 0)) as bal_diff
        FROM accounts a
        LEFT JOIN account_entries ae ON ae.account_id = a.id
        WHERE a.deleted_at IS NULL
        GROUP BY a.id, a.name, a.balance
        HAVING bal_diff > 0.50
        ORDER BY bal_diff DESC
        LIMIT 20
    ");

    count($desyncs) === 0
        ? ok("All account balances match their entries SUM")
        : fail("Desync accounts (balance != SUM entries): " . count($desyncs));

    foreach ($desyncs as $d) {
        warn("  Account [{$d->name}] stored={$d->stored_bal} computed={$d->computed_bal} diff={$d->bal_diff}");
    }

} catch (\Exception $e) {
    fail('Trial balance error', $e->getMessage());
}

// ============================================================
// 3. التقارير المحاسبية
// ============================================================
section('3. ACCOUNTING REPORTS');
try {
    // FinancialReportService
    try {
        $svc = app(FinancialReportService::class);
        ok("FinancialReportService instantiated");
    } catch (\Exception $e) {
        fail("FinancialReportService failed", $e->getMessage());
    }

    // TrialBalanceExportService
    try {
        $tb = app(TrialBalanceExportService::class);
        ok("TrialBalanceExportService instantiated");
    } catch (\Exception $e) {
        warn("TrialBalanceExportService", $e->getMessage());
    }

    // AccountingService
    try {
        $as = app(AccountingService::class);
        ok("AccountingService instantiated");
    } catch (\Exception $e) {
        fail("AccountingService", $e->getMessage());
    }

    // إيرادات ومصروفات حقيقية
    $txStats = DB::table('transactions')
        ->selectRaw("type, module, COUNT(*) as cnt, SUM(amount) as total")
        ->groupBy('type', 'module')
        ->get();

    $totalRevenue = $txStats->whereIn('type', ['income', 'revenue'])->sum('total');
    $totalExpense = $txStats->where('type', 'expense')->sum('total');
    $net = $totalRevenue - $totalExpense;

    info("Total Revenue: " . number_format($totalRevenue, 2) . " EGP");
    info("Total Expense: " . number_format($totalExpense, 2) . " EGP");
    info("Net P&L:       " . number_format($net, 2) . " EGP " . ($net >= 0 ? "(PROFIT)" : "(LOSS)"));

    $net >= 0 ? ok("Company is profitable", number_format($net, 2) . " EGP") : warn("Company shows loss", number_format(abs($net), 2) . " EGP");

} catch (\Exception $e) {
    fail('Reports check error', $e->getMessage());
}

// ============================================================
// 4. الديون والمديونية
// ============================================================
section('4. RECEIVABLES & PAYABLES (DEYON & MADYOUNIYA)');
try {
    // حسابات العملاء
    $custAccs = DB::table('accounts')->where('type', 'customer')->whereNull('deleted_at')->get();
    ok("Customer accounts: " . $custAccs->count());

    $posBalance = $custAccs->where('balance', '>', 0)->sum('balance');
    $negBalance = $custAccs->where('balance', '<', 0)->sum('balance');
    info("Customer credit balances (مستحقاتهم): " . number_format($posBalance, 2) . " EGP");
    info("Customer debit balances (مديونيتهم): " . number_format(abs($negBalance), 2) . " EGP");

    // حسابات الموردين
    $suppAccs = DB::table('accounts')->where('type', 'supplier')->whereNull('deleted_at')->get();
    ok("Supplier accounts: " . $suppAccs->count());
    info("Supplier total balance: " . number_format($suppAccs->sum('balance'), 2) . " EGP");

    // حجوزات الطيران غير مدفوعة
    $flightUnpaid = DB::table('flight_bookings')
        ->whereIn('status', ['PENDING', 'pending', 'CONFIRMED', 'confirmed'])
        ->whereNull('deleted_at')
        ->selectRaw('COUNT(*) as cnt, SUM(selling_price) as total, SUM(profit) as profit')
        ->first();
    info("Active flight bookings: " . ($flightUnpaid->cnt ?? 0) .
         " | Revenue: " . number_format($flightUnpaid->total ?? 0, 2) .
         " | Profit: " . number_format($flightUnpaid->profit ?? 0, 2));

    // حجوزات الفيزا
    $visaActive = DB::table('visa_bookings')
        ->whereNotIn('status', ['cancelled', 'refunded'])
        ->whereNull('deleted_at')
        ->selectRaw('COUNT(*) as cnt, SUM(selling_price) as total, SUM(profit) as profit')
        ->first();
    info("Active visa bookings: " . ($visaActive->cnt ?? 0) .
         " | Revenue: " . number_format($visaActive->total ?? 0, 2) .
         " | Profit: " . number_format($visaActive->profit ?? 0, 2));

    // حجوزات الباص
    $busActive = DB::table('bus_bookings')
        ->whereNotIn('status', ['cancelled', 'refunded'])
        ->whereNull('deleted_at')
        ->selectRaw('COUNT(*) as cnt, SUM(total_price) as total, SUM(paid_amount) as paid')
        ->first();
    $busDebt = ($busActive->total ?? 0) - ($busActive->paid ?? 0);
    info("Active bus bookings: " . ($busActive->cnt ?? 0) .
         " | Total: " . number_format($busActive->total ?? 0, 2) .
         " | Paid: " . number_format($busActive->paid ?? 0, 2) .
         " | Outstanding: " . number_format($busDebt, 2));

    $busDebt > 0
        ? warn("Bus outstanding debt: " . number_format($busDebt, 2) . " EGP")
        : ok("No outstanding bus debt");

    // حجوزات الحج والعمرة
    $hajjActive = DB::table('hajj_umra_bookings')
        ->whereNotIn('status', ['cancelled', 'refunded'])
        ->whereNull('deleted_at')
        ->selectRaw('COUNT(*) as cnt, SUM(selling_price) as total, SUM(profit) as profit')
        ->first();
    info("Active hajj/umra bookings: " . ($hajjActive->cnt ?? 0) .
         " | Revenue: " . number_format($hajjActive->total ?? 0, 2) .
         " | Profit: " . number_format($hajjActive->profit ?? 0, 2));

} catch (\Exception $e) {
    fail('Receivables/Payables error', $e->getMessage());
}

// ============================================================
// 5. الخزائن
// ============================================================
section('5. TREASURIES');
try {
    // treasuries has no deleted_at, balance column is current_balance
    // accounts table has no owner_id — match by treasury name in account name
    $treas = DB::table('treasuries')->get();
    ok("Treasuries count: " . $treas->count());

    foreach ($treas as $t) {
        // Treasury GL account is matched by name convention or treasury_type field
        $accBalance = DB::table('accounts')
            ->whereNull('deleted_at')
            ->where(function($q) use ($t) {
                $q->where('name', 'like', '%' . $t->name . '%')
                  ->orWhere('treasury_type', $t->name);
            })
            ->whereIn('type', ['cashbox', 'bank', 'wallet'])
            ->value('balance');

        $tBalance = $t->current_balance ?? 0;

        // اعتبر الخزينة OK إذا لم يوجد حساب GL مرتبط (الربط اختياري)
        if ($accBalance === null) {
            info("Treasury [{$t->name}] — No GL account linked (standalone)",
                 number_format($tBalance, 2) . ' ' . ($t->currency ?? 'EGP'));
        } else {
            $diff = round(abs($accBalance - $tBalance), 2);
            $diff <= 0.01
                ? ok("Treasury [{$t->name}] IN-SYNC",
                     number_format($tBalance, 2) . " " . ($t->currency ?? 'EGP'))
                : fail("Treasury [{$t->name}] DESYNC!",
                       "treasury={$tBalance} | GL={$accBalance} | diff={$diff}");
        }
    }

    $totalLiquidity = DB::table('accounts')
        ->whereIn('type', ['cashbox', 'bank'])
        ->whereNull('deleted_at')
        ->selectRaw("type, SUM(balance) as total, currency")
        ->groupBy('type', 'currency')
        ->get();

    foreach ($totalLiquidity as $row) {
        info("Liquidity [{$row->type}/{$row->currency}]: " . number_format($row->total, 2));
    }

} catch (\Exception $e) {
    fail('Treasuries check error', $e->getMessage());
}

// ============================================================
// 6. موديول الطيران
// ============================================================
section('6. FLIGHT MODULE');
try {
    $carriers = DB::table('flight_carriers')->whereNull('deleted_at')->get();
    $systems  = DB::table('flight_systems')->whereNull('deleted_at')->count();
    ok("Flight carriers: " . $carriers->count());
    ok("Flight systems: {$systems}");

    $fStats = DB::table('flight_bookings')
        ->whereNull('deleted_at')
        ->selectRaw('status, COUNT(*) as cnt, SUM(selling_price) as rev, SUM(profit) as profit')
        ->groupBy('status')
        ->get();
    foreach ($fStats as $s) {
        info("Flight [{$s->status}]: {$s->cnt} | Revenue: " .
             number_format($s->rev ?? 0, 2) . " | Profit: " .
             number_format($s->profit ?? 0, 2));
    }

    // فحص desync الناقلين
    // accounts has no owner_id — match by name convention 'رصيد مسبق — {carrier name}'
    $carrierDesyncs = 0;
    foreach ($carriers as $c) {
        $accBal = DB::table('accounts')
            ->whereNull('deleted_at')
            ->where('name', 'رصيد مسبق — ' . $c->name)
            ->value('balance');
        if ($accBal !== null) {
            $diff = round(abs(($c->balance ?? 0) - $accBal), 2);
            if ($diff > 1.00) {
                $carrierDesyncs++;
                warn("Carrier [{$c->name}] DESYNC", "carrier={$c->balance} | GL={$accBal} | diff={$diff}");
            } else {
                info("Carrier [{$c->name}] SYNCED", number_format($c->balance, 2) . ' EGP');
            }
        } else {
            info("Carrier [{$c->name}] no GL account found");
        }
    }
    $carrierDesyncs === 0
        ? ok("All carrier balances synced with GL")
        : fail("{$carrierDesyncs} carrier(s) out of sync with GL");

} catch (\Exception $e) {
    fail('Flight module error', $e->getMessage());
}

// ============================================================
// 7. موديول الفيزا
// ============================================================
section('7. VISA MODULE');
try {
    $vStats = DB::table('visa_bookings')
        ->whereNull('deleted_at')
        ->selectRaw('status, COUNT(*) as cnt, SUM(selling_price) as rev, SUM(profit) as profit')
        ->groupBy('status')
        ->get();

    ok("Visa booking statuses: " . $vStats->count());
    foreach ($vStats as $s) {
        info("Visa [{$s->status}]: {$s->cnt} | Revenue: " .
             number_format($s->rev ?? 0, 2) . " | Profit: " .
             number_format($s->profit ?? 0, 2));
    }

    $vpCount = DB::table('visa_payments')->count();
    ok("Visa payments: {$vpCount}");

} catch (\Exception $e) {
    fail('Visa module error', $e->getMessage());
}

// ============================================================
// 8. موديول الباص
// ============================================================
section('8. BUS MODULE');
try {
    $busCompanies = DB::table('bus_companies')->count();
    $busRoutes    = 0;
    try { $busRoutes = DB::table('bus_routes')->count(); } catch (\Exception $e) {}
    $busInventory = DB::table('bus_inventories')->count();

    ok("Bus companies: {$busCompanies}");
    ok("Bus inventory entries: {$busInventory}");

    $bStats = DB::table('bus_bookings')
        ->whereNull('deleted_at')
        ->selectRaw('status, payment_status, COUNT(*) as cnt, SUM(total_price) as total, SUM(paid_amount) as paid, SUM(profit) as profit')
        ->groupBy('status', 'payment_status')
        ->get();

    ok("Bus booking groups: " . $bStats->count());
    foreach ($bStats as $s) {
        info("Bus [{$s->status}/{$s->payment_status}]: {$s->cnt} | Total: " .
             number_format($s->total ?? 0, 2) . " | Paid: " .
             number_format($s->paid ?? 0, 2) . " | Profit: " .
             number_format($s->profit ?? 0, 2));
    }

    $busTickets = DB::table('bus_tickets')->count();
    info("Bus tickets: {$busTickets}");

    $companyPayments = DB::table('bus_company_payments')->count();
    info("Bus company payments: {$companyPayments}");

    $refunds = DB::table('bus_refund_requests')->count();
    info("Bus refund requests: {$refunds}");

} catch (\Exception $e) {
    fail('Bus module error', $e->getMessage());
}

// ============================================================
// 9. موديول الحج والعمرة
// ============================================================
section('9. HAJJ & UMRA MODULE');
try {
    // الأعمدة الصحيحة من DESCRIBE: لا يوجد type، لكن module موجود
    $hStats = DB::table('hajj_umra_bookings')
        ->whereNull('deleted_at')
        ->selectRaw('module, status, COUNT(*) as cnt, SUM(selling_price) as rev, SUM(profit) as profit')
        ->groupBy('module', 'status')
        ->get();

    ok("Hajj/Umra booking groups: " . $hStats->count());
    foreach ($hStats as $s) {
        info("[{$s->module}/{$s->status}]: {$s->cnt} | Revenue: " .
             number_format($s->rev ?? 0, 2) . " | Profit: " .
             number_format($s->profit ?? 0, 2));
    }

    $programs = DB::table('programs')->count();
    ok("Programs: {$programs}");

} catch (\Exception $e) {
    fail('Hajj/Umra module error', $e->getMessage());
}

// ============================================================
// 10. ضخ بيانات حقيقية + التحقق + Rollback
// ============================================================
section('10. REAL DATA INJECTION TEST (with Rollback)');
DB::beginTransaction();
try {
    $ts = now()->format('His');

    // أ. إنشاء عميل
    $customerId = DB::table('customers')->insertGetId([
        'full_name'     => "Test Customer {$ts}",
        'phone'         => '0100' . rand(1000000, 9999999),
        'email'         => "test{$ts}@audit.com",
        'nationality'   => 'EG',
        'gender'        => 'male',
        'status'        => 'active',
        'type'          => 'individual',
        'customer_tier' => 'STANDARD',
        'total_spent'   => 0,
        'bookings_count'=> 0,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    ok("Customer created", "ID: {$customerId}");

    // ب. إيجاد حسابات cashbox و revenue
    $cashboxAcc = DB::table('accounts')->where('type', 'cashbox')->whereNull('deleted_at')->first();
    $revenueAcc = DB::table('accounts')->where('type', 'revenue')->whereNull('deleted_at')->first();
    $expenseAcc = DB::table('accounts')->where('type', 'expense')->whereNull('deleted_at')->first();

    if (!$cashboxAcc) {
        // إنشاء حساب cashbox تجريبي
        $cashboxId = DB::table('accounts')->insertGetId([
            'name'       => "Cashbox Test {$ts}",
            'type'       => 'cashbox',
            'currency'   => 'EGP',
            'balance'    => 100000.00,
            'is_active'  => 1,
            'owner_type' => 'owner',
            'module_type'=> 'tourism',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashboxAcc = DB::table('accounts')->find($cashboxId);
        ok("Cashbox account CREATED", "ID: {$cashboxId}");
    } else {
        ok("Cashbox account EXISTS", "#{$cashboxAcc->id} bal={$cashboxAcc->balance}");
    }

    if (!$revenueAcc) {
        $revenueId = DB::table('accounts')->insertGetId([
            'name'       => "Revenue Test {$ts}",
            'type'       => 'revenue',
            'currency'   => 'EGP',
            'balance'    => 0.00,
            'is_active'  => 1,
            'owner_type' => 'owner',
            'module_type'=> 'tourism',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revenueAcc = DB::table('accounts')->find($revenueId);
        ok("Revenue account CREATED", "ID: {$revenueId}");
    } else {
        ok("Revenue account EXISTS", "#{$revenueAcc->id} bal={$revenueAcc->balance}");
    }

    // ج. قيد محاسبي متوازن: دائن نقدية ← مدين إيراد (بيع تذكرة)
    $saleAmount = 5750.00;
    $txId = DB::table('transactions')->insertGetId([
        'type'            => 'income',
        'amount'          => $saleAmount,
        'module'          => 'flight',
        'from_account_id' => $revenueAcc->id,
        'to_account_id'   => $cashboxAcc->id,
        'notes'           => "AUDIT TEST - Flight ticket sale",
        'created_by'      => 1,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    ok("Transaction created", "TX#{$txId} amount={$saleAmount}");

    // قيد الخزينة (credit + for cashbox)
    $newCashBal = round((float)$cashboxAcc->balance + $saleAmount, 2);
    DB::table('account_entries')->insert([
        'account_id'     => $cashboxAcc->id,
        'transaction_id' => $txId,
        'debit'          => 0,
        'credit'         => $saleAmount,
        'balance_after'  => $newCashBal,
        'notes'          => "Flight sale - cash in",
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    DB::table('accounts')->where('id', $cashboxAcc->id)->update(['balance' => $newCashBal]);

    // قيد الإيراد (debit - for revenue clearing)
    $newRevBal = round((float)$revenueAcc->balance - $saleAmount, 2);
    DB::table('account_entries')->insert([
        'account_id'     => $revenueAcc->id,
        'transaction_id' => $txId,
        'debit'          => $saleAmount,
        'credit'         => 0,
        'balance_after'  => $newRevBal,
        'notes'          => "Flight sale - revenue posted",
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    DB::table('accounts')->where('id', $revenueAcc->id)->update(['balance' => $newRevBal]);
    ok("Double-entry posted", "Cashbox +{$saleAmount} | Revenue -{$saleAmount}");

    // تحقق التوازن
    $entryCheck = DB::table('account_entries')
        ->where('transaction_id', $txId)
        ->selectRaw('SUM(debit) as d, SUM(credit) as c')
        ->first();
    $entryDiff = round(abs(($entryCheck->d ?? 0) - ($entryCheck->c ?? 0)), 2);
    $entryDiff <= 0.01
        ? ok("Entry BALANCED: debit=credit={$saleAmount}")
        : fail("Entry UNBALANCED! diff={$entryDiff}");

    // د. إنشاء حجز فيزا تجريبي
    $visaDetail = DB::table('visa_details')->whereNull('deleted_at')->first();
    if ($visaDetail) {
        $vbId = DB::table('visa_bookings')->insertGetId([
            'customer_id'  => $customerId,
            'visa_detail_id'  => $visaDetail->id,
            'module'       => 'VISA',
            'purchase_price'  => 3000.00,
            'selling_price'   => 3500.00,
            'profit'       => 500.00,
            'currency'     => 'EGP',
            'status'       => 'pending',
            'agent_name'   => 'AUDIT TEST',
            'created_by'   => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        ok("Visa booking created", "ID: {$vbId}");
    } else {
        warn("No visa_detail found - skip visa booking injection");
    }

    // هـ. إنشاء حجز حج/عمرة تجريبي
    $program = DB::table('programs')->whereNull('deleted_at')->first();
    if ($program) {
        $hjId = DB::table('hajj_umra_bookings')->insertGetId([
            'customer_id'        => $customerId,
            'program_id'         => $program->id,
            'module'             => 'HAJJ_UMRA',
            'purchase_price'     => 45000.00,
            'companion_purchase_price' => 0,
            'selling_price'      => 50000.00,
            'companion_selling_price'  => 0,
            'profit'             => 5000.00,
            'currency'           => 'EGP',
            'per_person'         => 1,
            'accommodation_choice' => 'standard',
            'accommodation_extra_charge' => 0,
            'status'             => 'pending',
            'agent_name'         => 'AUDIT TEST',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        ok("Hajj/Umra booking created", "ID: {$hjId}");
    } else {
        warn("No program found - skip hajj booking injection");
    }

    // و. إنشاء باص تجريبي
    $busInv = DB::table('bus_inventories')->first();
    if ($busInv) {
        $bbId = DB::table('bus_bookings')->insertGetId([
            'inventory_id'  => $busInv->id,
            'customer_id'   => $customerId,
            'quantity'      => 2,
            'unit_price'    => 150.00,
            'total_price'   => 300.00,
            'paid_amount'   => 150.00,
            'payment_status'=> 'partial',
            'profit'        => 50.00,
            'status'        => 'pending',
            'currency'      => 'EGP',
            'exchange_rate_to_egp' => 1.000000,
            'created_by'    => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        ok("Bus booking created", "ID: {$bbId} | Total=300 | Paid=150 | Outstanding=150");
    } else {
        warn("No bus_inventory found - skip bus booking injection");
    }

    // الإجمالي بعد الضخ
    info("--- State after injection (before rollback) ---");
    $newCashBalCheck = DB::table('accounts')->where('id', $cashboxAcc->id)->value('balance');
    info("Cashbox balance after: " . number_format($newCashBalCheck, 2) . " EGP");

    DB::rollBack();
    ok("ROLLBACK SUCCESS - database clean");

} catch (\Exception $e) {
    DB::rollBack();
    fail('Data injection error', $e->getMessage());
}

// ============================================================
// 11. ضخ بيانات حقيقية DURABLE (بدون rollback) — فقط إذا DB فارغة
// ============================================================
section('11. DURABLE SEED (only if DB is empty)');
try {
    $existingCustomers = DB::table('customers')->count();
    $existingBookings  = DB::table('flight_bookings')->count() +
                         DB::table('visa_bookings')->count() +
                         DB::table('bus_bookings')->count();

    if ($existingCustomers > 0 || $existingBookings > 0) {
        ok("DB has existing data — skipping durable seed", 
           "{$existingCustomers} customers, {$existingBookings} bookings");
    } else {
        warn("DB is EMPTY — consider running: php artisan db:seed");
        
        // تحقق من وجود seeders
        $seederDir = __DIR__ . '/database/seeders';
        $seeders = glob($seederDir . '/*.php');
        info("Available seeders: " . count($seeders));
        foreach ($seeders as $s) {
            info("  " . basename($s));
        }
    }

    // حالة الحسابات الثلاثة الحالية
    $currentAccounts = DB::table('accounts')->whereNull('deleted_at')->get();
    foreach ($currentAccounts as $acc) {
        info("Account #{$acc->id} [{$acc->type}] '{$acc->name}' balance=" . 
             number_format($acc->balance, 2) . " {$acc->currency}");
    }

} catch (\Exception $e) {
    fail('Durable seed check error', $e->getMessage());
}

// ============================================================
// 12. تحقق القيد المزدوج الكامل
// ============================================================
section('12. DOUBLE-ENTRY INTEGRITY');
try {
    $unbalanced = DB::select("
        SELECT t.id, t.type, t.module, t.amount,
               COALESCE(SUM(ae.credit),0) as sum_credit,
               COALESCE(SUM(ae.debit),0) as sum_debit,
               ABS(COALESCE(SUM(ae.credit),0) - COALESCE(SUM(ae.debit),0)) as imbalance
        FROM transactions t
        LEFT JOIN account_entries ae ON ae.transaction_id = t.id
        GROUP BY t.id, t.type, t.module, t.amount
        HAVING imbalance > 0.50
        ORDER BY imbalance DESC
        LIMIT 20
    ");

    count($unbalanced) === 0
        ? ok("All transactions are BALANCED (double-entry OK)")
        : fail("Unbalanced transactions: " . count($unbalanced));

    foreach ($unbalanced as $tx) {
        warn("TX#{$tx->id} [{$tx->module}] cr={$tx->sum_credit} dr={$tx->sum_debit} diff={$tx->imbalance}");
    }

    $noEntries = DB::selectOne("
        SELECT COUNT(*) as cnt FROM transactions t
        WHERE NOT EXISTS (SELECT 1 FROM account_entries ae WHERE ae.transaction_id = t.id)
    ");
    $noEntriesCnt = $noEntries->cnt ?? 0;
    $noEntriesCnt === 0
        ? ok("No transactions without entries")
        : warn("Transactions without entries: {$noEntriesCnt}");

} catch (\Exception $e) {
    fail('Double-entry check error', $e->getMessage());
}

// ============================================================
// 13. P&L Report per Module
// ============================================================
section('13. PROFIT & LOSS REPORT BY MODULE');
try {
    $modules = ['flight','visa','bus','hajj','general','online','fawry'];
    $grandRev = 0; $grandExp = 0;

    echo "\n  " . str_pad('Module', 12) . str_pad('Revenue', 16) . str_pad('Expense', 16) . "Net\n";
    echo "  " . str_repeat('-', 60) . "\n";

    foreach ($modules as $mod) {
        $rev = DB::table('transactions')->where('module', $mod)
                  ->whereIn('type', ['income','revenue'])->sum('amount') ?? 0;
        $exp = DB::table('transactions')->where('module', $mod)
                  ->where('type', 'expense')->sum('amount') ?? 0;
        $net = $rev - $exp;
        $grandRev += $rev; $grandExp += $exp;
        echo "  " . str_pad($mod, 12) . str_pad(number_format($rev, 0), 16) .
             str_pad(number_format($exp, 0), 16) . number_format($net, 0) . "\n";
    }
    echo "  " . str_repeat('=', 60) . "\n";
    $grandNet = $grandRev - $grandExp;
    echo "  " . str_pad('TOTAL', 12) . str_pad(number_format($grandRev, 0), 16) .
         str_pad(number_format($grandExp, 0), 16) . number_format($grandNet, 0) . " EGP\n";

    ok("P&L report generated successfully");

    // تقارير الحجوزات الإجمالية
    echo "\n  --- Booking P&L from booking tables ---\n";
    $modules2 = [
        'flight_bookings'    => ['profit'],
        'visa_bookings'      => ['profit'],
        'bus_bookings'       => ['profit', 'total_price'],  // bus has total_price not selling_price
        'hajj_umra_bookings' => ['profit'],
    ];
    foreach ($modules2 as $tbl => $cols) {
        $profCol = $cols[0];
        $revCol  = isset($cols[1]) ? $cols[1] : 'selling_price';
        try {
            $result = DB::table($tbl)->whereNull('deleted_at')
                ->selectRaw("COUNT(*) as cnt, SUM({$profCol}) as total_profit, SUM({$revCol}) as revenue")
                ->first();
            info("{$tbl}: {$result->cnt} bookings | Revenue: " .
                 number_format($result->revenue ?? 0, 2) . " | Profit: " .
                 number_format($result->total_profit ?? 0, 2));
        } catch (\Exception $e) {
            warn("{$tbl}: " . $e->getMessage());
        }
    }

} catch (\Exception $e) {
    fail('P&L report error', $e->getMessage());
}

// ============================================================
// 14. فحص Seeders والهيكل الكامل
// ============================================================
section('14. SEEDERS & INFRASTRUCTURE CHECK');
try {
    // فحص أن كل Seeders موجودة ومكتملة
    $seederDir = __DIR__ . '/database/seeders';
    $seeders = array_map('basename', glob($seederDir . '/*.php') ?: []);
    ok("Seeders found: " . count($seeders));

    $criticalSeeders = ['DatabaseSeeder.php', 'AccountSeeder.php'];
    foreach ($criticalSeeders as $seeder) {
        in_array($seeder, $seeders)
            ? ok("Seeder [{$seeder}] exists")
            : warn("Seeder [{$seeder}] missing");
    }

    // فحص migrations الحالة
    $migStatus = DB::table('migrations')->orderBy('batch', 'desc')->limit(5)->get();
    ok("Last migrations: " . $migStatus->count());
    foreach ($migStatus as $m) {
        info("Migration: {$m->migration} (batch {$m->batch})");
    }

    // التحقق من الإعدادات المحاسبية
    $strictDoubleEntry = config('accounting.strict_double_entry', 'not configured');
    info("Config accounting.strict_double_entry: " . var_export($strictDoubleEntry, true));

    $allowLegacy = config('accounting.allow_legacy_single_leg_fallback', 'not configured');
    info("Config accounting.allow_legacy_single_leg_fallback: " . var_export($allowLegacy, true));

    // فحص queue jobs
    $pendingJobs = 0;
    try {
        $pendingJobs = DB::table('jobs')->count();
    } catch (\Exception $e) {}
    info("Pending queue jobs: {$pendingJobs}");

} catch (\Exception $e) {
    fail('Infrastructure check error', $e->getMessage());
}

// ============================================================
// 15. FINAL SUMMARY
// ============================================================
section('15. FINAL SUMMARY');

$total = $passed + $failed + $warnings;
$rate  = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n";
echo "  +--------------------------------------------+\n";
echo "  |   SafarakEalayna Accounting Audit Report   |\n";
echo "  +--------------------------------------------+\n";
echo "  |  PASSED:   " . str_pad($passed, 5) . "                          |\n";
echo "  |  FAILED:   " . str_pad($failed, 5) . "                          |\n";
echo "  |  WARNINGS: " . str_pad($warnings, 5) . "                          |\n";
echo "  |  TOTAL:    " . str_pad($total, 5) . "                          |\n";
echo "  |  SUCCESS:  {$rate}%                         |\n";
echo "  +--------------------------------------------+\n\n";

if ($failed === 0 && $warnings <= 3) {
    echo "  >> SYSTEM READY FOR PRODUCTION\n";
} elseif ($failed === 0) {
    echo "  >> SYSTEM OPERATIONAL - Review warnings\n";
} else {
    echo "  >> {$failed} CRITICAL ISSUES - Fix before production!\n";
}

$elapsed = round(microtime(true) - LARAVEL_START, 2);
echo "\n  Time: {$elapsed}s | Date: " . now()->format('Y-m-d H:i:s') . "\n";

// حفظ JSON
$outFile = __DIR__ . '/ACCOUNTING_AUDIT_v2_' . now()->format('Ymd_His') . '.json';
file_put_contents($outFile, json_encode([
    'audit_date' => now()->toIso8601String(),
    'passed' => $passed, 'failed' => $failed, 'warnings' => $warnings,
    'success_rate' => $rate,
    'log' => $log,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n  Report saved: {$outFile}\n\n";

exit($failed > 0 ? 1 : 0);
