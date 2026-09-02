<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * T22 — Cross-Currency Service-Level Guard (Strict Contract Regression)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * الـ Finding #2 من BUS_MODULE_FULL_E2E_REPORT_20260812.md:
 *   BusBookingService::payBooking() بيقبل دفع من account بعملة مختلفة
 *   عن الـ booking currency. الـ currency check موجود في PayBusBookingRequest
 *   (FormRequest) بس مش في الـ service نفسه.
 *
 * الـ Strict contract المطلوب (per user's instruction):
 *   - لما الـ booking currency != account currency، الـ service Lازم يرمي
 *     InvalidArgumentException (مع رسالة واضحة)
 *   - ده يحمي الـ system من الـ callers المباشرة (Tinker, scripts, Filament actions)
 *
 * لو الـ service مش بيرمي الـ exception → الاختبار FAIL → مساهمة في NO-GO verdict.
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
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'tests' => [],
];

$ok = function (string $m) use (&$results): void {
    echo "  ✅ $m\n";
};
$fail = function (string $m) use (&$results): void {
    echo "  ❌ $m\n";
};
$info = function (string $m): void {
    echo "  ℹ  $m\n";
};

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  T22 — Cross-Currency Service-Level Guard Regression\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Setup: create USD + EGP liquidity accounts + a USD booking ───
$adminId = DB::table('users')->where('role', 'owner')->value('id');
$egpVault = DB::table('accounts')->whereIn('type', ['cashbox', 'bank'])->where('currency', 'EGP')->where('module_type', 'office')->first();

// Create USD liquidity account (for the booking's payment — same currency — succeeds)
$usdLiquidity = Account::firstOrCreate(
    ['name' => 'TX-AUDIT USD Cashbox', 'currency' => 'USD', 'module_type' => 'office', 'type' => 'bank'],
    ['balance' => 10000, 'is_active' => 1, 'created_by' => $adminId]
);
$usdLiquidityId = $usdLiquidity->id;
$info("USD liquidity id={$usdLiquidityId} (balance=10000)");

// Create company + inventory + customer for the USD booking
$company = BusCompany::create([
    'name' => 'TX-AUDIT Cross-Currency Co',
    'is_active' => true, 'created_by' => $adminId,
]);
$inventory = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'TX-AUDIT USD Route',
    'travel_date' => '2026-10-01',
    'departure_time' => '12:00:00',
    'total_tickets' => 5, 'available_tickets' => 5,
    'cost_per_ticket' => 100, // 100 USD per ticket
    'selling_price' => 150,
    'payment_type' => BusInventoryPaymentType::Cash,
    'remaining_debt' => 0, 'amount_paid' => 500,
    'currency' => 'USD',
    'account_id' => $usdLiquidityId,
    'exchange_rate_to_egp' => 50.0,
    'notes' => 'TX-AUDIT cross-currency inventory',
    'created_by' => $adminId,
]);
$customer = Customer::create([
    'full_name' => 'TX-AUDIT Cross-Currency Customer',
    'phone' => '01090000099', 'created_by' => $adminId,
]);

$busBookingService = app(BusBookingService::class);
$booking = $busBookingService->createBooking([
    'inventory_id' => $inventory->id,
    'customer_id' => $customer->id,
    'quantity' => 2,
    'notes' => 'TX-AUDIT cross-currency booking',
    'created_by' => $adminId,
]);
$bookingId = $booking->id;
$info("USD booking created id=$bookingId (currency=".$booking->currency.', EGP equivalent='.number_format($booking->total_price * $booking->exchange_rate_to_egp, 2).')');

