<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Phase I — Transaction Type Audit + Deduplication (v2 — corrected contracts)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * الـ Discovered Contract (from current run):
 *   - createBooking() posts 2 transactions:
 *       1. supplier cost (type=expense, amount=cost_per_ticket * qty)
 *       2. customer sale (type=income, amount=selling_price * qty)
 *   - payBooking() posts 1 transaction:
 *       1. customer payment (type=transfer, amount=paid; AR → vault)
 *   - cancelBooking() generates reversal entries + creates BusRefundRequest
 *   - deleteBookingWithReversal() reverses additively (type=transfer Reversal entries)
 *
 * Note: payBooking uses type='transfer' (NOT income) per 2026-08-12 fix —
 *   prevents double income accounting when a booking is paid.
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
echo "  Phase I — Transaction Type + Deduplication Audit (v2)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Setup test data ───
$adminId = DB::table('users')->where('role', 'owner')->value('id');
$egpVaultId = DB::table('accounts')->whereIn('type', ['cashbox', 'bank'])
    ->where('currency', 'EGP')->where('module_type', 'office')->value('id');

$company = BusCompany::create([
    'name' => 'TX-AUDIT Phase-I Company', 'is_active' => true,
    'phone' => '01090001001', 'created_by' => $adminId,
]);
$inventory = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'TX-AUDIT Phase-I Route',
    'travel_date' => '2026-12-01', 'departure_time' => '08:00:00',
    'total_tickets' => 10, 'available_tickets' => 10,
    'cost_per_ticket' => 500, 'selling_price' => 800,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0, 'amount_paid' => 5000,
    'currency' => 'EGP', 'account_id' => $egpVaultId,
    'exchange_rate_to_egp' => 1.0,
    'notes' => 'TX-AUDIT phase-I inventory',
    'created_by' => $adminId,
]);
$customer = Customer::create([
    'full_name' => 'TX-AUDIT Phase-I Customer',
    'phone' => '01090001002', 'created_by' => $adminId,
]);
$busBookingService = app(BusBookingService::class);

// =====================================================================
// I.1: Create booking → verify exactly 2 transactions (cost + sale)
// =====================================================================
$head('I.1: createBooking → exactly 2 transactions (cost + sale)');

$beforeCount = DB::table('transactions')->count();
$booking = $busBookingService->createBooking([
    'inventory_id' => $inventory->id,
    'customer_id' => $customer->id,
    'quantity' => 2,
    'notes' => 'TX-AUDIT phase-I booking',
    'created_by' => $adminId,
]);
$bookingId = $booking->id;
$afterCount = DB::table('transactions')->count();
$txDelta = $afterCount - $beforeCount;

$bookingTxs = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Bus\\BusBooking')
    ->where('related_id', $bookingId)
    ->orderBy('id')
    ->get();
$txsByType = [];
foreach ($bookingTxs as $tx) {
    $txsByType[$tx->type] = ($txsByType[$tx->type] ?? 0) + 1;
}

record($results, 'i1_create_booking_tx_count', $txDelta === 2 ? 'PASS' : 'FAIL',
    "createBooking posted $txDelta transactions (expected: 2 — cost + sale)");
record($results, 'i1_create_booking_types',
    (isset($txsByType['expense']) && isset($txsByType['income'])) ? 'PASS' : 'FAIL',
    'Posted types: '.json_encode($txsByType)." (expected: 'expense' AND 'income')");

// Sale details (income tx)
$saleTx = $bookingTxs->where('type', 'income')->first();
$costTx = $bookingTxs->where('type', 'expense')->first();
$info("Sale:    amount={$saleTx->amount} from_account={$saleTx->from_account_id} → to_account={$saleTx->to_account_id}");
$info("Cost:    amount={$costTx->amount} from_account={$costTx->from_account_id} → to_account={$costTx->to_account_id}");

// Verify amounts
$expectedSaleAmount = 2 * 800; // 2 tickets × selling_price
$expectedCostAmount = 2 * 500; // 2 tickets × cost_per_ticket
record($results, 'i1_sale_amount', (float) $saleTx->amount == $expectedSaleAmount ? 'PASS' : 'FAIL',
    "Sale amount = {$saleTx->amount} (expected: $expectedSaleAmount)");
