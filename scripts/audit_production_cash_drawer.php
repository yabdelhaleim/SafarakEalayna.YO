<?php
/**
 * PRODUCTION AUDIT — Read-only diagnostic.
 * Answers: which accounts are "نقدي درج" in the UI, and what's the real balance?
 *
 * Run via SSH on production:
 *   cd /var/www/safarakealayna && php scripts/audit_production_cash_drawer.php
 *
 * This script is 100% read-only. No INSERT/UPDATE/DELETE is performed.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n";
echo "════════════════════════════════════════════════════════════════════\n";
echo "  PRODUCTION AUDIT — Cash Drawer & TX-FULL-E2E Test Data\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

$env = app()->environment();
echo "Environment: {$env}\n";
echo "DB:          " . config('database.default') . " / " . config('database.connections.' . config('database.default') . '.database') . "\n\n";

// ── 1. The specific account id=6 ────────────────────────────────────────
echo "▶ [1] Account id=6 (assumed: cash drawer)\n";
echo "  " . str_repeat('─', 90) . "\n";
$acc6 = DB::table('accounts')->where('id', 6)->first();
if ($acc6) {
    printf("    id=%d  name='%s'  type=%s  balance=%.2f  module_type=%s\n",
        $acc6->id, $acc6->name, $acc6->type ?? 'NULL',
        $acc6->balance, $acc6->module_type ?? 'NULL');
    echo "    currency: " . ($acc6->currency ?? 'NULL') . "  treasury_type: " . ($acc6->treasury_type ?? 'NULL') . "\n";
    echo "    is_active: " . ($acc6->is_active ?? 'NULL') . "  created_at: " . $acc6->created_at . "\n";
} else {
    echo "    ⚠️  Account id=6 does NOT exist!\n";
}
echo "\n";

// ── 2. Sum of all transactions on account id=6 ──────────────────────────
echo "▶ [2] Sum of all transactions on account id=6 (vs reported balance)\n";
echo "  " . str_repeat('─', 90) . "\n";
$tx6 = DB::table('transactions')
    ->where('from_account_id', 6)
    ->orWhere('to_account_id', 6)
    ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount),0) as total_in_out, 
                 COALESCE(SUM(CASE WHEN to_account_id = 6 THEN amount ELSE -amount END),0) as net_to_6')
    ->first();
echo "    tx count:    {$tx6->cnt}\n";
echo "    total in+out: " . number_format($tx6->total_in_out, 2) . "\n";
echo "    NET (in−out): " . number_format($tx6->net_to_6, 2) . "  ← this should equal reported balance\n\n";

// ── 3. Earliest + latest transactions on id=6 ───────────────────────────
echo "▶ [3] Date range of transactions on account id=6\n";
echo "  " . str_repeat('─', 90) . "\n";
$range = DB::table('transactions')
    ->where('from_account_id', 6)
    ->orWhere('to_account_id', 6)
    ->selectRaw('MIN(created_at) as first, MAX(created_at) as last')
    ->first();
echo "    first tx: " . ($range->first ?? 'NONE') . "\n";
echo "    last  tx: " . ($range->last ?? 'NONE') . "\n\n";

// ── 4. Transactions on id=6 grouped by month (buildup of the balance) ───
echo "▶ [4] Monthly buildup of account id=6\n";
echo "  " . str_repeat('─', 90) . "\n";
$monthly = DB::table('transactions')
    ->where('from_account_id', 6)
    ->orWhere('to_account_id', 6)
    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month,
                 COUNT(*) as cnt,
                 COALESCE(SUM(CASE WHEN to_account_id = 6 THEN amount ELSE 0 END),0) as deposits,
                 COALESCE(SUM(CASE WHEN from_account_id = 6 THEN amount ELSE 0 END),0) as withdrawals,
                 COALESCE(SUM(CASE WHEN to_account_id = 6 THEN amount ELSE -amount END),0) as net")
    ->groupBy('month')
    ->orderBy('month')
    ->get();
echo "    MONTH     | COUNT |   DEPOSITS  |  WITHDRAWALS |    NET       | CUMULATIVE\n";
echo "    " . str_repeat('─', 80) . "\n";
$cum = 0;
foreach ($monthly as $m) {
    $cum += (float)$m->net;
    printf("    %s | %5d | %11.2f | %12.2f | %11.2f | %12.2f\n",
        $m->month, $m->cnt, $m->deposits, $m->withdrawals, $m->net, $cum);
}
echo "\n    ↑ Look at the columns: NET should match reported balance (22,220)\n";
echo "    ↑ Look at the MONTH breakdown: when did the big jumps happen?\n\n";

// ── 5. Find accounts with name "نقدي درج" exactly ────────────────────────
echo "▶ [5] Accounts whose name matches 'نقدي درج*' or 'cash drawer*'\n";
echo "  " . str_repeat('─', 90) . "\n";
$cashAccounts = DB::table('accounts')
    ->where(function ($q) {
        $q->where('name', 'like', '%نقدي درج%')
          ->orWhere('name', 'like', '%cash drawer%')
          ->orWhere('name', 'like', '%cash_dra%');
    })
    ->select('id', 'name', 'type', 'treasury_type', 'balance', 'module_type', 'is_active', 'created_at')
    ->orderBy('id')
    ->get();
echo "    Found: " . $cashAccounts->count() . " account(s)\n";
foreach ($cashAccounts as $a) {
    printf("    id=%d | %s | type=%s | balance=%.2f | module=%s | active=%s\n",
        $a->id, $a->name, $a->type ?? '-', $a->balance,
        $a->module_type ?? '-', $a->is_active ?? '-');
}
echo "\n";

// ── 6. TX-FULL-E2E accounts (the test data) ─────────────────────────────
echo "▶ [6] TX-FULL-E2E accounts (test data)\n";
echo "  " . str_repeat('─', 90) . "\n";
$e2e = DB::table('accounts')
    ->where('name', 'like', 'TX-FULL-E2E-%')
    ->select('id', 'name', 'type', 'balance', 'module_type')
    ->orderBy('id')
    ->get();
echo "    Found: " . $e2e->count() . " account(s)\n";
$e2eIds = [];
$e2eBalanceTotal = 0;
foreach ($e2e as $a) {
    $e2eIds[] = $a->id;
    $e2eBalanceTotal += (float)$a->balance;
    printf("    id=%d | %s | balance=%.2f\n", $a->id, $a->name, $a->balance);
}
echo "    Total E2E account balances: " . number_format($e2eBalanceTotal, 2) . " EGP\n\n";

$hasE2E = !empty($e2eIds);
if (!$hasE2E) {
    echo "    ⚠️  NO accounts named TX-FULL-E2E-* found in accounts table!\n";
    echo "    The 20,000 came from somewhere else (deleted earlier, or different naming).\n";
    echo "    Need deeper investigation in [11].\n\n";
} else {
    $cashDrawerId = 6;
    $impact = DB::table('transactions')
        ->where(function ($q) use ($e2eIds, $cashDrawerId) {
            $q->where(function ($a) use ($e2eIds, $cashDrawerId) {
                $a->whereIn('from_account_id', $e2eIds)->where('to_account_id', $cashDrawerId);
            })->orWhere(function ($b) use ($e2eIds, $cashDrawerId) {
                $b->whereIn('to_account_id', $e2eIds)->where('from_account_id', $cashDrawerId);
            });
        })
        ->selectRaw('COUNT(*) as cnt,
                     COALESCE(SUM(CASE WHEN to_account_id = ? THEN amount ELSE 0 END), 0) AS deposits,
                     COALESCE(SUM(CASE WHEN from_account_id = ? THEN amount ELSE 0 END), 0) AS withdrawals',
            [$cashDrawerId, $cashDrawerId])
        ->first();

    echo "▶ [7] Impact of TX-FULL-E2E accounts on cash drawer (id=6)\n";
    echo "  " . str_repeat('─', 90) . "\n";
    echo "    tx count (E2E ↔ drawer): {$impact->cnt}\n";
    echo "    E2E deposits TO drawer:  " . number_format($impact->deposits, 2) . "\n";
    echo "    E2E withdrawals FROM drawer: " . number_format($impact->withdrawals, 2) . "\n";
    echo "    NET (deposits − withdrawals): " . number_format($impact->deposits - $impact->withdrawals, 2) . "\n\n";

    if ((int)$impact->cnt > 0) {
        echo "▶ [8] The individual TX-FULL-E2E ↔ cash drawer transactions\n";
        echo "  " . str_repeat('─', 90) . "\n";
        $rows = DB::table('transactions')
            ->where(function ($q) use ($e2eIds, $cashDrawerId) {
                $q->where(function ($a) use ($e2eIds, $cashDrawerId) {
                    $a->whereIn('from_account_id', $e2eIds)->where('to_account_id', $cashDrawerId);
                })->orWhere(function ($b) use ($e2eIds, $cashDrawerId) {
                    $b->whereIn('to_account_id', $e2eIds)->where('from_account_id', $cashDrawerId);
                });
            })
            ->select('id', 'from_account_id', 'to_account_id', 'amount', 'type', 'notes', 'created_at')
            ->orderBy('id')
            ->get();
        foreach ($rows as $t) {
            printf("    tx #%-5d | %3d → %3d | amount=%.2f | type=%s | created=%s\n",
                $t->id, $t->from_account_id ?? 0, $t->to_account_id ?? 0,
                $t->amount, $t->type, $t->created_at);
            if (!empty($t->notes)) {
                $note = mb_strimwidth((string)$t->notes, 0, 100, '...');
                echo "             notes: {$note}\n";
            }
        }
        echo "\n";
    }
}

// ── 9. Discrepancy check ────────────────────────────────────────────────
echo "▶ [9] Discrepancy check\n";
echo "  " . str_repeat('─', 90) . "\n";
$reportedBalance = (float)($acc6->balance ?? 0);
$transactionNet = (float)($tx6->net_to_6 ?? 0);
echo "    reported balance (id=6):    " . number_format($reportedBalance, 2) . "\n";
echo "    tx NET (deposits − wdr):    " . number_format($transactionNet, 2) . "\n";
$diff = $reportedBalance - $transactionNet;
echo "    difference:                " . number_format($diff, 2) . "  ";
if (abs($diff) < 0.01) {
    echo "(balance = sum(tx) — consistent)\n";
} else {
    echo "⚠️  DIFFERS — opening balance or audit discrepancy of " . number_format($diff, 2) . " EGP\n";
}
if ($hasE2E && isset($impact)) {
    $e2eNetImpact = (float)($impact->deposits ?? 0) - (float)($impact->withdrawals ?? 0);
    echo "    E2E impact on drawer:      " . number_format($e2eNetImpact, 2) . "  (will be removed)\n";
    echo "    ─────────────────────────────────────────────\n";
    echo "    If we delete E2E, balance would be:        " . number_format($reportedBalance - $e2eNetImpact, 2) . "\n";
    echo "    If we delete E2E, post-correction would be: " . number_format($transactionNet - $e2eNetImpact, 2) . "\n";
}
echo "\n";

// ── 10. Customers / bookings linked to TX-FULL-E2E accounts ─────────────
echo "▶ [10] Customers & bookings linked to TX-FULL-E2E accounts (count only)\n";
echo "  " . str_repeat('─', 90) . "\n";

$customers = DB::table('customers')
    ->where(function ($q) {
        $q->where('full_name', 'like', 'TX-FULL-E2E-%')
          ->orWhere('phone', 'like', 'TX-FULL-E2E-%')
          ->orWhere('email', 'like', 'TX-FULL-E2E-%');
    })->count();
echo "    TX-FULL-E2E customers: {$customers}\n";

foreach (['bus_bookings', 'flight_bookings', 'visa_bookings'] as $tbl) {
    if (!Schema::hasTable($tbl)) continue;
    $cnt = DB::table($tbl)->whereIn('customer_id',
        DB::table('customers')->where(function ($q) {
            $q->where('full_name', 'like', 'TX-FULL-E2E-%')
              ->orWhere('phone', 'like', 'TX-FULL-E2E-%')
              ->orWhere('email', 'like', 'TX-FULL-E2E-%');
        })->pluck('id')->toArray()
    )->count();
    echo "    {$tbl}: {$cnt} linked to E2E customers\n";
}
echo "\n";

echo "════════════════════════════════════════════════════════════════════\n";
echo "  AUDIT COMPLETE — no rows inserted, updated, or deleted\n";
echo "════════════════════════════════════════════════════════════════════\n\n";