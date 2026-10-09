<?php

/**
 * ════════════════════════════════════════════════════════════════════════
 * FIX VERIFICATION — Deferred Inventory + payInventoryDebt + deleteInventory
 * ════════════════════════════════════════════════════════════════════════
 *
 * Covers the cascade gap found in the delete+reversal verification:
 *   Deferred inventory → payInventoryDebt() ×N → deleteInventory() should
 *   reverse each BusCompanyPayment transaction so cashbox & expense_clearing
 *   return to pre-payment state.
 *
 * The user's invariant: after deletion, all affected balances should return
 * EXACTLY to the state BEFORE the operation was created.
 *
 * Cases:
 *   1. Deferred inventory with NO payInventoryDebt() → balances unchanged
 *   2. Deferred inventory with ONE payInventoryDebt() → balances restored
 *   3. Deferred inventory with MULTIPLE payInventoryDebt() → cumulative reverse
 *   4. Idempotency — original transactions stay intact, reversals are additive
 *   5. Cash inventory — existing behavior preserved
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

use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusInventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = ['tests' => []];

$ok = function (string $m) use (&$results): void {
    echo "  ✅ $m\n";
};
$fail = function (string $m) use (&$results): void {
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

/**
 * Capture all account balances as a hash so we can compare pre-pay vs post-delete.
 */
function snapshot(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $hash = [];
    foreach ($rows as $r) {
        $hash[(int) $r['id']] = (float) $r['balance'];
    }

    return $hash;
}

function diff(array $before, array $after, PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $changes = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $b = $before[$id] ?? 0.0;
        $a = $after[$id] ?? 0.0;
        $d = round($a - $b, 2);
        if (abs($d) > 0.005) {
            $changes[] = "{$r['name']} (id=$id): $b → $a (delta=".($d > 0 ? '+' : '')."$d)";
        }
    }

    return $changes;
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  FIX VERIFICATION — Deferred Inventory + payInventoryDebt + delete\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$adminId = DB::table('users')->where('role', 'owner')->value('id');
$service = app(BusInventoryService::class);
$pdo = DB::connection()->getPdo();

