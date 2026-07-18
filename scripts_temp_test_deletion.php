<?php
/**
 * MANUAL TEST: Flight booking soft-delete with full financial reversal
 * Outputs JSON to storage/logs/manual_test_result.json to bypass tinker output buffering.
 *
 * Run with:
 *   cd C:\travile\SafarakEalayna
 *   php artisan tinker < scripts_temp_test_deletion.php
 *   cat storage/logs/manual_test_result.json
 */

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Services\Flight\FlightBookingService;

$result = [
    'success' => false,
    'error'   => null,
    'steps'   => [],
    'balances' => [],
    'audit_trail' => [],
];

// Set the auth user context first — many services use Auth::id() ?? 1 internally
// and user ID 1 doesn't exist in this DB.
$realUserId = App\Models\User::orderBy('id')->value('id');
auth()->setUser(App\Models\User::find($realUserId));

// Find or create a test employee (FK on flight_bookings.employee_id)
$testEmployee = App\Models\Employee::where('full_name', 'TEST_DELETE_EMPLOYEE')->first();
if (! $testEmployee) {
    $testEmployee = App\Models\Employee::create([
        'full_name'    => 'TEST_DELETE_EMPLOYEE',
        'phone'        => '0000000000',
        'national_id'  => 'TESTEMP1', // short to fit column length
        'created_by'   => $realUserId,
    ]);
}
$employeeId = $testEmployee->id;

