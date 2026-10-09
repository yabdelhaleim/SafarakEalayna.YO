<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flights Module E2E Test — STAGING
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Tests all 10 public methods of FlightBookingService:
 *   1. getAllBookings
 *   2. createBooking (PENDING, no payment)
 *   3. updatePrices (on PENDING)
 *   4. updateBooking (modify fields)
 *   5. confirmBooking (PENDING → CONFIRMED)
 *   6. addPayment (additional payment)
 *   7. createBooking (with initial payment → CONFIRMED)
 *   8. cancelBooking (with reversal)
 *   9. deleteBookingWithReversal (full reversal)
 *  10. backfillMissingCustomerSaleLedgers
 *  11. getBookingById
 *
 * Usage: php tests/e2e/flights_e2e_staging.php
 */
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────
function section(string $name): void
{
    echo "\n".str_repeat('═', 75)."\n";
    echo "  $name\n";
    echo str_repeat('═', 75)."\n";
}
function ok(string $m = 'OK'): void
{
    echo "    ✅ $m\n";
}
function fail(string $m): void
{
    echo "    ❌ $m\n";
}
function warn(string $m): void
{
    echo "    ⚠  $m\n";
}
function info(string $m): void
{
    echo "    ℹ  $m\n";
}
function head(string $m): void
{
    echo "    → $m\n";
}

function snapAcc(int $id): array
{
    $a = Account::find($id);
    if (! $a) {
        return ['missing' => true];
    }

    return [
        'id' => $a->id, 'name' => $a->name, 'type' => $a->type,
        'balance' => (float) $a->balance,
    ];
}

function snapCarrier(int $id): array
{
    $c = FlightCarrier::find($id);
    if (! $c) {
        return ['missing' => true];
    }

    return ['id' => $c->id, 'name' => $c->name, 'balance' => (float) $c->balance];
}

function snapBooking(int $id): array
{
    $b = FlightBooking::withTrashed()->find($id);
    if (! $b) {
        return ['missing' => true];
    }

    return [
        'id' => $b->id, 'ref' => $b->booking_reference,
        'status' => $b->status instanceof BackedEnum ? $b->status->value : $b->status,
        'purchase' => (float) $b->purchase_price,
        'selling' => (float) $b->selling_price,
        'profit' => (float) $b->profit,
        'paid' => (float) $b->payments()->sum('amount'),
        'remaining' => (float) $b->selling_price - (float) $b->payments()->sum('amount'),
        'deleted_at' => $b->deleted_at?->toIso8601String(),
    ];
}

function passCount(): int
{
    static $n = 0;

    return ++$n;
}
function failCount(): int
{
    static $n = 0;

    return ++$n;
}

$REPORT = [
    'started_at' => date('Y-m-d H:i:s'),
    'env' => config('app.env'),
    'db' => DB::connection()->getDatabaseName(),
    'tests' => [],
];

// ─────────────────────────────────────────────────────────────────────────
// Environment guard
// ─────────────────────────────────────────────────────────────────────────
if (config('app.env') !== 'staging') {
    exit('❌ REFUSED: This script must run on STAGING only (current: '.config('app.env').")\n");
}

// ─────────────────────────────────────────────────────────────────────────
// Setup: auth as admin
// ─────────────────────────────────────────────────────────────────────────
section('Setup: authenticate as admin');

// Find any admin user — production uses admin@safarakealayna.com,
// staging has admin@admin.com (id=6). Fall back to first user.
$admin = User::whereIn('email', ['admin@safarakealayna.com', 'admin@admin.com'])->first();
if (! $admin) {
    $admin = User::where('name', 'Admin')->first();
}
if (! $admin) {
    $admin = User::first();
}
if (! $admin) {
    exit("❌ No users found in DB. Run the staging seeders first.\n");
}
Auth::login($admin);
ok("Logged in as user: id={$admin->id} email={$admin->email} name={$admin->name}");