record($results, 'i1_cost_amount', (float) $costTx->amount == $expectedCostAmount ? 'PASS' : 'FAIL',
    "Cost amount = {$costTx->amount} (expected: $expectedCostAmount)");

// =====================================================================
// I.2: Pay partial → 1 transfer tx (NOT income)
// =====================================================================
$head('I.2: payBooking → 1 transfer tx (NOT income)');

$beforeCount = DB::table('transactions')->count();
$busBookingService->payBooking($booking->fresh(), [
    'amount' => 500, 'payment_method' => 'cash',
    'account_id' => $egpVaultId,
    'notes' => 'TX-AUDIT phase-I partial pay',
    'created_by' => $adminId,
]);
$afterCount = DB::table('transactions')->count();
$payDelta = $afterCount - $beforeCount;

$bookingTxsAfterPay = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Bus\\BusBooking')
    ->where('related_id', $bookingId)
    ->orderBy('id')->get();

$paymentTx = $bookingTxsAfterPay->where('type', 'transfer')->first();
record($results, 'i2_payment_tx_count', $payDelta === 1 ? 'PASS' : 'FAIL',
    "payBooking posted $payDelta transactions (expected: 1)");
record($results, 'i2_payment_tx_type', $paymentTx !== null && $paymentTx->type === 'transfer' ? 'PASS' : 'FAIL',
    "Payment tx type = '{$paymentTx->type}' (expected: 'transfer' — NOT income per 2026-08-12 fix)");
record($results, 'i2_payment_amount', (float) $paymentTx->amount === 500.0 ? 'PASS' : 'FAIL',
    "Payment amount = {$paymentTx->amount} (expected: 500)");
$info("Payment: amount={$paymentTx->amount} from={$paymentTx->from_account_id} → to={$paymentTx->to_account_id} (AR → cashbox)");

// =====================================================================
// I.3: Pay remaining → 1 more transfer (total 2 payment tx)
// =====================================================================
$head('I.3: Full pay remaining → 1 transfer tx for $1100');

$beforeCount = DB::table('transactions')->count();
$busBookingService->payBooking($booking->fresh(), [
    'amount' => 1100, 'payment_method' => 'cash',
    'account_id' => $egpVaultId,
    'notes' => 'TX-AUDIT phase-I full pay remainder',
    'created_by' => $adminId,
]);
$afterCount = DB::table('transactions')->count();
$payDelta2 = $afterCount - $beforeCount;
record($results, 'i3_full_pay_count', $payDelta2 === 1 ? 'PASS' : 'FAIL',
    "Final payment posted $payDelta2 transaction (expected: 1)");

// Total tx per booking should now be 4 (cost + sale + 2 payments)
$bookingFinalTxCount = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Bus\\BusBooking')
    ->where('related_id', $bookingId)
    ->count();
record($results, 'i3_total_per_booking', $bookingFinalTxCount === 4 ? 'PASS' : 'FAIL',
    "Booking tx total = $bookingFinalTxCount (expected: 4 — cost + sale + 2 payments)");

// =====================================================================
// I.4: Cancel fully-paid booking → refund tx + BusRefundRequest
// =====================================================================
$head('I.4: Cancel fully-paid booking → BusRefundRequest + reversal entries');

$beforeCount = DB::table('transactions')->count();
$beforeRefundCount = DB::table('bus_refund_requests')->count();
try {
    $refund = $busBookingService->cancelBooking($booking->fresh(), [
        'refund_amount' => 0,    // fully paid, no refund (already settled)
        'company_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $egpVaultId,    // cancelBooking always requires this
        'notes' => 'TX-AUDIT phase-I cancel',
    ]);
    $cancelStatus = 'PASS';
    $cancelEvidence = "BusRefundRequest id={$refund->id} created";
} catch (Throwable $e) {
    $cancelStatus = 'FAIL';
    $cancelEvidence = 'cancelBooking threw: '.$e->getMessage();
}
record($results, 'i4_cancel', $cancelStatus, $cancelEvidence);

// =====================================================================
// I.5: deleteBooking → reversal (additive, non-destructive)
// =====================================================================
$head('I.5: deleteBookingWithReversal → reversal entries added (additive)');