// ─────────────────────────────────────────────────────────────────────
// T22.1 — Same-currency payment (USD booking → USD account) → MUST succeed
// ─────────────────────────────────────────────────────────────────────
echo "\n── T22.1: Same-currency payment (USD booking → USD account)\n";
try {
    $paid = $busBookingService->payBooking($booking->fresh(), [
        'amount' => 100,
        'payment_method' => 'cash',
        'account_id' => $usdLiquidityId,
        'notes' => 'TX-AUDIT T22.1 partial pay USD',
        'created_by' => $adminId,
    ]);
    record($results, 't22_1_same_currency', 'PASS',
        'Paid 100 USD via USD account. Booking paid_amount='.$paid->fresh()->paid_amount);
    $ok('Same-currency payment succeeded');
} catch (Throwable $e) {
    record($results, 't22_1_same_currency', 'FAIL', 'Same-currency payment should succeed but threw: '.$e->getMessage());
    $fail('Same-currency payment unexpectedly failed: '.$e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────
// T22.2 — Cross-currency payment via SERVICE (USD booking → EGP account) → MUST throw InvalidArgumentException
// ─────────────────────────────────────────────────────────────────────
echo "\n── T22.2: Cross-currency payment via service (USD booking → EGP account)\n";
$egpVaultId = $egpVault->id;
$info("Attempting to pay $100 USD-equivalent via EGP vault id=$egpVaultId (bypassing FormRequest check)");
try {
    // We bypass the FormRequest by calling the service directly. The contract
    // says: the service must reject this with an InvalidArgumentException.
    $busBookingService->payBooking($booking->fresh(), [
        'amount' => 100,
        'payment_method' => 'cash',
        'account_id' => $egpVaultId,    // EGP account!
        'notes' => 'TX-AUDIT T22.2 cross-currency attack',
        'created_by' => $adminId,
    ]);
    // If we reach here, the service DID NOT throw → FAILURE
    record($results, 't22_2_cross_currency_service_guard', 'FAIL',
        'SERVICE-LEVEL GUARD MISSING. Service accepted cross-currency payment without throwing. This is the documented Finding #2 — contributes to NO-GO verdict.');
    $fail('Service accepted cross-currency payment (USD booking paid via EGP account) — contract violated');
} catch (InvalidArgumentException $e) {
    // CORRECT: service rejected with InvalidArgumentException
    record($results, 't22_2_cross_currency_service_guard', 'PASS',
        'Service correctly threw InvalidArgumentException: '.$e->getMessage());
    $ok('Service correctly rejected cross-currency payment: '.substr($e->getMessage(), 0, 100));
} catch (Throwable $e) {
    // Some other exception — depends on what service does
    record($results, 't22_2_cross_currency_service_guard', 'FAIL',
        'Service threw unexpected exception (not InvalidArgumentException): '.get_class($e).' — '.$e->getMessage());
    $fail('Service threw wrong exception type: '.get_class($e).' — expected InvalidArgumentException');
}

// ─────────────────────────────────────────────────────────────────────
// T22.3 — Cross-currency payment with same currency via service → still works
// ─────────────────────────────────────────────────────────────────────
echo "\n── T22.3: Verify booking after attempted cross-currency\n";
$booking = $booking->fresh();
record($results, 't22_3_state_check', $booking->paid_amount > 0 ? 'PASS' : 'INFO',
    "Booking state after attacks: paid_amount={$booking->paid_amount}, total_price={$booking->total_price}, status={$booking->status?->value}");
$info("Final booking state: paid_amount={$booking->paid_amount}, status={$booking->status?->value}");

// Verify there's no phantom transaction posted
$phantomTxCount = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Bus\\BusBooking')
    ->where('related_id', $bookingId)
    ->where('notes', 'TX-AUDIT T22.2 cross-currency attack')
    ->count();
record($results, 't22_4_no_phantom_tx', $phantomTxCount === 0 ? 'PASS' : 'FAIL',
    "Phantom cross-currency transactions posted: $phantomTxCount (expected 0 if guard works)");
if ($phantomTxCount === 0) {
    $ok('No phantom cross-currency transactions in DB');
} else {
    $fail("$phantomTxCount phantom transactions found!");
}

$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_h_cross_currency.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  T22 Summary\n";
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
echo '  Tests: '.count($results['tests'])." | Passed: $passed | Failed: $failed\n";
echo "  Detailed results: storage/logs/bus_audit_phase_h_cross_currency.json\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}
