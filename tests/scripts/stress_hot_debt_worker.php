<?php

declare(strict_types=1);

/**
 * stress_hot_debt_worker.php — Hot Debt payment worker.
 * Spawned by stress_hot_debt.php. Pays $amount EGP toward a customer's debt.
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$batchId = 0; $custId = 0; $amount = 1000.0;
foreach ($argv as $arg) {
    if (preg_match('/^--batch-id=(\d+)$/', $arg, $m)) $batchId = (int) $m[1];
    if (preg_match('/^--cust-id=(\d+)$/', $arg, $m)) $custId = (int) $m[1];
    if (preg_match('/^--amount=([\d.]+)$/', $arg, $m)) $amount = (float) $m[1];
}

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "WORKER {$batchId} SAFETY ABORT: ".$e->getMessage()."\n");
    exit(2);
}

mt_srand(20260814 + $batchId);
$accepted = 0; $rejected = 0; $deadlocks = 0;
$maxAttempts = 5;
for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    try {
        DB::transaction(function () use ($custId, $amount, &$accepted) {
            $customer = \App\Models\Customer::lockForUpdate()->find($custId);
            if (!$customer || !$customer->account_id) throw new \RuntimeException('customer missing');
            $account = \App\Models\Account::lockForUpdate()->find($customer->account_id);
            if (!$account) throw new \RuntimeException('account missing');
            if ($account->balance < $amount) throw new \RuntimeException('insufficient balance');
            $tx = \App\Models\Transaction::create([
                'type' => 'income', 'amount' => $amount, 'currency' => 'EGP',
                'module' => 'general', 'from_account_id' => $account->id,
                'to_account_id' => $account->id,
                'notes' => '[HOT-DEBT-PAY]', 'created_by' => 1,
            ]);
            app(\App\Services\Finance\AccountService::class)->debit($account, $amount, $tx->id);
            $accepted = 1;
        });
        break;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '1213') || str_contains($msg, 'Deadlock')) {
            $deadlocks++;
            usleep(100_000 * ($attempt + 1));
            continue;
        }
        $rejected = 1;
        break;
    }
}

fwrite(STDOUT, "METRICS ".json_encode([
    'accepted' => $accepted,
    'rejected' => $rejected,
    'deadlocks' => $deadlocks,
])."\n");
exit(0);
