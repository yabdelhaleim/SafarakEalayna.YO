<?php

declare(strict_types=1);

/**
 * stress_concurrent_reversals.php — concurrent reversals.
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Transaction;
use App\Services\Finance\TransactionService;
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
fwrite(STDOUT, "  Concurrent Reversals — workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

$txsToReverse = Transaction::query()
    ->whereNull(DB::raw('NULL')) // placeholder
    ->where('notes', 'NOT LIKE', 'عكس:%')
    ->whereNotNull('from_account_id')
    ->whereNotNull('to_account_id')
    ->limit($workers * 5)
    ->pluck('id')
    ->all();

if (empty($txsToReverse)) {
    fwrite(STDOUT, "No transactions to reverse; aborting.\n");
    exit(1);
}

$perWorker = 5;
$procs = []; $pipes = [];
$batchSize = (int) ceil(count($txsToReverse) / $workers);
for ($w = 0; $w < $workers; $w++) {
    $batch = array_slice($txsToReverse, $w * $batchSize, $perWorker);
    if (empty($batch)) continue;
    $cmd = sprintf(
        'php -d memory_limit=512M %s --batch-id=%d --tx-ids=%s 2>&1',
        escapeshellarg(__DIR__.'/stress_concurrent_reversals_worker.php'),
        $w, escapeshellarg(implode(',', $batch))
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $wp);
    if (!is_resource($proc)) continue;
    $procs[$w] = $proc; $pipes[$w] = $wp;
    fclose($wp[0]);
}
$totalReversed = 0; $totalDeadlocks = 0;
foreach ($procs as $w => $proc) {
    $stdout = stream_get_contents($pipes[$w][1]);
    fclose($pipes[$w][1]); fclose($pipes[$w][2]);
    proc_close($proc);
    if (preg_match('/METRICS (.+)/', $stdout, $m)) {
        $stats = json_decode($m[1], true) ?: [];
        $totalReversed += $stats['reversed'] ?? 0;
        $totalDeadlocks += $stats['deadlocks'] ?? 0;
    }
}

$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents($dir.'/phase-C-concurrent-reversals.json', json_encode([
    'workers' => $workers,
    'reversed' => $totalReversed,
    'deadlocks' => $totalDeadlocks,
    'verdict' => $totalDeadlocks < $workers * 2 ? 'PASS' : 'FAIL',
], JSON_PRETTY_PRINT));
fwrite(STDOUT, "Reversed: {$totalReversed}, Deadlocks: {$totalDeadlocks}\n");
exit(0);
