<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * T22 — Comprehensive Regression Test for F-3 / Currency-Match Guard
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Companion to scripts/bus_audit_phase_h_cross_currency.php.
 *
 * The phase-H test verifies the SERVICE throws InvalidArgumentException on
 * a cross-currency attempt. This test verifies the COMPLETE contract:
 *   T1 — USD booking paid with USD account → PASS (paid_amount updated, tx created)
 *   T2 — USD booking paid with EGP account → throws InvalidArgumentException,
 *         NO side effects (no bus_payment, no transaction, no balance change)
 *   T3 — Retry T2 → still throws, no double-rejection side effects
 *   T4 — All-same-currency-EGP flow (book → partial pay → full pay) still works
 *   T5 — Verify no BusPayment was created with amount > 0 in the rejected cases
 *   T6 — Verify no Transaction was created in the rejected cases (count delta = 0)
 *
 * Mirrors the bus_audit_f5_regression.php pattern: bootstrap fresh SQLite,
 * exercise the contract, write JSON to storage/logs/bus_audit_t22_regression.json.
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
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'tests' => [],
];

$ok = function (string $m): void {
    echo "  [PASS] $m\n";
};
$fail = function (string $m): void {
    echo "  [FAIL] $m\n";
};
$head = function (string $m): void {
    echo "\n── $m\n";
};

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  T22 — Comprehensive Regression: F-3 / Currency-Match Guard\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Bootstrap: EGP + USD liquidity accounts + a USD + an EGP inventory ───
$adminId = DB::table('users')->where('role', 'owner')->value('id');

// Reuse the EGP office vault created by UnifiedVaultsSeeder
$egpVault = DB::table('accounts')
    ->whereIn('type', ['cashbox', 'bank'])
    ->where('currency', 'EGP')
    ->where('module_type', 'office')
    ->first();
if (! $egpVault) {
    echo "  [FAIL] No EGP office vault found. Run bus_audit_setup.php first.\n";
    $results['finished_at'] = date('Y-m-d H:i:s');
    file_put_contents(
        storage_path('logs/bus_audit_t22_regression.json'),
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    exit(1);
}
$egpVaultId = $egpVault->id;

// Ensure a USD liquidity account exists (idempotent)
$usdLiquidity = Account::firstOrCreate(
    ['name' => 'T22-REG USD Cashbox', 'currency' => 'USD', 'module_type' => 'office', 'type' => 'bank'],
    ['balance' => 10000, 'is_active' => 1, 'created_by' => $adminId]
);
$usdLiquidityId = $usdLiquidity->id;
$usdBalanceBefore = (float) $usdLiquidity->balance;
$egpBalanceBefore = (float) Account::find($egpVaultId)->balance;
echo "  - USD liquidity id={$usdLiquidityId} (balance=$usdBalanceBefore)\n";
echo "  - EGP vault     id={$egpVaultId} (balance=$egpBalanceBefore)\n";

// ─────────────────────────────────────────────────────────────────────
// Setup test companies + inventories + customers
// ─────────────────────────────────────────────────────────────────────

// USD company + inventory + customer
$usdCompany = BusCompany::create([
    'name' => 'T22-REG USD Co',
    'is_active' => true,
    'created_by' => $adminId,
]);
$usdInventory = BusInventory::create([
    'company_id' => $usdCompany->id,
    'route' => 'T22-REG USD Route',
    'travel_date' => '2026-11-01',
    'departure_time' => '10:00:00',
    'total_tickets' => 10, 'available_tickets' => 10,
    'cost_per_ticket' => 100, // 100 USD per ticket
    'selling_price' => 150,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0, 'amount_paid' => 0,
    'currency' => 'USD',
    'account_id' => $usdLiquidityId,
    'exchange_rate_to_egp' => 50.0,
    'notes' => 'T22-REG USD inventory',
    'created_by' => $adminId,
]);
$usdCustomer = Customer::create([
    'full_name' => 'T22-REG USD Customer',
    'phone' => '01099910001',
    'created_by' => $adminId,
]);

// EGP company + inventory + customer
$egpCompany = BusCompany::create([
    'name' => 'T22-REG EGP Co',
    'is_active' => true,
    'created_by' => $adminId,
]);
$egpInventory = BusInventory::create([
    'company_id' => $egpCompany->id,
    'route' => 'T22-REG EGP Route',
    'travel_date' => '2026-11-02',
    'departure_time' => '11:00:00',
    'total_tickets' => 10, 'available_tickets' => 10,
    'cost_per_ticket' => 500, // 500 EGP per ticket
    'selling_price' => 1000,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0, 'amount_paid' => 0,
    'currency' => 'EGP',
    'account_id' => $egpVaultId,
    'exchange_rate_to_egp' => 1.0,
    'notes' => 'T22-REG EGP inventory',
    'created_by' => $adminId,
]);
$egpCustomer = Customer::create([
    'full_name' => 'T22-REG EGP Customer',
    'phone' => '01099910002',
    'created_by' => $adminId,
]);

$busBookingService = app(BusBookingService::class);

// =====================================================================
// T1: USD booking paid with USD account → PASS
// =====================================================================
$head('T1: USD booking paid with USD account (must succeed)');

$usdBooking = $busBookingService->createBooking([
    'inventory_id' => $usdInventory->id,
    'customer_id' => $usdCustomer->id,
    'quantity' => 2,
    'notes' => 'T22-REG T1 USD booking',
    'created_by' => $adminId,
]);
$usdBookingId = $usdBooking->id;
echo "  - USD booking id=$usdBookingId, total=300 USD\n";

$txCountBeforeT1 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $usdBookingId)
    ->count();