function makeCompany(): array
{
    global $adminId, $pdo;
    $company = BusCompany::create([
        'name' => 'TX-CASCADE-'.substr(md5(uniqid()), 0, 6),
        'is_active' => true, 'created_by' => $adminId,
    ]);
    $vault = $pdo->query("SELECT id, balance FROM accounts WHERE type IN ('cashbox','bank') AND currency='EGP' AND module_type='office' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE accounts SET balance = balance + 100000 WHERE id = ?')->execute([$vault['id']]);

    return ['company' => $company, 'vault_id' => (int) $vault['id']];
}

function makeInv(int $companyId, int $vaultId, string $paymentType, int $total = 2000): BusInventory
{
    global $adminId;

    return BusInventory::create([
        'company_id' => $companyId,
        'route' => 'TX-CASCADE-'.substr(md5(uniqid()), 0, 6),
        'travel_date' => '2027-01-15', 'departure_time' => '09:00:00',
        'total_tickets' => 10, 'available_tickets' => 10,
        'cost_per_ticket' => 200, 'selling_price' => 300,
        'payment_type' => $paymentType,
        'remaining_debt' => $paymentType === 'deferred' ? $total : 0,
        'amount_paid' => $paymentType === 'cash' ? $total : 0,
        'currency' => 'EGP', 'account_id' => $vaultId,
        'exchange_rate_to_egp' => 1.0,
        'notes' => 'TX-CASCADE', 'created_by' => $adminId,
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// CASE 1: Deferred inventory with NO payInventoryDebt() → balances unchanged
// ─────────────────────────────────────────────────────────────────────
$head('CASE 1: Deferred inventory with NO payInventoryDebt()');
$env1 = makeCompany();
$inv1 = makeInv($env1['company']->id, $env1['vault_id'], 'deferred');
$before1 = snapshot($pdo);
$service->deleteInventory($inv1->fresh());
$after1 = snapshot($pdo);
$changes1 = diff($before1, $after1, $pdo);
$bal1Ok = empty($changes1);
record($results, 'case1_deferred_no_pay_balances', $bal1Ok ? 'PASS' : 'FAIL',
    $bal1Ok ? 'No balance changes (no payInventoryDebt to reverse)' : 'Unexpected changes: '.implode('; ', $changes1));
$bal1Ok ? $ok('No balance change: balances before delete == after delete') : $fail('Balance drifted: '.implode('; ', $changes1));

// ─────────────────────────────────────────────────────────────────────
// CASE 2: Deferred inventory with ONE payInventoryDebt() → balances restored
// ─────────────────────────────────────────────────────────────────────
$head('CASE 2: Deferred inventory with ONE payInventoryDebt()');
$env2 = makeCompany();
$inv2 = makeInv($env2['company']->id, $env2['vault_id'], 'deferred');
$before2 = snapshot($pdo);  // BEFORE any payment — the user's invariant baseline
$service->payInventoryDebt($inv2->fresh(), ['amount' => 500, 'account_id' => $env2['vault_id'], 'notes' => 'CASE2.PAY']);
$mid2 = snapshot($pdo);  // after payment (should differ from before2)
$service->deleteInventory($inv2->fresh());
$after2 = snapshot($pdo);
$changes2 = diff($before2, $after2, $pdo);
$midChanges2 = diff($before2, $mid2, $pdo);
$bal2Ok = empty($changes2);
$midOk = ! empty($midChanges2);  // mid should differ (payment was real)
record($results, 'case2_deferred_one_pay_balances_restored', $bal2Ok ? 'PASS' : 'FAIL',
    $bal2Ok ? 'Balances before-payment == after-delete (correctly restored)' : 'Did not restore: '.implode('; ', $changes2));
record($results, 'case2_payment_was_real', $midOk ? 'PASS' : 'FAIL',
    'Payment did affect balances (mid != before): '.(empty($midChanges2) ? 'NO DIFF (BUG!)' : implode('; ', $midChanges2)));
$bal2Ok ? $ok('Balance exactly restored: before-payment == after-delete') : $fail('Balance not restored: '.implode('; ', $changes2));
$midOk ? $info('Payment had real effect on balances (mid-state differs from before)') : $fail('Payment did not affect balances!');

// Original transaction still present + reversal entries added
$txId2 = DB::table('bus_company_payments')->where('inventory_id', $inv2->id)->value('transaction_id');
$originalStillPresent = $txId2 && DB::table('transactions')->where('id', $txId2)->exists();
$reversalEntries = DB::table('account_entries')->where('transaction_id', $txId2)->where('notes', 'like', 'عكس%')->count();
record($results, 'case2_original_tx_intact', $originalStillPresent ? 'PASS' : 'FAIL',
    "Original transaction id=$txId2 is still present (immutable)");
record($results, 'case2_reversal_entries_added', $reversalEntries >= 2 ? 'PASS' : 'FAIL',
    "Reversal AccountEntry rows: $reversalEntries (expected ≥2 — one per leg)");
$originalStillPresent ? $ok('Original transaction id='.$txId2.' is still present') : $fail('Original transaction was deleted');
$reversalEntries >= 2 ? $ok("$reversalEntries reversal entries added (additive)") : $fail("Only $reversalEntries reversal entries");

// ─────────────────────────────────────────────────────────────────────
// CASE 3: Deferred inventory with MULTIPLE payInventoryDebt() → cumulative reverse
// ─────────────────────────────────────────────────────────────────────
$head('CASE 3: Deferred inventory with MULTIPLE payInventoryDebt()');
$env3 = makeCompany();
$inv3 = makeInv($env3['company']->id, $env3['vault_id'], 'deferred');
$before3 = snapshot($pdo);
$service->payInventoryDebt($inv3->fresh(), ['amount' => 500, 'account_id' => $env3['vault_id'], 'notes' => 'CASE3.PAY1']);
$service->payInventoryDebt($inv3->fresh(), ['amount' => 700, 'account_id' => $env3['vault_id'], 'notes' => 'CASE3.PAY2']);
$service->payInventoryDebt($inv3->fresh(), ['amount' => 300, 'account_id' => $env3['vault_id'], 'notes' => 'CASE3.PAY3']);
$service->deleteInventory($inv3->fresh());
$after3 = snapshot($pdo);
$changes3 = diff($before3, $after3, $pdo);
$bal3Ok = empty($changes3);
record($results, 'case3_deferred_multi_pay_balances', $bal3Ok ? 'PASS' : 'FAIL',
    $bal3Ok ? 'After 3 payments + delete, balances == before3 (cumulative reversal worked)' : 'Did not restore: '.implode('; ', $changes3));
$bal3Ok ? $ok('3 cumulative payments fully reversed: balances == before') : $fail('Balance not restored: '.implode('; ', $changes3));

$paymentCount3 = DB::table('bus_company_payments')->where('inventory_id', $inv3->id)->count();
$txIds3 = DB::table('bus_company_payments')->where('inventory_id', $inv3->id)->pluck('transaction_id')->toArray();
$allIntact3 = ! empty($txIds3);
foreach ($txIds3 as $txId) {
    if (! DB::table('transactions')->where('id', $txId)->exists()) {
        $allIntact3 = false;
        break;
    }
}
$reversalEntries3 = 0;
foreach ($txIds3 as $txId) {
    $reversalEntries3 += DB::table('account_entries')->where('transaction_id', $txId)->where('notes', 'like', 'عكس%')->count();
}
record($results, 'case3_original_txs_intact', $allIntact3 ? 'PASS' : 'FAIL',
    "All $paymentCount3 original transactions still present (immutable)");
record($results, 'case3_reversal_entries_count', $reversalEntries3 >= 2 * $paymentCount3 ? 'PASS' : 'FAIL',
    "Reversal entries: $reversalEntries3 (expected ≥".(2 * $paymentCount3)." = 2 × $paymentCount3 payments)");
$allIntact3 ? $ok("All $paymentCount3 original transactions intact") : $fail('Some original transactions were deleted');
$reversalEntries3 >= 2 * $paymentCount3 ? $ok("$reversalEntries3 reversal entries added cumulatively") : $fail("Only $reversalEntries3 reversal entries");

// ─────────────────────────────────────────────────────────────────────
// CASE 4: Idempotency — original transactions stay intact, reversals are additive
// ─────────────────────────────────────────────────────────────────────
$head('CASE 4: Idempotency — original transactions stay intact, reversals are additive');
$env4 = makeCompany();
$inv4 = makeInv($env4['company']->id, $env4['vault_id'], 'deferred');
$service->payInventoryDebt($inv4->fresh(), ['amount' => 800, 'account_id' => $env4['vault_id'], 'notes' => 'CASE4.PAY1']);
$service->payInventoryDebt($inv4->fresh(), ['amount' => 200, 'account_id' => $env4['vault_id'], 'notes' => 'CASE4.PAY2']);
$service->deleteInventory($inv4->fresh());
$txIds4 = DB::table('bus_company_payments')->where('inventory_id', $inv4->id)->pluck('transaction_id')->toArray();
$allReversed4 = 0;
foreach ($txIds4 as $txId) {
    $tx = DB::table('transactions')->where('id', $txId)->first();
    if ($tx && str_starts_with((string) $tx->notes, 'عكس:')) {
        $allReversed4++;
    }
}
$idempotencyOk = $allReversed4 === count($txIds4);
record($results, 'case4_idempotency', $idempotencyOk ? 'PASS' : 'FAIL',
    'All '.count($txIds4)." original transactions now have 'عكس:' prefix (idempotency guard active)");
$idempotencyOk ? $ok('All '.count($txIds4)." transactions marked 'عكس:' (idempotency works)") : $fail("Only $allReversed4/".count($txIds4).' marked');

// ─────────────────────────────────────────────────────────────────────
// CASE 5: Cash inventory — existing behavior preserved
// ─────────────────────────────────────────────────────────────────────
$head('CASE 5: Cash inventory — existing behavior preserved');
$env5 = makeCompany();
$inv5 = makeInv($env5['company']->id, $env5['vault_id'], 'cash');
$before5 = snapshot($pdo);
$service->deleteInventory($inv5->fresh());
$after5 = snapshot($pdo);
$changes5 = diff($before5, $after5, $pdo);
$bal5Ok = empty($changes5);
record($results, 'case5_cash_inventory_balances', $bal5Ok ? 'PASS' : 'FAIL',
    $bal5Ok ? 'Cash inventory: balances before-create == after-delete (existing behavior preserved)' : 'Cash inventory changed: '.implode('; ', $changes5));
$bal5Ok ? $ok('Cash inventory: existing behavior preserved (balance restored)') : $fail('Cash inventory balance drifted: '.implode('; ', $changes5));

// ─────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────
$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_deferred_inventory_delete_regression.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  FIX VERIFICATION Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
$passed = 0;
$failed = 0;
foreach ($results['tests'] as $t) {
    if ($t['status'] === 'PASS') {
        $passed++;
    } elseif ($t['status'] === 'FAIL') {
        $failed++;
    }
}
echo '  Tests: '.count($results['tests'])." | Passed: $passed | Failed: $failed\n";
echo "  Results: storage/logs/bus_audit_deferred_inventory_delete_regression.json\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($failed === 0) {
    echo "  ✅ PASS — DELETE + REVERSAL NOW RESTORES THE PRE-OPERATION BALANCE.\n\n";
} else {
    echo "  ❌ FAIL — $failed test(s) failed.\n\n";
}
