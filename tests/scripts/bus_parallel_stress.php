<?php

/**
 * Phase 9 — Bus Module concurrent stress test.
 *
 * Real parallel HTTP load against the live Bus API. PHPUnit on in-memory
 * SQLite is single-threaded by design; this script verifies the Phase 9
 * race-condition fixes (lockForUpdate on inventory / cashbox / treasury)
 * under genuine concurrent requests.
 *
 * Usage:
 *   php artisan serve --host=127.0.0.1 --port=8765 &
 *   php tests/Scripts/bus_parallel_stress.php
 *
 * What it tests:
 *   Scenario A — Concurrent booking on capacity=5 inventory (10 parallel)
 *      Expect: exactly 5 succeed (201), 5 fail (422). Inventory available_tickets = 0.
 *
 *   Scenario B — Concurrent pay of the SAME booking (20 parallel, 50 EGP each)
 *      Expect: total = 250 → exactly 5 succeed (200), 15 fail (422). Booking paid_amount = 250.
 *
 *   Scenario C — Concurrent pay-inventory-debt (10 parallel, 50 EGP each on 400 EGP debt)
 *      Expect: exactly 8 succeed (200), 2 fail (422). inventory.remaining_debt = 0.
 *
 *   Scenario D — Concurrent process-refund (5 parallel on same refund request)
 *      Expect: exactly 1 succeeds with full reversal, 4 are no-ops (idempotent).
 *               Treasury credited ONCE only.
 *
 * Setup requirements:
 *   - DB seeded with: 1 admin user (id=1, password='password'),
 *                     1 office cashbox, 1 USD wallet, 1 bus supplier account.
 *
 * Exit codes:
 *   0 = all invariants held
 *   1 = invariant violated (bug detected)
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

// ─── Configuration ───────────────────────────────────────────────────────────
$baseUrl = $argv[1] ?? 'http://127.0.0.1:8765';
$parallelism = (int) ($argv[2] ?? 10);

$failures = [];
$successes = [];

/**
 * Run N HTTP requests in parallel using curl_multi.
 *
 * @return array<int, array{status: int, body: array}> Indexed by request order.
 */
function parallelRequest(string $method, string $url, array $body, string $token, int $count): array
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

    // Execute all requests in parallel
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

// ─── Setup ───────────────────────────────────────────────────────────────────
echo "═══ Phase 9 Concurrent Stress Test ═══\n";
echo "Base URL: {$baseUrl}\n";
echo "Parallelism: {$parallelism}\n\n";

