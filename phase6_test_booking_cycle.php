<?php
/**
 * PHASE 6: BOOKING CYCLE TEST
 * ────────────────────────────
 * اختبار كامل لـ booking cycle:
 *   - Booking #1: EGP currency
 *   - Booking #2: USD (foreign) currency
 *
 * الـ script:
 *   1. شحن NDC_WONDR system بـ 50,000 EGP (عشان balance يكون موجب)
 *   2. Booking #1 EGP → balance = 49,900
 *   3. Cancel #1 → balance = 50,000
 *   4. Booking #2 USD → balance = 47,575
 *   5. Cancel #2 → balance = 50,000
 *   6. Refund الـ 50,000 للـ source → balance = -26,174.65 (نفس الأصلية)
 *
 * Usage:
 *   php artisan tinker --execute='require "phase6_test_booking_cycle.php";'
 */

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightSystem;
use App\Services\Flight\FlightSystemRechargeService;
use App\Services\Flight\FlightBookingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  PHASE 6: BOOKING CYCLE TEST (EGP + USD)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════
// [1] Pre-flight checks
// ═══════════════════════════════════════════════════════════════════
echo "▸ Pre-flight checks:\n";

$system = FlightSystem::find(1); // NDC_WONDR
if (! $system) {
    echo "  ✗ NDC_WONDR (id=1) not found\n";
    exit(1);
}
$balanceBeforeRecharge = (float) $system->balance;
echo "  - System: {$system->name} (id={$system->id}), balance={$balanceBeforeRecharge} {$system->currency}\n";

$source = Account::where('currency', $system->currency)
    ->where('is_active', true)
    ->where('balance', '>', 0)
    ->orderByDesc('balance')
    ->first();
if (! $source) {
    echo "  ✗ No active EGP account with balance > 0 found for source\n";
    exit(1);
}
echo "  - Source account: {$source->name} (id={$source->id}), balance={$source->balance} {$source->currency}\n";

$customer = Customer::firstOrCreate(
    ['phone' => '01000000001'],
    [
        'full_name' => 'مسافر اختبار Phase 6',
        'customer_tier' => 'STANDARD',
        'status' => 'active',
        'national_id' => '29001010100011',
        'created_by' => 1,
    ]
);
echo "  - Customer: {$customer->full_name} (id={$customer->id})\n";

