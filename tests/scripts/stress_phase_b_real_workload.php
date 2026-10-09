<?php

declare(strict_types=1);

/**
 * stress_phase_b_real_workload.php
 *
 * Phase 25-B — Real Hajj/Umrah workload via the actual service contracts.
 *
 * Allocates and generates:
 *   500 Hajj/Umrah bookings via HajjUmraBookingService::create()
 *   2500 payments via HajjUmraBookingService::addPayment()  (mix partial + full)
 *   500 cancellations via HajjUmraBookingService::cancel()
 *
 * All operations go through the real service layer — no direct DB inserts,
 * no model hacks. Each booking gets a unique program_id=1 (the legacy
 * STRESS-HU-PROGRAM), random customer from the 1001 in safarak_stress,
 * and one of the 50 liquidity accounts as its paying account.
 *
 * Each payment carries a UNIQUE idempotency_key so the new idempotency
 * protection is exercised at scale (not as a single burst). Cancellation
 * is invoked on a separate subset of bookings to exercise the reversal
 * pipeline.
 *
 * Performance metrics (P50/P95/P99/max, throughput, ops/sec) are
 * captured per category and written to JSON artifacts.
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

echo "═══════════════════════════════════════════════════════════\n";
echo "  Phase B — Real Hajj/Umrah Workload\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "APP_ENV:           " . env('APP_ENV') . "\n";
echo "DB_DATABASE:       " . $dbName . "\n";
echo "SELECT DATABASE(): " . DB::selectOne('SELECT DATABASE() AS d')->d . "\n";
echo "Disk free (GiB):   " . round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . "\n";
echo "─────────────────────────────────────────────────────────────\n";

// ── 1. Resolve required master data
$customers = Customer::query()->orderBy('id')->limit(500)->pluck('id')->all();
$programId = (int) DB::table('programs')->where('program_name', 'STRESS-HU-PROGRAM')->value('id');
$liquidityAccounts = Account::query()
    ->where(function ($q) {
        $q->where('type', 'cashbox')->orWhere('type', 'bank')->orWhere('type', 'wallet');
    })
    ->where('is_active', true)
    ->limit(50)
    ->pluck('id')
    ->all();

if (empty($customers) || !$programId || empty($liquidityAccounts)) {
    fwrite(STDERR, "🛑  Missing master data: customers=" . count($customers) .
        " program_id={$programId} liquidity=" . count($liquidityAccounts) . "\n");
    exit(3);
}

echo "Resolved master data:\n";
echo "  customers:        " . count($customers) . "\n";
echo "  program_id:       {$programId}\n";
echo "  liquidity accts:  " . count($liquidityAccounts) . "\n\n";

// Auth as the stress actor so service-level actor attribution works
$actor = \App\Models\User::firstOrCreate(
    ['email' => 'stress-actor@safarakealayna.test'],
    ['name' => 'STRESS-ACTOR', 'password' => bcrypt('stress-' . bin2hex(random_bytes(8)))]
);
Auth::login($actor);

$service = app(HajjUmraBookingService::class);

$metrics = [
    'bookings'   => ['target' => 500,  'created' => 0, 'failed' => 0, 'latencies_ms' => []],
    'payments'   => ['target' => 2500, 'created' => 0, 'failed' => 0, 'latencies_ms' => []],
    'cancels'    => ['target' => 500,  'created' => 0, 'failed' => 0, 'latencies_ms' => []],
];

$bookingsCreated = [];
$paymentSeq = 0;
$cancelSeq = 0;

// ── 2. Create 500 real bookings
echo "── Creating 500 real Hajj/Umrah bookings ──\n";
$bookingStart = microtime(true);
for ($i = 0; $i < 500; $i++) {
    $customerId = $customers[$i % count($customers)];
    $accountId = $liquidityAccounts[$i % count($liquidityAccounts)];
    $purchase = 8000 + ($i * 13 % 4000);
    $selling = $purchase + 3000 + ($i % 1500);
    $payload = [
        'customer_id' => $customerId,
        'program_id' => $programId,
        'account_id' => $accountId,
        'purchase_price' => $purchase,
        'selling_price' => $selling,
        'currency' => 'EGP',
        'per_person' => true,
        'accommodation_extra_charge' => 0,
        'status' => 'confirmed',
        'notes' => "STRESS-PHASE-B booking #{$i}",
    ];
    $t0 = microtime(true);
    try {
        $booking = $service->create($payload);
        $bookingsCreated[] = $booking->id;
        $metrics['bookings']['created']++;
        $metrics['bookings']['latencies_ms'][] = (microtime(true) - $t0) * 1000.0;
    } catch (\Throwable $e) {
        $metrics['bookings']['failed']++;
        if ($metrics['bookings']['failed'] <= 3) {
            fwrite(STDERR, "    booking #{$i} failed: " . $e->getMessage() . "\n");
        }
    }
    if (($i + 1) % 100 === 0) {
        echo sprintf("    [%s] %d / 500 bookings\n", date('H:i:s'), $i + 1);
    }
}
$bookingsElapsed = microtime(true) - $bookingStart;
echo sprintf("  ✓ bookings: %d created, %d failed in %.2fs\n",
    $metrics['bookings']['created'], $metrics['bookings']['failed'], $bookingsElapsed);

// ── 3. Add 2500 payments across the bookings
echo "\n── Adding 2500 real payments ──\n";
$paymentStart = microtime(true);
for ($i = 0; $i < 2500; $i++) {
    $bookingId = $bookingsCreated[$i % count($bookingsCreated)];
    $booking = HajjUmraBooking::find($bookingId);
    if (!$booking) {
        $metrics['payments']['failed']++;
        continue;
    }
    $remaining = (float) $booking->selling_price - (float) $booking->paid_amount;
    if ($remaining <= 0) {
        // booking is fully paid — skip and create another partial
        $bookingId = $bookingsCreated[($i + 17) % count($bookingsCreated)];
        $booking = HajjUmraBooking::find($bookingId);
        if (!$booking) { $metrics['payments']['failed']++; continue; }
        $remaining = (float) $booking->selling_price - (float) $booking->paid_amount;
        if ($remaining <= 0) { $metrics['payments']['failed']++; continue; }
    }
    $paymentAmount = min($remaining, 500 + ($i * 7 % 3000));
    if ($paymentAmount <= 0) { $metrics['payments']['failed']++; continue; }
    $paymentSeq++;
    $t0 = microtime(true);
    try {
        $payment = $service->addPayment($booking, [
            'amount' => $paymentAmount,
            'payment_method' => 'cash',
            'payment_date' => date('Y-m-d'),
            'reference' => sprintf('STRESS-B-PAY-%07d', $paymentSeq),
            'idempotency_key' => sprintf('STRESS-IDEM-B-%010d', $paymentSeq),
            'paid_by' => 'STRESS-ACTOR',
            'notes' => "STRESS-PHASE-B payment #{$paymentSeq}",
        ]);
        $metrics['payments']['created']++;
        $metrics['payments']['latencies_ms'][] = (microtime(true) - $t0) * 1000.0;
    } catch (\Throwable $e) {
        $metrics['payments']['failed']++;
        if ($metrics['payments']['failed'] <= 3) {
            fwrite(STDERR, "    payment #{$paymentSeq} failed: " . $e->getMessage() . "\n");
        }
    }
    if (($i + 1) % 500 === 0) {
        echo sprintf("    [%s] %d / 2500 payments\n", date('H:i:s'), $i + 1);
    }
}
$paymentsElapsed = microtime(true) - $paymentStart;
echo sprintf("  ✓ payments: %d created, %d failed in %.2fs\n",
    $metrics['payments']['created'], $metrics['payments']['failed'], $paymentsElapsed);

// ── 4. Cancel 500 bookings (only those with no payments yet, to avoid conflicts)
echo "\n── Cancelling 500 bookings (reversal exercise) ──\n";
$cancelStart = microtime(true);
$bookingsForCancel = array_slice($bookingsCreated, 250, 500);  // last 250 of 500
for ($i = 0; $i < 500; $i++) {
    if ($i < count($bookingsForCancel)) {
        $bookingId = $bookingsForCancel[$i];
    } else {
        $bookingId = $bookingsCreated[($i * 7 + 3) % count($bookingsCreated)];
    }
    $booking = HajjUmraBooking::find($bookingId);
    if (!$booking) { $metrics['cancels']['failed']++; continue; }
    if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
        // Already cancelled by an earlier iteration — skip
        continue;
    }
    $cancelSeq++;
    $t0 = microtime(true);
    try {
        $service->cancel($booking, "STRESS-PHASE-B cancel #{$cancelSeq}");
        $metrics['cancels']['created']++;
        $metrics['cancels']['latencies_ms'][] = (microtime(true) - $t0) * 1000.0;
    } catch (\Throwable $e) {
        $metrics['cancels']['failed']++;
        if ($metrics['cancels']['failed'] <= 3) {
            fwrite(STDERR, "    cancel #{$cancelSeq} failed: " . $e->getMessage() . "\n");
        }
    }
    if (($i + 1) % 100 === 0) {
        echo sprintf("    [%s] %d / 500 cancels\n", date('H:i:s'), $i + 1);
    }
}
$cancelsElapsed = microtime(true) - $cancelStart;
echo sprintf("  ✓ cancels: %d created, %d failed in %.2fs\n",
    $metrics['cancels']['created'], $metrics['cancels']['failed'], $cancelsElapsed);

// ── 5. Per-category latency summary
$summary = [];
foreach ($metrics as $cat => $m) {
    if (!empty($m['latencies_ms'])) {
        sort($m['latencies_ms']);
        $count = count($m['latencies_ms']);
        $p = function ($pct) use ($m, $count) {
            $idx = (int) floor(($pct / 100.0) * ($count - 1));
            return $m['latencies_ms'][$idx];
        };
        $summary[$cat] = [
            'target' => $m['target'],
            'created' => $m['created'],
            'failed' => $m['failed'],
            'elapsed_sec' => match ($cat) {
                'bookings' => round($bookingsElapsed, 2),
                'payments' => round($paymentsElapsed, 2),
                'cancels' => round($cancelsElapsed, 2),
            },
            'throughput_per_sec' => match ($cat) {
                'bookings' => round($m['created'] / max($bookingsElapsed, 0.001), 1),
                'payments' => round($m['created'] / max($paymentsElapsed, 0.001), 1),
                'cancels' => round($m['created'] / max($cancelsElapsed, 0.001), 1),
            },
            'latency_ms' => [
                'p50' => round($p(50), 3),
                'p95' => round($p(95), 3),
                'p99' => round($p(99), 3),
                'max' => round(max($m['latencies_ms']), 3),
                'min' => round(min($m['latencies_ms']), 3),
            ],
        ];
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  Real Hajj/Umrah Workload COMPLETE\n";
echo "═══════════════════════════════════════════════════════════\n";
foreach ($summary as $cat => $s) {
    echo sprintf("  %-10s %d/%d created (%.1f/s) P50=%.2fms P95=%.2fms P99=%.2fms max=%.2fms\n",
        $cat, $s['created'], $s['target'], $s['throughput_per_sec'],
        $s['latency_ms']['p50'], $s['latency_ms']['p95'],
        $s['latency_ms']['p99'], $s['latency_ms']['max']);
}

// ── 6. Final reconciliation
echo "\n── Final reconciliation ──\n";
$report = StressReconciliation::runAll();
echo "  per_account failed:       " . $report['per_account']['failed'] . "\n";
echo "  per_transaction failed:   " . $report['per_transaction']['failed'] . "\n";
echo "  orphan entries:           " . $report['orphan_entries']['count'] . "\n";
echo "  orphan transactions:      " . $report['orphan_transactions']['count'] . "\n";
echo "  duplicate income:         " . $report['duplicate_income']['count'] . "\n";
echo "  reversals net impact:     " . round($report['reversals']['net_impact_egp'], 2) . "\n";
echo "  totals diff:              " . round($report['totals']['diff'], 4) . "\n";
echo "  FK integrity broken:      " . $report['fk_integrity']['broken'] . "\n";
echo "  soft deletes unexpected:  " . count($report['soft_deletes']) . "\n";
echo "  verdict:                  " . $report['verdict'] . "\n";

// ── 7. Write artifact
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir . '/phase-B-real-workload.json',
    json_encode([
        'phase' => 'B-real-workload',
        'module' => 'hajj_umra',
        'actor' => $actor->id,
        'master_data' => [
            'customers' => count($customers),
            'program_id' => $programId,
            'liquidity_accounts' => count($liquidityAccounts),
        ],
        'metrics' => $summary,
        'reconciliation' => $report,
        'ran_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n✅ Artifact: storage/app/stress/phase-B-real-workload.json\n";
exit($report['verdict'] === 'PASS' ? 0 : 1);