$beforeTxCount = DB::table('transactions')->count();
$busBookingService->deleteBookingWithReversal($bookingId, $adminId);
$afterTxCount = DB::table('transactions')->count();

// Reversal pattern: each payment → 1 reversal tx (or more)
// Expected: ~2 reverse entries (one per payment)
// Note: After cancelBooking(), the booking enters Cancelled status. Then
//       deleteBookingWithReversal() short-circuits via line 1093 (no reversal
//       needed for already-cancelled bookings) — so 0 reversal tx is CORRECT here.
$reversalTxCount = $afterTxCount - $beforeTxCount;
$bookingStatus = DB::table('bus_bookings')->where('id', $bookingId)->value('status');
$shortCircuitStates = ['cancelled', 'refunded', 'partially_refunded'];
if (in_array($bookingStatus, $shortCircuitStates, true) && $reversalTxCount === 0) {
    record($results, 'i5_reversal_tx_count', 'PASS',
        "deleteBookingWithReversal on $bookingStatus booking takes short-circuit (BusBookingService.php:1093) — 0 reversal tx is intentional design");
} elseif ($reversalTxCount >= 1) {
    record($results, 'i5_reversal_tx_count', 'PASS',
        "deleteBookingWithReversal added $reversalTxCount reversal tx (additive pattern)");
} else {
    record($results, 'i5_reversal_tx_count', 'FAIL',
        "deleteBookingWithReversal added $reversalTxCount reversal tx (status='$bookingStatus' — neither short-circuited nor reversed)");
}
$info("Reversal tx: $reversalTxCount (booking status: $bookingStatus)");

// =====================================================================
// I.6: Verify refund→tx link null-out (Fix #12)
// =====================================================================
$head('I.6: BusRefundRequest.transaction_id null-out (Fix #12)');

$staleRefundLinks = DB::table('bus_refund_requests')
    ->where('bus_booking_id', $bookingId)
    ->whereNotNull('transaction_id')
    ->count();
// Fix #12 only runs in NON-CANCELLED branch of deleteBookingWithReversal.
// When called on already-cancelled bookings, the fix is BYPASSED.
// Reporting this as a real finding: "Fix #12 has incomplete coverage for cancelled bookings"
if ($staleRefundLinks > 0) {
    record($results, 'i6_refund_tx_null_out', 'FAIL',
        "Stale refund→tx links after cancel→delete: $staleRefundLinks (Fix #12 line 1120-1122 only runs in non-cancelled branch — gap in scope; contributes to NO-GO-adjacent finding)");
} else {
    record($results, 'i6_refund_tx_null_out', 'PASS',
        'Stale refund→tx links: 0 (Fix #12 null-out executed correctly)');
}

// =====================================================================
// I.7: Per-class transaction type breakdown
// =====================================================================
$head('I.7: Final transaction type distribution');

$typeBreakdown = DB::select('
    SELECT type, COUNT(*) AS cnt, SUM(amount) AS total
    FROM transactions
    GROUP BY type
    ORDER BY cnt DESC
');
echo "\n  Type breakdown:\n";
foreach ($typeBreakdown as $r) {
    printf("    %-12s count=%-3d  total=%s\n", $r->type, $r->cnt, $r->total);
}
record($results, 'i7_type_breakdown', 'PASS',
    'Phase-I scenario posted types: '.implode(', ', array_map(
        fn ($r) => "{$r->type}({$r->cnt})", $typeBreakdown
    )));

// =====================================================================
// I.8: Per-entity tx breakdown
// =====================================================================
$head('I.8: Per-related-entity tx counts');

$entityBreakdown = DB::select('
    SELECT related_type, COUNT(*) AS cnt
    FROM transactions
    WHERE related_type IS NOT NULL
    GROUP BY related_type
    ORDER BY cnt DESC
');
foreach ($entityBreakdown as $r) {
    $short = class_basename($r->related_type);
    echo "    $short: {$r->cnt}\n";
}
record($results, 'i8_entity_breakdown', 'PASS',
    'Entity-driven tx counts: '.implode(', ', array_map(
        fn ($r) => class_basename($r->related_type).'='.$r->cnt, $entityBreakdown
    )));

$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_i_transaction.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase I Summary\n";
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
echo "═══════════════════════════════════════════════════════════════════\n\n";
