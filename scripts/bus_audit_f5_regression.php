<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * F-5 Regression Test — BusRefundRequest.transaction_id null-out consistency
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Finding F-5 (2026-08-13 audit):
 *   `BusRefundRequest.transaction_id` is not nulled in the cancelled-booking
 *   delete path because `deleteBookingWithReversal()` short-circuits and
 *   skips the Fix #12 cleanup that runs only in the normal (non-cancelled)
 *   branch.
 *
 * This test exercises the contract:
 *   1. Create a booking, pay partially, cancel it (creates BusRefundRequest
 *      with transaction_id set).
 *   2. deleteBookingWithReversal() on the cancelled booking MUST null-out
 *      BusRefundRequest.transaction_id (this is the F-5 fix).
 *   3. Second deleteBookingWithReversal() on the already-deleted booking
 *      MUST throw/short-circuit (idempotency guard preserved).
 *   4. Transaction count must not increase between T1 and T2 (no duplicate
 *      reversal transactions on repeated call).
 *
 * Uses the bus_audit_setup.php pattern for DB bootstrap. Targets
 * storage/app/local_bus_audit.sqlite (the isolated audit DB).
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
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = ['tests' => []];

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
echo "  F-5 Regression — BusRefundRequest.transaction_id null-out\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Setup ───
$adminId = DB::table('users')->where('role', 'owner')->value('id');
$egpVaultId = DB::table('accounts')->whereIn('type', ['cashbox', 'bank'])
    ->where('currency', 'EGP')->where('module_type', 'office')->value('id');

$company = BusCompany::create([
    'name' => 'F5-REG Company',
    'is_active' => true,
    'phone' => '01099999001',
    'created_by' => $adminId,
]);
$inventory = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'F5-REG Route',
    'travel_date' => '2027-02-15',
    'departure_time' => '09:00:00',
    'total_tickets' => 10,
    'available_tickets' => 10,
    'cost_per_ticket' => 500,
    'selling_price' => 1000,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0,
    'amount_paid' => 5000,
    'currency' => 'EGP',
    'account_id' => $egpVaultId,
    'exchange_rate_to_egp' => 1.0,
    'notes' => 'F5 regression inventory',
    'created_by' => $adminId,
]);
$customer = Customer::create([
    'full_name' => 'F5-REG Customer',
    'phone' => '01099999002',
    'created_by' => $adminId,
]);

$busBookingService = app(BusBookingService::class);

// ─── Build booking → pay partial → cancel ───
$head('Setup: create booking (qty=2, total=2000) → pay 1000 → cancel');

$booking = $busBookingService->createBooking([
    'inventory_id' => $inventory->id,
    'customer_id' => $customer->id,
    'quantity' => 2,
    'notes' => 'F5 regression booking',
    'created_by' => $adminId,
]);
$bookingId = $booking->id;
echo "  - booking_id = $bookingId, total = 2000\n";

$busBookingService->payBooking($booking->fresh(), [
    'amount' => 1000,
    'payment_method' => 'cash',
    'account_id' => $egpVaultId,
    'notes' => 'F5 regression partial pay',
    'created_by' => $adminId,
]);
echo "  - paid 1000 (partial)\n";

$refund = $busBookingService->cancelBooking($booking->fresh(), [
    'company_penalty' => 0,
    'office_penalty' => 0,
    'account_id' => $egpVaultId,
    'notes' => 'F5 regression cancel',
]);
echo "  - cancelled, BusRefundRequest id = {$refund->id}\n";

// ─── Pre-conditions ───
$head('Pre-condition: BusRefundRequest.transaction_id MUST be set');

$preRefund = BusRefundRequest::find($refund->id);
if ($preRefund && $preRefund->transaction_id !== null) {
    record($results, 'pre_refund_tx_set', 'PASS',
        "BusRefundRequest.transaction_id = {$preRefund->transaction_id} (pre-condition OK)");
    $ok('BusRefundRequest.transaction_id = '.$preRefund->transaction_id);
} else {
    record($results, 'pre_refund_tx_set', 'FAIL',
        'BusRefundRequest.transaction_id is NULL before delete — test setup wrong');
    $fail('Setup error: BusRefundRequest.transaction_id is NULL before delete');
    echo "\n  Aborting: cannot validate F-5 if pre-condition fails.\n";
    $results['finished_at'] = date('Y-m-d H:i:s');
    file_put_contents(
        storage_path('logs/bus_audit_f5_regression.json'),
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    exit(1);
}

// =====================================================================
// T1: First deleteBookingWithReversal on the cancelled booking
// =====================================================================
$head('T1: deleteBookingWithReversal on cancelled booking');

$txCountBeforeT1 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $bookingId)
    ->count();
echo "  - tx count (related to booking) before T1: $txCountBeforeT1\n";

