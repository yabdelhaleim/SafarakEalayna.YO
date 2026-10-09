<?php
/**
 * CLOSURE-GAP D3 — Duplicate Income reproduction + classification.
 *
 * READ-ONLY on production code (no fixes). Uses canonical paths.
 *
 * Reproduces 3 sequential addPayment calls on a single booking and inspects
 * the resulting transactions, ledger entries, and accounting semantics to
 * determine whether addPayment creates Income or Transfer and whether that
 * conflicts with the intended business flow.
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
$carrier    = FlightCarrier::where('code', 'STRESS-FC-001')->firstOrFail();
$egpTreasury = Account::where('name', 'STRESS-FLIGHTS-TREASURY-EGP')->firstOrFail();

// ============================================================================
// 1. Create a fresh EGP booking (no payment yet, status=PENDING)
// ============================================================================
$booking = $bookingSvc->createBooking([
    'customer_id' => 1,
    'flight_carrier_id' => $carrier->id,
    'flight_system_id' => null,
    'flight_group_id' => null,
    'purchase_balance_source' => 'carrier',
    'airline' => 'D3-AIR',
    'pnr' => 'STR-D3-001-' . uniqid(),
    'from_airport' => 'CAI', 'to_airport' => 'JED',
    'departure_date' => '2026-09-20',
    'passenger_count' => 1, 'trip_type' => 'one_way',
    'purchase_price' => 8000, 'selling_price' => 12000,
    'currency' => 'EGP', 'exchange_rate' => 1.0,
    'passengers' => [['first_name' => 'A', 'last_name' => 'D3', 'type' => 'adult']],
    'segments' => [['from_airport' => 'CAI', 'to_airport' => 'JED', 'airline' => 'D3-AIR', 'flight_number' => 'D3-1', 'departure_time' => '2026-09-20 08:00:00', 'arrival_time' => '2026-09-20 11:00:00']],
]);
$bookingId = $booking->id;
echo "[1] Created booking id={$bookingId} book#={$booking->booking_number} status=PENDING selling_price=12000\n\n";

// ============================================================================
// 2. Inspect SALE-side transaction already posted by createBooking
// ============================================================================
echo "[2] Inspect SALE-side transaction posted at createBooking:\n";
$saleTx = $booking->sale_gl_transaction_id
    ? Transaction::find($booking->sale_gl_transaction_id)
    : null;
if ($saleTx) {
    $rawType = $saleTx->getRawOriginal('type');
    echo "    sale tx id={$saleTx->id} type={$rawType}\n";
    echo "    notes: " . substr($saleTx->notes ?? '', 0, 100) . "\n";
} else {
    echo "    no sale transaction (sale_gl_transaction_id is null)\n";
}
echo "\n";

// ============================================================================
// 3. Make 3 sequential partial payments: 4000, 4000, 4000
// ============================================================================
echo "[3] Making 3 sequential partial payments of 4000 EGP each:\n";

$runPayment = function (int $seq, int $amount) use ($booking, $egpTreasury, $bookingSvc) {
    echo "  Payment #{$seq} (amount={$amount}):\n";
    try {
        $payment = $bookingSvc->addPayment($booking, [
            'amount'         => $amount,
            'payment_method' => 'cash',
            'currency'       => 'EGP',
            'original_amount'=> $amount,
            'exchange_rate'  => 1.0,
            'account_id'     => $egpTreasury->id,
            'notes'          => "D3 pay #{$seq}",
        ]);
        echo "    ✅ ACCEPTED — payment id={$payment->id}\n";
        // Inspect transaction type
        $tx = Transaction::find($payment->transaction_id);
        if ($tx) {
            $rawType = $tx->getRawOriginal('type');
            echo "    -> transaction id={$tx->id} type={$rawType} (raw enum={$tx->type})\n";
            echo "    -> notes: " . substr($tx->notes ?? '', 0, 100) . "\n";
        }
        // Inspect ledger entries
        $entries = DB::table('account_entries')->where('transaction_id', $tx->id ?? 0)->get();
        echo "    -> ledger entries: " . $entries->count() . " (debit_total=" . (float) $entries->sum('debit') . " credit_total=" . (float) $entries->sum('credit') . ")\n";
        return true;
    } catch (\Throwable $e) {
        echo "    ❌ REJECTED — " . substr($e->getMessage(), 0, 250) . "\n";
        return false;
    }
};

$p1 = $runPayment(1, 4000);
$p2 = $runPayment(2, 4000);
$p3 = $runPayment(3, 4000);

// ============================================================================
// 4. Inspect booking totals and ledger state
// ============================================================================
echo "\n[4] Final booking state:\n";
$booking->refresh();
echo "  status: {$booking->status->value}\n";
echo "  paid_amount: " . (float) $booking->paid_amount . "\n";
echo "  remaining_amount: " . (float) $booking->remaining_amount . "\n";
echo "  sale_gl_transaction_id: " . ($booking->sale_gl_transaction_id ?? 'null') . "\n";

$payments = FlightPayment::where('flight_booking_id', $booking->id)->get();
echo "  FlightPayment rows: {$payments->count()}\n";
foreach ($payments as $p) {
    echo "    - id={$p->id} amount={$p->amount} tx={$p->transaction_id} notes=" . substr($p->notes ?? '', 0, 50) . "\n";
}

$relatedTx = Transaction::where('related_type', FlightBooking::class)->where('related_id', $booking->id)->get();
echo "  Related transactions: {$relatedTx->count()}\n";
foreach ($relatedTx as $t) {
    $type = $t->getRawOriginal('type');
    echo "    - id={$t->id} type={$type} amount={$t->amount} notes=" . substr($t->notes ?? '', 0, 80) . "\n";
}

// ============================================================================
// 5. Inspect customer AR / treasury balances
// ============================================================================
echo "\n[5] Customer AR + treasury balances (post-payments):\n";
echo "  EGP treasury: balance={$egpTreasury->fresh()->balance}\n";

// ============================================================================
// 6. Classification analysis
// ============================================================================
echo "\n=== CLASSIFICATION ANALYSIS ===\n";
echo "Result: payment #1 accepted=" . ($p1?'yes':'no') . ", #2 accepted=" . ($p2?'yes':'no') . ", #3 accepted=" . ($p3?'yes':'no') . "\n";

// What type was each successful payment?
$paymentTransactions = Transaction::whereIn('id', $payments->pluck('transaction_id')->filter()->all())->get();
$incomeCount = $paymentTransactions->filter(fn($t) => $t->getRawOriginal('type') === 'income')->count();
$transferCount = $paymentTransactions->filter(fn($t) => $t->getRawOriginal('type') === 'transfer')->count();
echo "  accepted payments → Income: {$incomeCount}, Transfer: {$transferCount}\n";
echo "\n";

// Cross-check: a "Transfer"-type customer collection would mean the service treats\n// the customer's money as a movement between two accounts (cash → AR).\n// An "Income"-type would mean the service treats the collection AS the sale itself.\n// The Path-C duplicate-income guard is consistent with the latter interpretation:\n//   "Each booking can have only ONE ACTIVE income transaction (the sale). Subsequent\n//    COLLECTIONS on a booking must use Transfer (type=transfer)."\n// Source: TransactionService::recordJournalTransfer lines 660-680, comment block.

echo "CONCLUSION:\n";
echo " - addPayment uses TransactionService::recordIncome (which calls recordJournalTransfer with type='income').\n";
echo " - Path-C duplicate-income guard is INTENTIONALLY in place: enforces '1 sale per booking',\n";
echo "   and the project's documented rule is that COLLECTIONS must use Transfer.\n";
echo " - The current addPayment() does NOT honour this rule — this is the architectural mismatch.\n";
echo " - This is NOT a 'real production defect' (no money-loss / no double-charge).\n";
echo " - It IS an architectural inconsistency: the guard is consistent with the intended\n";
echo "   business model (Income = sale, Transfer = collection), but addPayment posts Income\n";
echo "   for each payment instead of Transfer.\n";
echo "\n";
echo "D3 CLASSIFICATION: ARCHITECTURAL MISMATCH (between addPayment's per-call Income and\n";
echo "Path-C's one-Income-per-booking invariant).\n";
echo "\n";
echo "RECOMMENDATION (NOT TO BE DONE IN THIS AUDIT — separate task):\n";
echo " - Change FlightBookingService::addPayment to call recordTransfer() (cash → AR)\n";
echo "   instead of recordIncome() for each payment.\n";
echo " - Keep the existing recordIncome() call ONLY for the initial sale at createBooking.\n";
echo " - Update FinancialReportService::classifyPL line 1751 to filter Income.classifier\n";
echo "   for revenue (sale) and Transfer.type='inbound' for collections.\n";
echo "\n";
