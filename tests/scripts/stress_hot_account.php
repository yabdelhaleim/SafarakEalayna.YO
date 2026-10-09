<?php

declare(strict_types=1);

/**
 * stress_hot_account.php
 *
 * Phase 25.12 — Hot Account test.
 *
 * Scenario:
 *   - Pick ONE Cash Account as the contended target.
 *   - Initial balance: 1,000,000 EGP (set by seeder if not present).
 *   - Fire --workers concurrent operations, each attempting:
 *       * deposit (credit) of a random small amount
 *       * withdrawal (debit) of a random small amount
 *       * transfer (debit + credit on another account)
 *
 * Pass criterion:
 *   * No deadlock cascade
 *   * Final balance = initial + sum(credits) - sum(debits)
 *   * DB row locks resolve (no lost updates)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressBulkFactory;
use Tests\Stress\Support\StressReconciliation;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

// ── Parse args
$workers = 50;
foreach ($argv as $arg) {
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) {
        $workers = (int) $m[1];
    }
}

// ── Safety guard
try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  Hot Account test — workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

// ── Ensure a "Stress Cash Account" exists with 1M EGP
$hotAccount = Account::query()->where('name', 'STRESS-HOT-CASH')->first();
if (!$hotAccount) {
    $actorId = (int) \App\Models\User::query()->where('email', 'stress-actor@safarakealayna.test')->value('id') ?: 1;
    $hotAccount = Account::factory()->create([
        'name'        => 'STRESS-HOT-CASH',
        'type'        => AccountType::Cashbox,
        'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        'module'      => null,
        'currency'    => 'EGP',
        'is_active'   => true,
        'balance'     => 0,
    ]);
    StressBulkFactory::openBalance($hotAccount, 1_000_000.0, $actorId, 'HOT-CASH-OPENING');
    fwrite(STDOUT, "→ Created STRESS-HOT-CASH with 1M EGP opening balance.\n");
}

// Pick a side account to receive transfers (find or create)
$side = Account::query()->where('name', 'STRESS-HOT-SIDE')->first();
if (!$side) {
    $side = Account::factory()->create([
        'name'        => 'STRESS-HOT-SIDE',
        'type'        => AccountType::Cashbox,
        'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        'module'      => null,
        'currency'    => 'EGP',
        'is_active'   => true,
        'balance'     => 0,
    ]);
}

$initialBalance = (float) $hotAccount->fresh()->balance;
$initialSide    = (float) $side->fresh()->balance;

fwrite(STDOUT, "→ Hot account initial balance: {$initialBalance} EGP\n");
fwrite(STDOUT, "→ Side account initial balance: {$initialSide} EGP\n");
fwrite(STDOUT, "→ Firing {$workers} concurrent operations…\n");

$start = microtime(true);

// ── Fire {$workers} concurrent operations using parallel DB connections
// We use PHP's pthreads-free approach: spawn N separate PHP processes
// that each run a small workload targeting the hot account.
$workloadPerWorker = 20; // 20 ops × {$workers} workers = {$workers * 20} total operations
$procs = [];
$pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $cmd = sprintf(
        'php -d memory_limit=512M %s --workers=1 --batch-id=%d --hot-id=%d --side-id=%d --per-worker=%d 2>&1',
        escapeshellarg(__FILE__),
        $w,
        $hotAccount->id,
        $side->id,
        $workloadPerWorker
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $workerPipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "🛑 Failed to spawn worker {$w}\n");
        continue;
    }
    $procs[$w] = $proc;
    $pipes[$w] = $workerPipes;
    fclose($workerPipes[0]); // close stdin
}

$workerOutputs = [];
foreach ($procs as $w => $proc) {
    $stdout = stream_get_contents($pipes[$w][1]);
    fclose($pipes[$w][1]);
    fclose($pipes[$w][2]);
    $exitCode = proc_close($proc);
    $workerOutputs[$w] = ['stdout' => $stdout, 'exit' => $exitCode];
}

$elapsed = round(microtime(true) - $start, 2);
fwrite(STDOUT, "→ All workers completed in {$elapsed} sec.\n");

// ── Aggregate worker stats
$totalOps = 0; $totalDeadlocks = 0; $totalRetries = 0; $totalCredits = 0.0; $totalDebits = 0.0;
$perWorkerMetrics = [];
foreach ($workerOutputs as $w => $out) {
    if (preg_match('/METRICS (.+)/', $out['stdout'], $m)) {
        $stats = json_decode($m[1], true) ?: [];
        $perWorkerMetrics[$w] = $stats;
        $totalOps += $stats['ops'] ?? 0;
        $totalDeadlocks += $stats['deadlocks'] ?? 0;
        $totalRetries += $stats['retries'] ?? 0;
        $totalCredits += $stats['credits'] ?? 0;
        $totalDebits += $stats['debits'] ?? 0;
    } else {
        fwrite(STDERR, "Worker {$w} exit={$out['exit']} output=".substr($out['stdout'], 0, 200)."\n");
    }
}

$finalBalance = (float) $hotAccount->fresh()->balance;
$finalSide    = (float) $side->fresh()->balance;
$expectedBalance = $initialBalance + $totalCredits - $totalDebits;

fwrite(STDOUT, "\n═══════════ Results ═══════════\n");
fwrite(STDOUT, "Workers:                 {$workers}\n");
fwrite(STDOUT, "Total operations:        {$totalOps}\n");
fwrite(STDOUT, "Deadlocks observed:      {$totalDeadlocks}\n");
fwrite(STDOUT, "Retries triggered:       {$totalRetries}\n");
fwrite(STDOUT, "Sum of credits:          {$totalCredits}\n");
fwrite(STDOUT, "Sum of debits:           {$totalDebits}\n");
fwrite(STDOUT, "Hot account initial:     {$initialBalance}\n");
fwrite(STDOUT, "Hot account final:       {$finalBalance}\n");
fwrite(STDOUT, "Hot account expected:    {$expectedBalance}\n");
fwrite(STDOUT, "Variance:                ".round($finalBalance - $expectedBalance, 2)."\n");
fwrite(STDOUT, "Side account initial:    {$initialSide}\n");
fwrite(STDOUT, "Side account final:      {$finalSide}\n");
fwrite(STDOUT, "Elapsed:                 {$elapsed} sec\n");

$variance = abs($finalBalance - $expectedBalance);
$verdict = ($variance < 0.02 && $totalDeadlocks < $workers * 2) ? 'PASS' : 'FAIL';
fwrite(STDOUT, "\nVerdict: {$verdict}\n");

// ── Persist artifact
$artifact = [
    'scenario'           => 'hot_account',
    'workers'            => $workers,
    'workload_per_worker'=> $workloadPerWorker,
    'total_ops'          => $totalOps,
    'deadlocks'          => $totalDeadlocks,
    'retries'            => $totalRetries,
    'credits_sum'        => $totalCredits,
    'debits_sum'         => $totalDebits,
    'initial_balance'    => $initialBalance,
    'final_balance'      => $finalBalance,
    'expected_balance'   => $expectedBalance,
    'variance'           => round($finalBalance - $expectedBalance, 4),
    'elapsed_sec'        => $elapsed,
    'per_worker'         => $perWorkerMetrics,
    'verdict'            => $verdict,
];
$dir = storage_path('app/stress');
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
file_put_contents(
    $dir."/phase-C-hot-account.json",
    json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
fwrite(STDOUT, "Artifact: storage/app/stress/phase-C-hot-account.json\n");

exit($verdict === 'PASS' ? 0 : 1);