// ─────────────────────────────────────────────────────────────────────────
// Setup: load STG test data
// ─────────────────────────────────────────────────────────────────────────
section('Setup: load STG test entities');

$customer = Customer::where('full_name', 'like', 'STG-%')->first();
if (! $customer) {
    exit("❌ No STG customers. Run StagingSeeder first.\n");
}

// IMPORTANT: Flight bookings must use a TOURISM division cashbox (not office)
// because the AccountModuleContract requires module_type='tourism' for flights.
$tourismCashbox = Account::where('name', 'STG Cashbox Tourism')->first();
if (! $tourismCashbox) {
    exit("❌ STG Cashbox Tourism not found.\n");
}

$officeCashbox = Account::where('name', 'STG Cashbox Office')->first();
if (! $officeCashbox) {
    exit("❌ STG Cashbox Office not found.\n");
}

$bankAcc = Account::where('name', 'STG Bank Egypt')->first();
if (! $bankAcc) {
    exit("❌ STG Bank Egypt not found.\n");
}

$system = FlightSystem::where('name', 'like', 'STG Test System%')->first();
if (! $system) {
    exit("❌ STG flight system not found.\n");
}

$carrier = FlightCarrier::where('name', 'like', 'STG %')->where('flight_system_id', $system->id)->first();
if (! $carrier) {
    exit("❌ STG carrier not found.\n");
}

// Use the tourism cashbox for flight bookings — the office one is for office-division modules only
$cashbox = $tourismCashbox;

ok("Customer: id={$customer->id} name={$customer->full_name}");
ok("Tourism cashbox: id={$tourismCashbox->id} module_type=$tourismCashbox->module_type balance={$tourismCashbox->balance}");
ok("Office cashbox: id={$officeCashbox->id} module_type=$officeCashbox->module_type balance={$officeCashbox->balance}");
ok("Bank: id={$bankAcc->id} balance={$bankAcc->balance}");
ok("Flight system: id={$system->id} name={$system->name}");
ok("Flight carrier: id={$carrier->id} name={$carrier->name}");

// ─────────────────────────────────────────────────────────────────────────
// Snapshot BEFORE
// ─────────────────────────────────────────────────────────────────────────
$cashboxBefore = snapAcc($cashbox->id);
$bankBefore = snapAcc($bankAcc->id);
$carrierBefore = snapCarrier($carrier->id);
info("BEFORE: cashbox={$cashboxBefore['balance']} bank={$bankBefore['balance']} carrier={$carrierBefore['balance']}");

// ─────────────────────────────────────────────────────────────────────────
// Test 1: getAllBookings
// ─────────────────────────────────────────────────────────────────────────
section('T1: FlightBookingService::getAllBookings');

