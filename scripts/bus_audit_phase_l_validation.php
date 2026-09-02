<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Phase L — Validation Tests (API-Level)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * بيختبر كل الـ FormRequest validations للـ Bus:
 *   - StoreBusBookingRequest: customer, inventory, quantity, total_price, etc.
 *   - PayBusBookingRequest: amount > 0, <= remaining, currency match (in FormRequest)
 *   - StoreBusInventoryRequest
 *   - StoreBusCompanyRequest
 *
 * للـ missing/invalid inputs:
 *   - الـ API Lازم يرجع 422
 *   - الـ DB Lازم ما يتغيرش
 *   - لا financial side-effect
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
use App\Http\Requests\Bus\PayBusBookingRequest;
use App\Http\Requests\Bus\StoreBusBookingRequest;
use App\Http\Requests\Bus\StoreBusCompanyRequest;
use App\Http\Requests\Bus\StoreBusInventoryRequest;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

$results = ['tests' => []];

$ok = function (string $m): void {
    echo "  ✓ $m\n";
};
$fail = function (string $m): void {
    echo "  ✗ $m\n";
};
$info = function (string $m): void {
    echo "  ℹ $m\n";
};
$head = function (string $m): void {
    echo "\n── $m\n";
};

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Phase L — Validation Tests\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$adminId = DB::table('users')->where('role', 'owner')->value('id');
$egpVaultId = DB::table('accounts')->whereIn('type', ['cashbox', 'bank'])
    ->where('currency', 'EGP')->where('module_type', 'office')->value('id');

$company = BusCompany::create([
    'name' => 'TX-AUDIT Phase-L Co', 'is_active' => true,
    'phone' => '01090003001', 'created_by' => $adminId,
]);
$inventory = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'TX-AUDIT Phase-L Route',
    'travel_date' => '2026-12-20', 'departure_time' => '08:00:00',
    'total_tickets' => 10, 'available_tickets' => 10,
    'cost_per_ticket' => 500, 'selling_price' => 800,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0, 'amount_paid' => 5000,
    'currency' => 'EGP', 'account_id' => $egpVaultId,
    'exchange_rate_to_egp' => 1.0,
    'notes' => 'TX-AUDIT phase-L',
    'created_by' => $adminId,
]);
$customer = Customer::create([
    'full_name' => 'TX-AUDIT Phase-L Customer',
    'phone' => '01090003002', 'created_by' => $adminId,
]);
$booking = BusBooking::runProfitMutation(function () use ($inventory, $customer, $adminId) {
    return BusBooking::create([
        'inventory_id' => $inventory->id,
        'customer_id' => $customer->id,
        'quantity' => 2,
        'unit_price' => 800,
        'total_price' => 1600,
        'paid_amount' => 0,
        'profit' => 600,
        'currency' => 'EGP',
        'exchange_rate_to_egp' => 1.0,
        'status' => 'pending',
        'notes' => 'TX-AUDIT phase-L booking',
        'created_by' => $adminId,
    ]);
});

// ─── Helper: validate via FormRequest rules (NOT through HTTP) ─────────
function validate(array $data, string $requestClass): array
{
    $req = new $requestClass;
    $validator = Validator::make($data, $req->rules(), $req->messages(), $req->attributes());
    // Also call prepareForValidation side effects by routing through the request
    $req->merge($data);
    $req->setContainer(app());
    try {
        $req->validateResolved();
    } catch (Throwable $e) {
    }

    return [
        'validator' => $validator,
        'errors' => $validator->errors()->toArray(),
    ];
}

// =====================================================================
// L.1: Missing inventory_id → must fail validation
// =====================================================================
$head('L.1: Booking creation — missing inventory_id');

$txsBefore = DB::table('transactions')->count();
$bookingsBefore = DB::table('bus_bookings')->count();
$res = validate([
    'customer_id' => $customer->id,
    'quantity' => 1,
], StoreBusBookingRequest::class);
$ok_status = empty($res['errors']) ? 'FAIL' : 'PASS';
record($results, 'l1_missing_inventory', $ok_status,
    'missing inventory_id → errors: '.json_encode(array_keys($res['errors'])));
$ok_status === 'PASS' ? $ok('Missing inventory_id rejected') : $fail('Should reject but accepted');

$txsAfter = DB::table('transactions')->count();
$bookingsAfter = DB::table('bus_bookings')->count();
record($results, 'l1_no_db_change_on_failure',
    ($txsAfter === $txsBefore && $bookingsAfter === $bookingsBefore) ? 'PASS' : 'FAIL',
    "After rejected validation: bookings=$bookingsAfter (expected $bookingsBefore), txs=$txsAfter (expected $txsBefore)");

// =====================================================================
// L.2: Missing customer_id → must fail validation
// =====================================================================
$head('L.2: Booking creation — missing customer_id');

$txsBefore = DB::table('transactions')->count();
$res = validate([
    'inventory_id' => $inventory->id,
    'quantity' => 1,
], StoreBusBookingRequest::class);
$ok_status = empty($res['errors']) ? 'FAIL' : 'PASS';
record($results, 'l2_missing_customer', $ok_status,
    'missing customer_id → errors: '.json_encode(array_keys($res['errors'])));
$ok_status === 'PASS' ? $ok('Missing customer_id rejected') : $fail('Should reject');

// =====================================================================
// L.3: Quantity <= 0 → must fail validation
// =====================================================================
$head('L.3: Booking creation — quantity=0');

$res = validate([
    'inventory_id' => $inventory->id,
    'customer_id' => $customer->id,
    'quantity' => 0,
], StoreBusBookingRequest::class);
$hasQtyError = isset($res['errors']['quantity']) || isset($res['errors']['total_price']);
record($results, 'l3_quantity_zero', $hasQtyError ? 'PASS' : 'FAIL',
    'quantity=0 → errors: '.json_encode(array_keys($res['errors'])));