$paymentCountBeforeT1 = DB::table('bus_payments')
    ->where('booking_id', $usdBookingId)
    ->count();

$t1Succeeded = false;
$t1Error = null;
try {
    $paid = $busBookingService->payBooking($usdBooking->fresh(), [
        'amount' => 100,
        'payment_method' => 'cash',
        'account_id' => $usdLiquidityId,
        'notes' => 'T22-REG T1 USD match',
        'created_by' => $adminId,
    ]);
    $t1Succeeded = true;
    $t1PaidAmount = (float) $paid->fresh()->paid_amount;
} catch (Throwable $e) {
    $t1Error = $e->getMessage();
}

record($results, 't1_usd_booking_usd_account',
    $t1Succeeded && $t1PaidAmount === 100.0 ? 'PASS' : 'FAIL',
    $t1Succeeded
        ? "Same-currency payment succeeded, paid_amount={$t1PaidAmount}"
        : "Same-currency payment threw: {$t1Error}");
$t1Succeeded && $t1PaidAmount === 100.0
    ? $ok("USD booking paid with USD account: paid_amount={$t1PaidAmount}")
    : $fail('Same-currency payment broke: '.($t1Error ?? 'unknown'));

$txCountAfterT1 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $usdBookingId)
    ->count();
$paymentCountAfterT1 = DB::table('bus_payments')
    ->where('booking_id', $usdBookingId)
    ->count();
$txDeltaT1 = $txCountAfterT1 - $txCountBeforeT1;
$paymentDeltaT1 = $paymentCountAfterT1 - $paymentCountBeforeT1;

record($results, 't1_tx_created',
    $txDeltaT1 === 1 ? 'PASS' : 'FAIL',
    "tx delta = {$txDeltaT1} (expected: 1 — one transfer tx for the payment)");
$txDeltaT1 === 1 ? $ok('1 tx created for payment') : $fail("expected 1 tx, got {$txDeltaT1}");

record($results, 't1_payment_created',
    $paymentDeltaT1 === 1 ? 'PASS' : 'FAIL',
    "payment delta = {$paymentDeltaT1} (expected: 1 — one BusPayment row)");
$paymentDeltaT1 === 1 ? $ok('1 BusPayment row created') : $fail("expected 1 payment, got {$paymentDeltaT1}");

// Lock USD booking state for T2 (still has 200 USD remaining)
$usdBooking = $usdBooking->fresh();

