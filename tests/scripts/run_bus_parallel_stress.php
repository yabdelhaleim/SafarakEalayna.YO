<?php

/**
 * Phase 9 — Bus Module concurrent stress test runner.
 *
 * Boots a real `php artisan serve` instance against a file-backed SQLite DB
 * and fires parallel HTTP requests via curl_multi to verify Phase 9
 * race-condition fixes under genuine concurrent load.
 *
 * Usage:
 *   php tests/Scripts/run_bus_parallel_stress.php
 *
 * Exit codes:
 *   0 = all invariants held (PASS)
 *   1 = invariant violated (FAIL)
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// ─── Setup isolated test DB ──────────────────────────────────────────────────
$dbPath = storage_path('framework/bus_stress.sqlite');
if (File::exists($dbPath)) {
    File::delete($dbPath);
}

// Override config for this script run only
config(['database.connections.sqlite.database' => $dbPath]);
config(['database.default' => 'sqlite']);

// Force the artisan facade to pick up new config
\DB::purge('sqlite');
\DB::reconnect('sqlite');

echo "═══ Phase 9 Bus Concurrent Stress Test ═══\n";
echo "Test DB: {$dbPath}\n\n";

// Run migrations
echo "Migrating fresh DB...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
echo Artisan::output();

// ─── Bootstrap fixtures ──────────────────────────────────────────────────────
echo "\nSeeding fixtures...\n";
$admin = \App\Models\User::create([
    'name' => 'Bus Stress Admin',
    'email' => 'bus-stress@example.com',
    'password' => \Illuminate\Support\Facades\Hash::make('password'),
    'role' => 'admin',
    'is_active' => true,
]);
echo "  admin id={$admin->id}\n";

\App\Support\Finance\LedgerBalanceMutationGuard::run(function () {
    \App\Models\Account::create([
        'name' => 'Stress Cashbox',
        'type' => \App\Enums\AccountType::Cashbox,
        'currency' => 'EGP',
        'balance' => 100000.0,
        'is_active' => true,
        'owner_type' => \App\Models\Account::OWNER_TYPE_OFFICE,
        'module_type' => 'office',
        'is_module_vault' => true,
        'notes' => 'Stress cashbox',
        'created_by' => 1,
    ]);
});
$cashbox = \App\Models\Account::where('name', 'Stress Cashbox')->first();
echo "  cashbox id={$cashbox->id}\n";

\App\Services\Finance\LedgerClearingAccounts::class;
app(\App\Services\Finance\LedgerClearingAccounts::class)
    ->incomeContraIdForModule(\App\Enums\TransactionModule::Bus->value);
app(\App\Services\Finance\LedgerClearingAccounts::class)
    ->expenseContraIdForModule(\App\Enums\TransactionModule::Bus->value);

$treasury = \App\Models\Treasury::create([
    'name' => 'Stress Treasury',
    'currency' => 'EGP',
    'current_balance' => 0,
    'is_active' => true,
]);
echo "  treasury id={$treasury->id}\n";

// ─── Boot artisan serve ──────────────────────────────────────────────────────
$port = 8765;
$baseUrl = "http://127.0.0.1:{$port}";

echo "\nBooting artisan serve on port {$port}...\n";
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$serverProc = proc_open(
    "php artisan serve --host=127.0.0.1 --port={$port}",
    $descriptors,
    $pipes,
    base_path()
);

// Wait for server to come up
echo "Waiting for server to be ready...\n";
$ready = false;
for ($i = 0; $i < 30; $i++) {
    usleep(500_000); // 0.5s
    $ch = curl_init($baseUrl.'/up');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $ready = true;
        break;
    }
}
if (! $ready) {
    echo "✗ Server failed to start.\n";
    proc_terminate($serverProc);
    exit(1);
}
echo "✓ Server ready\n\n";

// Helper: login + cache token
echo "Authenticating...\n";
$loginResp = file_get_contents("{$baseUrl}/api/v1/auth/login", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => ['Content-Type: application/json'],
        'content' => json_encode(['email' => 'bus-stress@example.com', 'password' => 'password']),
    ],
]));
$loginData = json_decode($loginResp, true);
if (! ($loginData['success'] ?? false)) {
    echo "✗ Login failed: ".$loginResp."\n";
    proc_terminate($serverProc);
    exit(1);
}
$token = $loginData['data']['token'];
echo "✓ Token acquired\n\n";

// ─── Scenario A: Concurrent booking on capacity=5 ────────────────────────────
$failures = [];
echo "═══ Scenario A: 10 parallel bookings on capacity=5 ═══\n";
$companyA = \App\Models\Bus\BusCompany::create(['name' => 'Stress Co A', 'is_active' => true]);
$inventoryA = \App\Models\Bus\BusInventory::create([
    'company_id' => $companyA->id,
    'route' => 'Stress Route A',
    'travel_date' => now()->addDays(7)->toDateString(),
    'departure_time' => '08:00',
    'total_tickets' => 5,
    'available_tickets' => 5,
    'cost_per_ticket' => 80,
    'selling_price' => 100,
    'payment_type' => 'deferred',
    'currency' => 'EGP',
    'exchange_rate_to_egp' => 1.0,
]);

$results = parallelCurl('POST', "{$baseUrl}/api/v1/bus/bookings", [
    'inventory_id' => $inventoryA->id,
    'customer_name' => 'Stress Cust A',
    'customer_phone' => '0100STRA000',
    'quantity' => 1,
], $token, 10);
$okA = count(array_filter($results, fn ($r) => $r['status'] === 201));
$rejA = count(array_filter($results, fn ($r) => $r['status'] === 422));
echo "  Successes: {$okA} (expected 5)\n";
echo "  Rejects: {$rejA} (expected 5)\n";
echo "  Final available: ".$inventoryA->fresh()->available_tickets." (expected 0)\n";
if ($okA !== 5) $failures[] = "Scenario A: ok={$okA} (want 5)";
if ($rejA !== 5) $failures[] = "Scenario A: rejects={$rejA} (want 5)";
if ($inventoryA->fresh()->available_tickets !== 0) $failures[] = "Scenario A: avail=".$inventoryA->fresh()->available_tickets;

// ─── Scenario B: Concurrent pay ───────────────────────────────────────────────
echo "\n═══ Scenario B: 20 parallel pays of 50 EGP on total=250 ═══\n";
$companyB = \App\Models\Bus\BusCompany::create(['name' => 'Stress Co B', 'is_active' => true]);
$inventoryB = \App\Models\Bus\BusInventory::create([
    'company_id' => $companyB->id,
    'route' => 'Stress Route B',
    'travel_date' => now()->addDays(8)->toDateString(),
    'departure_time' => '08:00',
    'total_tickets' => 1,
    'available_tickets' => 1,
    'cost_per_ticket' => 200,
    'selling_price' => 250,
    'payment_type' => 'deferred',
    'currency' => 'EGP',
    'exchange_rate_to_egp' => 1.0,
]);

// Create the booking via API so the auth + employee flow is exercised end-to-end
$bookingResp = json_decode(file_get_contents("{$baseUrl}/api/v1/bus/bookings", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => ['Content-Type: application/json', 'Authorization: Bearer '.$token],
        'content' => json_encode([
            'inventory_id' => $inventoryB->id,
            'customer_name' => 'Stress Cust B',
            'customer_phone' => '0100STRB000',
            'quantity' => 1,
        ]),
    ],
])), true);
$bookingB = \App\Models\Bus\BusBooking::find($bookingResp['data']['id']);
echo "  Booking created id={$bookingB->id} total=250\n";

$results = parallelCurl('POST', "{$baseUrl}/api/v1/bus/bookings/{$bookingB->id}/pay", [
    'amount' => 50.0,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
], $token, 20);
$okB = count(array_filter($results, fn ($r) => $r['status'] === 200));
$rejB = count(array_filter($results, fn ($r) => $r['status'] === 422));
echo "  Successes: {$okB} (expected 5)\n";
echo "  Rejects: {$rejB} (expected 15)\n";
echo "  Final paid: ".$bookingB->fresh()->paid_amount." (expected 250)\n";
$cashboxBalance = (float) $cashbox->fresh()->balance;
echo "  Cashbox balance: {$cashboxBalance} (expected 100250)\n";
if ($okB !== 5) $failures[] = "Scenario B: ok={$okB} (want 5)";
if ($rejB !== 15) $failures[] = "Scenario B: rejects={$rejB} (want 15)";
if ((float) $bookingB->fresh()->paid_amount !== 250.0) $failures[] = "Scenario B: paid=".$bookingB->fresh()->paid_amount." (want 250)";
if (abs($cashboxBalance - 100250.0) > 0.01) $failures[] = "Scenario B: cashbox={$cashboxBalance} (want 100250)";

// ─── Scenario C: Concurrent pay-inventory-debt ───────────────────────────────
echo "\n═══ Scenario C: 10 parallel pay-inventory-debt of 50 on remaining=400 ═══\n";
$companyC = \App\Models\Bus\BusCompany::create(['name' => 'Stress Co C', 'is_active' => true]);
$inventoryC = \App\Models\Bus\BusInventory::create([
    'company_id' => $companyC->id,
    'route' => 'Stress Route C',
    'travel_date' => now()->addDays(9)->toDateString(),
    'departure_time' => '08:00',
    'total_tickets' => 8,
    'available_tickets' => 8,
    'cost_per_ticket' => 50,
    'selling_price' => 70,
    'payment_type' => 'deferred',
    'currency' => 'EGP',
    'exchange_rate_to_egp' => 1.0,
    'total_cost' => 400,
    'amount_paid' => 0,
    'remaining_debt' => 400,
]);

$results = parallelCurl('POST', "{$baseUrl}/api/v1/bus/inventories/{$inventoryC->id}/pay-debt", [
    'amount' => 50.0,
    'account_id' => $cashbox->id,
], $token, 10);
$okC = count(array_filter($results, fn ($r) => $r['status'] === 201 || $r['status'] === 200));
$rejC = count(array_filter($results, fn ($r) => $r['status'] === 422));
echo "  Successes: {$okC} (expected 8)\n";
echo "  Rejects: {$rejC} (expected 2)\n";
echo "  Final remaining_debt: ".$inventoryC->fresh()->remaining_debt." (expected 0)\n";
echo "  Final amount_paid: ".$inventoryC->fresh()->amount_paid." (expected 400)\n";
if ($okC !== 8) $failures[] = "Scenario C: ok={$okC} (want 8)";
if ($rejC !== 2) $failures[] = "Scenario C: rejects={$rejC} (want 2)";
if ((float) $inventoryC->fresh()->remaining_debt !== 0.0) $failures[] = "Scenario C: remaining_debt=".$inventoryC->fresh()->remaining_debt;
if ((float) $inventoryC->fresh()->amount_paid !== 400.0) $failures[] = "Scenario C: amount_paid=".$inventoryC->fresh()->amount_paid;

// ─── Scenario D: Concurrent process-refund ───────────────────────────────────
echo "\n═══ Scenario D: 5 parallel process-refund on same refund request ═══\n";
$companyD = \App\Models\Bus\BusCompany::create(['name' => 'Stress Co D', 'is_active' => true]);
$inventoryD = \App\Models\Bus\BusInventory::create([
    'company_id' => $companyD->id,
    'route' => 'Stress Route D',
    'travel_date' => now()->addDays(10)->toDateString(),
    'departure_time' => '08:00',
    'total_tickets' => 5,
    'available_tickets' => 5,
    'cost_per_ticket' => 50,
    'selling_price' => 100,
    'payment_type' => 'deferred',
    'currency' => 'EGP',
    'exchange_rate_to_egp' => 1.0,
]);
$bookingResp = json_decode(file_get_contents("{$baseUrl}/api/v1/bus/bookings", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => ['Content-Type: application/json', 'Authorization: Bearer '.$token],
        'content' => json_encode([
            'inventory_id' => $inventoryD->id,
            'customer_name' => 'Stress Cust D',
            'customer_phone' => '0100STRD000',
            'quantity' => 1,
        ]),
    ],
])), true);
$bookingD = \App\Models\Bus\BusBooking::find($bookingResp['data']['id']);

$refundCreateResp = file_get_contents("{$baseUrl}/api/v1/bus/refunds", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => ['Content-Type: application/json', 'Authorization: Bearer '.$token],
        'content' => json_encode([
            'bus_booking_id' => $bookingD->id,
            'cancellation_fee' => 0,
            'refund_currency' => 'EGP',
            'refund_exchange_rate' => 1.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]),
    ],
]));
$refundData = json_decode($refundCreateResp, true);
$refundId = $refundData['data']['id'];

$treasuryBefore = (float) $treasury->fresh()->current_balance;
$results = parallelCurl('POST', "{$baseUrl}/api/v1/bus/refunds/{$refundId}/process", [], $token, 5);
$okD = count(array_filter($results, fn ($r) => $r['status'] === 200));
$treasuryAfter = (float) $treasury->fresh()->current_balance;
echo "  Successes: {$okD} (all should be 200 — idempotent)\n";
echo "  Treasury delta: ".($treasuryAfter - $treasuryBefore)." (expected 100 — credited ONCE)\n";
$refundReq = \App\Models\Bus\BusRefundRequest::find($refundId);
echo "  Refund status: {$refundReq->status} (expected processed)\n";
if (abs(($treasuryAfter - $treasuryBefore) - 100.0) > 0.01) {
    $failures[] = "Scenario D: treasury delta=".($treasuryAfter - $treasuryBefore)." (want 100)";
}
if ($refundReq->status !== 'processed') {
    $failures[] = "Scenario D: refund status={$refundReq->status}";
}

// ─── Final Report ─────────────────────────────────────────────────────────────
echo "\n═══ FINAL REPORT ═══\n";
if (empty($failures)) {
    echo "✓ ALL CONCURRENT INVARIANTS HELD — Phase 9 race-condition fixes confirmed.\n";
    $exit = 0;
} else {
    echo "✗ FAILURES DETECTED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    $exit = 1;
}

// Teardown
proc_terminate($serverProc);
proc_close($serverProc);
echo "\nServer stopped.\n";
exit($exit);

/**
 * Fire N parallel curl requests.
 */
function parallelCurl(string $method, string $url, array $body, string $token, int $count): array
{
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer '.$token,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($active > 0);

    $results = [];
    foreach ($handles as $i => $ch) {
        $response = curl_multi_getcontent($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $results[$i] = [
            'status' => $status,
            'body' => json_decode($response, true) ?? [],
        ];
    }
    curl_multi_close($mh);
    return $results;
}
