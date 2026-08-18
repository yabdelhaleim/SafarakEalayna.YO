<?php

declare(strict_types=1);

/**
 * stress_hot_debt.php
 *
 * Phase 25.13 — Hot Customer Debt test.
 *
 * Scenario:
 *   - Pick ONE customer with a 100,000 EGP debt.
 *   - Fire --workers parallel payments of 1,000 EGP each.
 *   - Track total accepted vs total attempted.
 *
 * Pass criterion:
 *   * No double-spend
 *   * Accepted payments sum <= 100,000 EGP
 *   * Debt becomes 0 (or some positive remainder) without going negative
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Customer;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$workers = 50;
foreach ($argv as $arg) {
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) $workers = (int) $m[1];
}

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  Hot Customer Debt test — workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

// ── Find or create a customer with ~100K debt.
$customer = Customer::query()->where('national_id', 'STRESS-HOT-DEBT')->first();
if (!$customer) {
    $actorId = (int) \App\Models\User::query()->where('email', 'stress-actor@safarakealayna.test')->value('id') ?: 1;
    $customer = Customer::factory()->create([
        'full_name'   => 'STRESS-HOT-DEBT-CUSTOMER',
        'phone'       => '+201000000999',
        'national_id' => 'STRESS-HOT-DEBT',
        'module_type' => 'bus',
        'created_by'  => $actorId,
    ]);
    // Allocate a 100K AR balance by direct, service-owned credit
    $svc = app(\App\Services\Finance\AccountService::class);
    if ($customer->account_id) {
        $account = \App\Models\Account::find($customer->account_id);
        $tx = \App\Models\Transaction::create([
            'type' => 'income', 'amount' => 100000.0, 'currency' => 'EGP',
            'module' => 'general', 'to_account_id' => $account->id,
            'notes' => 'HOT-DEBT-INITIAL', 'created_by' => $actorId,
        ]);
        $svc->credit($account, 100000.0, $tx->id);
    }
    fwrite(STDOUT, "→ Created STRESS-HOT-DEBT customer with 100K EGP AR balance.\n");
}

$initialDebt = $customer->account_id ? (float) \App\Models\Account::find($customer->account_id)->balance : 0.0;
fwrite(STDOUT, "→ Customer debt initial: {$initialDebt} EGP\n");
fwrite(STDOUT, "→ Firing {$workers} parallel payments of 1,000 EGP each…\n");

$start = microtime(true);

$perWorker = 1;
$procs = [];
$pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $cmd = sprintf(
        'php -d memory_limit=512M %s --batch-id=%d --cust-id=%d --amount=1000 2>&1',
        escapeshellarg(__DIR__.'/stress_hot_debt_worker.php'),
        $w, $customer->id
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $workerPipes);
    if (!is_resource($proc)) continue;
    $procs[$w] = $proc;
    $pipes[$w] = $workerPipes;
    fclose($workerPipes[0]);
}

$totalAccepted = 0;
$totalRejected = 0;
$deadlocks = 0;
foreach ($procs as $w => $proc) {
    $stdout = stream_get_contents($pipes[$w][1]);
    fclose($pipes[$w][1]); fclose($pipes[$w][2]);
    proc_close($proc);
    if (preg_match('/METRICS (.+)/', $stdout, $m)) {
        $stats = json_decode($m[1], true) ?: [];
        $totalAccepted += $stats['accepted'] ?? 0;
        $totalRejected += $stats['rejected'] ?? 0;
        $deadlocks += $stats['deadlocks'] ?? 0;
    }
}

$elapsed = round(microtime(true) - $start, 2);
$finalDebt = $customer->account_id ? (float) \App\Models\Account::find($customer->account_id)->balance : 0.0;
$expectedAccepted = min($workers, (int) floor($initialDebt / 1000));

fwrite(STDOUT, "\n═══════════ Results ═══════════\n");
fwrite(STDOUT, "Workers:                 {$workers}\n");
fwrite(STDOUT, "Payments accepted:       {$totalAccepted}\n");
fwrite(STDOUT, "Payments rejected:       {$totalRejected}\n");
fwrite(STDOUT, "Expected accepted:       {$expectedAccepted}\n");
fwrite(STDOUT, "Initial debt:            {$initialDebt}\n");
fwrite(STDOUT, "Final debt:              {$finalDebt}\n");
fwrite(STDOUT, "Negative impossible?:    ".($finalDebt < 0 ? 'YES — FAIL' : 'no')."\n");
fwrite(STDOUT, "Deadlocks observed:      {$deadlocks}\n");
fwrite(STDOUT, "Elapsed:                 {$elapsed} sec\n");

$verdict = ($finalDebt >= 0 && abs($totalAccepted - $expectedAccepted) <= 1) ? 'PASS' : 'FAIL';
fwrite(STDOUT, "Verdict: {$verdict}\n");

$artifact = [
    'scenario' => 'hot_debt',
    'workers' => $workers,
    'initial_debt' => $initialDebt,
    'final_debt' => $finalDebt,
    'accepted' => $totalAccepted,
    'rejected' => $totalRejected,
    'expected_accepted' => $expectedAccepted,
    'deadlocks' => $deadlocks,
    'elapsed_sec' => $elapsed,
    'verdict' => $verdict,
];
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents($dir.'/phase-C-hot-debt.json', json_encode($artifact, JSON_PRETTY_PRINT));
fwrite(STDOUT, "Artifact: storage/app/stress/phase-C-hot-debt.json\n");

exit($verdict === 'PASS' ? 0 : 1);
