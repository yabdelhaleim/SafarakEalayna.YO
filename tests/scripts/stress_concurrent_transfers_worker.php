<?php

declare(strict_types=1);

/**
 * stress_concurrent_transfers_worker.php — generic concurrent transfers worker.
 * Spawned by stress_concurrent_transfers.php. Performs $perWorker random
 * transfer operations.
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

$batchId = 0; $perWorker = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--batch-id=(\d+)$/', $arg, $m)) $batchId = (int) $m[1];
    if (preg_match('/^--per-worker=(\d+)$/', $arg, $m)) $perWorker = (int) $m[1];
}

try {
    StressSafetyGuard::assertSafeEnvironment('mysql');
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "WORKER {$batchId} SAFETY ABORT: ".$e->getMessage()."\n");
    exit(2);
}

mt_srand(20260814 + $batchId);
$accountIds = Account::query()->where('is_active', true)->pluck('id')->all();
if (count($accountIds) < 2) {
    fwrite(STDOUT, "METRICS ".json_encode(['ops' => 0, 'deadlocks' => 0, 'retries' => 0])."\n");
    exit(0);
}

$svc = app(TransactionService::class);
$actorId = (int) \App\Models\User::query()->where('email', 'stress-actor@safarakealayna.test')->value('id') ?: 1;

$ops = 0; $deadlocks = 0; $retries = 0;
for ($i = 0; $i < $perWorker; $i++) {
    $fromIdx = mt_rand(0, count($accountIds) - 1);
    $toIdx   = mt_rand(0, count($accountIds) - 1);
    while ($toIdx === $fromIdx) $toIdx = mt_rand(0, count($accountIds) - 1);
    $amount = mt_rand(100, 5000) / 100.0; // 1.00 to 50.00

    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            $svc->recordTransfer([
                'from_account_id' => $accountIds[$fromIdx],
                'to_account_id'   => $accountIds[$toIdx],
                'amount'          => $amount,
                'currency'        => 'EGP',
                'module'          => 'general',
                'notes'           => '[STRESS-XFER]',
                'created_by'      => $actorId,
            ]);
            $ops++;
            break;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '1213') || str_contains($msg, 'Deadlock')) {
                $deadlocks++; $retries++;
                usleep(100_000 * ($attempt + 1));
                continue;
            }
            if (str_contains($msg, '1205') || str_contains($msg, 'Lock wait')) {
                $retries++;
                usleep(50_000 * ($attempt + 1));
                continue;
            }
            if (str_contains($msg, 'Insufficient')) break;
            // other errors — log and continue
            break;
        }
    }
}

fwrite(STDOUT, "METRICS ".json_encode([
    'ops'       => $ops,
    'deadlocks' => $deadlocks,
    'retries'   => $retries,
])."\n");
exit(0);
