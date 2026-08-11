<?php
/**
 * DRY-RUN — Read-only diagnostic.
 * Shows exactly which rows would be deleted if we run the real cleanup.
 * Run via:  php scripts/dryrun_delete_tx_e2e_test_data.php
 *
 * This script is 100% read-only. It performs no INSERT/UPDATE/DELETE.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n";
echo "════════════════════════════════════════════════════════════════════\n";
echo "  DRY-RUN: Identify TX-FULL-E2E-* test data (READ-ONLY)\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

$env = app()->environment();
echo "Environment:        {$env}\n";
echo "DB connection:      " . config('database.default') . "\n";
echo "DB name:            " . config('database.connections.' . config('database.default') . '.database') . "\n\n";

if ($env === 'production') {
    echo "⚠️  PRODUCTION environment — proceeding in READ-ONLY mode.\n\n";
}

// ── 1. Test customers ─────────────────────────────────────────────────────
$testCustomers = DB::table('customers')
    ->where(function ($q) {
        $q->where('full_name', 'like', 'TX-FULL-E2E-%')
          ->orWhere('phone',    'like', 'TX-FULL-E2E-%')
          ->orWhere('email',    'like', 'TX-FULL-E2E-%');
    })
    ->select('id', 'full_name', 'phone', 'email', 'module_type', 'created_at')
    ->orderBy('id')
    ->get();

echo "▸ TEST CUSTOMERS ({$testCustomers->count()}):\n";
echo "  " . str_repeat('─', 100) . "\n";
foreach ($testCustomers as $c) {
    printf("  id=%-5d | %-40s | phone=%-15s | module=%-8s | created=%s\n",
        $c->id, substr($c->full_name ?? 'NULL', 0, 40), $c->phone ?? '—',
        $c->module_type ?? 'NULL', $c->created_at);
}
echo "\n";

$testCustomerIds = $testCustomers->pluck('id')->toArray();

// ── 2. Test accounts ──────────────────────────────────────────────────────
$testAccounts = DB::table('accounts')
    ->where('name', 'like', 'TX-FULL-E2E-%')
    ->select('id', 'name', 'account_type', 'balance', 'module_type', 'created_at')
    ->orderBy('id')
    ->get();

echo "▸ TEST ACCOUNTS ({$testAccounts->count()}):\n";
echo "  " . str_repeat('─', 110) . "\n";
foreach ($testAccounts as $a) {
    printf("  id=%-5d | %-45s | type=%-12s | balance=%10.2f | module=%-8s\n",
        $a->id, substr($a->name ?? 'NULL', 0, 45), $a->account_type ?? 'NULL',
        (float)$a->balance, $a->module_type ?? 'NULL');
}
echo "\n";

$testAccountIds = $testAccounts->pluck('id')->toArray();

if (empty($testAccountIds)) {
    echo "ℹ️  No accounts named TX-FULL-E2E-* — searching for accounts owned by test customers...\n\n";

    if (!empty($testCustomerIds)) {
        $testAccounts = DB::table('accounts')
            ->whereIn('customer_id', $testCustomerIds)
            ->select('id', 'name', 'account_type', 'balance', 'module_type', 'created_at')
            ->orderBy('id')
            ->get();
        $testAccountIds = $testAccounts->pluck('id')->toArray();

        echo "▸ ACCOUNTS BELONGING TO TEST CUSTOMERS ({$testAccounts->count()}):\n";
        echo "  " . str_repeat('─', 110) . "\n";
        foreach ($testAccounts as $a) {
            printf("  id=%-5d | %-45s | type=%-12s | balance=%10.2f | module=%-8s\n",
                $a->id, substr($a->name ?? 'NULL', 0, 45), $a->account_type ?? 'NULL',
                (float)$a->balance, $a->module_type ?? 'NULL');
        }
        echo "\n";
    }
}

// ── 3. Test transactions ─────────────────────────────────────────────────
if (!empty($testAccountIds)) {
    $testTx = DB::table('transactions')
        ->where(function ($q) use ($testAccountIds) {
            $q->whereIn('from_account_id', $testAccountIds)
              ->orWhereIn('to_account_id', $testAccountIds);
        })
        ->select('id', 'from_account_id', 'to_account_id', 'amount', 'transaction_type', 'description', 'created_at')
        ->orderBy('id')
        ->get();

    echo "▸ TEST TRANSACTIONS ({$testTx->count()}):\n";
    echo "  " . str_repeat('─', 110) . "\n";
    foreach ($testTx as $t) {
        printf("  id=%-5d | %3d → %3d | amount=%10.2f | type=%-15s | desc='%s'\n",
            $t->id, $t->from_account_id ?? 0, $t->to_account_id ?? 0,
            (float)$t->amount, $t->transaction_type ?? 'NULL',
            substr($t->description ?? '', 0, 50));
    }
    echo "\n";
} else {
    $testTx = collect();
    echo "▸ TEST TRANSACTIONS: 0 (no test accounts found)\n\n";
}

// ── 4. Test bookings ─────────────────────────────────────────────────────
$tables = [
    'bus_bookings'        => ['customer_id'],
    'flight_bookings'     => ['customer_id'],
    'visa_bookings'       => ['customer_id'],
    'hajj_umra_bookings'  => ['customer_id'],
    'fawry_transactions'  => ['customer_id'],
    'online_transactions' => ['customer_id'],
];

foreach ($tables as $table => $cols) {
    if (!Schema::hasTable($table)) continue;
    if (empty($testCustomerIds)) continue;

    $q = DB::table($table)->where(function ($w) use ($cols, $testCustomerIds) {
        foreach ($cols as $c) {
            if (Schema::hasColumn($table, $c)) {
                $w->orWhereIn($c, $testCustomerIds);
            }
        }
    });

    $count = (clone $q)->count();
    if ($count > 0) {
        echo "▸ {$table} ({$count} rows):\n";
        foreach ($q->limit(20)->get() as $b) {
            $row = [];
            foreach ($cols as $c) $row[] = "{$c}=" . ($b->$c ?? 'NULL');
            $row[] = 'id=' . $b->id;
            echo "    " . implode(' | ', $row) . "\n";
        }
        echo "\n";
    }
}

// ── 5. Cash drawer (id=6) ────────────────────────────────────────────────
$cashDrawerId = 6;
$currentBalance = DB::table('accounts')->where('id', $cashDrawerId)->value('balance');

echo "▸ CASH DRAWER (id={$cashDrawerId}):\n";
echo "  current balance:  " . number_format((float)$currentBalance, 2) . " EGP\n\n";

// ── 6. Impact calculation ─────────────────────────────────────────────────
if (!empty($testAccountIds)) {
    $impact = DB::table('transactions')
        ->where(function ($q) use ($testAccountIds, $cashDrawerId) {
            $q->where(function ($a) use ($testAccountIds, $cashDrawerId) {
                $a->whereIn('from_account_id', $testAccountIds)->where('to_account_id', $cashDrawerId);
            })->orWhere(function ($b) use ($testAccountIds, $cashDrawerId) {
                $b->whereIn('to_account_id', $testAccountIds)->where('from_account_id', $cashDrawerId);
            });
        })
        ->selectRaw('
            COALESCE(SUM(CASE WHEN to_account_id = ? THEN amount ELSE 0 END), 0) AS deposits,
            COALESCE(SUM(CASE WHEN from_account_id = ? THEN amount ELSE 0 END), 0) AS withdrawals
        ', [$cashDrawerId, $cashDrawerId])
        ->first();

    $projectedBalance = (float)$currentBalance - (float)$impact->deposits + (float)$impact->withdrawals;

    echo "▸ PROJECTED IMPACT ON CASH DRAWER (id={$cashDrawerId}):\n";
    echo "  test deposits (to drawer):     " . number_format((float)$impact->deposits, 2) . " EGP\n";
    echo "  test withdrawals (from drawer): " . number_format((float)$impact->withdrawals, 2) . " EGP\n";
    echo "  ───────────────────────────────────────────────────────\n";
    echo "  current balance:               " . number_format((float)$currentBalance, 2) . " EGP\n";
    echo "  projected after delete:        " . number_format($projectedBalance, 2) . " EGP\n\n";
} else {
    echo "▸ No impact to calculate (no test accounts found)\n\n";
}

// ── 7. Other potentially-related tables ──────────────────────────────────
echo "▸ SCAN: Other tables that may reference test data\n";
echo "  " . str_repeat('─', 80) . "\n";

$otherTables = ['customer_ledger', 'account_transactions', 'audit_logs', 'activity_log'];
foreach ($otherTables as $table) {
    if (!Schema::hasTable($table)) continue;

    try {
        $cols = Schema::getColumnListing($table);
        $q = DB::table($table)->where(function ($w) use ($cols, $testCustomerIds, $testAccountIds) {
            if (in_array('customer_id', $cols) && !empty($testCustomerIds)) {
                $w->orWhereIn('customer_id', $testCustomerIds);
            }
            if (in_array('account_id', $cols) && !empty($testAccountIds)) {
                $w->orWhereIn('account_id', $testAccountIds);
            }
            if (in_array('from_account_id', $cols) && !empty($testAccountIds)) {
                $w->orWhereIn('from_account_id', $testAccountIds);
            }
            if (in_array('to_account_id', $cols) && !empty($testAccountIds)) {
                $w->orWhereIn('to_account_id', $testAccountIds);
            }
        });
        $count = $q->count();
        if ($count > 0) {
            echo "  ⚠️  {$table}: {$count} related rows\n";
        } else {
            echo "  ✓  {$table}: 0 related rows\n";
        }
    } catch (\Throwable $e) {
        echo "  ✗  {$table}: error — " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ── 8. Summary ────────────────────────────────────────────────────────────
echo "▸ SUMMARY (READ-ONLY — NO CHANGES MADE):\n";
echo "  test customers:    " . count($testCustomerIds) . "\n";
echo "  test accounts:     " . count($testAccountIds) . "\n";
echo "  test transactions: " . $testTx->count() . "\n";
echo "\n";

echo "════════════════════════════════════════════════════════════════════\n";
echo "  DRY-RUN COMPLETE — no rows were inserted, updated, or deleted\n";
echo "════════════════════════════════════════════════════════════════════\n\n";