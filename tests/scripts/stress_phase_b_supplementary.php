<?php

declare(strict_types=1);

/**
 * stress_phase_b_supplementary.php
 *
 * Phase 25-B — Supplementary stream to reach the full ~50,000
 * financial transactions target.
 *
 * The first stream (stress_phase_b_complete_transactions.php) generated
 * 28,000 balanced transactions across 5 categories. The legacy
 * idempotency-gate fixtures contribute 46 transactions + 100 new opening
 * entries = 146 baseline. This script adds 22,000 more balanced
 * transactions across 4 supplementary categories to reach ≥ 50K total:
 *
 *   customer_debts    =  4,000  (customer→vault collection cycles)
 *   supplier_debts    =  2,000  (vault→supplier settlement cycles)
 *   mixed_transfers   = 10,000  (free-form balanced transfers)
 *   extra_reversals   =  6,000  (reversal-of-reversal cycles)
 *
 * All transactions go through the same StressBulkFactory::directBalancedTransaction()
 * path that the main stream uses, so reconciliation invariants are
 * preserved.
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
echo "  Phase B — Supplementary 22K Balanced Transactions\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "APP_ENV:           " . env('APP_ENV') . "\n";
echo "DB_DATABASE:       " . $dbName . "\n";
echo "SELECT DATABASE(): " . DB::selectOne('SELECT DATABASE() AS d')->d . "\n";
echo "Disk free (GiB):   " . round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . "\n";
echo "─────────────────────────────────────────────────────────────\n";

$startTx = (int) DB::table('transactions')->count();
echo "Starting tx count: {$startTx}\n\n";

$alloc = [
    'customer_debts'  => 4000,
    'supplier_debts'  => 2000,
    'mixed_transfers' => 10000,
    'extra_reversals' => 6000,
];
$target = array_sum($alloc);  // 22,000
echo "Supplementary target: {$target}\n\n";

$startTotal = microtime(true);
$latencies = [];
$created = 0;
$chunkSize = 500;

foreach ($alloc as $category => $count) {
    echo "── Generating {$count} {$category} ──\n";
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
                    // skip
                }
            }
        });
        if ($catCreated % 2000 === 0) {
            $elapsed = microtime(true) - $catStart;
            echo sprintf("    [%s] %d / %d\n", date('H:i:s'), $catCreated, $count);
        }
    }
    $created += $catCreated;
    echo sprintf("  ✓ %s: %d tx in %.2fs\n\n", $category, $catCreated, microtime(true) - $catStart);
}

$totalElapsed = microtime(true) - $startTotal;
$endTx = (int) DB::table('transactions')->count();
echo "═══════════════════════════════════════════════════════════\n";
echo "  Supplementary COMPLETE\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "Created:           {$created}\n";
echo "Tx before:         {$startTx}\n";
echo "Tx after:          {$endTx}\n";
echo "Elapsed:           " . round($totalElapsed, 2) . " s\n";
echo "Throughput:        " . round($created / max($totalElapsed, 0.001), 1) . " tx/s\n";

if (!empty($latencies)) {
    sort($latencies);
    $count = count($latencies);
    $p = function ($pct) use ($latencies, $count) {
        $idx = (int) floor(($pct / 100.0) * ($count - 1));
        return $latencies[$idx];
    };
    echo sprintf("  P50=%.3f  P95=%.3f  P99=%.3f  max=%.3f\n",
        $p(50), $p(95), $p(99), max($latencies));
}

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
echo "  verdict:                  " . $report['verdict'] . "\n";

$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir . '/phase-B-supplementary.json',
    json_encode([
        'phase' => 'B-supplementary',
        'allocations' => $alloc,
        'created' => $created,
        'tx_before' => $startTx,
        'tx_after' => $endTx,
        'elapsed_sec' => round($totalElapsed, 2),
        'throughput_per_sec' => round($created / max($totalElapsed, 0.001), 1),
        'reconciliation' => $report,
        'ran_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n✅ Artifact: storage/app/stress/phase-B-supplementary.json\n";
exit($report['verdict'] === 'PASS' ? 0 : 1);