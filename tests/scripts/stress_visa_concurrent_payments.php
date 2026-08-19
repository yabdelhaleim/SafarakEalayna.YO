<?php

/**
 * Phase 9.9b — TRUE HTTP Concurrency: Stress Visa concurrent payments.
 *
 * Fires N parallel POST requests to /api/v1/visa/bookings/{id}/payments
 * with DIFFERENT idempotency_keys but the SAME transaction_reference.
 *
 * Expected behavior (Phase 9.8 fix):
 *   - Exactly ONE payment row created (DB UNIQUE on (booking_id, ref))
 *   - Exactly ONE vault credit (no double-spend)
 *   - Other N-1 requests return 200 with the existing payment (idempotent replay)
 *
 * Usage:
 *   php tests/scripts/stress_visa_concurrent_payments.php [concurrency]
 *   e.g. php tests/scripts/stress_visa_concurrent_payments.php 25
 */

declare(strict_types=1);

$stressDbPath = 'storage/app/stress.sqlite';
putenv('APP_ENV=local');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $stressDbPath);
$_ENV['APP_ENV'] = 'local';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $stressDbPath;
$_SERVER['APP_ENV'] = 'local';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $stressDbPath;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyGuard;

StressSafetyGuard::assertSafeEnvironment();

$TOKEN = 'stress-tier-fixed-token-for-curl-scripts';
$BASE  = 'http://127.0.0.1:18000';
$CONCURRENCY = (int) ($argv[1] ?? 25);

// ─── Setup: create a fresh booking for this stress run ──────────────────
$adminId   = (int) DB::table('users')->where('email', 'stress-admin@safarakealayna.test')->value('id');
$vaultId   = (int) DB::table('accounts')->where('name', 'Stress Vault EGP')->value('id');
$agentId   = (int) DB::table('visa_agents')->value('id');
$durationId = (int) DB::table('visa_durations')->value('id');
$customerId = (int) DB::table('customers')->value('id');

echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│  Phase 9.9b — Concurrent payment stress                     │\n";
echo "│  Concurrency: {$CONCURRENCY} parallel HTTP requests          │\n";
echo "└─────────────────────────────────────────────────────────────┘\n\n";

// Create 1 fresh booking
echo "[SETUP] Creating fresh booking...\n";
$booking = app(\App\Services\Visa\VisaBookingService::class)->create([
    'customer_id' => $customerId,
    'purchase_price' => 1000.0,
    'selling_price' => 1600.0,
    'service_fee' => 100.0,
    'currency' => 'EGP',
    'account_id' => $vaultId,
    'agent_name' => 'Stress Agent',
    'notes' => 'Stress test booking',
    'visa_details' => [
        'visa_type' => 'tourist',
        'country' => 'STRESS-LAND',
        'duration' => '30',
        'visa_duration_id' => $durationId,
        'entry_type' => 'single',
        'validity_from' => date('Y-m-d'),
        'validity_to' => date('Y-m-d', strtotime('+30 days')),
        'executing_company' => 'Stress Co',
        'visa_agent_id' => $agentId,
    ],
]);

$bookingId = $booking->id;
echo "[SETUP] Created booking #{$bookingId}\n\n";

$baselineVault = (float) DB::table('account_entries')
    ->where('account_id', $vaultId)
    ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
$baselinePaymentCount = (int) DB::table('visa_payments')->count();

$reference = "CONC_PAY_" . uniqid();

// ─── Build N payloads with DIFFERENT idempotency_keys but SAME reference ──
$payloads = [];
for ($i = 1; $i <= $CONCURRENCY; $i++) {
    $payloads[] = [
        'amount' => 100.0,
        'payment_method' => 'cash',
        'account_id' => $vaultId,
        'idempotency_key' => "CONC_KEY_{$i}_" . uniqid(),
        'reference' => $reference,
        'currency' => 'EGP',
        'paid_by' => 'stress',
    ];
}

// ─── Fire N parallel HTTP POSTs ─────────────────────────────────────────
echo "[STRESS] Firing {$CONCURRENCY} parallel POST /payments...\n";
$start = microtime(true);

