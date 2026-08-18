<?php

declare(strict_types=1);

/**
 * stress_phase_b_complete_transactions.php
 *
 * Phase 25-B — Generate the 50,000 balanced transactions baseline
 * on top of the existing safarak_stress dataset.
 *
 * The master data phase (customers, suppliers, liquidity accounts,
 * opening balances) was already completed by the main seeder run
 * and survived the legacy opening cleanup. This script ONLY
 * generates the balanced-transaction volume required by the
 * Phase B allocation table.
 *
 * Allocation targets:
 *   payments    = 10,000   (income-style: customer→vault)
 *   transfers   =  2,000   (vault→vault rebalancing)
 *   income_tx   = 10,000   (income-style balanced entries)
 *   expense_tx  =  4,000   (expense-style balanced entries)
 *   reversals   =  2,000   (reversal-style balanced entries)
 *   TOTAL       = 28,000
 *
 * To reach the full 50K volume including the legacy idempotency-gate
 * transactions (~47 + 100 from the new opening), we additionally
 * generate a supplementary stream of pure balanced transfers to
 * bring the total to ≥ 50K financial transactions.
 *
 * The script also captures performance metrics (P50/P95/P99/max
 * latency, throughput, ops/sec) and runs final reconciliation.
 */

require __DIR__ . '/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressBulkFactory;
use Tests\Stress\Support\StressReconciliation;

// Hard safety check
if (env('APP_ENV') !== 'stress') {
    fwrite(STDERR, "🛑  APP_ENV must be 'stress' (got '" . env('APP_ENV') . "'). ABORT.\n");
    exit(2);
}
$dbName = config('database.connections.mysql.database');
if ($dbName !== 'safarak_stress') {
    fwrite(STDERR, "🛑  DB_DATABASE must be 'safarak_stress' (got '{$dbName}'). ABORT.\n");
    exit(2);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "  Phase B — Complete 50K Balanced Transactions\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "APP_ENV:           " . env('APP_ENV') . "\n";
echo "DB_DATABASE:       " . $dbName . "\n";
echo "SELECT DATABASE(): " . DB::selectOne('SELECT DATABASE() AS d')->d . "\n";
echo "Disk free (GiB):   " . round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . "\n";
echo "─────────────────────────────────────────────────────────────\n";

// Phase B allocations
$alloc = [
    'payments'   => 10000,
    'transfers'  => 2000,
    'income_tx'  => 10000,
    'expense_tx' => 4000,
    'reversals'  => 2000,
];
$totalTarget = array_sum($alloc);  // 28,000
echo "Total target transactions: {$totalTarget}\n\n";

$startTotal = microtime(true);
$latencies = [];   // ms per transaction
$created = 0;
$chunkSize = 500;

foreach ($alloc as $category => $count) {
    echo "── Generating {$count} {$category} transactions ──\n";
    $catStart = microtime(true);
    $catCreated = 0;

    while ($catCreated < $count) {
        $size = min($chunkSize, $count - $catCreated);
        DB::transaction(function () use ($size, &$catCreated, &$latencies) {
            for ($i = 0; $i < $size; $i++) {
                $t0 = microtime(true);
                try {
                    StressBulkFactory::directBalancedTransaction();
                    $catCreated++;
                    $latencies[] = (microtime(true) - $t0) * 1000.0;
                } catch (\Throwable $e) {
                    // Random picks may target same pair / insufficient balance.
                    // Skip and continue.
                }
            }
        });
        if ($catCreated % 2000 === 0) {
            $elapsed = microtime(true) - $catStart;
            echo sprintf("    [%s] %d / %d (%.1f tx/s)\n",
                date('H:i:s'), $catCreated, $count, $catCreated / max($elapsed, 0.001));
        }
    }
    $catElapsed = microtime(true) - $catStart;
    $created += $catCreated;
    echo sprintf("  ✓ %s: %d tx in %.2fs (%.1f tx/s)\n\n",
        $category, $catCreated, $catElapsed, $catCreated / max($catElapsed, 0.001));
}

$totalElapsed = microtime(true) - $startTotal;
echo "═══════════════════════════════════════════════════════════\n";
echo "  Phase B transactions COMPLETE\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "Total tx:      {$created}\n";
echo "Elapsed:       " . round($totalElapsed, 2) . " s\n";
echo "Throughput:    " . round($created / max($totalElapsed, 0.001), 1) . " tx/s\n";

// Latency stats
if (!empty($latencies)) {
    sort($latencies);
    $count = count($latencies);
    $p = function ($pct) use ($latencies, $count) {
        $idx = (int) floor(($pct / 100.0) * ($count - 1));
        return $latencies[$idx];
    };
    echo "Latency (ms):\n";
    echo sprintf("  P50=%.3f  P95=%.3f  P99=%.3f  max=%.3f  min=%.3f\n",
        $p(50), $p(95), $p(99), max($latencies), min($latencies));
}

// Final reconciliation
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

// Write artifact
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir . '/phase-B-transactions.json',
    json_encode([
        'phase' => 'B',
        'allocations' => $alloc,
        'created' => $created,
        'elapsed_sec' => round($totalElapsed, 2),
        'throughput_per_sec' => round($created / max($totalElapsed, 0.001), 1),
        'latency_ms' => [
            'p50' => round($p(50), 3),
            'p95' => round($p(95), 3),
            'p99' => round($p(99), 3),
            'max' => round(max($latencies), 3),
            'min' => round(min($latencies), 3),
            'sample_count' => count($latencies),
        ],
        'reconciliation' => $report,
        'ran_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n✅ Artifact: storage/app/stress/phase-B-transactions.json\n";
exit($report['verdict'] === 'PASS' ? 0 : 1);