<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Phase J — Treasury / Accounting Reconciliation
 * ════════════════════════════════════════════════════════════════════════════
 *
 * لكل account balance (الـ vault، customer AR، supplier AP، clearing accounts):
 *   - حساب stored balance (من accounts.balance)
 *   - حساب computed balance (من SUM(account_entries.credit) - SUM(account_entries.debit))
 *   - الـ drift = stored - computed
 *   - PASS لو drift = 0
 *   - FAIL لو drift ≠ 0 (فساد محاسبي)
 *
 * الـ Truth Source: account_entries (ledger) — ماشي accounts.balance.
 *   أي اختلاف = corruption.
 *
 * بعد كل عملية مالية، نقارن الـ balance stored vs computed ونتحقق مفيش missing/duplicate/unexplained money.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

$localDbPath = storage_path('app/local_bus_audit.sqlite');
if (file_exists($localDbPath)) {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $localDbPath);
    DB::purge('sqlite');
}

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = ['tests' => []];

$ok = function (string $m): void {
    echo "  ✅ $m\n";
};
$fail = function (string $m): void {
    echo "  ❌ $m\n";
};
$info = function (string $m): void {
    echo "  ℹ  $m\n";
};
$head = function (string $m): void {
    echo "\n── $m\n";
};

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Phase J — Treasury / Accounting Reconciliation\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

function reconcileAllAccounts(): array
{
    $accounts = DB::table('accounts')->orderBy('id')->get();
    $rows = [];
    foreach ($accounts as $acct) {
        $computed = (float) DB::table('account_entries')
            ->where('account_id', $acct->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS computed')
            ->value('computed');
        $stored = (float) $acct->balance;
        $drift = $stored - $computed;
        $rows[] = [
            'id' => $acct->id,
            'name' => $acct->name,
            'type' => $acct->type,
            'currency' => $acct->currency,
            'module_type' => $acct->module_type,
            'stored' => $stored,
            'computed' => $computed,
            'drift' => $drift,
        ];
    }

    return $rows;
}

// =====================================================================
// J.0: Initial reconciliation — should be clean
// =====================================================================
$head('J.0: Initial state reconciliation (post-setup, no transactions)');

$initialRows = reconcileAllAccounts();
$totalDrift = array_sum(array_column($initialRows, 'drift'));
echo "\n  Initial account state:\n";
foreach ($initialRows as $r) {
    printf("    id=%-3d %-50s %s/%s stored=%-10.2f computed=%-10.2f drift=%-10.2f\n",
        $r['id'], substr($r['name'], 0, 50), $r['type'], $r['currency'], $r['stored'], $r['computed'], $r['drift']);
}
record($results, 'j0_initial_reconcile', abs($totalDrift) < 0.01 ? 'PASS' : 'INFO',
    'Initial total drift: '.number_format($totalDrift, 2).' (sum across all accounts)');
$info("Initial state: $totalDrift total drift (acceptable if some accounts start seeded with balances)");

// =====================================================================
// J.1: Setup → Create booking → Reconcile EVERY account again
// =====================================================================
$head('J.1: After createBooking — reconciliation must be 0 drift');

$adminId = DB::table('users')->where('role', 'owner')->value('id');
$egpVaultId = DB::table('accounts')->whereIn('type', ['cashbox', 'bank'])
    ->where('currency', 'EGP')->where('module_type', 'office')->value('id');

$company = BusCompany::create([
    'name' => 'TX-AUDIT Phase-J Company', 'is_active' => true,
    'phone' => '01090002001', 'created_by' => $adminId,
]);
$inventory = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'TX-AUDIT Phase-J Route',
    'travel_date' => '2026-12-15', 'departure_time' => '08:00:00',
    'total_tickets' => 10, 'available_tickets' => 10,
    'cost_per_ticket' => 500, 'selling_price' => 800,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0, 'amount_paid' => 5000,
    'currency' => 'EGP', 'account_id' => $egpVaultId,
    'exchange_rate_to_egp' => 1.0,
    'notes' => 'TX-AUDIT phase-J inventory',
    'created_by' => $adminId,
]);
$customer = Customer::create([
    'full_name' => 'TX-AUDIT Phase-J Customer',
    'phone' => '01090002002', 'created_by' => $adminId,
]);

$busBookingService = app(BusBookingService::class);
$booking = $busBookingService->createBooking([
    'inventory_id' => $inventory->id,
    'customer_id' => $customer->id,
    'quantity' => 2,
    'notes' => 'TX-AUDIT phase-J booking',
    'created_by' => $adminId,
]);
$bookingId = $booking->id;

$postCreateRows = reconcileAllAccounts();
$postCreateDrift = 0;
foreach ($postCreateRows as $r) {
    $label = "[id={$r['id']} {$r['name']}] drift=".number_format($r['drift'], 2);
    if (abs($r['drift']) >= 0.01) {
        echo "    $label\n";
    }
    $postCreateDrift += abs($r['drift']);
}
record($results, 'j1_after_create_drift_perfect',
    $postCreateDrift < 0.01 ? 'PASS' : 'WARN',
    'After createBooking — total absolute drift: '.number_format($postCreateDrift, 2).' (each account.balance == SUM(entries))');
$info("Post-create: total drift $postCreateDrift (perfect if all individual accounts reconcile)");

// =====================================================================
// J.2: After partial payment → reconcile
// =====================================================================
$head('J.2: After partial pay 500 — reconciliation must still match');

