<?php

declare(strict_types=1);

/**
 * stress_concurrent_payments.php — concurrent customer payments.
 * Generic smoke for 25.11.
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Customer;
use App\Services\Finance\AccountService;
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
fwrite(STDOUT, "  Concurrent Customer Payments — workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

$customers = Customer::query()->whereNotNull('account_id')->limit(200)->pluck('id')->all();
if (count($customers) < 5) {
    fwrite(STDOUT, "Not enough customers seeded; aborting.\n");
    exit(1);
}

$perWorker = 10;
$procs = []; $pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $custList = implode(',', array_slice($customers, ($w * 3) % count($customers), 3));
    $cmd = sprintf(
        'php -d memory_limit=512M %s --batch-id=%d --per-worker=%d --cust-ids=%s 2>&1',
        escapeshellarg(__DIR__.'/stress_concurrent_payments_worker.php'),
        $w, $perWorker, escapeshellarg($custList)
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

$elapsed = round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))), 2);
fwrite(STDOUT, "\nWorkers: {$workers}, ops: {$totalOps}, deadlocks: {$totalDeadlocks}, retries: {$totalRetries}\n");
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents($dir.'/phase-C-concurrent-payments.json', json_encode([
    'workers' => $workers,
    'ops' => $totalOps,
    'deadlocks' => $totalDeadlocks,
    'retries' => $totalRetries,
    'verdict' => $totalDeadlocks < $workers * 2 ? 'PASS' : 'FAIL',
], JSON_PRETTY_PRINT));
exit(0);
