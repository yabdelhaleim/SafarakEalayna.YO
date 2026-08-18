<?php

declare(strict_types=1);

/**
 * stress_phase_b_http_concurrency.php
 *
 * Phase 25-B — HTTP concurrency at 25 workers (Phase B tier), plus
 * scaled-up stress scenarios (50/100) to push the idempotency
 * protection harder than the pre-Phase-B gate.
 *
 * Scenarios:
 *   A. 25 identical idempotency_key           → expect 1 mutation
 *   B. 25 distinct idempotency_keys           → expect 25 mutations
 *   C. 13 identical + 12 distinct (= 25 total) → expect 13 mutations
 *   D. 50 identical                            → expect 1 mutation  (scale up)
 *   E. 100 distinct                            → expect 100 mutations (scale up)
 *   F. 50 identical + 50 distinct (= 100)      → expect 51 mutations  (scale up)
 *
 * Every booking used by these scenarios is pre-created via the
 * service layer (real Hajj/Umrah bookings created by
 * stress_phase_b_real_workload.php). The script targets DIFFERENT
 * bookings for each scenario so idempotency keys don't cross-pollute.
 *
 * All requests fire via curl_multi against artisan serve on :18000.
 * Performance metrics: P50/P95/P99/max latency, throughput,
 * deadlocks (HTTP 500 with retryable err), retries triggered.
 */

require __DIR__ . '/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressReconciliation;

if (env('APP_ENV') !== 'stress') {
    fwrite(STDERR, "🛑  APP_ENV must be 'stress'. ABORT.\n");
    exit(2);
}
$dbName = config('database.connections.mysql.database');
if ($dbName !== 'safarak_stress') {
    fwrite(STDERR, "🛑  DB_DATABASE must be 'safarak_stress'. ABORT.\n");
    exit(2);
}

$BASE = 'http://127.0.0.1:18000';

// Pre-flight reachability
$ch = curl_init($BASE . '/api/v1/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code === 0) {
    fwrite(STDERR, "🛑  artisan serve on :18000 NOT REACHABLE. ABORT.\n");
    exit(2);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "  Phase B — HTTP Concurrency (25 workers)\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "APP_ENV:           " . env('APP_ENV') . "\n";
echo "DB_DATABASE:       " . $dbName . "\n";
echo "SELECT DATABASE(): " . DB::selectOne('SELECT DATABASE() AS d')->d . "\n";
echo "artisan serve:     :18000 HTTP " . $code . " (reachable)\n";
echo "Disk free (GiB):   " . round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . "\n";
echo "─────────────────────────────────────────────────────────────\n";

// Get stress actor token
$actor = User::firstOrCreate(
    ['email' => 'stress-actor@safarakealayna.test'],
    ['name' => 'STRESS-ACTOR', 'password' => bcrypt('stress-' . bin2hex(random_bytes(8)))]
);
$token = $actor->createToken('stress-phase-b-http-concurrency')->plainTextToken;
echo "actor id={$actor->id}, token issued\n\n";

// Auth the actor for service-layer booking creation
Auth::login($actor);
$service = app(HajjUmraBookingService::class);

// Helper: create ONE fresh booking + return it N times (same booking, N requests).
// Per Phase B spec, the idempotency identity is (booking_id, idempotency_key).
// To test "25 identical requests" we must target the SAME booking 25 times
// (otherwise (booking_id, key) is unique per booking and no dedup is expected).
function makeBookings(HajjUmraBookingService $svc, User $actor, int $n, string $label): array {
    $customerIds = Customer::query()->orderByDesc('id')->limit($n * 2)->pluck('id')->all();
    $vault = Account::getModuleVault('hajj_umra');
    if (!$vault) {
        throw new \RuntimeException('No Hajj/Umrah vault found in stress DB.');
    }
    $vaultId = (int) $vault->id;
    if (! (is_int($vaultId) && $vaultId > 0)) {
        throw new \RuntimeException("Vault id failed local assertion: is_int=" . var_export(is_int($vaultId), true) . " val={$vaultId}");
    }
    $programId = (int) DB::table('programs')->where('program_name', 'STRESS-HU-PROGRAM')->value('id');
    // Create ONE booking with selling_price large enough to absorb N partial payments.
    $capacity = max(10000, $n * 2000);
    $booking = $svc->create([
        'customer_id' => $customerIds[0],
        'program_id' => $programId,
        'account_id' => $vaultId,
        'purchase_price' => $capacity - 2000,
        'selling_price' => $capacity,
        'currency' => 'EGP',
        'per_person' => true,
        'accommodation_extra_charge' => 0,
        'status' => 'confirmed',
        'notes' => "STRESS-PHASE-B-CONC-{$label} (N={$n}, capacity={$capacity})",
    ]);
    $entries = [];
    for ($i = 0; $i < $n; $i++) {
        $entries[] = ['id' => $booking->id, 'account_id' => $vaultId];
    }
    echo "  single booking created: id={$booking->id} capacity={$capacity} (will absorb {$n} payment requests)\n";
    return $entries;
}

// Helper: fire N concurrent HTTP POSTs with curl_multi, return [statuses, latencies, replay_flags]
function fireConcurrent(
    string $base, string $token, array $bookings, array $idempotencyKeys,
    float $amount = 1000.0, string $scenarioLabel = ''
): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, count($bookings));
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, count($bookings));
    $handles = [];
    foreach ($bookings as $i => $b) {
        $bookingId = is_array($b) ? $b['id'] : $b;
        $accountId = is_array($b) ? ($b['account_id'] ?? null) : null;
        // HARNESS ASSERTION (per Phase B spec): is_int && >0
        if (! (is_int($accountId) && $accountId > 0)) {
            fwrite(STDERR, "🛑 HARNESS HARD FAIL before request {$i}: account_id=" . var_export($accountId, true) . " booking=" . var_export($bookingId, true) . "\n");
            exit(2);
        }
        $key = $idempotencyKeys[$i] ?? null;
        $payload = [
            'amount' => $amount,
            'payment_method' => 'cash',
            'payment_date' => date('Y-m-d'),
            'account_id' => $accountId,
            'reference' => 'STRESS-B-CONC-' . substr(md5(uniqid('', true)), 0, 8) . '-' . $i,
            'idempotency_key' => $key,
            'paid_by' => 'STRESS-ACTOR',
            'notes' => "STRESS-PHASE-B-CONC {$scenarioLabel} booking={$bookingId}",
        ];
        // Per harness spec: print the JSON payload for the first 3 requests BEFORE add_handle.
        if ($i < 3) {
            echo "  [PRE-SEND #{$i}] " . json_encode($payload) . "\n";
        }
        $ch = curl_init($base . "/api/v1/hajj-umra/bookings/{$bookingId}/payments");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $handles[$i] = ['ch' => $ch, 't0' => microtime(true)];
        curl_multi_add_handle($mh, $ch);
    }
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $i => $h) {
        $body = curl_multi_getcontent($h['ch']);
        $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
        $elapsed = (microtime(true) - $h['t0']) * 1000.0;
        $j = json_decode($body, true);
        $out[$i] = [
            'status' => $code,
            'json' => $j,
            'latency_ms' => $elapsed,
            'replay' => (bool) ($j['data']['idempotent_replay'] ?? false),
            'payment_id' => $j['data']['payment']['id'] ?? null,
        ];
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);
    return $out;
}

