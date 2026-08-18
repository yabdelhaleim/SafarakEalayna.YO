<?php

declare(strict_types=1);

/**
 * stress_concurrent_transfers.php
 *
 * Phase 25.11 — Concurrent transfers between random account pairs.
 * Generic concurrency smoke test for Phases A/B.
 *
 * Spawns N parallel worker processes that each issue M transfers.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$workers = 25;
foreach ($argv as $arg) {
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) $workers = (int) $m[1];
}

try {
    StressSafetyGuard::assertSafeEnvironment('mysql');
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  Concurrent Transfers — workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

// Snapshot initial global balance
$initialCredits = (float) DB::table('account_entries')->sum('credit');
$initialDebits  = (float) DB::table('account_entries')->sum('debit');
$initialBalanceSum = (float) DB::table('accounts')->sum('balance');

$start = microtime(true);
$perWorker = 10;
$procs = []; $pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $cmd = sprintf(
        'php -d memory_limit=512M %s --batch-id=%d --per-worker=%d 2>&1',
        escapeshellarg(__DIR__.'/stress_concurrent_transfers_worker.php'),
        $w, $perWorker
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $wp);
    if (!is_resource($proc)) continue;
    $procs[$w] = $proc; $pipes[$w] = $wp;
    fclose($wp[0]);
}
$totalOps = 0; $totalDeadlocks = 0; $totalRetries = 0;
foreach ($procs as $w => $proc) {
    $stdout = stream_get_contents($pipes[$w][1]);
    fclose($pipes[$w][1]); fclose($pipes[$w][2]);
    proc_close($proc);
    if (preg_match('/METRICS (.+)/', $stdout, $m)) {
        $stats = json_decode($m[1], true) ?: [];
        $totalOps += $stats['ops'] ?? 0;
        $totalDeadlocks += $stats['deadlocks'] ?? 0;
        $totalRetries += $stats['retries'] ?? 0;
    }
}
$elapsed = round(microtime(true) - $start, 2);

$finalCredits = (float) DB::table('account_entries')->sum('credit');
$finalDebits  = (float) DB::table('account_entries')->sum('debit');
$finalBalanceSum = (float) DB::table('accounts')->sum('balance');

$deltaCredits = $finalCredits - $initialCredits;
$deltaDebits  = $finalDebits - $initialDebits;
$balanceInvariance = abs(($finalCredits - $finalDebits) - $finalBalanceSum);

fwrite(STDOUT, "\n────────── Results ──────────\n");
fwrite(STDOUT, "Workers:                  {$workers}\n");
fwrite(STDOUT, "Per-worker ops:           {$perWorker}\n");
fwrite(STDOUT, "Total ops:                {$totalOps}\n");
fwrite(STDOUT, "Deadlocks:                {$totalDeadlocks}\n");
fwrite(STDOUT, "Retries:                  {$totalRetries}\n");
fwrite(STDOUT, "Delta credits:            {$deltaCredits}\n");
fwrite(STDOUT, "Delta debits:             {$deltaDebits}\n");
fwrite(STDOUT, "Ledger invariance:        {$balanceInvariance}\n");
fwrite(STDOUT, "Elapsed:                  {$elapsed} sec\n");

$opsPerSec = $elapsed > 0 ? round($totalOps / $elapsed, 2) : 0;
fwrite(STDOUT, "ops/sec:                  {$opsPerSec}\n");

$verdict = ($balanceInvariance < 0.02) ? 'PASS' : 'FAIL';
fwrite(STDOUT, "Verdict: {$verdict}\n");

$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents($dir.'/phase-C-concurrent-transfers.json', json_encode([
    'scenario' => 'concurrent_transfers',
    'workers' => $workers,
    'per_worker' => $perWorker,
    'total_ops' => $totalOps,
    'deadlocks' => $totalDeadlocks,
    'retries' => $totalRetries,
    'delta_credits' => $deltaCredits,
    'delta_debits' => $deltaDebits,
    'ledger_invariance' => $balanceInvariance,
    'elapsed_sec' => $elapsed,
    'ops_per_sec' => $opsPerSec,
    'verdict' => $verdict,
], JSON_PRETTY_PRINT));
exit($verdict === 'PASS' ? 0 : 1);