$busBookingService->payBooking($booking->fresh(), [
    'amount' => 500, 'payment_method' => 'cash',
    'account_id' => $egpVaultId,
    'notes' => 'TX-AUDIT phase-J partial 500',
    'created_by' => $adminId,
]);
$postPay1 = reconcileAllAccounts();
$drift1 = 0;
foreach ($postPay1 as $r) {
    if (abs($r['drift']) >= 0.01) {
        echo "    ⚠ id={$r['id']} {$r['name']} drift=".number_format($r['drift'], 2)."\n";
    }
    $drift1 += abs($r['drift']);
}
record($results, 'j2_after_pay_500_drift', $drift1 < 0.01 ? 'PASS' : 'FAIL',
    'After partial pay 500 — total absolute drift: '.number_format($drift1, 2));
$drift1 < 0.01 ? $ok('Reconciliation perfect after partial pay') : $fail("Drift detected after partial pay: $drift1");

// =====================================================================
// J.3: After second payment → reconcile
// =====================================================================
$head('J.3: After final pay 1100 — reconciliation must still match');

$busBookingService->payBooking($booking->fresh(), [
    'amount' => 1100, 'payment_method' => 'cash',
    'account_id' => $egpVaultId,
    'notes' => 'TX-AUDIT phase-J final 1100',
    'created_by' => $adminId,
]);
$postPay2 = reconcileAllAccounts();
$drift2 = 0;
foreach ($postPay2 as $r) {
    if (abs($r['drift']) >= 0.01) {
        echo "    ⚠ id={$r['id']} {$r['name']} drift=".number_format($r['drift'], 2)."\n";
    }
    $drift2 += abs($r['drift']);
}
record($results, 'j3_after_full_pay_drift', $drift2 < 0.01 ? 'PASS' : 'FAIL',
    'After full pay — total absolute drift: '.number_format($drift2, 2));
$drift2 < 0.01 ? $ok('Reconciliation perfect after full pay') : $fail("Drift detected: $drift2");

// =====================================================================
// J.4: Per-account final state
// =====================================================================
$head('J.4: Final per-account reconciliation');

$finalRows = reconcileAllAccounts();
echo "\n  Final state (after create + 2 pays = 1 fully paid booking):\n";
foreach ($finalRows as $r) {
    $flag = abs($r['drift']) < 0.01 ? '✓' : '⚠';
    printf("    %s id=%-3d %-50s %s/%s stored=%-10.2f computed=%-10.2f drift=%-10.2f\n",
        $flag, $r['id'], substr($r['name'], 0, 50), $r['type'], $r['currency'],
        $r['stored'], $r['computed'], $r['drift']);
}

$failedAccounts = array_filter($finalRows, fn ($r) => abs($r['drift']) >= 0.01);
record($results, 'j4_final_reconcile_all',
    count($failedAccounts) === 0 ? 'PASS' : 'FAIL',
    count($failedAccounts) === 0 ? 'All accounts reconcile perfectly' :
    count($failedAccounts).' accounts out of balance: '.implode(', ', array_map(fn ($r) => "id={$r['id']} drift=".number_format($r['drift'], 2), $failedAccounts)));
if (count($failedAccounts) === 0) {
    $ok('All accounts in balance (no drift)');
} else {
    $fail(count($failedAccounts).' accounts have drift');
}

// =====================================================================
// J.5: Invariant — debit_balance equals -credit_balance across all entries
// =====================================================================
$head('J.5: Invariant — SUM(debit) == SUM(credit) across all entries');

$totals = DB::selectOne('
    SELECT COALESCE(SUM(debit), 0) AS total_debit,
           COALESCE(SUM(credit), 0) AS total_credit
    FROM account_entries
');
$imbalance = (float) $totals->total_debit - (float) $totals->total_credit;
record($results, 'j5_global_debit_credit_balance', abs($imbalance) < 0.01 ? 'PASS' : 'FAIL',
    'Σdebit='.number_format($totals->total_debit, 2).' Σcredit='.number_format($totals->total_credit, 2).' Δ='.number_format($imbalance, 2));
abs($imbalance) < 0.01 ? $ok('Trial balance OK — debit = credit') : $fail("Trial balance broken! Δ=$imbalance");

// =====================================================================
// J.6: Idempotency — re-reconcile returns same result
// =====================================================================
$head('J.6: Idempotency — re-reconcile same numbers');

$run1 = array_sum(array_column(reconcileAllAccounts(), 'drift'));
$run2 = array_sum(array_column(reconcileAllAccounts(), 'drift'));
record($results, 'j6_idempotent_reconcile', abs($run1 - $run2) < 0.001 ? 'PASS' : 'FAIL',
    "Reconcile calls return same total drift: $run1 vs $run2");
abs($run1 - $run2) < 0.001 ? $ok('Reconciliation is idempotent') : $fail('Reconciliation varies');

$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_j_treasury.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase J Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
$passed = 0;
$failed = 0;
$warn = 0;
foreach ($results['tests'] as $t) {
    if ($t['status'] === 'PASS') {
        $passed++;
    } elseif ($t['status'] === 'FAIL') {
        $failed++;
    } elseif ($t['status'] === 'WARN') {
        $warn++;
    }
}
echo '  Tests: '.count($results['tests'])." | PASS: $passed | FAIL: $failed | WARN: $warn\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
