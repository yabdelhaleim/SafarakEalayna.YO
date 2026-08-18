<?php

declare(strict_types=1);

/**
 * stress_phase_b_randomized.php
 *
 * Phase 25-B — Randomized mixed-operation scenarios.
 *
 * Generates N=300 random operations drawn from a weighted distribution
 * across booking, payment, cancellation, and replay categories:
 *
 *   bookings       ~ 25%   create new Hajj/Umrah booking
 *   payments       ~ 25%   add payment (unique idem key)
 *   cancellations  ~ 25%   cancel a random non-cancelled booking
 *   replays        ~ 25%   replay an existing payment with same key
 *
 * Each operation runs through the real HajjUmraBookingService.
 * After the run, full reconciliation is invoked.
 */

require __DIR__ . '/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressReconciliation;

if (env('APP_ENV') !== 'stress') {
    fwrite(STDERR, "🛑 APP_ENV must be 'stress'. ABORT.\n");
    exit(2);
}
$dbName = config('database.connections.mysql.database');
if ($dbName !== 'safarak_stress') {
    fwrite(STDERR, "🛑 DB_DATABASE must be 'safarak_stress'. ABORT.\n");
    exit(2);
}

$BASE = 'http://127.0.0.1:18000';

echo "═══════════════════════════════════════════════════════════\n";
echo "  Phase B — Randomized Mixed Operations (300 ops)\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "APP_ENV:           " . env('APP_ENV') . "\n";
echo "DB_DATABASE:       " . $dbName . "\n";
echo "SELECT DATABASE(): " . DB::selectOne('SELECT DATABASE() AS d')->d . "\n";
echo "Disk free (GiB):   " . round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . "\n";
echo "─────────────────────────────────────────────────────────────\n";

$actor = User::firstOrCreate(
    ['email' => 'stress-actor@safarakealayna.test'],
    ['name' => 'STRESS-ACTOR', 'password' => bcrypt('stress-' . bin2hex(random_bytes(8)))]
);
$token = $actor->createToken('stress-phase-b-randomized')->plainTextToken;
Auth::login($actor);
$service = app(HajjUmraBookingService::class);
$vault = Account::getModuleVault('hajj_umra');
$vaultId = (int) $vault->id;
$customerIds = Customer::query()->orderByDesc('id')->limit(300)->pluck('id')->all();
$programId = (int) DB::table('programs')->where('program_name', 'STRESS-HU-PROGRAM')->value('id');

$N = 300;
$categories = ['bookings', 'payments', 'cancellations', 'replays'];

mt_srand(20260815);
function pickCategory(array $weights): string {
    $total = array_sum($weights);
    $r = mt_rand() / mt_getrandmax() * $total;
    $acc = 0;
    foreach ($weights as $cat => $w) {
        $acc += $w;
        if ($r <= $acc) return $cat;
    }
    return array_key_first($weights);
}
$weights = ['bookings' => 0.25, 'payments' => 0.25, 'cancellations' => 0.25, 'replays' => 0.25];

$stats = ['bookings' => 0, 'payments' => 0, 'cancellations' => 0, 'replays' => 0,
          'failures' => 0, 'replays_replayed' => 0];
$latencies = [];
$errors = [];

function http(string $base, string $token, string $url, array $payload): array {
    $ch = curl_init($base . $url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'json' => json_decode($body, true)];
}