$bookingStillExists = BusBooking::find($bookingId);
if (! $bookingStillExists) {
    record($results, 't1_booking_exists', 'FAIL',
        'Booking unexpectedly missing before T1');
    $fail('Booking missing before T1');
    $results['finished_at'] = date('Y-m-d H:i:s');
    file_put_contents(
        storage_path('logs/bus_audit_f5_regression.json'),
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    exit(1);
}

$threwT1 = false;
$throwMsgT1 = null;
try {
    $busBookingService->deleteBookingWithReversal($bookingId, $adminId);
} catch (Throwable $e) {
    $threwT1 = true;
    $throwMsgT1 = $e->getMessage();
}

record($results, 't1_no_throw', ! $threwT1 ? 'PASS' : 'FAIL',
    $threwT1 ? 'deleteBookingWithReversal threw: '.$throwMsgT1 : 'deleteBookingWithReversal on cancelled booking completed (no throw — short-circuit path OK)');
$threwT1 ? $fail('Threw: '.$throwMsgT1) : $ok('No throw — short-circuit path allowed cancellation delete');

// Short-circuit must NOT create new transactions (i5 contract from Phase I)
$txCountAfterT1 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $bookingId)
    ->count();
$txDeltaT1 = $txCountAfterT1 - $txCountBeforeT1;
record($results, 't1_no_new_tx', $txDeltaT1 === 0 ? 'PASS' : 'FAIL',
    "tx delta after T1 = $txDeltaT1 (expected: 0 — cancelled short-circuit)");
$txDeltaT1 === 0 ? $ok('No new tx created (short-circuit preserved)') : $fail("Created $txDeltaT1 new tx");

// F-5 core assertion: BusRefundRequest.transaction_id MUST be NULL after T1
$postT1Refund = BusRefundRequest::find($refund->id);
$t1NullOut = $postT1Refund !== null && $postT1Refund->transaction_id === null;
record($results, 't1_refund_tx_nulled',
    $t1NullOut ? 'PASS' : 'FAIL',
    $t1NullOut
        ? 'BusRefundRequest.transaction_id = NULL after deleteBookingWithReversal on cancelled booking (F-5 fix verified)'
        : 'BusRefundRequest.transaction_id = '.var_export($postT1Refund->transaction_id, true).' (F-5 NOT fixed — stale link!)'
);
$t1NullOut ? $ok('BusRefundRequest.transaction_id is NULL (F-5 fix works)') : $fail('BusRefundRequest.transaction_id still set — F-5 NOT fixed');

// Verify booking is soft-deleted
$bookingAfterT1 = BusBooking::withTrashed()->find($bookingId);
record($results, 't1_booking_trashed',
    ($bookingAfterT1 && $bookingAfterT1->trashed()) ? 'PASS' : 'FAIL',
    'Booking trashed after T1: '.($bookingAfterT1 && $bookingAfterT1->trashed() ? 'true' : 'false'));
($bookingAfterT1 && $bookingAfterT1->trashed()) ? $ok('Booking soft-deleted') : $fail('Booking NOT soft-deleted');

// =====================================================================
// T2: Second deleteBookingWithReversal (already-deleted → idempotency guard)
// =====================================================================
$head('T2: second deleteBookingWithReversal (already deleted)');

$txCountBeforeT2 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $bookingId)
    ->count();

$threwT2 = false;
$throwMsgT2 = null;
try {
    $busBookingService->deleteBookingWithReversal($bookingId, $adminId);
} catch (Throwable $e) {
    $threwT2 = true;
    $throwMsgT2 = $e->getMessage();
}

record($results, 't2_idempotent_throws_or_short_circuits',
    $threwT2 ? 'PASS' : 'FAIL',
    $threwT2
        ? 'Second call threw (idempotency guard): '.$throwMsgT2
        : 'Second call returned without throwing — idempotency guard NOT enforced (REGRESSION!)'
);
$threwT2 ? $ok('Second call threw — idempotency preserved') : $fail('Second call did NOT throw — idempotency broken');

// =====================================================================
// T3: tx count must not increase between T1 and T2
// =====================================================================
$head('T3: tx count delta between T1 and T2');

$txCountAfterT2 = DB::table('transactions')
    ->where('related_type', BusBooking::class)
    ->where('related_id', $bookingId)
    ->count();
$txDeltaT2 = $txCountAfterT2 - $txCountBeforeT2;
record($results, 't3_no_tx_delta',
    $txDeltaT2 === 0 ? 'PASS' : 'FAIL',
    "tx delta T1→T2 = $txDeltaT2 (expected: 0 — second call created no tx)");
$txDeltaT2 === 0 ? $ok('Zero new tx between T1 and T2') : $fail("$txDeltaT2 new tx between T1 and T2");

// Bonus: verify the null-out from T1 was NOT undone by T2
$postT2Refund = BusRefundRequest::find($refund->id);
$t2NullHeld = $postT2Refund !== null && $postT2Refund->transaction_id === null;
record($results, 't3_null_held_after_t2',
    $t2NullHeld ? 'PASS' : 'FAIL',
    $t2NullHeld
        ? 'BusRefundRequest.transaction_id still NULL after T2 (null-out not undone)'
        : 'BusRefundRequest.transaction_id = '.var_export($postT2Refund->transaction_id, true).' (null-out was undone — REGRESSION!)'
);
$t2NullHeld ? $ok('Null-out held through T2') : $fail('Null-out was undone');

// ─── Cleanup / output ───
$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(
    storage_path('logs/bus_audit_f5_regression.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  F-5 Regression Summary\n";
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
echo "═══════════════════════════════════════════════════════════════════\n";

if ($failed > 0) {
    echo "\n  RESULT: FAIL — F-5 remediation incomplete.\n\n";
    exit(1);
}
echo "\n  RESULT: PASS — F-5 fix verified.\n\n";