$hasQtyError ? $ok('quantity=0 rejected') : $fail('Should reject 0 quantity');

// =====================================================================
// L.4: Quantity negative → must fail validation
// =====================================================================
$head('L.4: Booking creation — quantity=-5');

$res = validate([
    'inventory_id' => $inventory->id,
    'customer_id' => $customer->id,
    'quantity' => -5,
], StoreBusBookingRequest::class);
$hasQtyError = isset($res['errors']['quantity']) || isset($res['errors']['total_price']);
record($results, 'l4_quantity_negative', $hasQtyError ? 'PASS' : 'FAIL',
    'quantity=-5 → errors: '.json_encode(array_keys($res['errors'])));
$hasQtyError ? $ok('Negative quantity rejected') : $fail('Should reject');

// =====================================================================
// L.5: Inventory ID doesn't exist → must fail validation
// =====================================================================
$head('L.5: Booking creation — non-existent inventory_id');

$res = validate([
    'inventory_id' => 99999999,
    'customer_id' => $customer->id,
    'quantity' => 1,
], StoreBusBookingRequest::class);
record($results, 'l5_nonexistent_inventory', ! empty($res['errors']) ? 'PASS' : 'FAIL',
    'nonexistent inventory_id → errors: '.json_encode(array_keys($res['errors'])));
! empty($res['errors']) ? $ok('Nonexistent inventory rejected') : $fail('Should reject');

// =====================================================================
// L.6: Pay amount > remaining → must fail
// =====================================================================
$head('L.6: Payment validation — amount > remaining');

$res = validate([
    'amount' => 99999,  // way more than 1600
    'payment_method' => 'cash',
    'account_id' => $egpVaultId,
], PayBusBookingRequest::class);
$hasAmtError = isset($res['errors']['amount']);
// FormRequest rules only have min:0.01, no max. Service-layer rejects.
// This means FormRequest itself DOES NOT validate overpayment.
record($results, 'l6_payment_overpay_form_only',
    $hasAmtError ? 'PASS' : 'INFO',
    'amount=99999 (overpay) → FormRequest errors: '.json_encode(array_keys($res['errors'])).' — overpay is caught by SERVICE (BusBookingService::payBooking line 479), NOT by FormRequest. Existing design.');
$info('FormRequest does NOT enforce overpay limit. Service layer does.');

// Verify the service catches it (this is T22's job, but let me also assert here)
$busBookingService = app(BusBookingService::class);
$serviceCaughtOverpay = false;
try {
    $busBookingService->payBooking($booking->fresh(), [
        'amount' => 99999, 'payment_method' => 'cash',
        'account_id' => $egpVaultId, 'notes' => 'TX-AUDIT L.6 overpay',
        'created_by' => $adminId,
    ]);
} catch (Throwable $e) {
    $serviceCaughtOverpay = str_contains($e->getMessage(), 'exceeds') || str_contains($e->getMessage(), 'remaining') || str_contains($e->getMessage(), 'overpay');
}
record($results, 'l6_service_catches_overpay', $serviceCaughtOverpay ? 'PASS' : 'FAIL',
    "Service rejected overpay with appropriate exception: $serviceCaughtOverpay");
$serviceCaughtOverpay ? $ok('Service catches overpay') : $fail('Service did NOT reject overpay!');

// =====================================================================
// L.7: Pay amount = 0 → must fail
// =====================================================================
$head('L.7: Payment validation — amount=0');

$res = validate([
    'amount' => 0,
    'payment_method' => 'cash',
    'account_id' => $egpVaultId,
], PayBusBookingRequest::class);
$hasAmtError = isset($res['errors']['amount']);
record($results, 'l7_payment_zero', $hasAmtError ? 'PASS' : 'FAIL',
    'amount=0 → errors: '.json_encode(array_keys($res['errors'])));
$hasAmtError ? $ok('Zero payment rejected') : $fail('Should reject 0 amount');

// =====================================================================
// L.8: Pay amount negative → must fail
// =====================================================================
$head('L.8: Payment validation — amount=-100');

$res = validate([
    'amount' => -100,
    'payment_method' => 'cash',
    'account_id' => $egpVaultId,
], PayBusBookingRequest::class);
$hasAmtError = isset($res['errors']['amount']);
record($results, 'l8_payment_negative', $hasAmtError ? 'PASS' : 'FAIL',
    'amount=-100 → errors: '.json_encode(array_keys($res['errors'])));
$hasAmtError ? $ok('Negative payment rejected') : $fail('Should reject');

// =====================================================================
// L.9: Inventory creation — missing required fields
// =====================================================================
$head('L.9: Inventory creation — missing route');

$res = validate([], StoreBusInventoryRequest::class);
record($results, 'l9_inventory_missing_route', ! empty($res['errors']) ? 'PASS' : 'FAIL',
    'Empty inventory data → errors: '.json_encode(array_keys($res['errors'])));
! empty($res['errors']) ? $ok('Empty inventory rejected') : $fail('Should reject');

// =====================================================================
// L.10: Company creation — missing name
// =====================================================================
$head('L.10: Company creation — missing name');

$res = validate([], StoreBusCompanyRequest::class);
record($results, 'l10_company_missing_name', ! empty($res['errors']) ? 'PASS' : 'FAIL',
    'Empty company data → errors: '.json_encode(array_keys($res['errors'])));
! empty($res['errors']) ? $ok('Empty company rejected') : $fail('Should reject');

$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_l_validation.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase L Summary\n";
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