// Verify currencies table has USD
$usdCurrency = \App\Models\Setting\Currency::where('code', 'USD')->where('is_active', true)->first();
if (! $usdCurrency) {
    echo "  ⚠️ USD currency not found in currencies table — will use fallback rate 48.5\n";
    $exchangeRate = 48.5;
} else {
    echo "  - USD currency found: rate={$usdCurrency->exchange_rate} EGP per 1 USD\n";
    $exchangeRate = (float) $usdCurrency->exchange_rate;
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [2] Recharge the system (EGP 50,000) — needed to enable booking
// ═══════════════════════════════════════════════════════════════════
echo "▸ [2] Recharging NDC_WONDR with 50,000 EGP...\n";

$rechargeAmount = 50000.0;
$sourceBalanceBefore = (float) $source->balance;
$systemBalanceBefore = (float) $system->balance;

try {
    $rechargeResult = app(FlightSystemRechargeService::class)
        ->rechargeFromAccount($system, $source, $rechargeAmount, 'Phase 6 test: temporary recharge for booking cycle test');

    $system->refresh();
    $source->refresh();
    echo "  ✓ Recharge successful:\n";
    echo "    - Source balance: {$sourceBalanceBefore} → {$source->balance}\n";
    echo "    - System balance: {$systemBalanceBefore} → {$system->balance}\n";
    echo "    - AirlineTransaction id: {$rechargeResult['airline_transaction']->id}\n";
} catch (\Throwable $e) {
    echo "  ✗ Recharge failed: {$e->getMessage()}\n";
    exit(2);
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [3] Booking #1: EGP currency
// ═══════════════════════════════════════════════════════════════════
echo "▸ [3] Creating Booking #1 (EGP, 100 EGP)...\n";

$balanceBeforeBooking1 = (float) $system->fresh()->balance;

$booking1Payload = [
    'customer_id'              => $customer->id,
    'booking_channel_type'     => 'SIGN',
    'booking_channel_provider' => 'SIGN',
    'system_type'              => 'gds',
    'airline_name'             => 'TestAirline',
    'airline'                  => 'TestAirline',
    'from_airport'             => 'CAI',
    'to_airport'               => 'JED',
    'departure_date'           => now()->addDays(7)->toDateString(),
    'departure_time'           => '10:00',
    'arrival_time'             => '12:30',
    'trip_type'                => 'one_way',
    'passenger_count'          => 1,
    'currency'                 => 'EGP',
    'purchase_price'           => 100.0,
    'selling_price'            => 150.0,
    'exchange_rate'            => 1.0,
    'flight_system_id'         => $system->id,
    'agent_name'               => 'Phase6-Test',
    'notes'                    => 'Phase 6 booking test (EGP)',
    'passengers' => [[
        'first_name'    => 'Test',
        'last_name'     => 'User1',
        'type'          => 'adult',
        'date_of_birth' => '1990-01-01',
    ]],
    'segments' => [[
        'from_airport'  => 'CAI',
        'to_airport'    => 'JED',
        'departure_at'  => now()->addDays(7)->setTime(10, 0)->toDateTimeString(),
        'arrival_at'    => now()->addDays(7)->setTime(12, 30)->toDateTimeString(),
        'flight_number' => 'PHASE6TEST1',
    ]],
];

try {
    $booking1 = app(FlightBookingService::class)->createBooking($booking1Payload);
    $system->refresh();
    echo "  ✓ Booking #1 created:\n";
    echo "    - Booking number: {$booking1->booking_number} (id={$booking1->id})\n";
    echo "    - Status: {$booking1->status->value}\n";
    echo "    - Purchase: {$booking1->purchase_price} {$booking1->currency}\n";
    echo "    - Selling: {$booking1->selling_price} {$booking1->currency}\n";
    echo "    - System balance: {$balanceBeforeBooking1} → {$system->balance} (Δ=" . number_format($system->balance - $balanceBeforeBooking1, 2) . ")\n";
} catch (\Throwable $e) {
    echo "  ✗ Booking #1 failed: {$e->getMessage()}\n";
    echo "  → Cleaning up recharge first...\n";
    // Refund the recharge to leave system in original state
    app(FlightSystemRechargeService::class)
        ->rechargeFromAccount($system, $source, -$rechargeAmount, 'Phase 6 cleanup after booking 1 failed');
    exit(3);
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [4] Cancel Booking #1
// ═══════════════════════════════════════════════════════════════════
echo "▸ [4] Cancelling Booking #1...\n";

$balanceBeforeCancel1 = (float) $system->fresh()->balance;

try {
    $refund1 = app(FlightBookingService::class)->cancelBooking($booking1, [
        'airline_penalty' => 0.0,
        'office_penalty'  => 0.0,
        'account_id'      => $source->id,
        'notes'           => 'Phase 6 test cancel (EGP booking)',
    ]);
    $system->refresh();
    echo "  ✓ Booking #1 cancelled:\n";
    echo "    - Refund id: {$refund1->id}\n";
    echo "    - Refund status: {$refund1->status->value}\n";
    echo "    - Booking status: {$booking1->fresh()->status->value}\n";
    echo "    - System balance: {$balanceBeforeCancel1} → {$system->balance} (Δ=" . number_format($system->balance - $balanceBeforeCancel1, 2) . ")\n";
    echo "    - Expected: balance should be restored (50,000 - 100 + 100 = 50,000)\n";
} catch (\Throwable $e) {
    echo "  ✗ Cancel #1 failed: {$e->getMessage()}\n";
    exit(4);
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [5] Booking #2: USD currency (foreign)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [5] Creating Booking #2 (USD, 50 USD @ {$exchangeRate} EGP/USD)...\n";

$balanceBeforeBooking2 = (float) $system->fresh()->balance;

$booking2Payload = [
    'customer_id'              => $customer->id,
    'booking_channel_type'     => 'SIGN',
    'booking_channel_provider' => 'SIGN',
    'system_type'              => 'gds',
    'airline_name'             => 'TestAirline',
    'airline'                  => 'TestAirline',
    'from_airport'             => 'CAI',
    'to_airport'               => 'DXB',
    'departure_date'           => now()->addDays(10)->toDateString(),
    'departure_time'           => '14:00',
    'arrival_time'             => '19:00',
    'trip_type'                => 'one_way',
    'passenger_count'          => 1,
    'currency'                 => 'USD',
    'foreign_currency'         => 'USD',
    'purchase_price_foreign'   => 50.0,
    'exchange_rate'            => $exchangeRate,
    'flight_system_id'         => $system->id,
    'agent_name'               => 'Phase6-Test',
    'notes'                    => 'Phase 6 booking test (USD foreign currency)',
    'passengers' => [[
        'first_name'    => 'Test',
        'last_name'     => 'User2',
        'type'          => 'adult',
        'date_of_birth' => '1990-01-01',
    ]],
    'segments' => [[
        'from_airport'  => 'CAI',
        'to_airport'    => 'DXB',
        'departure_at'  => now()->addDays(10)->setTime(14, 0)->toDateTimeString(),
        'arrival_at'    => now()->addDays(10)->setTime(19, 0)->toDateTimeString(),
        'flight_number' => 'PHASE6TEST2',
    ]],
];

try {
    $booking2 = app(FlightBookingService::class)->createBooking($booking2Payload);
    $system->refresh();
    echo "  ✓ Booking #2 created:\n";
    echo "    - Booking number: {$booking2->booking_number} (id={$booking2->id})\n";
    echo "    - Status: {$booking2->status->value}\n";
    echo "    - Currency: {$booking2->currency}\n";
    echo "    - Purchase (foreign): {$booking2->purchase_price_foreign} {$booking2->foreign_currency}\n";
    echo "    - Purchase (EGP): {$booking2->purchase_price_egp} EGP\n";
    echo "    - Exchange rate: {$booking2->exchange_rate}\n";
    echo "    - System balance: {$balanceBeforeBooking2} → {$system->balance} (Δ=" . number_format($system->balance - $balanceBeforeBooking2, 2) . ")\n";
    echo "    - Expected Δ: -" . number_format(50.0 * $exchangeRate, 2) . " EGP\n";
} catch (\Throwable $e) {
    echo "  ✗ Booking #2 failed: {$e->getMessage()}\n";
    exit(5);
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [6] Cancel Booking #2
// ═══════════════════════════════════════════════════════════════════
echo "▸ [6] Cancelling Booking #2...\n";

$balanceBeforeCancel2 = (float) $system->fresh()->balance;

try {
    $refund2 = app(FlightBookingService::class)->cancelBooking($booking2, [
        'airline_penalty' => 0.0,
        'office_penalty'  => 0.0,
        'account_id'      => $source->id,
        'notes'           => 'Phase 6 test cancel (USD booking)',
    ]);
    $system->refresh();
    echo "  ✓ Booking #2 cancelled:\n";
    echo "    - Refund id: {$refund2->id}\n";
    echo "    - Refund status: {$refund2->status->value}\n";
    echo "    - Booking status: {$booking2->fresh()->status->value}\n";
    echo "    - System balance: {$balanceBeforeCancel2} → {$system->balance} (Δ=" . number_format($system->balance - $balanceBeforeCancel2, 2) . ")\n";
    echo "    - Expected: balance should be restored (50,000 - " . number_format(50.0 * $exchangeRate, 2) . " + " . number_format(50.0 * $exchangeRate, 2) . " = 50,000)\n";
} catch (\Throwable $e) {
    echo "  ✗ Cancel #2 failed: {$e->getMessage()}\n";
    exit(6);
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [7] Cleanup: Reverse the temporary recharge (restore original balance)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [7] Cleanup — reversing temporary recharge (-50,000 EGP)...\n";

$balanceBeforeCleanup = (float) $system->fresh()->balance;
$sourceBalanceBeforeCleanup = (float) $source->fresh()->balance;

try {
    // Reverse the recharge by doing a "negative recharge"
    $cleanupResult = app(FlightSystemRechargeService::class)
        ->rechargeFromAccount($system, $source, -$rechargeAmount, 'Phase 6 cleanup: reverse temporary recharge');

    $system->refresh();
    $source->refresh();
    echo "  ✓ Cleanup successful:\n";
    echo "    - System balance: {$balanceBeforeCleanup} → {$system->balance}\n";
    echo "    - Source balance: {$sourceBalanceBeforeCleanup} → {$source->balance}\n";
    echo "    - Expected system balance: " . number_format($balanceBeforeRecharge, 2) . " (original)\n";
    echo "    - Match: " . (abs($system->balance - $balanceBeforeRecharge) < 0.01 ? '✅ YES' : '❌ NO') . "\n";
} catch (\Throwable $e) {
    echo "  ✗ Cleanup failed: {$e->getMessage()}\n";
    echo "  ⚠️ Manual cleanup needed: source balance needs +{$rechargeAmount} EGP, system balance needs -{$rechargeAmount} EGP\n";
    exit(7);
}
echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [8] Final verification
// ═══════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  FINAL VERIFICATION\n";
echo "═══════════════════════════════════════════════════════════════════\n";

$finalSystemBalance = (float) $system->fresh()->balance;
$balanceMatches = abs($finalSystemBalance - $balanceBeforeRecharge) < 0.01;

echo "  - NDC_WONDR final balance: " . number_format($finalSystemBalance, 2) . " EGP\n";
echo "  - Original balance before test: " . number_format($balanceBeforeRecharge, 2) . " EGP\n";
echo "  - Balance restored: " . ($balanceMatches ? '✅ YES' : '❌ NO (diff=' . number_format($finalSystemBalance - $balanceBeforeRecharge, 2) . ')') . "\n";

$booking1Status = $booking1->fresh()->status->value;
$booking2Status = $booking2->fresh()->status->value;
echo "  - Booking #1 status: {$booking1Status} (expected: CANCELLED)\n";
echo "  - Booking #2 status: {$booking2Status} (expected: CANCELLED)\n";

echo "\n";
if ($balanceMatches && $booking1Status === 'cancelled' && $booking2Status === 'cancelled') {
    echo "✅ PHASE 6 TEST PASSED — booking cycle works correctly for both EGP and USD\n";
    echo "   System balance restored. Bookings cancelled. Safe to continue.\n";
} else {
    echo "⚠️ PHASE 6 TEST INCOMPLETE — review output above\n";
}

echo "\n";
Log::info('Phase 6 booking cycle test completed', [
    'booking1_number' => $booking1->booking_number,
    'booking2_number' => $booking2->booking_number,
    'system_balance_before' => $balanceBeforeRecharge,
    'system_balance_after'  => $finalSystemBalance,
    'matches_original' => $balanceMatches,
]);