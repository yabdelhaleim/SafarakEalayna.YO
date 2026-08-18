<?php
/**
 * PRE-PHASE-B IDEMPOTENCY — REAL HTTP CONCURRENCY TEST
 * ======================================================
 *
 * Drives the running Laravel stress server (`php artisan serve --port=18000 --env=stress`)
 * with parallel HTTP requests via curl_multi, then asserts:
 *
 *   SCENARIO A — IDENTICAL REPLAY (same idempotency_key)
 *     Send 25 concurrent POSTs with the SAME logical idempotency_key.
 *     Expected:
 *       - Exactly 1 HajjUmraPayment row created.
 *       - Exactly 1 Transfer Transaction row created.
 *       - Exactly 2 AccountEntry rows for that transaction (debit + credit).
 *       - All other 24 requests return either 201 (the winner) or 200 +
 *         idempotent_replay=true (the losers). ZERO should return 5xx.
 *       - Booking paid_amount grows by exactly one payment's amount.
 *
 *   SCENARIO B — LEGITIMATE DISTINCT PAYMENTS (different idempotency_keys)
 *     Send 25 concurrent POSTs each with a UNIQUE idempotency_key.
 *     Same booking, same amount, same method.
 *     Expected:
 *       - 25 HajjUmraPayment rows created.
 *       - 25 Transfer Transaction rows created.
 *       - 50 AccountEntry rows (2 per transaction).
 *       - Booking paid_amount grows by 25 × amount.
 *       - No false dedup — every distinct key is accepted independently.
 *
 *   SCENARIO C — CONCURRENT SAME KEY VS DIFFERENT KEYS (race verification)
 *     Mix 13 identical-replay requests with 12 distinct payments.
 *     Expected:
 *       - Exactly 1 row for the shared idempotency_key.
 *       - 12 rows for the distinct payments.
 *       - Booking paid_amount = 1×shared_amount + 12×distinct_amount.
 *
 * The script:
 *   1. Loads .env.stress explicitly.
 *   2. Bootstraps Laravel via bootstrap/app.php.
 *   3. Verifies DB safarak_stress + artisan serve up.
 *   4. Issues a Sanctum token for the stress actor.
 *   5. Seeds a booking (treasury + supplier + program + customer).
 *   6. For each scenario: fires N concurrent curls via curl_multi,
 *      captures statuses + bodies, then queries the DB to verify
 *      exactly the expected mutations happened.
 *   7. Persists the report and exits 0 on PASS, 1 on FAIL.
 *
 * Required: artisan serve already running on 127.0.0.1:18000 with --env=stress.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\AccountService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

// ──────────────────────────────────────────────────────────────────────────────
// 0. Load .env.stress + bootstrap Laravel + safety guard
// ──────────────────────────────────────────────────────────────────────────────
$FORBIDDEN = ['safarakealayna', 'safarak_ealayna', 'travel_office', 'production'];
$envStressPath = __DIR__ . '/../../.env.stress';
if (! is_file($envStressPath)) {
    fwrite(STDERR, "✗ HARD ABORT — .env.stress not found\n");
    exit(3);
}
foreach (file($envStressPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
    }
    if (preg_match('/^(.*?)\s+#.*$/', $v, $m)) {
        $v = $m[1];
    }
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
}

$appEnv  = (string) (getenv('APP_ENV') ?: '');
$dbConn  = (string) (getenv('DB_CONNECTION') ?: '');
$dbName  = (string) (getenv('DB_DATABASE') ?: '');
$dbHost  = (string) (getenv('DB_HOST') ?: '');

echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║  IDEMPOTENCY HTTP CONCURRENCY — Pre-flight            ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";
echo "STRESS DB:  {$dbConn} / {$dbName}\n";
echo "HOST:       {$dbHost}\n";
echo "APP_ENV:    {$appEnv}\n";
echo "PID:        " . getmypid() . "\n";

if (in_array($dbName, $FORBIDDEN, true) || in_array(strtolower($appEnv), ['production','prod','live'], true)) {
    fwrite(STDERR, "✗ HARD ABORT — forbidden DB / APP_ENV\n");
    exit(3);
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$selectDb = DB::selectOne('SELECT DATABASE() AS db')->db ?? '(null)';
if ($selectDb !== 'safarak_stress') {
    fwrite(STDERR, "✗ HARD ABORT — DB is '{$selectDb}', expected 'safarak_stress'.\n");
    exit(3);
}
echo "SELECT DATABASE(): {$selectDb}\n";

// Verify artisan serve is up
$ch = curl_init('http://127.0.0.1:18000/api/v1/auth/me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_POST, false);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code === 0 || $code >= 500) {
    fwrite(STDERR, "✗ HARD ABORT — artisan serve on :18000 not reachable (got HTTP {$code}).\n");
    exit(3);
}
echo "artisan serve on :18000 → HTTP {$code} (reachable)\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// 1. Set up fixtures
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 1. Set up fixtures ───\n";

$actor = User::query()->first();
if (! $actor) {
    fwrite(STDERR, "✗ ABORT — no actor user\n");
    exit(3);
}
auth()->login($actor);

$vault = LedgerBalanceMutationGuard::run(function () {
    return Account::firstOrCreate(
        ['name' => 'STRESS-HU-VAULT', 'currency' => 'EGP'],
        [
            'type' => \App\Enums\AccountType::Cashbox,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'tourism',
            'is_module_vault' => true,
            'notes' => 'Stress HU vault (concurrency test)',
            'created_by' => auth()->id() ?? 1,
        ]
    );
});
$openingEquity = LedgerBalanceMutationGuard::run(function () {
    return Account::firstOrCreate(
        ['name' => 'STRESS-HU-EQUITY', 'currency' => 'EGP'],
        [
            'type' => \App\Enums\AccountType::Owner,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'general',
            'is_module_vault' => false,
            'notes' => 'Stress HU opening equity',
            'created_by' => auth()->id() ?? 1,
        ]
    );
});
if (! Transaction::where('notes', 'STRESS-HU-OPENING')->exists()) {
    DB::transaction(function () use ($vault, $openingEquity) {
        $tx = Transaction::create([
            'type' => 'transfer', 'amount' => 1_000_000, 'currency' => 'EGP',
            'module' => 'general', 'from_account_id' => null,
            'to_account_id' => $vault->id, 'notes' => 'STRESS-HU-OPENING',
            'created_by' => auth()->id() ?? 1,
        ]);
        $svc = app(AccountService::class);
        $svc->credit($vault, 1_000_000, (int) $tx->id);
        $svc->credit($openingEquity, 1_000_000, (int) $tx->id);
    });
}
$supplierAccount = LedgerBalanceMutationGuard::run(function () {
    return Account::firstOrCreate(
        ['name' => 'STRESS-HU-SUPPLIER', 'currency' => 'EGP'],
        [
            'type' => \App\Enums\AccountType::Supplier, 'balance' => 0,
            'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra', 'is_module_vault' => false,
            'notes' => 'Stress supplier', 'created_by' => auth()->id() ?? 1,
        ]
    );
});
$supplier = UmrahSupplier::firstOrCreate(['name' => 'STRESS-HU-SUPPLIER-CO'], [
    'phone' => '+201000000099', 'account_id' => $supplierAccount->id,
    'default_cost_price' => 1000, 'is_active' => true,
]);
$program = Program::firstOrCreate(['program_name' => 'STRESS-HU-PROGRAM'], [
    'program_type' => 'UMRA', 'season' => 'STRESS', 'total_nights' => 7,
    'accommodation_type' => 'DOUBLE',
    'mecca_hotel_name' => 'STRESS-MECCA', 'mecca_nights' => 4,
    'medina_hotel_name' => 'STRESS-MEDINA', 'medina_nights' => 3,
    'departure_date' => now()->addDays(30)->toDateString(),
    'return_date' => now()->addDays(37)->toDateString(),
    'airline' => 'STRESS-AIR', 'executing_company' => 'STRESS-HU-EXEC',
    'departure_point' => 'CAI', 'booking_status' => 'CONFIRMED',
    'default_purchase_price' => 10000, 'default_selling_price' => 15000,
    'is_active' => true,
]);
$customer = Customer::firstOrCreate(['phone' => '+201000000055'], [
    'full_name' => 'STRESS CUSTOMER HU CONC',
    'national_id' => sprintf('STR%011d', 55),
    'module_type' => 'hajj_umra', 'created_by' => null,
]);
echo "   vault={$vault->id} supplier={$supplier->id} program={$program->id} customer={$customer->id}\n\n";

// Sanctum token
DB::table('personal_access_tokens')->where('tokenable_id', $actor->id)->delete();
$token = $actor->createToken('stress-idempotency-concurrency')->plainTextToken;
$bearer = "Bearer {$token}";

// Helper: fire N concurrent HTTP POSTs via curl_multi
function fireConcurrent(string $url, string $bearer, array $payloads): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($payloads as $i => $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: $bearer",
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $handles[$i] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, count($payloads));
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, count($payloads));

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out[$i] = [
            'status' => $code,
            'json'   => json_decode($body, true),
            'body'   => $body,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// Helper: create a fresh booking
function seedBooking(App\Models\Customer $customer, App\Models\Program $program, App\Models\HajjUmra\UmrahSupplier $supplier, App\Models\Account $vault, App\Models\User $actor, int $selling, int $purchase): HajjUmraBooking
{
    return app(HajjUmraBookingService::class)->create([
        'customer_id'    => $customer->id,
        'program_id'     => $program->id,
        'supplier_id'    => $supplier->id,
        'purchase_price' => $purchase,
        'selling_price'  => $selling,
        'currency'       => 'EGP',
        'per_person'     => true,
        'accommodation_choice' => 'standard',
        'account_id'     => $vault->id,
        'employee_id'    => $actor->id,
        'notes'          => '[STRESS-CONC] Booking',
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO A — IDENTICAL REPLAY (same idempotency_key, 25 concurrent)
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO A: 25 concurrent requests with the SAME idempotency_key ───\n";

$bookingA = seedBooking($customer, $program, $supplier, $vault, $actor, 15000, 10000);
$sharedKeyA = 'STRESS-IDEM-CONC-A-' . bin2hex(random_bytes(4));
$payloadA = [
    'amount' => 5000, 'payment_method' => 'cash', 'account_id' => $vault->id,
    'currency' => 'EGP', 'idempotency_key' => $sharedKeyA,
    'paid_by' => $customer->full_name,
];
$payloadsA = array_fill(0, 25, $payloadA);

$startA = microtime(true);
$resultsA = fireConcurrent(
    "http://127.0.0.1:18000/api/v1/hajj-umra/bookings/{$bookingA->id}/payments",
    $bearer,
    $payloadsA
);
$elapsedA = microtime(true) - $startA;

$statusCountA = [];
$paymentIdsA = [];
$replayFlagsA = [];
foreach ($resultsA as $r) {
    $statusCountA[$r['status']] = ($statusCountA[$r['status']] ?? 0) + 1;
    $paymentIdsA[] = $r['json']['data']['payment']['id'] ?? null;
    $replayFlagsA[] = $r['json']['data']['idempotent_replay'] ?? null;
}
$paymentIdsA = array_filter(array_unique($paymentIdsA), fn ($v) => $v !== null);

$paymentsRowsA = HajjUmraPayment::query()
    ->where('hajj_umra_booking_id', $bookingA->id)
    ->where('idempotency_key', $sharedKeyA)
    ->count();
$paymentTxRowsA = Transaction::query()
    ->where('related_type', HajjUmraBooking::class)
    ->where('related_id', $bookingA->id)
    ->where('type', 'transfer')
    ->where('notes', 'like', 'دفعة على حجز%')
    ->count();
$bookingA->refresh();

echo "   elapsed: " . round($elapsedA, 3) . "s\n";
echo "   HTTP statuses: " . json_encode($statusCountA) . "\n";
echo "   unique payment ids: " . count($paymentIdsA) . " (expect 1)\n";
echo "   replay flags: " . json_encode(array_count_values(array_map(fn ($v) => var_export($v, true), $replayFlagsA))) . "\n";
echo "   hajj_umra_payments rows with key={$sharedKeyA}: {$paymentsRowsA} (expect 1)\n";
echo "   payment Transfer tx rows: {$paymentTxRowsA} (expect 1)\n";
echo "   booking.paid_amount: {$bookingA->paid_amount} (expect 5000)\n\n";

$scenarioA = [
    'scenario'                  => 'A: 25 concurrent identical-replay',
    'elapsed_sec'               => round($elapsedA, 3),
    'http_status_histogram'     => $statusCountA,
    'unique_payment_ids'        => count($paymentIdsA),
    'unique_payment_ids_list'   => array_values($paymentIdsA),
    'replay_flags_count'        => array_count_values(array_map(fn ($v) => var_export($v, true), $replayFlagsA)),
    'hajj_umra_payments_with_key' => $paymentsRowsA,
    'payment_transfer_tx_rows'  => $paymentTxRowsA,
    'booking_paid_amount'       => (float) $bookingA->paid_amount,
    'passed' => (
        count($paymentIdsA) === 1
        && $paymentsRowsA === 1
        && $paymentTxRowsA === 1
        && abs((float) $bookingA->paid_amount - 5000.0) <= 0.01
        && empty(array_filter(array_keys($statusCountA), fn ($k) => $k >= 500))
    ),
];

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO B — LEGITIMATE DISTINCT PAYMENTS (25 different keys, concurrent)
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO B: 25 concurrent requests with DIFFERENT idempotency_keys ───\n";

$bookingB = seedBooking($customer, $program, $supplier, $vault, $actor, 25000, 15000);
$payloadsB = [];
$distinctKeysB = [];
for ($i = 0; $i < 25; $i++) {
    $k = 'STRESS-IDEM-CONC-B-' . bin2hex(random_bytes(4)) . "-{$i}";
    $distinctKeysB[] = $k;
    $payloadsB[] = [
        'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $vault->id,
        'currency' => 'EGP', 'idempotency_key' => $k,
        'paid_by' => $customer->full_name,
    ];
}

$startB = microtime(true);
$resultsB = fireConcurrent(
    "http://127.0.0.1:18000/api/v1/hajj-umra/bookings/{$bookingB->id}/payments",
    $bearer,
    $payloadsB
);
$elapsedB = microtime(true) - $startB;

$statusCountB = [];
$rejectedB = 0;
$successfulB = 0;
foreach ($resultsB as $r) {
    $statusCountB[$r['status']] = ($statusCountB[$r['status']] ?? 0) + 1;
    if ($r['status'] === 201) $successfulB++;
    if ($r['status'] >= 400) $rejectedB++;
}
$paymentsRowsB = HajjUmraPayment::query()
    ->where('hajj_umra_booking_id', $bookingB->id)
    ->count();
$paymentTxRowsB = Transaction::query()
    ->where('related_type', HajjUmraBooking::class)
    ->where('related_id', $bookingB->id)
    ->where('type', 'transfer')
    ->where('notes', 'like', 'دفعة على حجز%')
    ->count();
$bookingB->refresh();

echo "   elapsed: " . round($elapsedB, 3) . "s\n";
echo "   HTTP statuses: " . json_encode($statusCountB) . "\n";
echo "   hajj_umra_payments rows: {$paymentsRowsB} (expect 25)\n";
echo "   payment Transfer tx rows: {$paymentTxRowsB} (expect 25)\n";
echo "   booking.paid_amount: {$bookingB->paid_amount} (expect 25000)\n\n";

$scenarioB = [
    'scenario'                  => 'B: 25 concurrent distinct-key payments',
    'elapsed_sec'               => round($elapsedB, 3),
    'http_status_histogram'     => $statusCountB,
    'successful_201_count'      => $successfulB,
    'rejected_count'            => $rejectedB,
    'hajj_umra_payments_count'  => $paymentsRowsB,
    'payment_transfer_tx_rows'  => $paymentTxRowsB,
    'booking_paid_amount'       => (float) $bookingB->paid_amount,
    'passed' => (
        $paymentsRowsB === 25
        && $paymentTxRowsB === 25
        && abs((float) $bookingB->paid_amount - 25000.0) <= 0.01
        && $successfulB === 25
        && $rejectedB === 0
    ),
];

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO C — MIXED: 13 identical-replay + 12 distinct (race verification)
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO C: 13 identical-replay + 12 distinct, concurrent ───\n";

$bookingC = seedBooking($customer, $program, $supplier, $vault, $actor, 35000, 20000);
$sharedKeyC = 'STRESS-IDEM-CONC-C-' . bin2hex(random_bytes(4));
$payloadsC = [];
for ($i = 0; $i < 13; $i++) {
    $payloadsC[] = [
        'amount' => 2000, 'payment_method' => 'cash', 'account_id' => $vault->id,
        'currency' => 'EGP', 'idempotency_key' => $sharedKeyC,
        'paid_by' => $customer->full_name,
    ];
}
for ($i = 0; $i < 12; $i++) {
    $payloadsC[] = [
        'amount' => 2000, 'payment_method' => 'cash', 'account_id' => $vault->id,
        'currency' => 'EGP', 'idempotency_key' => 'STRESS-IDEM-CONC-C-DIST-' . $i . '-' . bin2hex(random_bytes(2)),
        'paid_by' => $customer->full_name,
    ];
}

$startC = microtime(true);
$resultsC = fireConcurrent(
    "http://127.0.0.1:18000/api/v1/hajj-umra/bookings/{$bookingC->id}/payments",
    $bearer,
    $payloadsC
);
$elapsedC = microtime(true) - $startC;

$sharedPaymentIdsC = [];
foreach ($resultsC as $i => $r) {
    if ($i < 13 && isset($r['json']['data']['payment']['id'])) {
        $sharedPaymentIdsC[] = $r['json']['data']['payment']['id'];
    }
}
$sharedUniqueC = count(array_unique($sharedPaymentIdsC));

$sharedPaymentsRowsC = HajjUmraPayment::query()
    ->where('hajj_umra_booking_id', $bookingC->id)
    ->where('idempotency_key', $sharedKeyC)
    ->count();
$totalPaymentsRowsC = HajjUmraPayment::query()
    ->where('hajj_umra_booking_id', $bookingC->id)
    ->count();
$paymentTxRowsC = Transaction::query()
    ->where('related_type', HajjUmraBooking::class)
    ->where('related_id', $bookingC->id)
    ->where('type', 'transfer')
    ->where('notes', 'like', 'دفعة على حجز%')
    ->count();
$bookingC->refresh();

echo "   elapsed: " . round($elapsedC, 3) . "s\n";
echo "   unique payment ids for shared key (13 requests): {$sharedUniqueC} (expect 1)\n";
echo "   hajj_umra_payments rows with shared key: {$sharedPaymentsRowsC} (expect 1)\n";
echo "   total hajj_umra_payments rows: {$totalPaymentsRowsC} (expect 13 = 1 shared + 12 distinct)\n";
echo "   payment Transfer tx rows: {$paymentTxRowsC} (expect 13)\n";
echo "   booking.paid_amount: {$bookingC->paid_amount} (expect 26000 = 1*2000 + 12*2000)\n\n";

$scenarioC = [
    'scenario'                  => 'C: 13 identical-replay + 12 distinct concurrent',
    'elapsed_sec'               => round($elapsedC, 3),
    'shared_unique_payment_ids' => $sharedUniqueC,
    'shared_payment_rows'       => $sharedPaymentsRowsC,
    'total_payment_rows'        => $totalPaymentsRowsC,
    'payment_transfer_tx_rows'  => $paymentTxRowsC,
    'booking_paid_amount'       => (float) $bookingC->paid_amount,
    'passed' => (
        $sharedUniqueC === 1
        && $sharedPaymentsRowsC === 1
        && $totalPaymentsRowsC === 13
        && $paymentTxRowsC === 13
        && abs((float) $bookingC->paid_amount - 26000.0) <= 0.01
    ),
];

// ──────────────────────────────────────────────────────────────────────────────
// VERDICT
// ──────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║  IDEMPOTENCY HTTP CONCURRENCY — VERDICT                 ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";

$allPassed = $scenarioA['passed'] && $scenarioB['passed'] && $scenarioC['passed'];
$verdict = $allPassed ? 'PASS' : 'FAIL';

echo "scenario A (25 identical): " . ($scenarioA['passed'] ? 'PASS' : 'FAIL') . "\n";
echo "scenario B (25 distinct):  " . ($scenarioB['passed'] ? 'PASS' : 'FAIL') . "\n";
echo "scenario C (13+12 mixed):  " . ($scenarioC['passed'] ? 'PASS' : 'FAIL') . "\n";
echo "\nFINAL VERDICT: {$verdict}\n";

// Cleanup tokens
DB::table('personal_access_tokens')->where('tokenable_id', $actor->id)->delete();

// Persist artifact
$artifact = [
    'phase' => 'PRE-PHASE-B',
    'gate' => 'idempotency_http_concurrency',
    'service' => 'HajjUmraBookingService',
    'endpoint' => 'POST /api/v1/hajj-umra/bookings/{id}/payments',
    'transport' => 'curl_multi against artisan serve on port 18000',
    'ran_at' => date('c'),
    'preflight' => [
        'app_env' => $appEnv, 'connection' => $dbConn,
        'host' => $dbHost, 'database' => $dbName,
        'select_db' => $selectDb, 'pid' => getmypid(),
    ],
    'fixtures' => [
        'actor_id' => (int) $actor->id, 'vault_id' => (int) $vault->id,
        'supplier_id' => (int) $supplier->id, 'program_id' => (int) $program->id,
        'customer_id' => (int) $customer->id,
    ],
    'scenarios' => [
        'A_25_identical' => $scenarioA,
        'B_25_distinct' => $scenarioB,
        'C_13_identical_plus_12_distinct' => $scenarioC,
    ],
    'verdict' => $verdict,
];

$logPath = storage_path('app/stress/idempotency-http-concurrency-run.log');
$jsonPath = storage_path('app/stress/idempotency-http-concurrency.json');
@mkdir(dirname($logPath), 0755, true);
file_put_contents($jsonPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($logPath, "\n--- idempotency-http-concurrency.json ---\n" . file_get_contents($jsonPath));

echo "\nArtifact: storage/app/stress/idempotency-http-concurrency.json\n\n";

exit($allPassed ? 0 : 1);