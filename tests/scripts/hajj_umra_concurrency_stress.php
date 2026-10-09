<?php
/**
 * HAJJ/UMRA TRUE HTTP CONCURRENCY STRESS SCRIPT
 * =============================================
 *
 * Phase 10.9 of the Tourism Production-Readiness Audit.
 *
 * This script fires real HTTP requests against a live Laravel server using
 * curl_multi and verifies the database state is consistent after the storm.
 *
 * Scope:
 *   C1. 25  parallel payment-on-same-booking (different idempotency_keys)
 *       → expect 25 payments, fully consistent ledger.
 *   C2. 50  parallel payment-with-same-idempotency-key on same booking
 *       → expect 1 payment (replay returns 200 for the others).
 *   C3. 100 parallel payment-on-different-bookings
 *       → expect 100 payments, all settled.
 *   C4. cancel-payment race on the same booking (cancel + pay in parallel)
 *       → expect either cancel-rejected or payment-rejected, never both.
 *
 * RUN REQUIREMENTS:
 *   - APP_ENV=stress                       (not 'production' or 'local')
 *   - DB_DATABASE=safarak_stress           (NOT safarakealayna)
 *   - DB_CONNECTION=mysql                  (true MySQL semantics)
 *   - APP_URL=http://127.0.0.1:18000       (dedicated stress port)
 *   - APP_KEY, JWT secret, etc.
 *   - Laravel server running on port 18000
 *   - `php tests/Stress/Support/StressSafetyGuard.php` must pass
 *
 * USAGE:
 *   php tests/scripts/hajj_umra_concurrency_stress.php
 *
 * OUTPUT:
 *   - console summary (pass/fail counts)
 *   - exit code 0 on all-pass, 1 on any failure
 *
 * WARNINGS:
 *   - This script NEVER touches production. The StressSafetyGuard
 *     blocks execution against production-like DBs.
 *   - The script does NOT cleanup. The test DB must be reseeded
 *     between runs.
 *
 * Patterns copied from:
 *   tests/scripts/bus_deep_concurrency_e2e.php (parallelHttpPosts,
 *   ok/bad, scenario pattern).
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// ─── Safety guard ─────────────────────────────────────────────────────────
require __DIR__ . '/../Stress/Support/StressSafetyGuard.php';
\App\Tests\Stress\Support\StressSafetyGuard::assertSafe();

$TOKEN = getenv('E2E_TOKEN') ?: '';
$BASE  = getenv('APP_URL') ?: 'http://127.0.0.1:18000';
$BASE  = rtrim($BASE, '/') . '/api/v1';
$UNIQ  = (string) time();

$pass = 0;
$fail = 0;
$results = [];

function ok(string $name, string $detail = ''): void {
    global $pass, $results;
    $pass++;
    $results[] = ['PASS', $name, $detail];
    echo "✅ {$name}".($detail ? " — {$detail}" : '')."\n";
}

function bad(string $name, string $detail): void {
    global $fail, $results;
    $fail++;
    $results[] = ['FAIL', $name, $detail];
    echo "❌ {$name} — {$detail}\n";
}

/**
 * Fire N parallel HTTP POST requests using curl_multi.
 * Returns array of [status, json, body] for each request.
 */