// =====================================================================
// T2: USD booking paid with EGP account → InvalidArgumentException, NO side effects
// =====================================================================
$head('T2: USD booking paid with EGP account (must throw, no side effects)');

$txCountBeforeT2 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $usdBookingId)
    ->count();
$paymentCountBeforeT2 = DB::table('bus_payments')
    ->where('booking_id', $usdBookingId)
    ->count();
$usdBalanceBeforeT2 = (float) Account::find($usdLiquidityId)->balance;
$egpBalanceBeforeT2 = (float) Account::find($egpVaultId)->balance;

$t2Threw = false;
$t2ExceptionType = null;
$t2ExceptionMessage = null;
try {
    $busBookingService->payBooking($usdBooking->fresh(), [
        'amount' => 50,
        'payment_method' => 'cash',
        'account_id' => $egpVaultId,    // EGP account! -> cross-currency
        'notes' => 'T22-REG T2 cross-currency attack',
        'created_by' => $adminId,
    ]);
} catch (InvalidArgumentException $e) {
    $t2Threw = true;
    $t2ExceptionType = 'InvalidArgumentException';
    $t2ExceptionMessage = $e->getMessage();
} catch (Throwable $e) {
    $t2Threw = true;
    $t2ExceptionType = get_class($e);
    $t2ExceptionMessage = $e->getMessage();
}

record($results, 't2_throws_invalid_argument',
    $t2Threw && $t2ExceptionType === 'InvalidArgumentException' ? 'PASS' : 'FAIL',
    $t2Threw
        ? "Service threw {$t2ExceptionType}: ".substr($t2ExceptionMessage, 0, 200)
        : 'Service did NOT throw — cross-currency payment was accepted (F-3 NOT fixed)');
$t2Threw && $t2ExceptionType === 'InvalidArgumentException'
    ? $ok('InvalidArgumentException thrown: '.substr($t2ExceptionMessage, 0, 100))
    : $fail('Wrong exception type: '.($t2ExceptionType ?? 'no throw').' — '.($t2ExceptionMessage ?? ''));

$txCountAfterT2 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $usdBookingId)
    ->count();
$paymentCountAfterT2 = DB::table('bus_payments')
    ->where('booking_id', $usdBookingId)
    ->count();
$txDeltaT2 = $txCountAfterT2 - $txCountBeforeT2;
$paymentDeltaT2 = $paymentCountAfterT2 - $paymentCountBeforeT2;

$usdBalanceAfterT2 = (float) Account::find($usdLiquidityId)->balance;
$egpBalanceAfterT2 = (float) Account::find($egpVaultId)->balance;

record($results, 't2_no_tx_delta',
    $txDeltaT2 === 0 ? 'PASS' : 'FAIL',
    "tx delta = {$txDeltaT2} (expected: 0 — DB::transaction rollback)");
$txDeltaT2 === 0 ? $ok('No new tx created (DB rollback worked)') : $fail("{$txDeltaT2} phantom tx!");

record($results, 't2_no_payment_delta',
    $paymentDeltaT2 === 0 ? 'PASS' : 'FAIL',
    "payment delta = {$paymentDeltaT2} (expected: 0 — DB::transaction rollback)");
$paymentDeltaT2 === 0 ? $ok('No BusPayment created (DB rollback worked)') : $fail("{$paymentDeltaT2} phantom payments!");

record($results, 't2_no_balance_change',
    ($usdBalanceAfterT2 === $usdBalanceBeforeT2 && $egpBalanceAfterT2 === $egpBalanceBeforeT2) ? 'PASS' : 'FAIL',
    'USD balance '.number_format($usdBalanceAfterT2, 2).'->'.number_format($usdBalanceBeforeT2, 2).' | EGP balance '.number_format($egpBalanceAfterT2, 2).'->'.number_format($egpBalanceBeforeT2, 2));
($usdBalanceAfterT2 === $usdBalanceBeforeT2 && $egpBalanceAfterT2 === $egpBalanceBeforeT2)
    ? $ok('No balance change on either account')
    : $fail('Balance drift detected!');