try {
    // ───────────────────────────────────────────────────────────
    // 1) Select carrier + cashbox + customer; create matching test carrier if needed
    // ───────────────────────────────────────────────────────────
    // Use or create a test carrier whose currency matches the test cashbox.
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        throw new \RuntimeException('No flights cashbox account found');
    }

    // Find existing test carrier with matching currency, or create one
    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_DELETE')
        ->where('currency', $cashbox->currency)
        ->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name'        => 'TEST_CARRIER_DELETE',
            'code'        => 'TCD' . substr(md5(uniqid()), 0, 4),
            'currency'    => $cashbox->currency,
            'balance'     => 0,
            'credit_limit'=> 0,
            'is_active'   => true,
            'created_by'  => App\Models\User::orderBy('id')->value('id') ?? 10,
        ]);
    }

    $neededAmount = 500.0;
    $recharged = false;
    if ((float) $carrier->balance < $neededAmount) {
        app(\App\Services\Flight\FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier,
            $cashbox,
            $neededAmount,
            'Manual test recharge (Phase 8 / flight deletion test)'
        );
        $recharged = true;
    }

    $prepaidAccountId = app(\App\Services\Finance\LedgerClearingAccounts::class)
        ->prepaidAccountId('flight_carrier');

    $customer = Customer::where('full_name', 'TEST_DELETE_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name'  => 'TEST_DELETE_CUSTOMER',
            'phone'      => '0000000000',
            'passport'   => 'TEST-DELETE-' . uniqid(),
            'created_by' => App\Models\User::orderBy('id')->value('id') ?? 10,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 2) Snapshot BEFORE
    // ───────────────────────────────────────────────────────────
    $before = [
        'carrier_id'           => $carrier->id,
        'carrier_name'         => $carrier->name,
        'carrier_balance'      => (float) $carrier->fresh()->balance,
        'carrier_currency'     => $carrier->currency,
        'prepaid_gl_id'        => $prepaidAccountId,
        'prepaid_gl_balance'   => (float) Account::find($prepaidAccountId)->balance,
        'cashbox_id'           => $cashbox->id,
        'cashbox_name'         => $cashbox->name,
        'cashbox_balance'      => (float) $cashbox->fresh()->balance,
        'cashbox_currency'     => $cashbox->currency,
        'customer_id'          => $customer->id,
    ];
    $result['balances']['before'] = $before;

    // ───────────────────────────────────────────────────────────
    // 3) Create booking
    // ───────────────────────────────────────────────────────────
    $sellingPrice = 100.0;
    $purchasePrice = 80.0;

    $bookingData = [
        'booking_reference'       => 'TEST-DELETE-' . uniqid(),
        'customer_id'             => $customer->id,
        'employee_id'             => $employeeId,
        'airline'                 => 'TEST_AIRLINE',
        'airline_name'             => 'Test Airline for Deletion',
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
            ['full_name' => 'TEST PASSENGER', 'passport_number' => 'TEST-PP', 'type' => 'adult'],
        ],
    ];

    $booking = app(FlightBookingService::class)->createBooking($bookingData);
    $result['steps']['create_booking'] = [
        'success' => true,
        'booking_id' => $booking->id,
        'booking_number' => $booking->booking_number,
        'tx_count_after_create' => \App\Models\Transaction::where('related_type', \App\Models\Flight\FlightBooking::class)->where('related_id', $booking->id)->count(),
    ];

    // ───────────────────────────────────────────────────────────
    // 4) Add payment
    // ───────────────────────────────────────────────────────────
    $payment = app(FlightBookingService::class)->addPayment($booking, [
        'amount'        => $sellingPrice,
        'currency'      => $carrier->currency,
        'payment_method'=> 'cash',
        'account_id'    => $cashbox->id,
        'notes'         => 'Manual test payment (Phase 8)',
    ]);
    $result['steps']['add_payment'] = [
        'success' => true,
        'payment_id' => $payment->id,
        'amount' => (float) $payment->amount,
    ];

    // Capture MIDDLE balances
    $middle = [
        'carrier_balance'      => (float) $carrier->fresh()->balance,
        'prepaid_gl_balance'   => (float) Account::find($prepaidAccountId)->balance,
        'cashbox_balance'      => (float) $cashbox->fresh()->balance,
        'tx_count'             => \App\Models\Transaction::where('related_type', \App\Models\Flight\FlightBooking::class)->where('related_id', $booking->id)->count(),
        'account_entry_count'  => \App\Models\AccountEntry::whereIn(
            'transaction_id',
            \App\Models\Transaction::where('related_type', \App\Models\Flight\FlightBooking::class)->where('related_id', $booking->id)->pluck('id')
        )->count(),
    ];
    $result['balances']['middle'] = $middle;

    // ───────────────────────────────────────────────────────────
    // 5) Delete the booking (THE KEY TEST)
    // ───────────────────────────────────────────────────────────
    // Re-assert auth user (some DB::transaction closures may reset context)
    auth()->setUser(App\Models\User::find($realUserId));
    $deleted = app(FlightBookingService::class)->deleteBookingWithReversal($booking->id, $realUserId);
    $booking->refresh();
    $payments = FlightPayment::withTrashed()->where('flight_booking_id', $booking->id)->get();

    $result['steps']['delete_booking'] = [
        'success' => $deleted,
        'booking_trashed' => $booking->trashed(),
        'booking_deleted_at' => $booking->deleted_at?->toIso8601String(),
        'payments_count' => $payments->count(),
        'payments_trashed' => $payments->filter->trashed()->count(),
    ];

    // Capture AFTER balances
    $after = [
        'carrier_balance'      => (float) $carrier->fresh()->balance,
        'prepaid_gl_balance'   => (float) Account::find($prepaidAccountId)->balance,
        'cashbox_balance'      => (float) $cashbox->fresh()->balance,
        'tx_count'             => \App\Models\Transaction::where('related_type', \App\Models\Flight\FlightBooking::class)->where('related_id', $booking->id)->count(),
        'account_entry_count'  => \App\Models\AccountEntry::whereIn(
            'transaction_id',
            \App\Models\Transaction::where('related_type', \App\Models\Flight\FlightBooking::class)->where('related_id', $booking->id)->pluck('id')
        )->count(),
    ];
    $result['balances']['after'] = $after;

    // Compute deltas
    $result['deltas'] = [
        'carrier'      => round($after['carrier_balance'] - $before['carrier_balance'], 2),
        'prepaid_gl'   => round($after['prepaid_gl_balance'] - $before['prepaid_gl_balance'], 2),
        'cashbox'      => round($after['cashbox_balance'] - $before['cashbox_balance'], 2),
    ];

    $result['verdict'] = [
        'all_balances_match' => ($result['deltas']['carrier'] == 0 && $result['deltas']['prepaid_gl'] == 0 && $result['deltas']['cashbox'] == 0),
        'tx_grew_after_delete' => $after['tx_count'] > $middle['tx_count'],
        'tx_rows_preserved' => $after['tx_count'] >= $middle['tx_count'], // original entries must still exist
        'booking_is_soft_deleted' => $booking->trashed(),
        'customer_account_balance' => (float) Account::find($customer->account_id)->balance,
    ];

    $result['success'] = $result['verdict']['all_balances_match'] && $result['verdict']['tx_rows_preserved'] && $result['verdict']['booking_is_soft_deleted'];
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
}

file_put_contents(
    storage_path('logs/manual_test_result.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);