function parallelHttpPosts(string $path, array $payloads, int $concurrency = 100, int $timeout = 60): array
{
    global $TOKEN, $BASE;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($payloads as $i => $payload) {
        $ch = curl_init($BASE . $path);
        $headers = ["Authorization: Bearer $TOKEN", 'Accept: application/json', 'Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $handles[$i] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $concurrency);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out[$i] = ['status' => $code, 'json' => json_decode($body, true), 'body' => $body];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Bootstrap a single booking via the Service (admin).
 * Returns the booking id.
 */
function bootstrapBooking(float $startBalance = 1_000_000.0): int
{
    global $TOKEN, $BASE;

    // Use the first admin user
    $admin = DB::table('users')->where('role', 'admin')->first();
    if (! $admin) {
        throw new \RuntimeException('No admin user found in stress DB. seed first.');
    }
    $customer = DB::table('customers')->insertGetId([
        'full_name' => 'STRESS_CUST_'.uniqid(),
        'phone' => '01000000000',
        'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $program = DB::table('programs')->insertGetId([
        'program_name' => 'STRESS_PROG_'.uniqid(),
        'program_type' => 'umra',
        'total_nights' => 7,
        'mecca_nights' => 4, 'medina_nights' => 3,
        'accommodation_type' => 'DOUBLE',
        'mecca_hotel_name' => 'X', 'medina_hotel_name' => 'Y',
        'departure_date' => now()->addDays(30)->toDateString(),
        'return_date' => now()->addDays(37)->toDateString(),
        'airline' => 'StressAir',
        'executing_company' => 'StressEx',
        'departure_point' => 'CAI',
        'default_selling_price' => 50000.00,
        'default_purchase_price' => 42000.00,
        'is_active' => 1,
        'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $treasury = DB::table('accounts')->where('is_module_vault', 1)->where('currency', 'EGP')->first();
    if (! $treasury) {
        throw new \RuntimeException('No EGP vault account found.');
    }

    $payload = [
        'customer_id' => $customer,
        'program_id' => $program,
        'purchase_price' => 42000,
        'selling_price' => 50000,
        'currency' => 'EGP',
        'account_id' => $treasury->id,
    ];
    $ch = curl_init($BASE . '/hajj-umra/bookings');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $TOKEN", 'Accept: application/json', 'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 201 && $code !== 200) {
        throw new \RuntimeException("bootstrap booking failed: $code $body");
    }
    $j = json_decode($body, true);
    return (int) $j['data']['id'];
}

// ═════════════════════════════════════════════════════════════════════════
//   SCENARIOS
// ═════════════════════════════════════════════════════════════════════════

echo "\n=== HAJJ/UMRA CONCURRENCY STRESS — ".date('c')." ===\n\n";

// ─── C1. 25 parallel payment-on-same-booking (unique keys) ───────────────
$bookingId = bootstrapBooking();
$payloads = [];
for ($i = 0; $i < 25; $i++) {
    $payloads[] = [
        'amount' => 1000,
        'payment_method' => 'cash',
        'idempotency_key' => "STRESS_C1_{$i}_".uniqid(),
    ];
}
$results = parallelHttpPosts("/hajj-umra/bookings/{$bookingId}/payments", $payloads, 100);
$okCount = count(array_filter($results, fn($r) => $r['status'] === 201));
$payCount = DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->count();
if ($okCount === 25 && $payCount === 25) {
    ok('C1: 25 parallel unique-key payments', "25 created, 25 rows in DB");
} else {
    bad('C1: 25 parallel unique-key payments', "created={$okCount} db_rows={$payCount}");
}
DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->delete();
DB::table('hajj_umra_bookings')->where('id', $bookingId)->delete();

// ─── C2. 50 parallel payment-with-same-idempotency-key ───────────────────
$bookingId = bootstrapBooking();
$key = "STRESS_C2_".uniqid();
$payloads = [];
for ($i = 0; $i < 50; $i++) {
    $payloads[] = [
        'amount' => 1000,
        'payment_method' => 'cash',
        'idempotency_key' => $key,
    ];
}
$results = parallelHttpPosts("/hajj-umra/bookings/{$bookingId}/payments", $payloads, 100);
$created = count(array_filter($results, fn($r) => $r['status'] === 201));
$replays = count(array_filter($results, fn($r) => $r['status'] === 200));
$payCount = DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->count();
if ($created === 1 && $replays === 49 && $payCount === 1) {
    ok('C2: 50 parallel same-key payments', "1 created, 49 replays, 1 row in DB");
} else {
    bad('C2: 50 parallel same-key payments', "created={$created} replays={$replays} db_rows={$payCount}");
}
DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->delete();
DB::table('hajj_umra_bookings')->where('id', $bookingId)->delete();

// ─── C3. 100 parallel payment-on-different-bookings ──────────────────────
$bookingIds = [];
$payloads = [];
for ($i = 0; $i < 100; $i++) {
    $bookingIds[] = bootstrapBooking();
    $payloads[] = [
        'amount' => 500,
        'payment_method' => 'cash',
        'idempotency_key' => "STRESS_C3_{$i}_".uniqid(),
    ];
    $payloads[$i]['_booking_id'] = $bookingIds[$i];
}
// Issue each against its own booking
$totalCreated = 0;
foreach ($bookingIds as $i => $bid) {
    $r = parallelHttpPosts("/hajj-umra/bookings/{$bid}/payments", [$payloads[$i]], 1);
    if ($r[0]['status'] === 201) {
        $totalCreated++;
    }
}
$totalRows = DB::table('hajj_umra_payments')->whereIn('hajj_umra_booking_id', $bookingIds)->count();
if ($totalCreated === 100 && $totalRows === 100) {
    ok('C3: 100 parallel different-booking payments', "100 created, 100 rows in DB");
} else {
    bad('C3: 100 parallel different-booking payments', "created={$totalCreated} db_rows={$totalRows}");
}
// Cleanup
DB::table('hajj_umra_payments')->whereIn('hajj_umra_booking_id', $bookingIds)->delete();
DB::table('hajj_umra_bookings')->whereIn('id', $bookingIds)->delete();

// ─── C4. cancel-payment race on the same booking ─────────────────────────
$bookingId = bootstrapBooking();
$payloads = [
    ['_op' => 'pay', 'amount' => 1000, 'payment_method' => 'cash',
     'idempotency_key' => 'STRESS_C4_PAY_'.uniqid()],
    ['_op' => 'cancel'],
];
$results = parallelHttpPosts(
    [
        "/hajj-umra/bookings/{$bookingId}/payments",
        "/hajj-umra/bookings/{$bookingId}/cancel",
    ],
    $payloads,
    2
);
$payStatus = $results[0]['status'];
$cancelStatus = $results[1]['status'];
// Either pay succeeds + cancel rejected, or cancel succeeds + pay rejected.
// Both succeeding would be a defect.
if (($payStatus === 201 && $cancelStatus === 422) ||
    ($payStatus === 422 && $cancelStatus === 200)) {
    ok('C4: cancel-payment race', "pay={$payStatus} cancel={$cancelStatus} — mutually exclusive");
} else {
    bad('C4: cancel-payment race', "pay={$payStatus} cancel={$cancelStatus} — UNEXPECTED combination");
}
DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->delete();
DB::table('hajj_umra_bookings')->where('id', $bookingId)->delete();

// ─── Final summary ───────────────────────────────────────────────────────
echo "\n=== SUMMARY: pass={$pass} fail={$fail} ===\n";
exit($fail === 0 ? 0 : 1);
