<?php

/**
 * Phase 9 — Bus Module concurrent stress test runner (MySQL variant).
 *
 * Runs against the .env MySQL DB (already fully migrated). Does NOT call
 * migrate:fresh (which conflicts with the live DB). Uses unique 'Stress9_*'
 * fixture names so it does not collide with seeded test data. Cleans up
 * Stress9_* rows at startup and again at the end.
 *
 * Boots a real `php artisan serve` instance and fires parallel HTTP
 * requests via curl_multi to verify Phase 9 race-condition fixes under
 * genuine concurrent load.
 *
 * Usage:
 *   php tests/Scripts/run_bus_parallel_stress_mysql.php
 *
 * Exit codes:
 *   0 = all invariants held (PASS)
 *   1 = invariant violated (FAIL)
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusRefundRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

echo "═══ Phase 9 Bus Concurrent Stress Test (MySQL) ═══\n";
echo 'DB: '.config('database.connections.mysql.database')."\n\n";

// ─── Pre-clean Stress9_* rows from previous runs ────────────────────────────
echo "Cleaning prior Stress9_* fixtures...\n";
$deleted = DB::table('bus_bookings')->where('notes', 'like', 'Stress9_%')->delete();
$deleted += DB::table('bus_refund_requests')->where('notes', 'like', 'Stress9_%')->delete();
$deleted += DB::table('bus_inventories')->where('route', 'like', 'Stress9 %')->delete();
$deleted += DB::table('bus_companies')->where('name', 'like', 'Stress9 %')->delete();
echo "  Deleted ~{$deleted} prior rows\n\n";

// ─── Bootstrap fresh fixtures ───────────────────────────────────────────────
$admin = User::query()->where('email', 'bus-stress9@example.com')->first();
if (! $admin) {
    $admin = User::query()->create([
        'name' => 'Bus Stress9 Admin',
        'email' => 'bus-stress9@example.com',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}
echo "✓ admin id={$admin->id}\n";

$cashbox = Account::query()->where('name', 'Stress9 Cashbox')->first();
if (! $cashbox) {
    LedgerBalanceMutationGuard::run(function () {
        Account::query()->create([
            'name' => 'Stress9 Cashbox',
            'type' => \App\Enums\AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 100000.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => true,
            'notes' => 'Stress9 cashbox',
            'created_by' => 1,
        ]);
    });
    $cashbox = Account::query()->where('name', 'Stress9 Cashbox')->first();
}
echo "✓ cashbox id={$cashbox->id}\n";

// Ensure clearing accounts exist for the Bus module
$ledgerClearing = app(\App\Services\Finance\LedgerClearingAccounts::class);
$ledgerClearing->incomeContraIdForModule(\App\Enums\TransactionModule::Bus->value);
$ledgerClearing->expenseContraIdForModule(\App\Enums\TransactionModule::Bus->value);

$treasury = Treasury::query()->where('name', 'Stress9 Treasury')->first();
if (! $treasury) {
    $treasury = Treasury::query()->create([
        'name' => 'Stress9 Treasury',
        'currency' => 'EGP',
        'current_balance' => 0,
        'is_active' => true,
    ]);
}
echo "✓ treasury id={$treasury->id}\n\n";

// ─── Boot artisan serve ─────────────────────────────────────────────────────
$port = 8765;
$baseUrl = "http://127.0.0.1:{$port}";

echo "Booting artisan serve on port {$port}...\n";
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

echo "Waiting for server to be ready...\n";
$ready = false;
for ($i = 0; $i < 30; $i++) {
    usleep(500_000);
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

// ─── Login ──────────────────────────────────────────────────────────────────
echo "Authenticating...\n";
$loginResp = file_get_contents("{$baseUrl}/api/v1/auth/login", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => ['Content-Type: application/json'],
        'content' => json_encode(['email' => 'bus-stress9@example.com', 'password' => 'password']),
    ],
]));
$loginData = json_decode($loginResp, true);
if (! ($loginData['success'] ?? false)) {
    echo "✗ Login failed: {$loginResp}\n";
    proc_terminate($serverProc);
    exit(1);
}
$token = $loginData['data']['token'];
echo "✓ Token acquired\n\n";

$failures = [];

// ─── Scenario A: Concurrent booking on capacity=5 ───────────────────────────
echo "═══ Scenario A: 10 parallel bookings on capacity=5 ═══\n";
$companyA = BusCompany::query()->create([
    'name' => 'Stress9 Co A', 'is_active' => true, 'created_by' => $admin->id,
]);
$inventoryA = BusInventory::query()->create([
    'company_id' => $companyA->id,
    'route' => 'Stress9 Route A',
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
    'customer_name' => 'Stress9 Cust A',
    'customer_phone' => '0100STRA900',
    'quantity' => 1,
], $token, 10);
$okA = count(array_filter($results, fn ($r) => $r['status'] === 201));
$rejA = count(array_filter($results, fn ($r) => $r['status'] === 422));
$invAfterA = (int) $inventoryA->fresh()->available_tickets;
echo "  Successes (201): {$okA} (want 5)\n";
echo "  Rejects (422): {$rejA} (want 5)\n";
echo "  Final available: {$invAfterA} (want 0)\n";
if ($okA !== 5) $failures[] = "Scenario A: ok={$okA} (want 5)";
if ($rejA !== 5) $failures[] = "Scenario A: rejects={$rejA} (want 5)";
if ($invAfterA !== 0) $failures[] = "Scenario A: avail={$invAfterA} (want 0)";

// ─── Scenario B: Concurrent pay on SAME booking ─────────────────────────────
echo "\n═══ Scenario B: 20 parallel pays of 50 EGP on total=250 ═══\n";
$companyB = BusCompany::query()->create([
    'name' => 'Stress9 Co B', 'is_active' => true, 'created_by' => $admin->id,
]);
$inventoryB = BusInventory::query()->create([
    'company_id' => $companyB->id,
    'route' => 'Stress9 Route B',
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
$bookingB = app(BusBookingService::class)->createBooking([
    'inventory_id' => $inventoryB->id,
    'customer_name' => 'Stress9 Cust B',
    'customer_phone' => '0100STRB900',
    'quantity' => 1,
]);
$bookingB->refresh();
echo "  Booking created id={$bookingB->id} total=250\n";

$results = parallelCurl('POST', "{$baseUrl}/api/v1/bus/bookings/{$bookingB->id}/pay", [
    'amount' => 50.0,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
], $token, 20);
$okB = count(array_filter($results, fn ($r) => $r['status'] === 200));
$rejB = count(array_filter($results, fn ($r) => $r['status'] === 422));
$paidAfter = (float) $bookingB->fresh()->paid_amount;
$cashboxBalance = (float) $cashbox->fresh()->balance;
echo "  Successes (200): {$okB} (want 5)\n";
echo "  Rejects (422): {$rejB} (want 15)\n";
echo "  Final paid: {$paidAfter} (want 250)\n";
echo "  Cashbox: {$cashboxBalance} (want 100250)\n";
if ($okB !== 5) $failures[] = "Scenario B: ok={$okB} (want 5)";
if ($rejB !== 15) $failures[] = "Scenario B: rejects={$rejB} (want 15)";
if (abs($paidAfter - 250.0) > 0.01) $failures[] = "Scenario B: paid={$paidAfter}";
if (abs($cashboxBalance - 100250.0) > 0.01) $failures[] = "Scenario B: cashbox={$cashboxBalance}";

// ─── Scenario C: Concurrent pay-inventory-debt ──────────────────────────────
echo "\n═══ Scenario C: 10 parallel pay-inventory-debt of 50 on 400 ═══\n";
$companyC = BusCompany::query()->create([
    'name' => 'Stress9 Co C', 'is_active' => true, 'created_by' => $admin->id,
]);
$inventoryC = BusInventory::query()->create([
    'company_id' => $companyC->id,
    'route' => 'Stress9 Route C',
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
$okC = count(array_filter($results, fn ($r) => $r['status'] === 200 || $r['status'] === 201));
$rejC = count(array_filter($results, fn ($r) => $r['status'] === 422));
$invCAfter = $inventoryC->fresh();
echo "  Successes: {$okC} (want 8)\n";
echo "  Rejects: {$rejC} (want 2)\n";
echo "  remaining_debt: {$invCAfter->remaining_debt} (want 0)\n";
echo "  amount_paid: {$invCAfter->amount_paid} (want 400)\n";
if ($okC !== 8) $failures[] = "Scenario C: ok={$okC} (want 8)";
if ($rejC !== 2) $failures[] = "Scenario C: rejects={$rejC} (want 2)";
if ((float) $invCAfter->remaining_debt !== 0.0) $failures[] = "Scenario C: rd={$invCAfter->remaining_debt}";
if ((float) $invCAfter->amount_paid !== 400.0) $failures[] = "Scenario C: ap={$invCAfter->amount_paid}";

// ─── Scenario D: Concurrent process-refund ──────────────────────────────────
echo "\n═══ Scenario D: 5 parallel process-refund on same request ═══\n";
$companyD = BusCompany::query()->create([
    'name' => 'Stress9 Co D', 'is_active' => true, 'created_by' => $admin->id,
]);
$inventoryD = BusInventory::query()->create([
    'company_id' => $companyD->id,
    'route' => 'Stress9 Route D',
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
$bookingD = app(BusBookingService::class)->createBooking([
    'inventory_id' => $inventoryD->id,
    'customer_name' => 'Stress9 Cust D',
    'customer_phone' => '0100STRD900',
    'quantity' => 1,
]);
$bookingD->refresh();

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
$refundId = $refundData['data']['id'] ?? null;
if (! $refundId) {
    echo "✗ Refund create failed: {$refundCreateResp}\n";
    proc_terminate($serverProc);
    exit(1);
}

$treasuryBefore = (float) $treasury->fresh()->current_balance;
$results = parallelCurl('POST', "{$baseUrl}/api/v1/bus/refunds/{$refundId}/process", [], $token, 5);
$okD = count(array_filter($results, fn ($r) => $r['status'] === 200));
$treasuryAfter = (float) $treasury->fresh()->current_balance;
$delta = $treasuryAfter - $treasuryBefore;
$refundReq = BusRefundRequest::find($refundId);
echo "  Successes: {$okD} (idempotent — all may be 200 but only first mutates)\n";
echo "  Treasury delta: {$delta} (want exactly 100 — credited ONCE)\n";
echo "  Refund status: {$refundReq->status} (want processed)\n";
if (abs($delta - 100.0) > 0.01) $failures[] = "Scenario D: delta={$delta} (want 100)";
if ($refundReq->status !== 'processed') $failures[] = "Scenario D: status={$refundReq->status}";

// ─── Final report ───────────────────────────────────────────────────────────
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

// Teardown: stop server + delete stress artifacts (keep user/accounts for next run)
proc_terminate($serverProc);
proc_close($serverProc);
echo "\nServer stopped.\n";

echo "\nCleaning stress9 bus rows...\n";
DB::table('bus_refund_requests')->where('id', $refundId ?? 0)->delete();
DB::table('bus_bookings')->whereIn('id', array_filter([$bookingB->id ?? null, $bookingD->id ?? null]))->delete();
DB::table('bus_bookings')->where('customer_phone', '0100STRA900')->delete();
DB::table('bus_inventories')->whereIn('id', array_filter([$inventoryA->id ?? null, $inventoryB->id ?? null, $inventoryC->id ?? null, $inventoryD->id ?? null]))->delete();
DB::table('bus_companies')->whereIn('id', array_filter([$companyA->id ?? null, $companyB->id ?? null, $companyC->id ?? null, $companyD->id ?? null]))->delete();
echo "✓ stress9 rows cleaned up\n";

exit($exit);

/**
 * Fire N parallel curl requests via curl_multi.
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