$mh = curl_multi_init();
$handles = [];
foreach ($payloads as $i => $payload) {
    $ch = curl_init($BASE . "/api/v1/visa/bookings/{$bookingId}/payments");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $TOKEN",
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $handles[$i] = $ch;
    curl_multi_add_handle($mh, $ch);
}
curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $CONCURRENCY);
curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $CONCURRENCY);

$active = null;
do {
    $status = curl_multi_exec($mh, $active);
    if ($active) curl_multi_select($mh, 0.5);
} while ($active && $status === CURLM_OK);

$results = [];
foreach ($handles as $i => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $results[$i] = ['status' => $code, 'json' => json_decode($body, true)];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$elapsed = round(microtime(true) - $start, 2);

// ─── Aggregate results ──────────────────────────────────────────────────
$statusCount = [];
foreach ($results as $r) {
    $statusCount[$r['status']] = ($statusCount[$r['status']] ?? 0) + 1;
}

echo "[STRESS] Completed in {$elapsed}s\n";
echo "[STRESS] Status codes: " . json_encode($statusCount) . "\n\n";

// ─── Verify database state ─────────────────────────────────────────────
$paymentCount = (int) DB::table('visa_payments')
    ->where('visa_booking_id', $bookingId)
    ->count();
$vaultAfter = (float) DB::table('account_entries')
    ->where('account_id', $vaultId)
    ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
$globalBalance = (float) DB::table('account_entries')
    ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
$booking = DB::table('visa_bookings')->find($bookingId);
$bookingPaidSum = (float) DB::table('visa_payments')
    ->where('visa_booking_id', $bookingId)
    ->sum('amount');

echo "[VERIFY] Payments on booking: {$paymentCount} (expected: 1)\n";
echo "[VERIFY] Vault NET change: " . round($vaultAfter - $baselineVault, 2) . " (expected: 100)\n";
echo "[VERIFY] Global ledger NET: " . round($globalBalance, 2) . " (expected: 0)\n";
echo "[VERIFY] SUM(visa_payments.amount) on booking: {$bookingPaidSum} (expected: 100)\n\n";

// ─── Verdict ────────────────────────────────────────────────────────────
$pass = true;
$failures = [];

if ($paymentCount !== 1) {
    $pass = false;
    $failures[] = "❌ Double-payment defect REGRESSED: {$paymentCount} payments (expected 1)";
}
if (abs(($vaultAfter - $baselineVault) - 100.0) > 0.01) {
    $pass = false;
    $failures[] = "❌ Vault double-credit: " . round($vaultAfter - $baselineVault, 2) . " (expected 100)";
}
if (abs($globalBalance) > 0.01) {
    $pass = false;
    $failures[] = "❌ Global ledger off: " . round($globalBalance, 2) . " (expected 0)";
}
if (abs($bookingPaidSum - 100.0) > 0.01) {
    $pass = false;
    $failures[] = "❌ Booking payments SUM off: {$bookingPaidSum} (expected 100)";
}

if ($pass) {
    echo "┌─────────────────────────────────────────────────────────────┐\n";
    echo "│  ✅ PASS — {$CONCURRENCY} concurrent same-ref payments → exactly 1 │\n";
    echo "│  • 1 payment row created                                     │\n";
    echo "│  • 100 EGP single vault credit                               │\n";
    echo "│  • Global ledger balanced                                   │\n";
    echo "│  • booking.paid_amount = 100                                │\n";
    echo "│                                                             │\n";
    echo "│  Status code distribution: " . json_encode($statusCount) . "             │\n";
    echo "└─────────────────────────────────────────────────────────────┘\n";
    exit(0);
} else {
    echo "┌─────────────────────────────────────────────────────────────┐\n";
    echo "│  ❌ FAIL — Phase 9.9b                                       │\n";
    echo "└─────────────────────────────────────────────────────────────┘\n";
    foreach ($failures as $f) echo "  $f\n";
    exit(1);
}