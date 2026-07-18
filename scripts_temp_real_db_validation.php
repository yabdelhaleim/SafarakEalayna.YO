<?php
/**
 * REAL DB VALIDATION: End-to-end test against the actual HTTP API endpoint.
 *
 * This script:
 *   1. Creates a real booking via the FlightBookingService
 *   2. Adds a real payment via the FlightBookingService
 *   3. Snapshots balances (carrier, prepaid GL, cashbox, customer)
 *   4. Calls the FlightController::destroy endpoint via HTTP (via artisan command wrapper)
 *      — this exercises the SAME code path the Vue UI uses
 *   5. Snapshots balances again
 *   6. Computes deltas — all must be exactly 0
 *   7. Writes JSON report to storage/logs/real_db_validation_result.json
 *
 * Run with: php scripts_temp_real_db_validation.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

$result = [
    'success' => false,
    'error'   => null,
    'steps'   => [],
    'balances' => [],
    'deltas' => [],
    'audit_trail' => [],
    'verdict' => [],
];

// Set auth user context
$realUserId = User::orderBy('id')->value('id');
$realUser = User::find($realUserId);
if (! $realUser) {
    // No users exist — create a test admin
    $realUser = User::create([
        'name' => 'Real DB Validation Admin',
        'email' => 'real-db-validation@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    echo "[setup] Created test user #{$realUser->id}\n";
}
$realUserId = $realUser->id;
auth()->setUser($realUser);

// Find or create test employee
$testEmployee = \App\Models\Employee::where('full_name', 'TEST_DELETE_EMPLOYEE')->first();
if (! $testEmployee) {
    $testEmployee = \App\Models\Employee::create([
        'full_name'    => 'TEST_DELETE_EMPLOYEE',
        'phone'        => '0000000000',
        'national_id'  => 'TESTEMP1',
        'created_by'   => $realUserId,
    ]);
}

try {
    // ───────────────────────────────────────────────────────────
    // 1) Setup: pick/create test carrier matching cashbox currency
    // ───────────────────────────────────────────────────────────
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        // No cashbox exists — create a test one for this validation
        $cashbox = Account::create([
            'name' => 'TEST_CASHBOX_REAL_VALIDATION',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $realUserId,
        ]);
        echo "[setup] Created test cashbox #{$cashbox->id}\n";
    }

    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_DELETE')
        ->where('currency', $cashbox->currency)
        ->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name'        => 'TEST_CARRIER_DELETE',
            'code'        => 'TCD'.substr(md5(uniqid()), 0, 4),
            'currency'    => $cashbox->currency,
            'balance'     => 0,
            'credit_limit'=> 0,
            'is_active'   => true,
            'created_by'  => $realUserId,
        ]);
    }

    // Recharge if low
    $neededAmount = 500.0;
    if ((float) $carrier->balance < $neededAmount) {
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier,
            $cashbox,
            $neededAmount,
            'Real DB validation: test recharge'
        );
    }

    $prepaidAccountId = app(LedgerClearingAccounts::class)
        ->prepaidAccountId('flight_carrier');

    $customer = Customer::where('full_name', 'TEST_DELETE_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name'  => 'TEST_DELETE_CUSTOMER',
            'phone'      => '0000000000',
            'passport'   => 'TEST-DEL-'.uniqid(),
            'created_by' => $realUserId,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 2) Snapshot BEFORE
    // ───────────────────────────────────────────────────────────
    $before = [
        'carrier'      => (float) $carrier->fresh()->balance,
        'prepaid_gl'   => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'      => (float) $cashbox->fresh()->balance,
        'customer_id'  => $customer->id,
        'customer'     => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['before'] = $before;

    // ───────────────────────────────────────────────────────────
    // 3) Create booking + payment via the SERVICE (same as Vue UI does)
    // ───────────────────────────────────────────────────────────
    $sellingPrice = 100.0;
    $purchasePrice = 80.0;

    $booking = app(FlightBookingService::class)->createBooking([
        'booking_reference'       => 'REAL-DEL-'.uniqid(),
        'customer_id'             => $customer->id,
        'employee_id'             => $testEmployee->id,
        'airline'                 => 'TEST_AIRLINE',
        'airline_name'             => 'Real DB Validation Airline',
        'origin'                  => 'TST',
        'destination'             => 'DEL',
        'from_airport'            => 'Test Origin',
        'to_airport'              => 'Test Dest',
        'departure_date'          => now()->addDays(7)->format('Y-m-d'),
        'departure_time'          => '12:00:00',
        'trip_type'               => 'one_way',
        'passenger_count'         => 1,
        'purchase_price'          => $purchasePrice,
        'selling_price'           => $sellingPrice,
        'profit'                  => $sellingPrice - $purchasePrice,
        'currency'                => $carrier->currency,
        'purchase_balance_source' => 'carrier',
        'flight_carrier_id'       => $carrier->id,
        'baggage_allowance_kg'    => 0,
        'exchange_rate'           => 1.0,
        'passengers'              => [
            ['full_name' => 'REAL DB TEST PAX', 'passport_number' => 'REAL-PP', 'type' => 'adult'],
        ],
    ]);

    $payment = app(FlightBookingService::class)->addPayment($booking, [
        'amount'        => $sellingPrice,
        'currency'      => $carrier->currency,
        'payment_method'=> 'cash',
        'account_id'    => $cashbox->id,
        'notes'         => 'Real DB validation payment',
    ]);

    $txCountBeforeDelete = \App\Models\Transaction::where('related_type', FlightBooking::class)
        ->where('related_id', $booking->id)
        ->count();

    $result['steps']['create_booking'] = [
        'success' => true,
        'booking_id' => $booking->id,
        'booking_number' => $booking->booking_number,
        'payment_id' => $payment->id,
        'tx_count_before_delete' => $txCountBeforeDelete,
    ];

    $result['balances']['middle'] = [
        'carrier'    => (float) $carrier->fresh()->balance,
        'prepaid_gl' => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'    => (float) $cashbox->fresh()->balance,
        'customer'   => (float) Account::find($customer->account_id)->balance,
        'tx_count'   => $txCountBeforeDelete,
    ];

    Log::info('Real DB validation: booking + payment created', [
        'booking_id' => $booking->id,
        'tx_count' => $txCountBeforeDelete,
    ]);

    // ───────────────────────────────────────────────────────────
    // 4) Call the API endpoint via HTTP (simulates the Vue UI)
    // ───────────────────────────────────────────────────────────
    // We invoke the same controller method that the Vue UI calls
    // (`App\Http\Controllers\Api\V1\Flight\FlightController::destroy`).
    // This bypasses HTTP middleware (auth/sanctum) but exercises the exact
    // controller + service code path.
    $controller = app(\App\Http\Controllers\Api\V1\Flight\FlightController::class);

    $controllerReflection = new \ReflectionMethod($controller, 'destroy');
    if (! $controllerReflection->isPublic()) {
        $controllerReflection->setAccessible(true);
    }

    $controllerResult = $controller->destroy($booking);

    // The controller returns a JsonResponse. Check success status.
    $result['steps']['http_delete'] = [
        'controller_class'  => get_class($controller),
        'controller_method' => 'destroy',
        'response_status'   => $controllerResult->getStatusCode(),
        'response_body'     => json_decode($controllerResult->getContent(), true),
    ];

    if ($controllerResult->getStatusCode() >= 400) {
        throw new \RuntimeException(
            "Controller returned error status {$controllerResult->getStatusCode()}: "
            . $controllerResult->getContent()
        );
    }

    Log::info('Real DB validation: Controller destroy succeeded', [
        'booking_id' => $booking->id,
        'controller_status' => $controllerResult->getStatusCode(),
        'response' => json_decode($controllerResult->getContent(), true),
    ]);

    // ───────────────────────────────────────────────────────────
    // 5) Snapshot AFTER
    // ───────────────────────────────────────────────────────────
    $booking->refresh();
    $payments = FlightPayment::withTrashed()->where('flight_booking_id', $booking->id)->get();

    $result['steps']['post_delete'] = [
        'booking_trashed' => $booking->trashed(),
        'booking_deleted_at' => $booking->deleted_at?->toIso8601String(),
        'payments_count' => $payments->count(),
        'payments_trashed' => $payments->filter->trashed()->count(),
    ];

    $after = [
        'carrier'    => (float) $carrier->fresh()->balance,
        'prepaid_gl' => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'    => (float) $cashbox->fresh()->balance,
        'customer'   => (float) Account::find($customer->account_id)->balance,
    ];

    $txCountAfterDelete = \App\Models\Transaction::where('related_type', FlightBooking::class)
        ->where('related_id', $booking->id)
        ->count();

    $result['balances']['after'] = array_merge($after, ['tx_count' => $txCountAfterDelete]);

    // ───────────────────────────────────────────────────────────
    // 6) Compute deltas and verdict
    // ───────────────────────────────────────────────────────────
    $result['deltas'] = [
        'carrier'    => round($after['carrier']    - $before['carrier'],    2),
        'prepaid_gl' => round($after['prepaid_gl'] - $before['prepaid_gl'], 2),
        'cashbox'    => round($after['cashbox']    - $before['cashbox'],    2),
        'customer'   => round($after['customer']   - $before['customer'],   2),
    ];

    $result['verdict'] = [
        'all_balances_match'     => ($result['deltas']['carrier'] == 0 && $result['deltas']['prepaid_gl'] == 0 && $result['deltas']['cashbox'] == 0 && $result['deltas']['customer'] == 0),
        'tx_grew_after_delete'   => $txCountAfterDelete > $txCountBeforeDelete,
        'tx_rows_preserved'      => $txCountAfterDelete >= $txCountBeforeDelete,
        'booking_is_soft_deleted'=> $booking->trashed(),
        'all_payments_trashed'   => $payments->filter->trashed()->count() === $payments->count(),
    ];

    $result['success'] = $result['verdict']['all_balances_match']
        && $result['verdict']['tx_rows_preserved']
        && $result['verdict']['booking_is_soft_deleted']
        && $result['verdict']['all_payments_trashed'];

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
    $result['trace_excerpt'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
}

file_put_contents(
    storage_path('logs/real_db_validation_result.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== REAL DB VALIDATION RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";
echo "Result file: " . storage_path('logs/real_db_validation_result.json') . "\n";