// Booking must remain untouched (paid_amount unchanged)
$usdBookingAfterT2 = $usdBooking->fresh();
record($results, 't2_booking_unchanged',
    (float) $usdBookingAfterT2->paid_amount === 100.0 ? 'PASS' : 'FAIL',
    "Booking paid_amount after T2 = {$usdBookingAfterT2->paid_amount} (expected: 100.00 — unchanged)");
(float) $usdBookingAfterT2->paid_amount === 100.0
    ? $ok('Booking paid_amount unchanged at 100.00')
    : $fail("Booking paid_amount changed to {$usdBookingAfterT2->paid_amount}!");

// =====================================================================
// T3: Retry T2 → still throws, no double-rejection side effects
// =====================================================================
$head('T3: Retry T2 — multiple cross-currency attempts');

$txCountBeforeT3 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $usdBookingId)
    ->count();
$paymentCountBeforeT3 = DB::table('bus_payments')
    ->where('booking_id', $usdBookingId)
    ->count();

$t3Attempts = 0;
$t3AllThrew = true;
$t3LastException = null;
for ($i = 0; $i < 3; $i++) {
    $t3Attempts++;
    try {
        $busBookingService->payBooking($usdBooking->fresh(), [
            'amount' => 10 + $i,
            'payment_method' => 'cash',
            'account_id' => $egpVaultId,
            'notes' => 'T22-REG T3 retry '.($i + 1),
            'created_by' => $adminId,
        ]);
        $t3AllThrew = false;
    } catch (InvalidArgumentException $e) {
        $t3LastException = $e->getMessage();
    } catch (Throwable $e) {
        $t3AllThrew = false;
        $t3LastException = 'Wrong exception: '.get_class($e);
    }
}

$txCountAfterT3 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $usdBookingId)
    ->count();
$paymentCountAfterT3 = DB::table('bus_payments')
    ->where('booking_id', $usdBookingId)
    ->count();

record($results, 't3_all_retries_threw',
    $t3AllThrew ? 'PASS' : 'FAIL',
    "All {$t3Attempts} retries threw InvalidArgumentException. Last: ".substr($t3LastException ?? '', 0, 100));
$t3AllThrew ? $ok("All {$t3Attempts} retries threw cleanly") : $fail("Retry {$t3Attempts} did not throw");

record($results, 't3_no_cumulative_side_effects',
    ($txCountAfterT3 - $txCountBeforeT3 === 0 && $paymentCountAfterT3 - $paymentCountBeforeT3 === 0) ? 'PASS' : 'FAIL',
    'tx delta over 3 retries = '.($txCountAfterT3 - $txCountBeforeT3).', payment delta = '.($paymentCountAfterT3 - $paymentCountBeforeT3));
($txCountAfterT3 - $txCountBeforeT3 === 0 && $paymentCountAfterT3 - $paymentCountBeforeT3 === 0)
    ? $ok('Zero cumulative side effects across 3 retries')
    : $fail('Cumulative side effects detected!');

// =====================================================================
// T4: All-same-currency-EGP flow (book → partial pay → full pay) still works
// =====================================================================
$head('T4: All-same-currency-EGP flow (book → partial → full)');

$egpBooking = $busBookingService->createBooking([
    'inventory_id' => $egpInventory->id,
    'customer_id' => $egpCustomer->id,
    'quantity' => 2,
    'notes' => 'T22-REG T4 EGP booking',
    'created_by' => $adminId,
]);
$egpBookingId = $egpBooking->id;
echo "  - EGP booking id=$egpBookingId, total=2000 EGP\n";

$t4Step1 = false;
$t4Step2 = false;
$t4PaymentCount = 0;
try {
    $busBookingService->payBooking($egpBooking->fresh(), [
        'amount' => 1200,
        'payment_method' => 'cash',
        'account_id' => $egpVaultId,
        'notes' => 'T22-REG T4 partial',
        'created_by' => $adminId,
    ]);
    $t4Step1 = true;
    $t4PartialPaid = (float) $egpBooking->fresh()->paid_amount;

    $busBookingService->payBooking($egpBooking->fresh(), [
        'amount' => 800,
        'payment_method' => 'cash',
        'account_id' => $egpVaultId,
        'notes' => 'T22-REG T4 full',
        'created_by' => $adminId,
    ]);
    $t4Step2 = true;
    $t4FinalPaid = (float) $egpBooking->fresh()->paid_amount;
    $t4PaymentCount = DB::table('bus_payments')->where('booking_id', $egpBookingId)->count();
} catch (Throwable $e) {
    $t4Message = $e->getMessage();
}