function pct(array $values, float $p): float {
    if (empty($values)) return 0.0;
    sort($values);
    $idx = (int) floor(($p / 100.0) * (count($values) - 1));
    return $values[$idx];
}

$scenarios = [];
$scenarioStart = microtime(true);

foreach ([
    ['label' => 'A', 'N' => 25,  'shared' => true,  'expected_unique_payments' => 1],
    ['label' => 'B', 'N' => 25,  'shared' => false, 'expected_unique_payments' => 25],
    ['label' => 'C', 'N' => 25,  'shared' => 'mixed', 'expected_unique_payments' => 13],
    ['label' => 'D', 'N' => 50,  'shared' => true,  'expected_unique_payments' => 1],
    ['label' => 'E', 'N' => 100, 'shared' => false, 'expected_unique_payments' => 100],
    ['label' => 'F', 'N' => 100, 'shared' => 'mixed-50-50', 'expected_unique_payments' => 51],
] as $cfg) {
    $label = $cfg['label'];
    $N = $cfg['N'];
    $expectedUnique = $cfg['expected_unique_payments'];
    echo "── SCENARIO {$label}: N={$N} shared=" . json_encode($cfg['shared']) . " expected_unique={$expectedUnique} ──\n";

    // Pre-create N fresh bookings for this scenario
    $bookings = makeBookings($service, $actor, $N, $label);
    echo "  created " . count($bookings) . " bookings\n";

    // Build idempotency keys
    if ($cfg['shared'] === true) {
        $sharedKey = "STRESS-IDEM-B-CONC-{$label}-" . bin2hex(random_bytes(4));
        $keys = array_fill(0, $N, $sharedKey);
    } elseif ($cfg['shared'] === false) {
        $keys = array_map(fn ($i) => "STRESS-IDEM-B-CONC-{$label}-{$i}-" . bin2hex(random_bytes(2)), range(0, $N - 1));
    } elseif ($cfg['shared'] === 'mixed') {
        // 13 shared + 12 distinct
        $sharedKey = "STRESS-IDEM-B-CONC-{$label}-SHARED-" . bin2hex(random_bytes(4));
        $keys = [];
        for ($i = 0; $i < 13; $i++) $keys[] = $sharedKey;
        for ($i = 13; $i < 25; $i++) $keys[] = "STRESS-IDEM-B-CONC-{$label}-DISTINCT-{$i}-" . bin2hex(random_bytes(2));
    } elseif ($cfg['shared'] === 'mixed-50-50') {
        $sharedKey = "STRESS-IDEM-B-CONC-{$label}-SHARED-" . bin2hex(random_bytes(4));
        $keys = [];
        for ($i = 0; $i < 50; $i++) $keys[] = $sharedKey;
        for ($i = 50; $i < 100; $i++) $keys[] = "STRESS-IDEM-B-CONC-{$label}-DISTINCT-{$i}-" . bin2hex(random_bytes(2));
    }

    $t0 = microtime(true);
    $results = fireConcurrent($BASE, $token, $bookings, $keys, 1000.0, $label);
    $elapsed = microtime(true) - $t0;

    // Analyze
    $statuses = array_count_values(array_column($results, 'status'));
    $latencies = array_column($results, 'latency_ms');
    $uniquePaymentIds = array_unique(array_filter(array_column($results, 'payment_id')));
    $replayFlags = array_count_values(array_map(fn ($v) => (int) (bool) $v, array_column($results, 'replay')));

    // Count hajj_umra_payments rows actually created in this scenario.
// Since each scenario now targets ONE booking, count distinct (booking_id, key)
// rows among the expected idempotency_keys.
    $expectedKeys = array_unique($keys);
    $bookingIds = array_unique(array_map(fn ($b) => is_array($b) ? $b['id'] : $b, $bookings));
    $rowsCreated = DB::table('hajj_umra_payments')
        ->whereIn('idempotency_key', $expectedKeys)
        ->whereIn('hajj_umra_booking_id', $bookingIds)
        ->count();

    $pass = ($rowsCreated === $expectedUnique);

    $scenarios[$label] = [
        'N' => $N,
        'shared' => $cfg['shared'],
        'expected_unique_payments' => $expectedUnique,
        'actual_rows_created' => $rowsCreated,
        'http_statuses' => $statuses,
        'unique_payment_ids' => count($uniquePaymentIds),
        'replay_flags' => $replayFlags,
        'elapsed_sec' => round($elapsed, 2),
        'throughput_per_sec' => round($N / max($elapsed, 0.001), 1),
        'latency_ms' => [
            'p50' => round(pct($latencies, 50), 3),
            'p95' => round(pct($latencies, 95), 3),
            'p99' => round(pct($latencies, 99), 3),
            'max' => round(max($latencies), 3),
            'min' => round(min($latencies), 3),
        ],
        'verdict' => $pass ? 'PASS' : 'FAIL',
    ];

    echo sprintf("  rows_created=%d (expected %d) statuses=%s replay=%s verdict=%s\n",
        $rowsCreated, $expectedUnique, json_encode($statuses), json_encode($replayFlags), $scenarios[$label]['verdict']);
    echo sprintf("  latency P50=%.1fms P95=%.1fms P99=%.1fms max=%.1fms\n",
        $scenarios[$label]['latency_ms']['p50'], $scenarios[$label]['latency_ms']['p95'],
        $scenarios[$label]['latency_ms']['p99'], $scenarios[$label]['latency_ms']['max']);
    echo "\n";
}

