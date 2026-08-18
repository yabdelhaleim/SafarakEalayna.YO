<?php

declare(strict_types=1);

/**
 * stress_hot_account_worker.php
 *
 * Phase 25.12 — Hot Account worker process.
 * Spawned by stress_hot_account.php — does NOT run standalone.
 * Performs $perWorker concurrent random deposit/withdraw/transfer
 * operations against the hot account and prints a METRICS JSON line
 * to stdout for the parent to aggregate.
 *
 * Usage:
 *   php stress_hot_account_worker.php --workers=1 \
 *       --batch-id=N --hot-id=ID --side-id=ID --per-worker=20
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Finance\AccountService;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$batchId  = 0;
$hotId    = 0;
$sideId   = 0;
$perWorker= 20;
foreach ($argv as $arg) {
    if (preg_match('/^--batch-id=(\d+)$/', $arg, $m)) $batchId = (int) $m[1];
    if (preg_match('/^--hot-id=(\d+)$/', $arg, $m))   $hotId   = (int) $m[1];
    if (preg_match('/^--side-id=(\d+)$/', $arg, $m))  $sideId  = (int) $m[1];
    if (preg_match('/^--per-worker=(\d+)$/', $arg, $m)) $perWorker = (int) $m[1];
}

// ── Per-process safety guard
try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "WORKER {$batchId} SAFETY ABORT: ".$e->getMessage()."\n");
    exit(2);
}

mt_srand(20260814 + $batchId);
$svc = app(AccountService::class);
$txSvc = app(TransactionService::class);

$ops = 0; $credits = 0.0; $debits = 0.0; $deadlocks = 0; $retries = 0;
$actorId = (int) \App\Models\User::query()->where('email', 'stress-actor@safarakealayna.test')->value('id') ?: 1;

for ($i = 0; $i < $perWorker; $i++) {
    $opType = mt_rand(0, 2); // 0=credit, 1=debit, 2=transfer
    $amount = mt_rand(100, 1000) / 10.0; // 10.0 to 100.0 EGP
    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            DB::transaction(function () use ($opType, $amount, $hotId, $sideId, $actorId, $svc, $txSvc, &$credits, &$debits) {
                if ($opType === 0) {
                    $tx = \App\Models\Transaction::create([
                        'type' => 'income', 'amount' => $amount, 'currency' => 'EGP',
                        'module' => 'general', 'to_account_id' => $hotId,
                        'notes' => '[HOT-CREDIT]', 'created_by' => $actorId,
                    ]);
                    $svc->credit(\App\Models\Account::find($hotId), $amount, $tx->id);
                    $credits += $amount;
                } elseif ($opType === 1) {
                    $tx = \App\Models\Transaction::create([
                        'type' => 'expense', 'amount' => $amount, 'currency' => 'EGP',
                        'module' => 'general', 'from_account_id' => $hotId,
                        'notes' => '[HOT-DEBIT]', 'created_by' => $actorId,
                    ]);
                    $svc->debit(\App\Models\Account::find($hotId), $amount, $tx->id);
                    $debits += $amount;
                } else {
                    $txSvc->recordTransfer([
                        'from_account_id' => $hotId,
                        'to_account_id'   => $sideId,
                        'amount'          => $amount,
                        'currency'        => 'EGP',
                        'module'          => 'general',
                        'notes'           => '[HOT-TRANSFER]',
                        'created_by'      => $actorId,
                    ]);
                    $credits += $amount; // side receives credit (net system effect)
                    $debits += $amount;  // hot debits the same amount (net system effect)
                }
            });
            $ops++;
            break;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '1213') || str_contains($msg, 'Deadlock')) {
                $deadlocks++;
                $retries++;
                usleep(100_000 * ($attempt + 1));
                continue;
            }
            if (str_contains($msg, '1205') || str_contains($msg, 'Lock wait')) {
                $retries++;
                usleep(50_000 * ($attempt + 1));
                continue;
            }
            if (str_contains($msg, 'Insufficient balance')) {
                // skip — too many debits; not a defect
                break;
            }
            // other errors: log and break
            fwrite(STDERR, "WORKER {$batchId} ERROR op={$i}: ".$msg."\n");
            break;
        }
    }
}

fwrite(STDOUT, "METRICS ".json_encode([
    'ops'       => $ops,
    'credits'   => round($credits, 4),
    'debits'    => round($debits, 4),
    'deadlocks' => $deadlocks,
    'retries'   => $retries,
])."\n");
exit(0);