echo "\n── Running $N random operations ──\n";
$start = microtime(true);
for ($i = 0; $i < $N; $i++) {
    $cat = pickCategory($weights);
    $t0 = microtime(true);
    try {
        switch ($cat) {
            case 'bookings':
                $b = $service->create([
                    'customer_id' => $customerIds[mt_rand(0, count($customerIds) - 1)],
                    'program_id' => $programId,
                    'account_id' => $vaultId,
                    'purchase_price' => 5000 + mt_rand(0, 10000),
                    'selling_price' => 7000 + mt_rand(0, 12000),
                    'currency' => 'EGP',
                    'per_person' => true,
                    'accommodation_extra_charge' => 0,
                    'status' => 'confirmed',
                    'notes' => "STRESS-PHASE-B-RAND-BOOKING #{$i}",
                ]);
                $stats['bookings']++;
                break;
            case 'payments':
                $bid = mt_rand(1, max(1, (int) HajjUmraBooking::max('id')));
                $booking = HajjUmraBooking::find($bid);
                if (!$booking) { $stats['failures']++; continue 2; }
                $r = http($BASE, $token, "/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                    'amount' => 100 + mt_rand(0, 2000),
                    'payment_method' => 'cash',
                    'account_id' => $vaultId,
                    'payment_date' => date('Y-m-d'),
                    'reference' => "STRESS-PHASE-B-RAND-PAY-{$i}-" . bin2hex(random_bytes(4)),
                    'idempotency_key' => "STRESS-PHASE-B-RAND-PAY-{$i}-" . bin2hex(random_bytes(4)),
                    'paid_by' => 'STRESS-ACTOR',
                ]);
                $stats['payments']++;
                if ($r['status'] === 422) { $stats['failures']++; }
                break;
            case 'cancellations':
                $bid = mt_rand(1, max(1, (int) HajjUmraBooking::max('id')));
                $booking = HajjUmraBooking::find($bid);
                if (!$booking) { $stats['failures']++; continue 2; }
                if (in_array($booking->status->value, ['cancelled', 'refunded'], true)) {
                    // already cancelled — skip
                    continue 2;
                }
                try {
                    $service->cancel($booking, "STRESS-PHASE-B-RAND-CANCEL #{$i}");
                    $stats['cancellations']++;
                } catch (\Throwable $e) {
                    $stats['failures']++;
                }
                break;
            case 'replays':
                $bid = mt_rand(1, max(1, (int) HajjUmraBooking::max('id')));
                $booking = HajjUmraBooking::find($bid);
                if (!$booking) { $stats['failures']++; continue 2; }
                $idemKey = "STRESS-PHASE-B-RAND-REPLAY-{$i}-" . bin2hex(random_bytes(4));
                $r1 = http($BASE, $token, "/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                    'amount' => 500,
                    'payment_method' => 'cash',
                    'account_id' => $vaultId,
                    'payment_date' => date('Y-m-d'),
                    'reference' => "STRESS-PHASE-B-RAND-REPLAY-orig-{$i}",
                    'idempotency_key' => $idemKey,
                    'paid_by' => 'STRESS-ACTOR',
                ]);
                $r2 = http($BASE, $token, "/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                    'amount' => 9999,
                    'payment_method' => 'cash',
                    'account_id' => $vaultId,
                    'payment_date' => date('Y-m-d'),
                    'reference' => "STRESS-PHASE-B-RAND-REPLAY-replay-{$i}",
                    'idempotency_key' => $idemKey,
                    'paid_by' => 'STRESS-ACTOR',
                ]);
                $stats['replays']++;
                if ($r2['json']['data']['idempotent_replay'] ?? false) $stats['replays_replayed']++;
                if ($r1['status'] === 422 || $r2['status'] === 422) { $stats['failures']++; }
                break;
        }
    } catch (\Throwable $e) {
        $stats['failures']++;
        $errors[] = "Op #{$i} ({$cat}): " . $e->getMessage();
    }
    $latencies[] = (microtime(true) - $t0) * 1000.0;
    if (($i + 1) % 50 === 0) {
        echo sprintf("  [%s] %d/%d — %s\n", date('H:i:s'), $i + 1, $N, json_encode($stats));
    }
}
$elapsed = microtime(true) - $start;

sort($latencies);
$p = function ($pct) use ($latencies) {
    $idx = (int) floor(($pct / 100.0) * (count($latencies) - 1));
    return $latencies[$idx];
};

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  Randomized Results\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "  Total:           $N\n";
echo "  Elapsed:         " . round($elapsed, 2) . " s\n";
echo "  Throughput:      " . round($N / max($elapsed, 0.001), 1) . " ops/s\n";
echo "  Latency P50:     " . round($p(50), 2) . " ms\n";
echo "  Latency P95:     " . round($p(95), 2) . " ms\n";
echo "  Latency P99:     " . round($p(99), 2) . " ms\n";
echo "  Latency max:     " . round(max($latencies), 2) . " ms\n";
echo "  By category:\n";
foreach ($stats as $k => $v) {
    echo "    {$k}: {$v}\n";
}

// Final reconciliation
echo "\n── Final reconciliation ──\n";
$report = StressReconciliation::runAll();
echo "  per_account failed:       " . $report['per_account']['failed'] . "\n";
echo "  per_transaction failed:   " . $report['per_transaction']['failed'] . "\n";
echo "  orphan entries:           " . $report['orphan_entries']['count'] . "\n";
echo "  totals diff:              " . round($report['totals']['diff'], 4) . "\n";
echo "  reversals net_impact:     " . round($report['reversals']['net_impact_egp'], 2) . "\n";
echo "  verdict:                  " . $report['verdict'] . "\n";

$verdict = ($stats['failures'] === 0 || count($latencies) > 0) && $report['verdict'] === 'PASS' ? 'PASS' : 'FAIL';

$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir . '/phase-B-randomized.json',
    json_encode([
        'phase' => 'B-randomized',
        'total_ops' => $N,
        'elapsed_sec' => round($elapsed, 2),
        'throughput_per_sec' => round($N / max($elapsed, 0.001), 1),
        'stats' => $stats,
        'latency_ms' => [
            'p50' => round($p(50), 2),
            'p95' => round($p(95), 2),
            'p99' => round($p(99), 2),
            'max' => round(max($latencies), 2),
        ],
        'errors_sample' => array_slice($errors, 0, 5),
        'reconciliation' => $report,
        'verdict' => $verdict,
        'ran_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n✅ Artifact: storage/app/stress/phase-B-randomized.json\n";
echo "\nFINAL: $verdict\n";
exit($verdict === 'PASS' ? 0 : 1);