$totalElapsed = microtime(true) - $scenarioStart;
$allPass = array_reduce($scenarios, fn ($c, $s) => $c && ($s['verdict'] === 'PASS'), true);

// Final reconciliation
echo "═══════════════════════════════════════════════════════════\n";
echo "  HTTP Concurrency — Final reconciliation\n";
echo "═══════════════════════════════════════════════════════════\n";
$report = StressReconciliation::runAll();
echo "  per_account failed:       " . $report['per_account']['failed'] . "\n";
echo "  per_transaction failed:   " . $report['per_transaction']['failed'] . "\n";
echo "  orphan entries:           " . $report['orphan_entries']['count'] . "\n";
echo "  totals diff:              " . round($report['totals']['diff'], 4) . "\n";
echo "  reversals net_impact:     " . round($report['reversals']['net_impact_egp'], 2) . "\n";
echo "  verdict:                  " . $report['verdict'] . "\n";

$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir . '/phase-B-http-concurrency.json',
    json_encode([
        'phase' => 'B-http-concurrency',
        'scenarios' => $scenarios,
        'total_elapsed_sec' => round($totalElapsed, 2),
        'final_verdict' => $allPass && $report['verdict'] === 'PASS' ? 'PASS' : 'FAIL',
        'reconciliation' => $report,
        'ran_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n✅ Artifact: storage/app/stress/phase-B-http-concurrency.json\n";
echo "\nFINAL: " . ($allPass && $report['verdict'] === 'PASS' ? 'PASS' : 'FAIL') . "\n";
exit($allPass && $report['verdict'] === 'PASS' ? 0 : 1);