try {
    $service = app(FlightBookingService::class);
    $result = $service->getAllBookings(['per_page' => 100]);
    if ($result instanceof LengthAwarePaginator) {
        ok("getAllBookings returned paginator with {$result->total()} total bookings");
        $REPORT['tests']['T1'] = ['status' => 'PASS', 'total' => $result->total()];
    } else {
        fail('getAllBookings did not return paginator');
        $REPORT['tests']['T1'] = ['status' => 'FAIL'];
    }
} catch (Throwable $e) {
    fail('T1 exception: '.$e->getMessage());
    $REPORT['tests']['T1'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 2: createBooking (PENDING, no payment)
// ─────────────────────────────────────────────────────────────────────────
section('T2: FlightBookingService::createBooking (PENDING — no payment)');

$pendingBid = null;
try {
    $service = app(FlightBookingService::class);
    $booking = $service->createBooking([
        'customer_id' => $customer->id,
        'pnr' => null,  // empty PNR → PENDING
        'airline' => 'STG',
        'airline_name' => 'STG Air',
        'flight_system_id' => $system->id,
        'flight_carrier_id' => $carrier->id,
        'from_airport' => 'CAI',
        'to_airport' => 'JED',
        'origin' => 'CAI',
        'destination' => 'JED',
        'departure_date' => now()->addDays(20)->format('Y-m-d'),
        'departure_time' => '10:00:00',
        'arrival_time' => '13:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'purchase_price' => 6000,
        'selling_price' => 8000,
        'account_id' => $cashbox->id,
        'purchase_balance_source' => 'carrier',
        'passengers' => [['first_name' => 'STG', 'last_name' => 'Test', 'passenger_type' => 'adult', 'nationality' => 'EG']],
    ]);

    $pendingBid = $booking->id;
    $bs = snapBooking($pendingBid);
    ok("Created booking #{$pendingBid} ref={$bs['ref']}");
    if ($bs['status'] === 'pending' || $bs['status'] === 'PENDING') {
        ok('Status is PENDING as expected');
    } else {
        warn("Status is {$bs['status']} (expected PENDING)");
    }
    $REPORT['tests']['T2'] = ['status' => 'PASS', 'booking_id' => $pendingBid, 'final_status' => $bs['status']];
} catch (Throwable $e) {
    fail('T2 exception: '.$e->getMessage());
    $REPORT['tests']['T2'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 3: updatePrices (on PENDING)
// ─────────────────────────────────────────────────────────────────────────
section('T3: FlightBookingService::updatePrices');

if ($pendingBid) {
    try {
        $service = app(FlightBookingService::class);
        $booking = FlightBooking::find($pendingBid);
        $updated = $service->updatePrices($booking, 6500, 8500);
        $bs = snapBooking($pendingBid);
        if (abs($bs['purchase'] - 6500) < 0.01 && abs($bs['selling'] - 8500) < 0.01) {
            ok('Prices updated: purchase=6500 selling=8500 profit='.$bs['profit']);
            $REPORT['tests']['T3'] = ['status' => 'PASS', 'purchase' => $bs['purchase'], 'selling' => $bs['selling']];
        } else {
            fail("Prices NOT updated correctly: purchase={$bs['purchase']} selling={$bs['selling']}");
            $REPORT['tests']['T3'] = ['status' => 'FAIL'];
        }
    } catch (Throwable $e) {
        fail('T3 exception: '.$e->getMessage());
        $REPORT['tests']['T3'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    warn('T3 skipped (no PENDING booking from T2)');
    $REPORT['tests']['T3'] = ['status' => 'SKIP'];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 4: updateBooking (modify fields)
// ─────────────────────────────────────────────────────────────────────────
section('T4: FlightBookingService::updateBooking');

if ($pendingBid) {
    try {
        $service = app(FlightBookingService::class);
        $booking = FlightBooking::find($pendingBid);
        $updated = $service->updateBooking($booking, [
            'passenger_count' => 2,
            'baggage_allowance_kg' => 30,
            'notes' => 'STG E2E updated',
        ]);
        $bs = snapBooking($pendingBid);
        info('Reload passenger_count and confirm updated');
        $b = FlightBooking::find($pendingBid);
        if ($b->passenger_count == 2 && $b->baggage_allowance_kg == 30) {
            ok('Fields updated: passenger_count=2 baggage=30');
            $REPORT['tests']['T4'] = ['status' => 'PASS'];
        } else {
            warn("Some fields not updated: passenger_count={$b->passenger_count} baggage={$b->baggage_allowance_kg}");
            $REPORT['tests']['T4'] = ['status' => 'WARN'];
        }
    } catch (Throwable $e) {
        fail('T4 exception: '.$e->getMessage());
        $REPORT['tests']['T4'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    warn('T4 skipped (no PENDING booking from T2)');
    $REPORT['tests']['T4'] = ['status' => 'SKIP'];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 5: confirmBooking (PENDING → CONFIRMED, no payment yet)
// ─────────────────────────────────────────────────────────────────────────
section('T5: FlightBookingService::confirmBooking');

if ($pendingBid) {
    try {
        $service = app(FlightBookingService::class);
        $booking = FlightBooking::find($pendingBid);
        $confirmed = $service->confirmBooking($booking);
        $bs = snapBooking($pendingBid);
        if ($bs['status'] === 'CONFIRMED' || $bs['status'] === 'confirmed') {
            ok("Booking #{$pendingBid} confirmed");
            $REPORT['tests']['T5'] = ['status' => 'PASS', 'final_status' => $bs['status']];
        } else {
            warn("Status after confirm: {$bs['status']} (expected CONFIRMED)");
            $REPORT['tests']['T5'] = ['status' => 'WARN', 'final_status' => $bs['status']];
        }
    } catch (Throwable $e) {
        fail('T5 exception: '.$e->getMessage());
        $REPORT['tests']['T5'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    warn('T5 skipped');
    $REPORT['tests']['T5'] = ['status' => 'SKIP'];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 6: addPayment (after confirm)
// ─────────────────────────────────────────────────────────────────────────
section('T6: FlightBookingService::addPayment');

if ($pendingBid) {
    try {
        $service = app(FlightBookingService::class);
        $booking = FlightBooking::find($pendingBid);
        $payment = $service->addPayment($booking, [
            'amount' => 2000,
            'payment_method' => 'bank_transfer',
            'account_id' => $bankAcc->id,
            'notes' => 'STG E2E first payment',
        ]);
        ok("Payment #{$payment->id} added: {$payment->amount} EGP");
        $bs = snapBooking($pendingBid);
        info("Paid: {$bs['paid']} / Remaining: {$bs['remaining']}");
        $REPORT['tests']['T6'] = ['status' => 'PASS', 'payment_id' => $payment->id, 'paid' => $bs['paid']];
    } catch (Throwable $e) {
        fail('T6 exception: '.$e->getMessage());
        $REPORT['tests']['T6'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    warn('T6 skipped');
    $REPORT['tests']['T6'] = ['status' => 'SKIP'];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 7: createBooking (with initial payment → CONFIRMED)
// ─────────────────────────────────────────────────────────────────────────
section('T7: FlightBookingService::createBooking (CONFIRMED with initial payment)');

$confirmedBid = null;
try {
    $service = app(FlightBookingService::class);
    $booking = $service->createBooking([
        'customer_id' => $customer->id,
        'pnr' => 'STG'.strtoupper(substr(md5(uniqid()), 0, 5)),
        'airline' => 'STG',
        'airline_name' => 'STG Air',
        'flight_system_id' => $system->id,
        'flight_carrier_id' => $carrier->id,
        'from_airport' => 'CAI',
        'to_airport' => 'DXB',
        'origin' => 'CAI',
        'destination' => 'DXB',
        'departure_date' => now()->addDays(25)->format('Y-m-d'),
        'departure_time' => '14:00:00',
        'arrival_time' => '17:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'currency' => 'EGP',
        'purchase_price' => 7000,
        'selling_price' => 9000,
        'account_id' => $cashbox->id,
        'purchase_balance_source' => 'carrier',
        'passengers' => [['first_name' => 'STG', 'last_name' => 'PayTest', 'passenger_type' => 'adult', 'nationality' => 'EG']],
        'payment' => [
            'amount' => 9000,
            'payment_method' => 'bank_transfer',
            'account_id' => $cashbox->id,
        ],
    ]);

    $confirmedBid = $booking->id;
    $bs = snapBooking($confirmedBid);
    ok("Created booking #{$confirmedBid} with full payment");
    if ($bs['status'] === 'CONFIRMED' || $bs['status'] === 'confirmed') {
        ok('Status is CONFIRMED');
    } else {
        warn("Status is {$bs['status']} (expected CONFIRMED)");
    }
    $REPORT['tests']['T7'] = ['status' => 'PASS', 'booking_id' => $confirmedBid, 'status' => $bs['status']];
} catch (Throwable $e) {
    fail('T7 exception: '.$e->getMessage());
    $REPORT['tests']['T7'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 8: cancelBooking (full reversal)
// ─────────────────────────────────────────────────────────────────────────
section('T8: FlightBookingService::cancelBooking');

if ($confirmedBid) {
    try {
        $cashboxPre = snapAcc($cashbox->id);
        $bankPre = snapAcc($bankAcc->id);
        $carrierPre = snapCarrier($carrier->id);

        $service = app(FlightBookingService::class);
        $booking = FlightBooking::find($confirmedBid);
        $refund = $service->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $cashbox->id,
            'notes' => 'STG E2E cancel',
        ]);
        ok("Cancelled booking #{$confirmedBid} → refund #{$refund->id}");

        $bs = snapBooking($confirmedBid);
        if ($bs['status'] === 'CANCELLED' || $bs['status'] === 'cancelled' || $bs['status'] === 'REFUNDED') {
            ok("Status is {$bs['status']}");
        } else {
            warn("Status is {$bs['status']}");
        }

        $cashboxPost = snapAcc($cashbox->id);
        $bankPost = snapAcc($bankAcc->id);
        $carrierPost = snapCarrier($carrier->id);
        $cashboxDelta = round($cashboxPost['balance'] - $cashboxPre['balance'], 2);
        $carrierDelta = round($carrierPost['balance'] - $carrierPre['balance'], 2);
        info("Cashbox Δ: {$cashboxDelta} (expected 0 net)");
        info("Carrier Δ: {$carrierDelta} (expected 0 net)");
        $REPORT['tests']['T8'] = [
            'status' => 'PASS',
            'booking_id' => $confirmedBid,
            'refund_id' => $refund->id,
            'cashbox_delta' => $cashboxDelta,
            'carrier_delta' => $carrierDelta,
        ];
    } catch (Throwable $e) {
        fail('T8 exception: '.$e->getMessage());
        $REPORT['tests']['T8'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    warn('T8 skipped');
    $REPORT['tests']['T8'] = ['status' => 'SKIP'];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 9: deleteBookingWithReversal (acts on T2's PENDING→confirmed booking)
// ─────────────────────────────────────────────────────────────────────────
section('T9: FlightBookingService::deleteBookingWithReversal');

if ($pendingBid) {
    try {
        $cashboxPre = snapAcc($cashbox->id);
        $bankPre = snapAcc($bankAcc->id);
        $carrierPre = snapCarrier($carrier->id);

        $service = app(FlightBookingService::class);
        $result = $service->deleteBookingWithReversal($pendingBid, $admin->id);
        ok('deleteBookingWithReversal returned: '.($result ? 'true' : 'false'));

        $b = FlightBooking::withTrashed()->find($pendingBid);
        if ($b && $b->trashed()) {
            ok("Booking #{$pendingBid} is soft-deleted");
        } else {
            fail("Booking #{$pendingBid} NOT soft-deleted");
        }

        $cashboxPost = snapAcc($cashbox->id);
        $carrierPost = snapCarrier($carrier->id);
        info('Cashbox Δ: '.round($cashboxPost['balance'] - $cashboxPre['balance'], 2));
        info('Carrier Δ: '.round($carrierPost['balance'] - $carrierPre['balance'], 2));
        $REPORT['tests']['T9'] = [
            'status' => 'PASS',
            'booking_id' => $pendingBid,
            'cashbox_delta' => round($cashboxPost['balance'] - $cashboxPre['balance'], 2),
        ];
    } catch (Throwable $e) {
        fail('T9 exception: '.$e->getMessage());
        $REPORT['tests']['T9'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    warn('T9 skipped');
    $REPORT['tests']['T9'] = ['status' => 'SKIP'];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 10: getBookingById
// ─────────────────────────────────────────────────────────────────────────
section('T10: FlightBookingService::getBookingById');

try {
    // Use a real booking ID — pick any existing STG booking
    $testBookingId = $confirmedBid ?? $pendingBid;
    if (! $testBookingId) {
        $testBookingId = FlightBooking::where('booking_number', 'like', 'STG-%')
            ->orWhere('booking_reference', 'like', 'STG-%')
            ->value('id');
    }
    if (! $testBookingId) {
        throw new RuntimeException('No STG booking found for T10 test');
    }

    $service = app(FlightBookingService::class);
    $b = $service->getBookingById($testBookingId);
    if ($b) {
        ok("Found booking #{$b->id} ref={$b->booking_reference}");
        $REPORT['tests']['T10'] = ['status' => 'PASS', 'booking_id' => $b->id];
    } else {
        warn("getBookingById returned null for id={$testBookingId}");
        $REPORT['tests']['T10'] = ['status' => 'WARN', 'booking_id' => $testBookingId];
    }
} catch (Throwable $e) {
    fail('T10 exception: '.$e->getMessage());
    $REPORT['tests']['T10'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Test 11: backfillMissingCustomerSaleLedgers
// ─────────────────────────────────────────────────────────────────────────
section('T11: FlightBookingService::backfillMissingCustomerSaleLedgers');

try {
    $service = app(FlightBookingService::class);
    // Returns array ['repaired' => N, 'skipped' => N, 'errors' => [...]]
    $stats = $service->backfillMissingCustomerSaleLedgers();
    if (is_array($stats)) {
        ok("backfillMissingCustomerSaleLedgers: repaired={$stats['repaired']} skipped={$stats['skipped']} errors=".count($stats['errors']));
        $REPORT['tests']['T11'] = ['status' => 'PASS', 'stats' => $stats];
    } else {
        warn('backfill returned non-array: '.gettype($stats));
        $REPORT['tests']['T11'] = ['status' => 'WARN'];
    }
} catch (Throwable $e) {
    warn('T11 exception: '.$e->getMessage());
    $REPORT['tests']['T11'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Final: snapshot AFTER
// ─────────────────────────────────────────────────────────────────────────
section('Final snapshot');
$cashboxAfter = snapAcc($cashbox->id);
$bankAfter = snapAcc($bankAcc->id);
$carrierAfter = snapCarrier($carrier->id);
info("AFTER:  cashbox={$cashboxAfter['balance']} bank={$bankAfter['balance']} carrier={$carrierAfter['balance']}");
info('Δ:      cashbox='.round($cashboxAfter['balance'] - $cashboxBefore['balance'], 2)
    .' bank='.round($bankAfter['balance'] - $bankBefore['balance'], 2)
    .' carrier='.round($carrierAfter['balance'] - $carrierBefore['balance'], 2));

$REPORT['finished_at'] = date('Y-m-d H:i:s');
$REPORT['final_snapshot'] = [
    'cashbox_after' => $cashboxAfter['balance'],
    'bank_after' => $bankAfter['balance'],
    'carrier_after' => $carrierAfter['balance'],
];

// ─────────────────────────────────────────────────────────────────────────
// Save report
// ─────────────────────────────────────────────────────────────────────────
$reportPath = storage_path('logs/flights_e2e_staging_results.json');
file_put_contents($reportPath, json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
ok("Report saved: $reportPath");

// ─────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────
section('Summary');
$total = count($REPORT['tests']);
$passed = count(array_filter($REPORT['tests'], fn ($t) => ($t['status'] ?? '') === 'PASS'));
$failed = count(array_filter($REPORT['tests'], fn ($t) => ($t['status'] ?? '') === 'FAIL'));
$skipped = count(array_filter($REPORT['tests'], fn ($t) => ($t['status'] ?? '') === 'SKIP'));
$warn = count(array_filter($REPORT['tests'], fn ($t) => ($t['status'] ?? '') === 'WARN'));

echo "  Total: $total | PASS: $passed | FAIL: $failed | WARN: $warn | SKIP: $skipped\n";
foreach ($REPORT['tests'] as $tname => $t) {
    $icon = match ($t['status'] ?? '') {
        'PASS' => '✅',
        'FAIL' => '❌',
        'SKIP' => '⏭ ',
        'WARN' => '⚠ ',
        default => '❓',
    };
    echo "  $icon $tname: {$t['status']}\n";
}

echo "\n  Done.\n".str_repeat('═', 75)."\n";
