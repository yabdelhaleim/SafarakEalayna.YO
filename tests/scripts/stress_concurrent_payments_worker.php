<?php

declare(strict_types=1);

/**
 * stress_concurrent_payments_worker.php — payment worker.
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Finance\AccountService;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$batchId = 0; $perWorker = 10; $custIds = [];
foreach ($argv as $arg) {
    if (preg_match('/^--batch-id=(\d+)$/', $arg, $m)) $batchId = (int) $m[1];
    if (preg_match('/^--per-worker=(\d+)$/', $arg, $m)) $perWorker = (int) $m[1];
    if (preg_match('/^--cust-ids=(.+)$/', $arg, $m)) $custIds = array_filter(explode(',', $m[1]));
}

try {
    StressSafetyGuard::assertSafeEnvironment('mysql');
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "WORKER {$batchId} SAFETY ABORT: ".$e->getMessage()."\n");
    exit(2);
}

if (empty($custIds)) {
    fwrite(STDOUT, "METRICS ".json_encode(['ops' => 0, 'deadlocks' => 0, 'retries' => 0])."\n");
    exit(0);
}

mt_srand(20260814 + $batchId);
$svc = app(AccountService::class);
$actorId = (int) \App\Models\User::query()->where('email', 'stress-actor@safarakealayna.test')->value('id') ?: 1;

$ops = 0; $deadlocks = 0; $retries = 0;
for ($i = 0; $i < $perWorker; $i++) {
    $custId = (int) $custIds[array_rand($custIds)];
    $amount = mt_rand(100, 5000) / 100.0;

    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            DB::transaction(function () use ($custId, $amount, $actorId, $svc, &$ops) {
                $customer = \App\Models\Customer::lockForUpdate()->find($custId);
                if (!$customer || !$customer->account_id) throw new \RuntimeException('no customer/account');
                $account = \App\Models\Account::lockForUpdate()->find($customer->account_id);
                if ($account->balance < $amount) throw new \RuntimeException('Insufficient balance');
                $tx = \App\Models\Transaction::create([
                    'type' => 'income', 'amount' => $amount, 'currency' => 'EGP',
                    'module' => 'general', 'from_account_id' => $account->id,
                    'to_account_id' => $account->id,
                    'notes' => '[STRESS-PAY]', 'created_by' => $actorId,
                ]);
                $svc->debit($account, $amount, $tx->id);
                $ops++;
            });
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
            break;
        }
    }
}

fwrite(STDOUT, "METRICS ".json_encode(['ops' => $ops, 'deadlocks' => $deadlocks, 'retries' => $retries])."\n");
exit(0);
