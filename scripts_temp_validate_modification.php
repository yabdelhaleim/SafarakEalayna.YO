<?php
/**
 * REAL DB VALIDATION SCENARIO 3: TicketModification reversal
 *
 * Tests that:
 *   1. A confirmed modification has its AirlineAccount.balance debited by airline_change_fee
 *   2. Booking fields (departure_date, destination) are updated by the modification
 *   3. ModificationController::destroy:
 *      - Credits back the AirlineAccount for the full fee (balance restored)
 *      - Restores booking fields (departure_date, destination, modification_count)
 *      - Soft-deletes the modification
 *      - Idempotency: second call returns 422 with Arabic error
 *
 * NOTE on listener bug:
 *   `ProcessTicketModificationAccounting` listener tries to firstOrCreate
 *   accounts with `type='liability'` / `'treasury'` which is not in the
 *   `AccountType` enum and not in the DB enum (post 2026_07_09 migration).
 *   So we simulate the post-confirmation state manually using the proper
 *   AirlineAccount::debit() method, which is what the listener WOULD do
 *   if the bug were fixed.
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\TicketModification;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\ModificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

$result = [
    'scenario' => 'TicketModification — Confirmed + Reversed via Controller',
    'success'  => false,
    'error'    => null,
    'steps'    => [],
    'balances' => [],
    'deltas'   => [],
    'verdict'  => [],
    'notes'    => [],
];

$realUser = User::orderBy('id')->first();
if (! $realUser) {
    $realUser = User::create([
        'name' => 'Modification Validator',
        'email' => 'mod-validator@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
}
auth()->setUser($realUser);
echo "[setup] User #{$realUser->id}\n";

$testEmployee = \App\Models\Employee::where('full_name', 'TEST_MOD_EMPLOYEE')->first();
if (! $testEmployee) {
    $testEmployee = \App\Models\Employee::create([
        'full_name' => 'TEST_MOD_EMPLOYEE',
        'phone' => '0000000000',
        'national_id' => 'TESTMOD1',
        'created_by' => $realUser->id,
    ]);
}

try {
    // ─────────────────────────────────────────────────────
    // Setup
    // ─────────────────────────────────────────────────────
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        $cashbox = Account::create([
            'name' => 'TEST_CASHBOX_MOD',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $realUser->id,
        ]);
    }

    $flightSystem = FlightSystem::where('name', 'TEST_SYSTEM_MOD')->first();
    if (! $flightSystem) {
        $flightSystem = FlightSystem::create([
            'name' => 'TEST_SYSTEM_MOD',
            'code' => 'TSM'.substr(md5(uniqid()), 0, 4),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $realUser->id,
        ]);
    }

    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_MOD')->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name' => 'TEST_CARRIER_MOD',
            'code' => 'TCM'.substr(md5(uniqid()), 0, 4),
            'flight_system_id' => $flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $realUser->id,
        ]);
    }
    if ((float) $carrier->balance < 500.0) {
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 500.0, 'Modification validator setup'
        );
    }

    $customer = Customer::where('full_name', 'TEST_MOD_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name' => 'TEST_MOD_CUSTOMER',
            'phone' => '0000000000',
            'passport' => 'TEST-MOD-'.uniqid(),
            'created_by' => $realUser->id,
        ]);
    }

    $airlineAccount = AirlineAccount::where('name', 'TEST_AIRLINE_ACCOUNT_MOD')->first();
    if (! $airlineAccount) {
        $airlineAccount = AirlineAccount::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'TEST_AIRLINE_ACCOUNT_MOD',
            'code' => 'TAAM'.substr(md5(uniqid()), 0, 4),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'is_active' => true,
        ]);
    }
    // Ensure airline account starts with a known balance (5000 EGP).
    // Use LedgerBalanceMutationGuard to bypass the boot guard (allowed in real DB context).
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($airlineAccount) {
        $airlineAccount->balance = 5000.00;
        $airlineAccount->save();
    });
    $airlineAccount->refresh();

    // ─────────────────────────────────────────────────────
    // Create booking with airline_account_id
    // ─────────────────────────────────────────────────────
    $sellingPrice = 200.0;
    $purchasePrice = 150.0;
    $originalDepartureDate = now()->addDays(7)->format('Y-m-d');
    $originalDestination = 'JED';
    $newDepartureDate = now()->addDays(14)->format('Y-m-d');
    $newDestination = 'RUH';
    $airlineChangeFee = 500.0;

    $booking = app(FlightBookingService::class)->createBooking([
        'booking_reference' => 'MOD-'.uniqid(),
        'customer_id' => $customer->id,
        'employee_id' => $testEmployee->id,
        'airline' => 'TEST_AIRLINE',
        'airline_name' => 'Modification Test',
        'origin' => 'TST', 'destination' => $originalDestination,
        'from_airport' => 'Test Origin', 'to_airport' => 'Test Dest',
        'departure_date' => $originalDepartureDate,
        'departure_time' => '12:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'purchase_price' => $purchasePrice,
        'selling_price' => $sellingPrice,
        'profit' => $sellingPrice - $purchasePrice,
        'currency' => $carrier->currency,
        'purchase_balance_source' => 'carrier',
        'flight_carrier_id' => $carrier->id,
        'airline_account_id' => $airlineAccount->id,
        'baggage_allowance_kg' => 0,
        'exchange_rate' => 1.0,
        'passengers' => [['full_name' => 'MOD PAX', 'passport_number' => 'MOD-PP', 'type' => 'adult']],
    ]);

    app(FlightBookingService::class)->addPayment($booking, [
        'amount' => $sellingPrice,
        'currency' => $carrier->currency,
        'payment_method' => 'cash',
        'account_id' => $cashbox->id,
    ]);

    $result['steps']['create_booking'] = [
        'booking_id' => $booking->id,
        'booking_number' => $booking->booking_number,
    ];

    // Snapshot AFTER booking (BEFORE modification) — this is our baseline
    $afterBooking = [
        'airline_account'        => (float) $airlineAccount->fresh()->balance,
        'booking_departure_date' => $booking->fresh()->departure_date->toDateString(),
        'booking_destination'    => $booking->fresh()->destination,
        'modification_count'     => (int) $booking->fresh()->modification_count,
    ];
    $result['balances']['after_booking'] = $afterBooking;
    echo "[after_booking] " . json_encode($afterBooking) . "\n";

    // ─────────────────────────────────────────────────────
    // Create modification request + CONFIRM it via the REAL service.
    // The listener will fire and post the GL entries (cashbox/liability/revenue
    // accounts). This is the FULL production code path — no simulation.
    // ─────────────────────────────────────────────────────
    $modification = app(ModificationService::class)->createRequest([
        'booking_id'         => $booking->id,
        'modification_type'  => 'date_change',
        'new_departure_date' => $newDepartureDate,
        'new_destination'    => $newDestination,
        'airline_change_fee' => $airlineChangeFee,
        'agency_commission'  => 100.0,
        'currency'           => 'EGP',
        'payment_method'     => 'cash',
        'notes'              => 'Modification validator test',
    ], $realUser->id);

    // Mark as confirmed with snapshots, then call confirmModification to fire listener
    $modification->airline_change_fee_snapshot = $airlineChangeFee;
    $modification->commission_snapshot = 100.0;
    $modification->modified_by = $realUser->id;
    $modification->save();

    // This dispatches the TicketModified event → listener fires → GL posted
    $modification = app(ModificationService::class)->confirmModification($modification->id, $realUser->id);

    // The listener (ProcessTicketModificationAccounting) implements ShouldQueue.
    // In production it's processed asynchronously; for this real-DB validation
    // we drain the queue synchronously to confirm the listener works end-to-end.
    \Illuminate\Support\Facades\Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $result['steps']['confirmed_simulation'] = [
        'modification_id' => $modification->id,
        'note' => 'Real confirmModification called — listener executed end-to-end (queue:work --once)',
    ];

    // Snapshot AFTER confirmation (BEFORE reversal) — should differ from after_booking
    $afterConfirm = [
        'airline_account'        => (float) $airlineAccount->fresh()->balance,
        'booking_departure_date' => $booking->fresh()->departure_date->toDateString(),
        'booking_destination'    => $booking->fresh()->destination,
        'modification_count'     => (int) $booking->fresh()->modification_count,
    ];
    $result['balances']['after_confirm'] = $afterConfirm;
    echo "[after_confirm] " . json_encode($afterConfirm) . "\n";

    $confirmDelta = [
        'airline_account'    => round($afterConfirm['airline_account']    - $afterBooking['airline_account'],    2),
        'departure_changed'  => ($afterConfirm['booking_departure_date'] !== $afterBooking['booking_departure_date']),
        'destination_changed'=> ($afterConfirm['booking_destination']    !== $afterBooking['booking_destination']),
        'count_changed'      => ($afterConfirm['modification_count']     !=  $afterBooking['modification_count']),
    ];
    $result['deltas']['confirm_vs_after_booking'] = $confirmDelta;
    echo "[confirm_delta] " . json_encode($confirmDelta) . "\n";

    // ─────────────────────────────────────────────────────
    // Call ModificationController::destroy (same as Vue UI) — NO WORKAROUND
    //
    // The previous version of this script wrapped the call in
    // LedgerBalanceMutationGuard to bypass a production bug in
    // reverseConfirmation() that did direct `$airlineAccount->balance = X; ->save()`.
    //
    // That bug has been fixed: reverseConfirmation() now uses
    // AirlineAccount::credit() which goes through mutateBalanceInternal()
    // and sets the internalBalanceUpdate flag — so the boot guard allows it.
    // ─────────────────────────────────────────────────────
    $controller = app(\App\Http\Controllers\Api\V1\Flight\ModificationController::class);
    $request = Request::create("/api/v1/flight/modifications/{$modification->id}", 'DELETE');
    $request->setUserResolver(fn () => $realUser);

    $controllerResult = $controller->destroy($request, $modification->id);

    $result['steps']['controller_destroy'] = [
        'http_status' => $controllerResult->getStatusCode(),
        'response' => json_decode($controllerResult->getContent(), true),
        'guard_workaround_used' => false,
        'note' => 'No workaround — production fix in place (AirlineAccount::credit())',
    ];

    if ($controllerResult->getStatusCode() >= 400) {
        throw new \RuntimeException('Controller returned error: ' . $controllerResult->getContent());
    }

    // Refresh entities
    $modification->refresh();
    $booking->refresh();
    $airlineAccount->refresh();

    // Snapshot AFTER reversal — should match after_booking
    $afterReversal = [
        'airline_account'        => (float) $airlineAccount->balance,
        'booking_departure_date' => $booking->departure_date->toDateString(),
        'booking_destination'    => $booking->destination,
        'modification_count'     => (int) $booking->modification_count,
    ];
    $result['balances']['after_reversal'] = $afterReversal;
    echo "[after_reversal] " . json_encode($afterReversal) . "\n";

    $reversalDelta = [
        'airline_account'    => round($afterReversal['airline_account']    - $afterBooking['airline_account'],    2),
        'departure_restored' => ($afterReversal['booking_departure_date'] === $afterBooking['booking_departure_date']),
        'dest_restored'      => ($afterReversal['booking_destination']    === $afterBooking['booking_destination']),
        'count_restored'     => ($afterReversal['modification_count']     ==  $afterBooking['modification_count']),
    ];
    $result['deltas']['reversal_vs_after_booking'] = $reversalDelta;
    echo "[reversal_delta_vs_after_booking] " . json_encode($reversalDelta) . "\n";

    // ─────────────────────────────────────────────────────
    // Idempotency test
    // ─────────────────────────────────────────────────────
    $secondCall = $controller->destroy($request, $modification->id);
    $result['steps']['idempotency_check'] = [
        'second_call_status' => $secondCall->getStatusCode(),
        'second_call_body' => json_decode($secondCall->getContent(), true),
    ];
    $decodedBody = json_decode($secondCall->getContent(), true);
    $idempotencyOk = ($secondCall->getStatusCode() === 422 && isset($decodedBody['message']) && str_contains($decodedBody['message'], 'محذوف بالفعل'));

    // ─────────────────────────────────────────────────────
    // Verdict
    // ─────────────────────────────────────────────────────
    $result['verdict'] = [
        'airline_had_effect'        => ($confirmDelta['airline_account'] == -$airlineChangeFee),
        'booking_fields_updated'    => ($confirmDelta['departure_changed'] && $confirmDelta['destination_changed'] && $confirmDelta['count_changed']),
        'airline_account_restored'  => ($reversalDelta['airline_account'] == 0.0),
        'departure_restored'        => $reversalDelta['departure_restored'],
        'destination_restored'      => $reversalDelta['dest_restored'],
        'count_restored'            => $reversalDelta['count_restored'],
        'modification_trashed'      => $modification->trashed(),
        'idempotency_throws'        => $idempotencyOk,
    ];

    $result['notes'][] = 'Known GAP (still present): AirlineAccount.balance is mutated directly (no paired GL reversal entry). This mirrors the confirmation flow which also debited directly (Phase 1v2 todo — see docs/ARCHITECTURE.md § 8.5).';
    $result['notes'][] = 'Listener now works end-to-end (2026-07-11 fix): migration added "liability" + "revenue" to accounts.type enum, PHP AccountType enum gained Liability case, listener line 97 changed "treasury" → "cashbox". ModificationService::reverseConfirmation now uses AirlineAccount::credit() instead of direct mutation.';

    $result['success'] = $result['verdict']['airline_had_effect']
        && $result['verdict']['booking_fields_updated']
        && $result['verdict']['airline_account_restored']
        && $result['verdict']['departure_restored']
        && $result['verdict']['destination_restored']
        && $result['verdict']['count_restored']
        && $result['verdict']['modification_trashed']
        && $result['verdict']['idempotency_throws'];

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
    $result['trace_excerpt'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 8);
}

file_put_contents(
    storage_path('logs/real_db_modification.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== SCENARIO 3 RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";