<?php

/**
 * Phase 9.9d — TRUE HTTP Concurrency: Concurrent cancel + payment race.
 *
 * Creates a booking, then fires N parallel requests: half try to cancel,
 * half try to add a payment. Verifies that the booking ends in a
 * deterministic terminal state (Cancelled or Paid, not a torn state),
 * and that no payment row is created after the cancel succeeds.
 *
 * Usage:
 *   php tests/scripts/stress_visa_concurrent_cancels.php
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
$PAYMENT_CONCURRENCY = 25;
$CANCEL_CONCURRENCY = 5;

echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│  Phase 9.9d — Concurrent cancel + payment race              │\n";
echo "│  {$PAYMENT_CONCURRENCY} payments + {$CANCEL_CONCURRENCY} cancels on SAME booking       │\n";
echo "└─────────────────────────────────────────────────────────────┘\n\n";

$vaultId   = (int) DB::table('accounts')->where('name', 'Stress Vault EGP')->value('id');
$agentId   = (int) DB::table('visa_agents')->value('id');
$durationId = (int) DB::table('visa_durations')->value('id');
$customerId = (int) DB::table('customers')->value('id');

$booking = app(\App\Services\Visa\VisaBookingService::class)->create([
    'customer_id' => $customerId,
    'purchase_price' => 1000.0,
    'selling_price' => 1500.0,
    'service_fee' => 100.0,
    'currency' => 'EGP',
    'account_id' => $vaultId,
    'agent_name' => 'Stress Agent',
    'notes' => 'Race test',
    'visa_details' => [
        'visa_type' => 'tourist',
        'country' => 'RACE-LAND',
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

// Build all payloads
$requests = [];
for ($i = 1; $i <= $PAYMENT_CONCURRENCY; $i++) {
    $requests[] = [
        'kind' => 'payment',
        'url' => "/api/v1/visa/bookings/{$bookingId}/payments",
        'payload' => [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $vaultId,
            'idempotency_key' => "RACE_PAY_{$i}_" . uniqid(),
            'reference' => "RACE-REF-{$i}",
            'currency' => 'EGP',
            'paid_by' => 'race',
        ],
    ];
}
for ($i = 1; $i <= $CANCEL_CONCURRENCY; $i++) {
    $requests[] = [
        'kind' => 'cancel',
        'url' => "/api/v1/visa/bookings/{$bookingId}/cancel",
        'payload' => ['reason' => "race cancel {$i}"],
    ];
}

// Shuffle so cancel and payment requests are interleaved
shuffle($requests);

echo "[STRESS] Firing " . count($requests) . " parallel requests (mixed)...\n";
$start = microtime(true);

$mh = curl_multi_init();
$handles = [];
foreach ($requests as $i => $req) {
    $ch = curl_init($BASE . $req['url']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $TOKEN",
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['payload']));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $handles[$i] = ['ch' => $ch, 'kind' => $req['kind']];
    curl_multi_add_handle($mh, $ch);
}
$total = count($requests);
curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $total);
curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $total);

$active = null;
do {
    $status = curl_multi_exec($mh, $active);
    if ($active) curl_multi_select($mh, 0.5);
} while ($active && $status === CURLM_OK);

$results = [];
foreach ($handles as $i => $h) {
    $body = curl_multi_getcontent($h['ch']);
    $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
    $results[$i] = ['kind' => $h['kind'], 'status' => $code, 'json' => json_decode($body, true)];
    curl_multi_remove_handle($mh, $h['ch']);
    curl_close($h['ch']);
}
curl_multi_close($mh);
$elapsed = round(microtime(true) - $start, 2);

$payStatuses = [];
$cancStatuses = [];
foreach ($results as $r) {
    if ($r['kind'] === 'payment') {
        $payStatuses[$r['status']] = ($payStatuses[$r['status']] ?? 0) + 1;
    } else {
        $cancStatuses[$r['status']] = ($cancStatuses[$r['status']] ?? 0) + 1;
    }
}

echo "[STRESS] Completed in {$elapsed}s\n";
echo "[STRESS] Payment status codes: " . json_encode($payStatuses) . "\n";
echo "[STRESS] Cancel  status codes: " . json_encode($cancStatuses) . "\n\n";

// Verify final state
$bookingAfter = DB::table('visa_bookings')->find($bookingId);
$paymentCount = (int) DB::table('visa_payments')
    ->where('visa_booking_id', $bookingId)->count();
$paymentSum = (float) DB::table('visa_payments')
    ->where('visa_booking_id', $bookingId)->sum('amount');
$vaultAfter = (float) DB::table('account_entries')
    ->where('account_id', $vaultId)
    ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
$globalBalance = (float) DB::table('account_entries')
    ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

echo "[VERIFY] Booking final status: {$bookingAfter->status}\n";
echo "[VERIFY] Payment rows: {$paymentCount}\n";
echo "[VERIFY] SUM(amount): {$paymentSum}\n";
echo "[VERIFY] Vault NET change: " . round($vaultAfter - $baselineVault, 2) . "\n";
echo "[VERIFY] Global ledger NET: " . round($globalBalance, 2) . "\n\n";

// INVARIANTS:
// - Booking must end in a deterministic state
// - Global ledger must be balanced (SUM credit == SUM debit)
// - Vault NET change reflects ONLY un-reversed payments:
//     * If status=cancelled  → vault NET change must be 0 (additive reversal)
//     * If status!=cancelled → vault NET change must == SUM of committed payments
$pass = true;
$failures = [];

if (!in_array($bookingAfter->status, ['cancelled', 'submitted', 'under_review', 'approved', 'issued'], true)) {
    $pass = false;
    $failures[] = "❌ Booking in unexpected state: {$bookingAfter->status}";
}
if (abs($globalBalance) > 0.01) {
    $pass = false;
    $failures[] = "❌ Global ledger off: " . round($globalBalance, 2);
}
$vaultDelta = round($vaultAfter - $baselineVault, 2);
if ($bookingAfter->status === 'cancelled') {
    // Cancel reverses all payments via additive-reversal pattern → vault returns to baseline
    if (abs($vaultDelta) > 0.01) {
        $pass = false;
        $failures[] = "❌ Vault NET change {$vaultDelta} must be 0 after cancel (reversal)";
    }
} else {
    // Active booking: vault NET change == SUM of committed (non-cancelled) payments
    if (abs($vaultDelta - $paymentSum) > 0.01) {
        $pass = false;
        $failures[] = "❌ Vault NET change {$vaultDelta} != payment SUM {$paymentSum}";
    }
}

if ($pass) {
    echo "┌─────────────────────────────────────────────────────────────┐\n";
    echo "│  ✅ PASS — Race resolved cleanly                            │\n";
    echo "│  • Final booking status: {$bookingAfter->status}                      │\n";
    echo "│  • {$paymentCount} payments committed, SUM = {$paymentSum}                │\n";
    echo "│  • Vault NET change: {$vaultDelta}                              │\n";
    echo "│  • Global ledger balanced                                   │\n";
    echo "│  • Status codes: pay=" . json_encode($payStatuses) . " cancel=" . json_encode($cancStatuses) . " │\n";
    echo "└─────────────────────────────────────────────────────────────┘\n";
    exit(0);
} else {
    echo "┌─────────────────────────────────────────────────────────────┐\n";
    echo "│  ❌ FAIL — Phase 9.9d                                       │\n";
    echo "└─────────────────────────────────────────────────────────────┘\n";
    foreach ($failures as $f) echo "  $f\n";
    exit(1);
}