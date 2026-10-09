<?php

declare(strict_types=1);

/**
 * stress_concurrent_reversals_worker.php — reversal worker.
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Finance\TransactionService;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$batchId = 0; $txIds = [];
foreach ($argv as $arg) {
    if (preg_match('/^--batch-id=(\d+)$/', $arg, $m)) $batchId = (int) $m[1];
    if (preg_match('/^--tx-ids=(.+)$/', $arg, $m)) $txIds = array_filter(explode(',', $m[1]));
}

try {
    StressSafetyGuard::assertSafeEnvironment('mysql');
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "WORKER {$batchId} SAFETY ABORT: ".$e->getMessage()."\n");
    exit(2);
}

$svc = app(TransactionService::class);
$reversed = 0; $deadlocks = 0;
foreach ($txIds as $txId) {
    $txId = (int) $txId;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $tx = \App\Models\Transaction::find($txId);
            if (!$tx) continue 2;
            $svc->reverseTransaction($tx);
            $reversed++;
            break;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '1213') || str_contains($msg, 'Deadlock')) {
                $deadlocks++;
                usleep(100_000 * ($attempt + 1));
                continue;
            }
            break;
        }
    }
}
fwrite(STDOUT, "METRICS ".json_encode(['reversed' => $reversed, 'deadlocks' => $deadlocks])."\n");
exit(0);
