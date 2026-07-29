<?php
/**
 * Concurrency / Race-condition tests for the Fawry + Bus modules.
 *
 * Uses curl_multi to fire N parallel HTTP requests and then verifies
 * the database state is consistent (no double-spend, no overdraw, no
 * phantom tickets, no deadlocks, no lost updates).
 *
 * Tests:
 *   1. N parallel recharges from same source account (no overdraw)
 *   2. N parallel recharges on same machine (machine balance integrity)
 *   3. N parallel pay-debt on same inventory (no overpay)
 *   4. N parallel bookings on same inventory (capacity guard)
 *   5. N parallel payments on same booking (no double-submit)
 *   6. N parallel supplier pay-debt (no overpay)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Models\Bus\BusCompany;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\DB;

$TOKEN = getenv('E2E_TOKEN') ?: '2|uS8LPhi9HfQsTR5rFsg6fd8WRhRfw9VrtwsLgF1616c25cfd';
$BASE  = 'http://127.0.0.1:8000/api/v1';
$UNIQ  = (string) time();

$pass = 0;
$fail = 0;
$results = [];

function ok(string $name, string $detail = ''): void {
    global $pass, $results;
    $pass++;
    $results[] = ['PASS', $name, $detail];
    echo "✅ {$name}".($detail ? " — {$detail}" : '')."\n";
}

function bad(string $name, string $detail): void {
    global $fail, $results;
    $fail++;
    $results[] = ['FAIL', $name, $detail];
    echo "❌ {$name} — {$detail}\n";
}

function freshAccount(string $name, string $type, string $currency, float $balance): Account {
    return Account::create([
        'name'           => $name,
        'type'           => $type,
        'balance'        => $balance,
        'currency'       => $currency,
        'is_active'      => true,
        'owner_type'     => Account::OWNER_TYPE_OWNER,
        'module_type'    => AccountModuleContract::OFFICE_MODULE_TYPE,
        'module'         => AccountModuleContract::OFFICE_MODULE_TYPE,
        'is_module_vault'=> false,
        'notes'          => 'Concurrency test fixture',
        'created_by'     => 1,
    ])->fresh();
}

/**
 * Fire N parallel HTTP POST requests using curl_multi.
 * Returns array of [status, json, body] for each request.
 */