// Bootstrap test fixtures
DB::beginTransaction();
try {
    // Admin user (id 1 may already exist; ensure role=admin)
    $admin = User::query()->where('email', 'bus-stress@example.com')->first();
    if (! $admin) {
        $admin = User::query()->create([
            'name' => 'Bus Stress',
            'email' => 'bus-stress@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    // Cashbox (EGP)
    $cashbox = Account::query()->where('name', 'Stress Cashbox')->first();
    if (! $cashbox) {
        LedgerBalanceMutationGuard::run(function () {
            Account::query()->create([
                'name' => 'Stress Cashbox',
                'type' => \App\Enums\AccountType::Cashbox,
                'currency' => 'EGP',
                'balance' => 100000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => true,
                'created_by' => 1,
            ]);
        });
        $cashbox = Account::query()->where('name', 'Stress Cashbox')->first();
    }

    // Treasury (for refund test)
    $treasury = Treasury::query()->where('name', 'Stress Treasury')->first();
    if (! $treasury) {
        $treasury = Treasury::query()->create([
            'name' => 'Stress Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    echo "✓ Test fixtures ready\n";
    echo "  admin id={$admin->id} cashbox id={$cashbox->id} treasury id={$treasury->id}\n\n";

    // Get auth token via API
    $tokenResp = Http::post($baseUrl.'/api/v1/auth/login', [
        'email' => 'bus-stress@example.com',
        'password' => 'password',
    ]);
    if (! $tokenResp->successful()) {
        // Fallback: try the Sanctum token issue route if /auth/login doesn't exist.
        echo "✗ Login endpoint not available — aborting.\n";
        DB::rollBack();
        exit(1);
    }
    $token = $tokenResp->json('data.token') ?? $tokenResp->json('token') ?? '';
    if (! $token) {
        echo "✗ No token returned from login — aborting.\n";
        DB::rollBack();
        exit(1);
    }
    echo "✓ Auth token acquired\n\n";
} catch (\Throwable $e) {
    DB::rollBack();
    echo "✗ Setup failed: ".$e->getMessage()."\n";
    exit(1);
}
DB::commit();

// ─── Scenario A: Concurrent booking on capacity=5 ────────────────────────────
echo "\n═══ Scenario A: Concurrent booking on capacity=5 ═══\n";
$companyA = BusCompany::query()->create(['name' => 'Stress Co A', 'is_active' => true]);
$inventoryA = BusInventory::query()->create([
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

$results = parallelRequest(
    'POST',
    $baseUrl.'/api/v1/bus/bookings',
    [
        'inventory_id' => $inventoryA->id,
        'customer_name' => 'Stress Cust A',
        'customer_phone' => '0100STRA000',
        'quantity' => 1,
    ],
    $token,
    10
);

$okA = count(array_filter($results, fn ($r) => $r['status'] === 201));
$rejectA = count(array_filter($results, fn ($r) => $r['status'] === 422));
echo "  Successes (201): {$okA}\n";
echo "  Rejects (422): {$rejectA}\n";
echo "  Final available_tickets: ".$inventoryA->fresh()->available_tickets."\n";

if ($okA !== 5) {
    $failures[] = "Scenario A: expected 5 successes, got {$okA}";
}
if ($rejectA !== 5) {
    $failures[] = "Scenario A: expected 5 rejects, got {$rejectA}";
}
if ($inventoryA->fresh()->available_tickets !== 0) {
    $failures[] = "Scenario A: expected available_tickets=0, got ".$inventoryA->fresh()->available_tickets;
}
$successes[] = "Scenario A: ".(empty(array_filter($failures, fn ($f) => str_starts_with($f, 'Scenario A'))) ? 'PASS' : 'FAIL');

// ─── Scenario B: Concurrent pay of the SAME booking ──────────────────────────
echo "\n═══ Scenario B: Concurrent pay (20 parallel of 50 EGP on total=250) ═══\n";
$companyB = BusCompany::query()->create(['name' => 'Stress Co B', 'is_active' => true]);
$inventoryB = BusInventory::query()->create([
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

$service = app(BusBookingService::class);
$bookingB = $service->createBooking([
    'inventory_id' => $inventoryB->id,
    'customer_name' => 'Stress Cust B',
    'customer_phone' => '0100STRB000',
    'quantity' => 1,
]);
$bookingB->refresh();

$results = parallelRequest(
    'POST',
    $baseUrl."/api/v1/bus/bookings/{$bookingB->id}/pay",
    [
        'amount' => 50.0,
        'payment_method' => 'cash',
        'account_id' => $cashbox->id,
    ],
    $token,
    20
);

$okB = count(array_filter($results, fn ($r) => $r['status'] === 200));
$rejectB = count(array_filter($results, fn ($r) => $r['status'] === 422));
echo "  Successes (200): {$okB}\n";
echo "  Rejects (422): {$rejectB}\n";
echo "  Final paid_amount: ".$bookingB->fresh()->paid_amount."\n";

if ($okB !== 5) {
    $failures[] = "Scenario B: expected 5 successes, got {$okB}";
}
if ($rejectB !== 15) {
    $failures[] = "Scenario B: expected 15 rejects, got {$rejectB}";
}
if ((float) $bookingB->fresh()->paid_amount !== 250.0) {
    $failures[] = "Scenario B: expected paid_amount=250, got ".$bookingB->fresh()->paid_amount;
}

// Cashbox integrity: 100000 (start) + 250 (5 pays × 50) = 100250
$expectedCashboxBalance = 100000.0 + 250.0;
$actualCashbox = (float) $cashbox->fresh()->balance;
echo "  Cashbox balance: {$actualCashbox} (expected {$expectedCashboxBalance})\n";
if (abs($actualCashbox - $expectedCashboxBalance) > 0.01) {
    $failures[] = "Scenario B: cashbox drift — expected {$expectedCashboxBalance}, got {$actualCashbox}";
}
$successes[] = "Scenario B: ".(empty(array_filter($failures, fn ($f) => str_starts_with($f, 'Scenario B'))) ? 'PASS' : 'FAIL');

// ─── Scenario C: Concurrent pay-inventory-debt ───────────────────────────────
echo "\n═══ Scenario C: Concurrent pay-inventory-debt (10 parallel of 50 on 400) ═══\n";
$companyC = BusCompany::query()->create(['name' => 'Stress Co C', 'is_active' => true]);
$inventoryC = BusInventory::query()->create([
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

$results = parallelRequest(
    'POST',
    $baseUrl."/api/v1/bus/inventories/{$inventoryC->id}/pay-debt",
    [
        'amount' => 50.0,
        'account_id' => $cashbox->id,
    ],
    $token,
    10
);

$okC = count(array_filter($results, fn ($r) => $r['status'] === 200 || $r['status'] === 201));
$rejectC = count(array_filter($results, fn ($r) => $r['status'] === 422));
echo "  Successes (200/201): {$okC}\n";
echo "  Rejects (422): {$rejectC}\n";
echo "  Final remaining_debt: ".$inventoryC->fresh()->remaining_debt."\n";
echo "  Final amount_paid: ".$inventoryC->fresh()->amount_paid."\n";

if ($okC !== 8) {
    $failures[] = "Scenario C: expected 8 successes, got {$okC}";
}
if ($rejectC !== 2) {
    $failures[] = "Scenario C: expected 2 rejects, got {$rejectC}";
}
if ((float) $inventoryC->fresh()->remaining_debt !== 0.0) {
    $failures[] = "Scenario C: expected remaining_debt=0, got ".$inventoryC->fresh()->remaining_debt;
}
if ((float) $inventoryC->fresh()->amount_paid !== 400.0) {
    $failures[] = "Scenario C: expected amount_paid=400, got ".$inventoryC->fresh()->amount_paid;
}
$successes[] = "Scenario C: ".(empty(array_filter($failures, fn ($f) => str_starts_with($f, 'Scenario C'))) ? 'PASS' : 'FAIL');

// ─── Scenario D: Concurrent process-refund (idempotency) ──────────────────────
echo "\n═══ Scenario D: Concurrent process-refund (5 parallel on same refund) ═══\n";
$companyD = BusCompany::query()->create(['name' => 'Stress Co D', 'is_active' => true]);
$inventoryD = BusInventory::query()->create([
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

$bookingD = $service->createBooking([
    'inventory_id' => $inventoryD->id,
    'customer_name' => 'Stress Cust D',
    'customer_phone' => '0100STRD000',
    'quantity' => 1,
]);
$bookingD->refresh();

$refundResp = Http::withToken($token)->post($baseUrl.'/api/v1/bus/refunds', [
    'bus_booking_id' => $bookingD->id,
    'cancellation_fee' => 0,
    'refund_currency' => 'EGP',
    'refund_exchange_rate' => 1.0,
    'destination' => 'agency_treasury',
    'treasury_id' => $treasury->id,
]);
$refundId = $refundResp->json('data.id');

$treasuryBefore = (float) $treasury->fresh()->current_balance;

$results = parallelRequest(
    'POST',
    $baseUrl."/api/v1/bus/refunds/{$refundId}/process",
    [],
    $token,
    5
);

$okD = count(array_filter($results, fn ($r) => $r['status'] === 200));
echo "  Successes (200): {$okD} (idempotent — all 5 may report 200 but only first mutates)\n";

$treasuryAfter = (float) $treasury->fresh()->current_balance;
echo "  Treasury before: {$treasuryBefore}, after: {$treasuryAfter}, delta: ".($treasuryAfter - $treasuryBefore)."\n";
echo "  Expected delta: 100 (one full refund credited)\n";

if (abs(($treasuryAfter - $treasuryBefore) - 100.0) > 0.01) {
    $failures[] = "Scenario D: treasury should be credited exactly 100 EGP ONCE (delta=".($treasuryAfter - $treasuryBefore).")";
}
$refundReq = BusRefundRequest::find($refundId);
if ($refundReq->status !== 'processed') {
    $failures[] = "Scenario D: refund status should be 'processed', got '{$refundReq->status}'";
}
$successes[] = "Scenario D: ".(empty(array_filter($failures, fn ($f) => str_starts_with($f, 'Scenario D'))) ? 'PASS' : 'FAIL');

// ─── Final report ─────────────────────────────────────────────────────────────
echo "\n═══ FINAL REPORT ═══\n";
foreach ($successes as $s) {
    echo "  {$s}\n";
}

if (empty($failures)) {
    echo "\n✓ ALL CONCURRENT INVARIANTS HELD — Phase 9 race-condition fixes confirmed.\n";
    exit(0);
} else {
    echo "\n✗ FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
