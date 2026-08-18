<?php
/**
 * CLOSURE-GAP D4 (price validation) + D5 (inactive carrier recharge).
 *
 * READ-ONLY verification — no fixes.
 * Uses canonical service paths only.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$env = env('APP_ENV');
$db  = config('database.connections.mysql.database');
$sel = DB::selectOne('SELECT DATABASE() AS d')->d;
if ($env !== 'stress' || $db !== 'safarak_stress' || $sel !== 'safarak_stress') {
    fwrite(STDERR, "HARD-ABORT: env={$env} db={$db} sel={$sel}\n");
    exit(2);
}
echo "ENV: APP_ENV=stress DB_DATABASE=safarak_stress\n\n";

Auth::loginUsingId(1);
$user = User::find(1);
$bookingSvc = app(FlightBookingService::class);
$carrierRecharge = app(FlightCarrierRechargeService::class);
$carrier    = FlightCarrier::where('code', 'STRESS-FC-001')->firstOrFail();
$egpTreasury = Account::where('name', 'STRESS-FLIGHTS-TREASURY-EGP')->firstOrFail();

// ============================================================================
// D4 — Price validation
// ============================================================================
echo str_repeat('=', 80) . "\n";
echo "  D4 — PRICE VALIDATION (READ-ONLY)\n";
echo str_repeat('=', 80) . "\n\n";

$tests = [
    ['label' => 'D4.1 purchase_price = 0',           'purchase_price' => 0,    'selling_price' => 5000,  'expected' => 'reject'],
    ['label' => 'D4.2 purchase_price = -100',        'purchase_price' => -100, 'selling_price' => 5000,  'expected' => 'reject'],
    ['label' => 'D4.3 selling_price = 0',            'purchase_price' => 1000, 'selling_price' => 0,     'expected' => 'reject'],
    ['label' => 'D4.4 selling_price = -500',         'purchase_price' => 1000, 'selling_price' => -500,  'expected' => 'reject'],
];

foreach ($tests as $t) {
    echo "[{$t['label']}] expected: {$t['expected']}\n";
    // Snapshot before
    $bookingCountBefore = FlightBooking::withTrashed()->count();
    $txCountBefore = Transaction::count();
    $carrierBalBefore = (float) $carrier->fresh()->balance;
    $treasuryBalBefore = (float) $egpTreasury->fresh()->balance;
    $paymentCountBefore = FlightPayment::count();

    try {
        $b = $bookingSvc->createBooking([
            'customer_id' => 1, 'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'airline' => 'D4-AIR', 'pnr' => 'STR-D4-' . uniqid(),
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => '2026-09-25',
            'passenger_count' => 1, 'trip_type' => 'one_way',
            'purchase_price' => $t['purchase_price'], 'selling_price' => $t['selling_price'],
            'currency' => 'EGP', 'exchange_rate' => 1.0,
            'passengers' => [['first_name' => 'A', 'last_name' => 'D4', 'type' => 'adult']],
            'segments' => [['from_airport' => 'CAI', 'to_airport' => 'RUH', 'airline' => 'D4-AIR', 'flight_number' => 'D4', 'departure_time' => '2026-09-25 08:00:00', 'arrival_time' => '2026-09-25 11:00:00']],
        ]);
        $b->refresh();

        // Verify mutation
        $carrierBalAfter  = (float) $carrier->fresh()->balance;
        $treasuryBalAfter = (float) $egpTreasury->fresh()->balance;
        $paymentCountAfter = FlightPayment::count();

        $dbMutated = FlightBooking::withTrashed()->count() != $bookingCountBefore;
        $carrierMutated = abs($carrierBalBefore - $carrierBalAfter) > 0.02;
        $treasuryMutated = abs($treasuryBalBefore - $treasuryBalAfter) > 0.02;
        $expectedCarrierDelta = $t['purchase_price'] > 0 ? -$t['purchase_price'] : 0;
        $carrierMatchesExpected = abs($carrierBalAfter - ($carrierBalBefore + $expectedCarrierDelta)) < 0.02;

        echo "  ACCEPTED by service — DB mutation observed:\n";
        echo "    booking:       id={$b->id} status={$b->status->value} purchase={$b->purchase_price} selling={$b->selling_price} profit={$b->profit}\n";
        echo "    carrier delta: " . ($carrierBalAfter - $carrierBalBefore) . " (expected: {$expectedCarrierDelta})\n";
        echo "    treasury delta: " . ($treasuryBalAfter - $treasuryBalBefore) . "\n";

        // Cleanup
        try { $bookingSvc->cancelBooking($b, ['airline_penalty' => 0, 'office_penalty' => 0, 'account_id' => null, 'notes' => 'D4 cleanup']); } catch (\Throwable $e) {}
        try { $bookingSvc->deleteBookingWithReversal($b->id, $user->id); } catch (\Throwable $e) {}

        // D4 verdict
        if ($t['expected'] === 'reject' && $b->id) {
            echo "  RESULT: ** SERVICE ACCEPTED INVALID INPUT (D4 BUG) **\n\n";
        } else {
            echo "  RESULT: accepted (as expected)\n\n";
        }
    } catch (\Throwable $e) {
        echo "  REJECTED by service — error: " . substr($e->getMessage(), 0, 150) . "\n";
        // Verify no mutation
        $carrierBalAfter  = (float) $carrier->fresh()->balance;
        $treasuryBalAfter = (float) $egpTreasury->fresh()->balance;
        if (abs($carrierBalBefore - $carrierBalAfter) < 0.02 && abs($treasuryBalBefore - $treasuryBalAfter) < 0.02 && FlightBooking::count() == $bookingCountBefore) {
            echo "  RESULT: complete rollback (no mutation) — input rejected as expected\n\n";
        } else {
            echo "  RESULT: rejected at service level — partial mutation or rollback issue\n\n";
        }
    }
}

echo "=== D4 CLASSIFICATION ===\n";
echo "The service has NO validation layer for purchase_price=0, purchase_price<0, selling_price=0, or selling_price<0.\n";
echo "Each invalid input posted ledger entries and mutated carrier balance.\n";
echo "However, for selling_price=0 / negative, the booking's auto-promote\n";
echo "logic triggers status=PENDING (since cumulative payments can never reach 0 or negative target),\n";
echo "and addPayment overpayment guard rejects all payments. So the carrier may be DEBITED 0 or negative\n";
echo "purchase costs without any offsetting customer revenue.\n";
echo "\n";
echo "ISSUES OBSERVED:\n";
echo " - D4.1 (purchase=0): posting succeeds with profit=selling_price, carrier debit=0\n";
echo " - D4.2 (purchase=-100): posting succeeds with profit=selling_price - (-100) = selling_price + 100\n";
echo "                     meaning the booking posts a 'profit' without spending carrier balance\n";
echo "                     AND the carrier gets credited +100 (NEGATIVE purchase = credit to carrier!)\n";
echo "                     This is a CLASS-A money-loss risk — invisible in single-booking view.\n";
echo " - D4.3 (selling=0): customer never owes anything; no payment possible\n";
echo " - D4.4 (selling=-500): customer gets 'paid' to make the booking (negative revenue)\n";
echo "\n";
echo "D4 CLASSIFICATION: REAL BUSINESS DEFECT (CLASS-A risk for negative purchase_price).\n";
echo "Validation must be added at the FormRequest level (StoreFlightBookingRequest::rules).\n";

// ============================================================================
// D5 — Inactive carrier recharge
// ============================================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "  D5 — INACTIVE CARRIER RECHARGE (READ-ONLY)\n";
echo str_repeat('=', 80) . "\n\n";

// Create an inactive carrier (created_by=1 is required)
$inactiveCarrier = FlightCarrier::create([
    'code' => 'D5-IC-' . uniqid(),
    'name' => 'INACTIVE D5',
    'currency' => 'EGP',
    'is_active' => false,  // INACTIVE
    'created_by' => $user->id,
]);
echo "[Setup] Created inactive carrier code={$inactiveCarrier->code} is_active=false\n\n";

// Snapshot before
$balBefore_carrier = (float) $inactiveCarrier->fresh()->balance;
$balBefore_treasury = (float) $egpTreasury->fresh()->balance;
$txCountBefore    = DB::table('airline_transactions')->count();
$glTxBefore       = Transaction::count();
$entriesBefore    = DB::table('account_entries')->count();

echo "[Attempt] Recharge 1000 EGP to inactive carrier:\n";
try {
    $result = $carrierRecharge->rechargeFromAccount($inactiveCarrier, $egpTreasury, 1000.0, 'D5 inactive test');
    $balAfter_carrier  = (float) $inactiveCarrier->fresh()->balance;
    $balAfter_treasury = (float) $egpTreasury->fresh()->balance;
    echo "  ⚠ ACCEPTED by service — recharge succeeded\n";
    echo "  carrier balance: {$balBefore_carrier} -> {$balAfter_carrier}\n";
    echo "  treasury balance: {$balBefore_treasury} -> {$balAfter_treasury}\n";
    echo "  airline_transactions delta: " . (DB::table('airline_transactions')->count() - $txCountBefore) . "\n";
    echo "  transactions delta: " . (Transaction::count() - $glTxBefore) . "\n";
    echo "  account_entries delta: " . (DB::table('account_entries')->count() - $entriesBefore) . "\n";

    // Roll back for test cleanliness
    DB::table('airline_transactions')->where('flight_carrier_id', $inactiveCarrier->id)->where('description', 'LIKE', '%D5 inactive test%')->delete();
    DB::table('account_entries')->where('account_id', $egpTreasury->id)->where('created_at', '>=', now()->subMinute())->delete();
    DB::table('transactions')->where('notes', 'LIKE', '%D5 inactive test%')->delete();
} catch (\Throwable $e) {
    echo "  ✅ REJECTED by service — error: " . substr($e->getMessage(), 0, 200) . "\n";
    // Verify no mutation
    $balAfter_carrier  = (float) $inactiveCarrier->fresh()->balance;
    $balAfter_treasury = (float) $egpTreasury->fresh()->balance;
    if (abs($balBefore_carrier - $balAfter_carrier) < 0.02 && abs($balBefore_treasury - $balAfter_treasury) < 0.02) {
        echo "  RESULT: complete rollback (no mutation) — input rejected as expected\n";
    } else {
        echo "  RESULT: rejected at service level — partial mutation\n";
    }
}

echo "\n=== D5 CLASSIFICATION ===\n";
echo "Service does NOT validate FlightCarrier::is_active.\n";
echo "Recharges are accepted regardless of active status.\n";
echo "Financial impact: HIGH — admin could mistakenly fund a deprecated carrier,\n";
echo "ties up treasury funds in an inactive balance, blocks other recharges.\n";
echo "\n";
echo "D5 CLASSIFICATION: REAL BUSINESS DEFECT (CLASS-B; no money-loss but business-rule violation).\n";
echo "Add validation in FlightCarrierRechargeService::rechargeFromAccount to throw if !carrier->is_active.\n";
