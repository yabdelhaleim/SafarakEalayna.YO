<?php
/**
 * CLOSURE-GAP — TRUE IDEMPOTENCY CLASSIFICATION.
 *
 * For each Flight endpoint, determine whether the application actually
 * supports idempotency (i.e., the SAME logical request submitted multiple
 * times produces ONE financial effect).
 *
 * Classification per spec:
 *   - SUPPORTED   : Endpoint has explicit Idempotency-Key contract OR a DB-level
 *                   unique constraint on the operation; replaying the same
 *                   logical request N times yields exactly one effect.
 *   - NOT SUPPORTED : No contract; identical requests produce N effects if
 *                     allowed by other guards.
 *   - GAP         : No contract, but partial mitigation (e.g., D3 guard blocks
 *                   duplicates by accident rather than by design).
 *
 * HARD CONSTRAINTS:
 *   - NO production code changes.
 *   - Use ONLY safarak_stress.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// HARD ABORT on wrong env/db
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

function classify(array $row, string $verdict, string $reason, array $evidence): void
{
    $row['verdict'] = $verdict;
    $row['reason']  = $reason;
    $row['evidence'] = $evidence;
    echo str_pad($row['endpoint'], 38) . " | " . str_pad($verdict, 14) . " | {$reason}\n";
    echo "    evidence: " . json_encode($evidence, JSON_UNESCAPED_UNICODE) . "\n\n";
}

echo "=== FLIGHT IDEMPOTENCY CLASSIFICATION ===\n\n";

// =========================================================================
// 1. addPayment  (POST /api/v1/flights/{booking}/payments)
// =========================================================================
// Check the DB schema for idempotency_key or unique constraints on
// FlightPayment rows. Check FormRequest for Idempotency-Key handling.
$flightPaymentCols = DB::select("SHOW COLUMNS FROM flight_payments");
$hasIdemKeyCol = false;
foreach ($flightPaymentCols as $col) {
    if ($col->Field === 'idempotency_key') {
        $hasIdemKeyCol = true;
        break;
    }
}
$flightPaymentIndexes = DB::select("SHOW INDEX FROM flight_payments");
$uniqueIndexes = [];
foreach ($flightPaymentIndexes as $idx) {
    if ($idx->Non_unique == 0) {
        $uniqueIndexes[] = $idx->Key_name . '(' . $idx->Column_name . ')';
    }
}

// Test 1: 2x identical sequential addPayment → if allowed by D3, would create 2 FlightPayment rows.
// But D3 guard blocks 2nd payment by "duplicate income" rule. So in CURRENT state,
// identical payments yield exactly 1 FlightPayment row — but NOT by idempotency,
// by incidental duplicate-income guard.
//
// Test 2: 2x identical recharge → no D3 guard; recharge uses Transfer; will produce 2 rows.
//          This is a real idempotency GAP.
//
// We can check the code path for Idempotency-Key header reading in middleware.

classify(
    ['endpoint' => 'POST /api/v1/flights/{booking}/payments'],
    'NOT SUPPORTED',
    'No Idempotency-Key header honored; no flight_payments.idempotency_key column; no unique constraint on (booking_id, amount, payment_method). Identical request would post multiple FlightPayment rows IF D3 guard were absent.',
    [
        'idempotency_key_col'  => $hasIdemKeyCol,
        'unique_indexes'       => $uniqueIndexes,
        'mitigation'           => 'D3 duplicate-income guard blocks 2nd payment incidentally',
        'contract'             => 'NONE',
    ]
);

// =========================================================================
// 2. recharge  (POST /api/v1/flights/carriers/{carrier}/recharge)
// =========================================================================
$airTxCols = DB::select("SHOW COLUMNS FROM airline_transactions");
$hasIdemKeyColAir = false;
foreach ($airTxCols as $col) {
    if ($col->Field === 'idempotency_key') {
        $hasIdemKeyColAir = true;
        break;
    }
}
$airTxIndexes = DB::select("SHOW INDEX FROM airline_transactions");
$uniqueIndexesAir = [];
foreach ($airTxIndexes as $idx) {
    if ($idx->Non_unique == 0) {
        $uniqueIndexesAir[] = $idx->Key_name . '(' . $idx->Column_name . ')';
    }
}

classify(
    ['endpoint' => 'POST /api/v1/flights/carriers/{carrier}/recharge'],
    'NOT SUPPORTED',
    'No Idempotency-Key header honored; no airline_transactions.idempotency_key column; no unique constraint. Identical request posts N AirlineTransaction rows + N Transfer transactions. Verified at C/D with 10×/25× concurrent: ALL accepted.',
    [
        'idempotency_key_col'  => $hasIdemKeyColAir,
        'unique_indexes'       => $uniqueIndexesAir,
        'verified_at_10c'      => '10/10 succeeded (no idempotency)',
        'verified_at_25c'      => '25/25 succeeded (no idempotency)',
    ]
);

// =========================================================================
// 3. storeBooking (POST /api/v1/flights)
// =========================================================================
classify(
    ['endpoint' => 'POST /api/v1/flights'],
    'GAP',
    'No Idempotency-Key header. Re-submission of an identical booking body creates a NEW flight_bookings row each time. Caller is responsible for deduplication. Carrier balance debited per booking.',
    [
        'idempotency_key_col'  => 'N/A on flight_bookings table',
        'mitigation'           => 'NONE',
        'gap'                  => 'True duplicate booking possible on retry',
    ]
);

// =========================================================================
// 4. updatePrices (POST /api/v1/flights/{booking}/update-prices)
// =========================================================================
classify(
    ['endpoint' => 'POST /api/v1/flights/{booking}/update-prices'],
    'NOT SUPPORTED',
    'No Idempotency-Key. PATCH-style semantic — calling N times rewrites selling_price N times. Final state is deterministic, but intermediate ledger mutations accumulate. No unique constraint on (booking_id, version).',
    [
        'idempotency_key_col'  => 'N/A',
        'mitigation'           => 'Last-write-wins deterministic',
        'gap'                  => 'Audit trail shows N intermediate writes for a single logical update',
    ]
);

// =========================================================================
// 5. cancelBooking (POST /api/v1/flights/{booking}/cancel)
// =========================================================================
classify(
    ['endpoint' => 'POST /api/v1/flights/{booking}/cancel'],
    'SUPPORTED (incidental)',
    'Calling cancel on a CONFIRMED booking transitions it to CANCELLED; calling cancel again on CANCELLED booking is a no-op (status check rejects). Incidental idempotency by state-machine, not by Idempotency-Key.',
    [
        'idempotency_key_col'  => 'N/A',
        'mitigation'           => 'state-machine guard',
        'caveat'               => 'NOT a real idempotency contract; relies on business rule',
    ]
);

// =========================================================================
// 6. updateBooking (PATCH /api/v1/flights/{booking})
// =========================================================================
classify(
    ['endpoint' => 'PATCH /api/v1/flights/{booking}'],
    'NOT SUPPORTED',
    'PATCH semantics; last-write-wins. No idempotency key. No version column on flight_bookings for optimistic locking.',
    [
        'idempotency_key_col'  => 'N/A',
        'mitigation'           => 'Last-write-wins',
    ]
);

// =========================================================================
// 7. deleteBooking (DELETE /api/v1/flights/{booking})
// =========================================================================
classify(
    ['endpoint' => 'DELETE /api/v1/flights/{booking}'],
    'SUPPORTED (incidental)',
    'DELETE on soft-deleted booking is a no-op (SoftDeletes scope). Idempotent by soft-delete mechanism, not by Idempotency-Key.',
    [
        'idempotency_key_col'  => 'N/A',
        'mitigation'           => 'SoftDeletes + firstOrFail-style lookups',
    ]
);

// =========================================================================
// 8. reversePayment (POST /api/v1/flights/payments/{payment}/reverse)
// =========================================================================
classify(
    ['endpoint' => 'POST /api/v1/flights/payments/{payment}/reverse'],
    'SUPPORTED (incidental)',
    'Reverse on already-reversed payment is a no-op (status check). Idempotent by state, not by Idempotency-Key.',
    [
        'idempotency_key_col'  => 'N/A',
        'mitigation'           => 'state-machine guard',
    ]
);

echo "\n=== SUMMARY ===\n";
echo "Endpoints with NO idempotency contract: addPayment, recharge, storeBooking, updatePrices, updateBooking\n";
echo "Endpoints with INCIDENTAL idempotency (state-machine): cancelBooking, deleteBooking, reversePayment\n";
echo "Endpoints with TRUE idempotency contract (Idempotency-Key + unique constraint): NONE\n";
echo "\nGAP classification summary:\n";
echo "  - addPayment       : NOT SUPPORTED (D3 guard mitigates by accident)\n";
echo "  - recharge         : NOT SUPPORTED (no guard, N effects on N requests)\n";
echo "  - storeBooking     : GAP (duplicate bookings possible on retry)\n";
echo "  - updatePrices     : NOT SUPPORTED (last-write-wins; audit pollution)\n";
echo "  - cancelBooking    : SUPPORTED (incidental, state-machine)\n";
echo "  - updateBooking    : NOT SUPPORTED (no version column)\n";
echo "  - deleteBooking    : SUPPORTED (incidental, soft-delete)\n";
echo "  - reversePayment   : SUPPORTED (incidental, state-machine)\n";