function parallelHttpPosts(string $path, array $payloads, int $concurrency = 50): array {
    global $TOKEN, $BASE;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($payloads as $i => $payload) {
        $ch = curl_init($BASE . $path);
        $headers = ["Authorization: Bearer $TOKEN", 'Accept: application/json', 'Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $handles[$i] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    // Allow up to N concurrent connections
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $concurrency);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out[$i] = ['status' => $code, 'json' => json_decode($body, true), 'body' => $body];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

echo "=== Concurrency / Race-condition Tests ===\n";
echo "Started: ".date('Y-m-d H:i:s')."\n\n";

// =============================================================================
// TEST 1: N parallel recharges from same source account
// =============================================================================
echo "── 1. N parallel recharges from same source account (no overdraw) ──\n";
$source = freshAccount("CONC_RECHARGE_SRC_{$UNIQ}", 'cashbox', 'EGP', 10000.00);
$machine1 = FawryMachine::create([
    'name'      => "CONC_MACHINE_{$UNIQ}",
    'type'      => 'fawry',
    'balance'   => 0.00,
    'is_active' => true,
    'notes'     => 'Concurrency test',
]);

$N = 20;
$perAmount = 600.00;  // 20 * 600 = 12000 (should exceed 10000 balance)
$totalRequested = $N * $perAmount;
echo "Source balance: 10000.00 EGP, {$N} parallel recharges of {$perAmount} EGP each (total requested: {$totalRequested})".PHP_EOL;

$payloads = [];
for ($i = 0; $i < $N; $i++) {
    $payloads[] = [
        'from_account_id' => $source->id,
        'amount'          => $perAmount,
        'notes'           => "parallel recharge #{$i}",
    ];
}

$start = microtime(true);
$responses = parallelHttpPosts("/fawry/machines/{$machine1->id}/recharge", $payloads);
$duration = microtime(true) - $start;

$success = 0;
$failed = 0;
foreach ($responses as $r) {
    if ($r['status'] >= 200 && $r['status'] < 300 && ($r['json']['success'] ?? false) === true) {
        $success++;
    } else {
        $failed++;
    }
}
echo "Duration: ".round($duration, 2)."s — Success: {$success}, Failed: {$failed}".PHP_EOL;

$sourceEnd = $source->fresh()->balance;
$machineEnd = $machine1->fresh()->balance;
$successAmount = $success * $perAmount;

if ($sourceEnd < 0) {
    bad('TEST 1: source balance went negative', "balance={$sourceEnd} (concurrent overdraw!)");
} else {
    ok('TEST 1: source balance non-negative', "balance={$sourceEnd}");
}
if (abs($machineEnd - $successAmount) < 0.01) {
    ok('TEST 1: machine balance equals successful recharges', "machine={$machineEnd}, expected={$successAmount}");
} else {
    bad('TEST 1: machine balance mismatch', "machine={$machineEnd}, expected={$successAmount}");
}
if (abs($sourceEnd - (10000 - $successAmount)) < 0.01) {
    ok('TEST 1: source debited exactly by successful recharges', "src={$sourceEnd}, expected debit=".$successAmount);
} else {
    bad('TEST 1: source balance mismatch', "src={$sourceEnd}, expected=".round(10000 - $successAmount, 2));
}
// The remaining balance should equal (initial - success_count * perAmount), i.e. no overdraw
$expectedRemain = 10000.0 - ($success * $perAmount);
if (abs($sourceEnd - $expectedRemain) < 0.01) {
    ok('TEST 1: atomicity preserved (no overdraw, no partial debits)', "remaining={$sourceEnd} = initial({10000}) - success_amount(".($success * $perAmount).")");
} else {
    bad('TEST 1: atomicity violation', "remaining={$sourceEnd}, expected={$expectedRemain}");
}

// =============================================================================
// TEST 2: N parallel recharges on same machine (from different sources)
// =============================================================================
echo "\n── 2. N parallel recharges on same machine (machine balance integrity) ──\n";
$machine2 = FawryMachine::create([
    'name'      => "CONC_MACHINE2_{$UNIQ}",
    'type'      => 'fawry',
    'balance'   => 0.00,
    'is_active' => true,
]);
$sources = [];
for ($i = 0; $i < $N; $i++) {
    $sources[] = freshAccount("CONC_SRC2_{$UNIQ}_{$i}", 'cashbox', 'EGP', 1000.00);
}

$perAmount = 100.00;
$payloads = [];
foreach ($sources as $i => $src) {
    $payloads[] = [
        'from_account_id' => $src->id,
        'amount'          => $perAmount,
        'notes'           => "parallel-machine #{$i}",
    ];
}
$start = microtime(true);
$responses = parallelHttpPosts("/fawry/machines/{$machine2->id}/recharge", $payloads);
$duration = microtime(true) - $start;

$success = 0;
foreach ($responses as $r) {
    if ($r['status'] >= 200 && $r['status'] < 300 && ($r['json']['success'] ?? false) === true) $success++;
}
echo "Duration: ".round($duration, 2)."s — Success: {$success}/{$N}".PHP_EOL;

$machineEnd = $machine2->fresh()->balance;
$expectedDelta = $success * $perAmount;
if (abs($machineEnd - $expectedDelta) < 0.01) {
    ok('TEST 2: machine balance matches successful recharges', "machine={$machineEnd}, expected={$expectedDelta}");
} else {
    bad('TEST 2: machine balance mismatch', "machine={$machineEnd}, expected={$expectedDelta}");
}

// Verify transaction ledger entries
$ledgerCount = FawryMachineTransaction::where('fawry_machine_id', $machine2->id)->count();
if ($ledgerCount === $success) {
    ok('TEST 2: machine ledger has one entry per successful recharge', "count={$ledgerCount}");
} else {
    bad('TEST 2: machine ledger mismatch', "count={$ledgerCount}, expected={$success}");
}

// =============================================================================
// TEST 3: N parallel pay-debt on same inventory (no overpay)
// =============================================================================
echo "\n── 3. N parallel pay-debt on same inventory (no overpay) ──\n";
$company = BusCompany::create([
    'name'      => "CONC_COMPANY_{$UNIQ}",
    'phone'     => '0100'.substr($UNIQ, -7),
    'is_active' => true,
    'notes'     => 'concurrency test',
]);
$cashbox = freshAccount("CONC_CASHBOX_{$UNIQ}", 'cashbox', 'EGP', 100000.00);
$inv = BusInventory::create([
    'company_id'         => $company->id,
    'route'              => "Concurrency route {$UNIQ}",
    'travel_date'        => date('Y-m-d', strtotime('+30 days')),
    'departure_time'     => '09:00',
    'total_tickets'      => 10,
    'available_tickets'  => 10,
    'cost_per_ticket'    => 100.00,
    'selling_price'      => 150.00,
    'payment_type'       => 'deferred',
    'total_cost'         => 1000.00,
    'amount_paid'        => 0.00,
    'remaining_debt'     => 1000.00,
    'currency'           => 'EGP',
    'exchange_rate_to_egp'=> 1.0,
    'notes'              => 'concurrency',
    'created_by'         => 1,
]);
// Re-fetch from DB to ensure we see the actual state after observer hooks
$inv = $inv->fresh();
$debt = (float) $inv->remaining_debt;
echo "Inventory #{$inv->id}: debt={$debt}, paid={$inv->amount_paid}".PHP_EOL;

$perAmount = 50.00;  // 20 * 50 = 1000 (exactly equal to debt)
$N3 = 20;
$payloads = [];
for ($i = 0; $i < $N3; $i++) {
    $payloads[] = [
        'amount'     => $perAmount,
        'account_id' => $cashbox->id,
    ];
}
$start = microtime(true);
$responses = parallelHttpPosts("/bus/inventories/{$inv->id}/pay-debt", $payloads);
$duration = microtime(true) - $start;

$success = 0;
$overpayRejected = 0;
$other = 0;
$statusCount = [];
foreach ($responses as $r) {
    $s = $r['status'];
    $statusCount[$s] = ($statusCount[$s] ?? 0) + 1;
    $jsonSuccess = $r['json']['success'] ?? null;
    // Accept any 2xx status (201 Created is also a success)
    if ($s >= 200 && $s < 300 && $jsonSuccess === true) {
        $success++;
    } elseif ($s === 422) {
        $overpayRejected++;
    } else {
        $other++;
    }
}
echo "Duration: ".round($duration, 2)."s — Success: {$success}, Rejected (overpay): {$overpayRejected}, Other: {$other}".PHP_EOL;
echo "Status counts: ".json_encode($statusCount).PHP_EOL;
if ($success === 0 && $other > 0) {
    foreach (array_slice($responses, 0, 3) as $i => $r) {
        echo "Response #{$i}: status={$r['status']}, json_success=".var_export($r['json']['success'] ?? 'NOT_SET', true).", body=".substr($r['body'], 0, 200).PHP_EOL;
    }
}

$invAfter = $inv->fresh();
$remainAfter = (float) $invAfter->remaining_debt;
$paidAfter = (float) $invAfter->amount_paid;
$expectedSuccess = (int) floor($debt / $perAmount);

if ($remainAfter < 0) {
    bad('TEST 3: inventory debt went negative', "debt={$remainAfter} (overpay!)");
} else {
    ok('TEST 3: inventory debt non-negative', "debt={$remainAfter}");
}
if (abs($paidAfter - $success * $perAmount) < 0.01) {
    ok('TEST 3: amount_paid matches successful payments', "paid={$paidAfter}, expected=".($success * $perAmount));
} else {
    bad('TEST 3: amount_paid mismatch', "paid={$paidAfter}, expected=".($success * $perAmount));
}
if ($remainAfter == 0 && $success == $expectedSuccess) {
    ok('TEST 3: all payments applied exactly', "debt fully paid: {$success} × {$perAmount} = ".($success * $perAmount));
} else {
    bad('TEST 3: debt settlement mismatch', "remaining={$remainAfter}, success={$success}/{$expectedSuccess}");
}

// =============================================================================
// TEST 4: N parallel bookings on same inventory (capacity guard)
// =============================================================================
echo "\n── 4. N parallel bookings on same inventory (capacity guard) ──\n";
$company2 = BusCompany::create([
    'name'      => "CONC_COMPANY_CAP_{$UNIQ}",
    'phone'     => '0101'.substr($UNIQ, -7),
    'is_active' => true,
]);
$cashbox2 = freshAccount("CONC_CASHBOX_CAP_{$UNIQ}", 'cashbox', 'EGP', 100000.00);
$invCap = BusInventory::create([
    'company_id'         => $company2->id,
    'route'              => "Capacity test {$UNIQ}",
    'travel_date'        => date('Y-m-d', strtotime('+30 days')),
    'departure_time'     => '10:00',
    'total_tickets'      => 10,
    'available_tickets'  => 10,
    'cost_per_ticket'    => 50.00,
    'selling_price'      => 80.00,
    'payment_type'       => 'cash',
    'account_id'         => $cashbox2->id,
    'total_cost'         => 500.00,
    'amount_paid'        => 500.00,
    'remaining_debt'     => 0.00,
    'currency'           => 'EGP',
    'exchange_rate_to_egp'=> 1.0,
    'created_by'         => 1,
]);

$customer = Customer::firstOrCreate(
    ['phone' => "CONC{$UNIQ}"],
    ['name' => "Concurrency Customer {$UNIQ}", 'full_name' => "Concurrency Customer {$UNIQ}", 'is_active' => true, 'created_by' => 1]
);

$N4 = 20;  // 20 parallel bookings, each with qty=1, but only 10 tickets available
$qty = 1;
$payloads = [];
for ($i = 0; $i < $N4; $i++) {
    $payloads[] = [
        'inventory_id' => $invCap->id,
        'customer_id'  => $customer->id,
        'quantity'     => $qty,
        'notes'        => "parallel booking #{$i}",
    ];
}
$start = microtime(true);
$responses = parallelHttpPosts('/bus/bookings', $payloads);
$duration = microtime(true) - $start;

$success = 0;
$rejected = 0;
foreach ($responses as $r) {
    if ($r['status'] === 201 && ($r['json']['success'] ?? false) === true) {
        $success++;
    } elseif ($r['status'] >= 400) {
        $rejected++;
    }
}
echo "Duration: ".round($duration, 2)."s — Success: {$success}, Rejected: {$rejected}".PHP_EOL;

$availAfter = $invCap->fresh()->available_tickets;
if ($availAfter < 0) {
    bad('TEST 4: inventory went negative (oversold!)', "avail={$availAfter}");
} else {
    ok('TEST 4: inventory available ≥ 0', "avail={$availAfter}");
}
if ($success <= 10) {
    ok('TEST 4: no overselling (success ≤ capacity)', "success={$success}, capacity=10");
} else {
    bad('TEST 4: oversold!', "success={$success} > capacity=10");
}
if ($availAfter === 10 - $success) {
    ok('TEST 4: available tickets = capacity - sold', "avail={$availAfter}, sold={$success}");
} else {
    bad('TEST 4: inventory count mismatch', "avail={$availAfter}, expected=".($cap = 10 - $success));
}

// =============================================================================
// TEST 5: N parallel payments on same booking (no double-submit)
// =============================================================================
echo "\n── 5. N parallel payments on same booking (no double-submit) ──\n";
$invPay = BusInventory::create([
    'company_id'         => $company2->id,
    'route'              => "Payment test {$UNIQ}",
    'travel_date'        => date('Y-m-d', strtotime('+30 days')),
    'departure_time'     => '11:00',
    'total_tickets'      => 5,
    'available_tickets'  => 5,
    'cost_per_ticket'    => 30.00,
    'selling_price'      => 50.00,
    'payment_type'       => 'cash',
    'account_id'         => $cashbox2->id,
    'total_cost'         => 150.00,
    'amount_paid'        => 150.00,
    'remaining_debt'     => 0.00,
    'currency'           => 'EGP',
    'exchange_rate_to_egp'=> 1.0,
    'created_by'         => 1,
]);

// Create one booking with total=250 (qty=5, unit=50)
$resp = parallelHttpPosts('/bus/bookings', [
    ['inventory_id' => $invPay->id, 'customer_id' => $customer->id, 'quantity' => 5, 'notes' => 'parallel payment test'],
]);
[$booking] = BusBooking::orderBy('id', 'desc')->limit(1)->get();
$booking = BusBooking::find($resp[0]['json']['data']['id']);
$totalPrice = (float) $booking->total_price;
$perPay = 50.00;
$N5 = (int) floor($totalPrice / $perPay) + 5;  // try slightly more than feasible

$payloads = [];
for ($i = 0; $i < $N5; $i++) {
    $payloads[] = [
        'amount'         => $perPay,
        'payment_method' => 'cash',
        'account_id'     => $cashbox2->id,
    ];
}
$start = microtime(true);
$responses = parallelHttpPosts("/bus/bookings/{$booking->id}/pay", $payloads);
$duration = microtime(true) - $start;

$success = 0;
$rejected = 0;
foreach ($responses as $r) {
    if ($r['status'] >= 200 && $r['status'] < 300 && ($r['json']['success'] ?? false) === true) $success++;
    elseif ($r['status'] >= 400) $rejected++;
}
echo "Duration: ".round($duration, 2)."s — Success: {$success}, Rejected: {$rejected}".PHP_EOL;

$bookingAfter = $booking->fresh();
$paidAmount = (float) $bookingAfter->paid_amount;
if (abs($paidAmount - $totalPrice) < 0.01 && $success == (int) round($totalPrice / $perPay)) {
    ok('TEST 5: booking fully paid, no overpay', "paid={$paidAmount}, total={$totalPrice}, success={$success}");
} else {
    bad('TEST 5: payment mismatch', "paid={$paidAmount}, total={$totalPrice}, success={$success}");
}

// =============================================================================
// TEST 6: N parallel supplier pay-debt (no overpay)
// =============================================================================
echo "\n── 6. N parallel supplier pay-debt (no overpay) ──\n";
// First, create a debt on the supplier by booking from a deferred inventory
$invDebt = BusInventory::create([
    'company_id'         => $company2->id,
    'route'              => "Debt test {$UNIQ}",
    'travel_date'        => date('Y-m-d', strtotime('+30 days')),
    'departure_time'     => '12:00',
    'total_tickets'      => 5,
    'available_tickets'  => 5,
    'cost_per_ticket'    => 100.00,
    'selling_price'      => 150.00,
    'payment_type'       => 'deferred',
    'total_cost'         => 500.00,
    'amount_paid'        => 0.00,
    'remaining_debt'     => 500.00,
    'currency'           => 'EGP',
    'exchange_rate_to_egp'=> 1.0,
    'created_by'         => 1,
]);
// At this point, the supplier (company2) account should have -500 (we owe 500)

// Refresh and check supplier debt
$company2 = $company2->fresh();
$supplierBalance = $company2->account->balance;  // negative = we owe
$debt = abs($supplierBalance);
echo "Supplier debt: {$debt} EGP".PHP_EOL;

$perAmount = 25.00;
$N6 = (int) ceil($debt / $perAmount) + 5;  // try slightly more than feasible
$cashbox3 = freshAccount("CONC_CASHBOX_DEBT_{$UNIQ}", 'cashbox', 'EGP', 100000.00);

$payloads = [];
for ($i = 0; $i < $N6; $i++) {
    $payloads[] = [
        'amount'          => $perAmount,
        'from_account_id' => $cashbox3->id,
    ];
}
$start = microtime(true);
$responses = parallelHttpPosts("/bus/companies/{$company2->id}/pay-debt", $payloads);
$duration = microtime(true) - $start;

$success = 0;
$rejected = 0;
foreach ($responses as $r) {
    if ($r['status'] >= 200 && $r['status'] < 300 && ($r['json']['success'] ?? false) === true) $success++;
    elseif ($r['status'] >= 400) $rejected++;
}
echo "Duration: ".round($duration, 2)."s — Success: {$success}, Rejected: {$rejected}".PHP_EOL;

$supplierAfter = $company2->fresh()->account->balance;
if ($supplierAfter >= 0 && $success == (int) round($debt / $perAmount)) {
    ok('TEST 6: supplier debt fully paid, no overpay', "balance={$supplierAfter}, success={$success}");
} else {
    bad('TEST 6: supplier debt mismatch', "balance={$supplierAfter}, expected=".$supplierAfter.", success={$success}");
}

// =============================================================================
// TEST 7: Total integrity check
// =============================================================================
echo "\n── 7. Total integrity check ──\n";
// Count concurrency-related transactions
$concurrencyTx = DB::table('transactions')
    ->where('notes', 'like', "parallel%")
    ->orWhere('notes', 'like', "parallel #%")
    ->count();
echo "Concurrency-tagged transactions: {$concurrencyTx}".PHP_EOL;

// Summary
echo "\n══════════════════════════════════════════════════\n";
echo "           CONCURRENCY RESULTS SUMMARY\n";
echo "══════════════════════════════════════════════════\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";
echo "TOTAL: ".($pass + $fail)."\n";
echo "══════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\n❌ FAILURES:\n";
    foreach ($results as $r) {
        if ($r[0] === 'FAIL') {
            echo "  - {$r[1]} → {$r[2]}\n";
        }
    }
    exit(1);
}

echo "\n✅ All concurrency tests passed.\n";
exit(0);