record($results, 't4_egp_partial_pay',
    $t4Step1 && $t4PartialPaid === 1200.0 ? 'PASS' : 'FAIL',
    $t4Step1
        ? "Partial 1200 EGP succeeded, paid_amount={$t4PartialPaid}"
        : 'Partial pay failed: '.($t4Message ?? 'unknown'));
$t4Step1 && $t4PartialPaid === 1200.0
    ? $ok('Partial 1200 EGP succeeded')
    : $fail('Partial pay broke: '.($t4Message ?? ''));

record($results, 't4_egp_full_pay',
    $t4Step2 && $t4FinalPaid === 2000.0 ? 'PASS' : 'FAIL',
    $t4Step2
        ? "Final 800 EGP succeeded, paid_amount={$t4FinalPaid}, status=".$egpBooking->fresh()->payment_status->value
        : 'Full pay failed: '.($t4Message ?? 'unknown'));
$t4Step2 && $t4FinalPaid === 2000.0
    ? $ok("Full 2000 EGP completed, status={$egpBooking->fresh()->payment_status->value}")
    : $fail('Full pay broke: '.($t4Message ?? ''));

record($results, 't4_payment_count',
    $t4PaymentCount === 2 ? 'PASS' : 'FAIL',
    "Payment count for EGP booking = {$t4PaymentCount} (expected: 2 — partial + final)");
$t4PaymentCount === 2 ? $ok('2 payments recorded') : $fail("Wrong payment count: {$t4PaymentCount}");

// =====================================================================
// T5: Verify no BusPayment was created with amount > 0 in the rejected cases
// =====================================================================
$head('T5: No defective BusPayment rows from rejected attempts');

$phantomPayments = DB::table('bus_payments')
    ->whereIn('notes', [
        'T22-REG T2 cross-currency attack',
        'T22-REG T3 retry 1',
        'T22-REG T3 retry 2',
        'T22-REG T3 retry 3',
    ])
    ->where('amount', '>', 0)
    ->count();

record($results, 't5_no_phantom_payments',
    $phantomPayments === 0 ? 'PASS' : 'FAIL',
    "Cross-currency BusPayment rows with amount > 0: {$phantomPayments} (expected: 0)");
$phantomPayments === 0 ? $ok('No phantom BusPayment rows') : $fail("{$phantomPayments} phantom payments!");

// =====================================================================
// T6: Verify no Transaction was created in the rejected cases (count delta = 0)
// =====================================================================
$head('T6: No phantom Transaction rows from rejected attempts');

$phantomTx = DB::table('transactions')
    ->whereIn('notes', [
        'T22-REG T2 cross-currency attack',
        'T22-REG T3 retry 1',
        'T22-REG T3 retry 2',
        'T22-REG T3 retry 3',
    ])
    ->count();

record($results, 't6_no_phantom_transactions',
    $phantomTx === 0 ? 'PASS' : 'FAIL',
    "Cross-currency Transactions: {$phantomTx} (expected: 0)");
$phantomTx === 0 ? $ok('No phantom Transaction rows') : $fail("{$phantomTx} phantom transactions!");

// ─── Output ───
$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(
    storage_path('logs/bus_audit_t22_regression.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  T22 Comprehensive Regression Summary\n";
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
echo '  Tests: '.count($results['tests'])." | PASS: $passed | FAIL: $failed\n";
echo "  Detailed results: storage/logs/bus_audit_t22_regression.json\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($failed > 0) {
    echo "  RESULT: FAIL — T22 regression incomplete.\n\n";
    exit(1);
}
echo "  RESULT: PASS — T22 cross-currency guard fully verified.\n\n";
