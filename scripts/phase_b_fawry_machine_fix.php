<?php
/**
 * Phase B — FawryMachine Protection: REAL DB VALIDATION.
 *
 * Validates the 2 contract guarantees shipped in Phase B:
 *
 *   ①  Boot guard on FawryMachine::updating blocks direct balance mutations
 *       from outside the sanctioned debit()/credit() path or from inside
 *       LedgerBalanceMutationGuard::run().
 *
 *   ②  FawryMachineRechargeService creates a GL Transaction with
 *       related_type=FawryMachine::class linking the recharge to the
 *       FawryMachine balance change.
 *
 *   Bonus: the original `FawryTransactionService::createTransaction()`
 *   flow (which uses machine->debit()) and `deleteTransaction()` (which
 *   uses machine->credit()) still work after the guard is added.
 *
 * Run: php scripts/phase_b_fawry_machine_fix.php
 *
 * Output: JSON verdict to stdout + storage/logs/phase_b_fawry_result.json
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$out = [];
$failures = [];

$check = static function (string $label, bool $ok, array &$failures) use (&$out): void {
    if ($ok) {
        $out[] = "  ✓ {$label}";
    } else {
        $out[] = "  ✗ {$label}";
        $failures[] = $label;
    }
};

$echo = static function () use (&$out): void {
    echo implode(PHP_EOL, $out).PHP_EOL;
    $out = [];
};

// ─────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────
$out[] = '═══ Phase B FawryMachine Protection — Real DB Validation ═══';
$out[] = '';
$echo();

$user = User::query()->where('is_active', true)->whereIn('role', ['admin', 'owner'])->first();
if (! $user) {
    $user = User::query()->create([
        'name' => 'Phase B Validator',
        'email' => 'phase-b-fawry@test.local',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    $out[] = "Created temp admin: {$user->email}";
} else {
    $out[] = "Using admin: {$user->email} (id={$user->id})";
}
$echo();
Auth::login($user);

// Create a FawryMachine with initial balance
$machine = FawryMachine::create([
    'name' => 'P-B Fawry Machine Vodafone',
    'type' => 'fawry',
    'balance' => 50000,
    'is_active' => true,
    'notes' => 'Phase B test machine',
]);
$out[] = "Created FawryMachine #{$machine->id} with balance=50000";
$echo();

DB::beginTransaction();
try {
    // ═══════════════════════════════════════════════════════════════
    // ① Sanctioned path: debit()/credit() work
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ① Sanctioned debit()/credit() still work ━━';

    $initialBalance = (float) $machine->balance;

    $debitTx = $machine->debit(500.0, 'test debit', $user->id, null);
    $machine->refresh();
    $check('debit(500) reduces balance to 49500', (float) $machine->balance === ($initialBalance - 500), $failures);
    $check('debit returns FawryMachineTransaction', $debitTx instanceof FawryMachineTransaction, $failures);
    $check('debit transaction.type = debit', $debitTx->type === 'debit', $failures);
    $check('debit transaction.amount = 500', (float) $debitTx->amount === 500.0, $failures);
    $check('debit transaction.balance_before = initial', (float) $debitTx->balance_before === $initialBalance, $failures);
    $check('debit transaction.balance_after = 49500', (float) $debitTx->balance_after === ($initialBalance - 500), $failures);

    $creditTx = $machine->credit(200.0, 'test credit', $user->id, null);
    $machine->refresh();
    $check('credit(200) restores balance to 49700', (float) $machine->balance === ($initialBalance - 300), $failures);
    $check('credit transaction.type = credit', $creditTx->type === 'credit', $failures);
    $check('credit transaction.amount = 200', (float) $creditTx->amount === 200.0, $failures);
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ② Boot guard blocks direct balance mutation
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ② Boot guard blocks direct balance mutation ━━';

    $beforeBlocked = (float) $machine->fresh()->balance;
    $blocked = false;
    $exceptionMessage = '';
    try {
        // Bypass the disable flag — try to update balance directly
        $machine->balance = $beforeBlocked + 999;
        $machine->save();
    } catch (\RuntimeException $e) {
        $blocked = true;
        $exceptionMessage = $e->getMessage();
    }
    $machine->refresh();
    $check('direct $machine->save() with dirty balance is BLOCKED', $blocked, $failures);
    $check(
        'exception mentions sanctioned paths (debit/credit/FawryMachineRechargeService)',
        str_contains($exceptionMessage, 'debit')
            || str_contains($exceptionMessage, 'credit')
            || str_contains($exceptionMessage, 'FawryMachineRechargeService'),
        $failures
    );
    $check(
        'balance UNCHANGED after blocked mutation',
        (float) $machine->balance === $beforeBlocked,
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ③ Boot guard allows mutation inside LedgerBalanceMutationGuard
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ③ Boot guard allows mutation inside LedgerBalanceMutationGuard ━━';

    $beforeGuard = (float) $machine->fresh()->balance;
    $ranOk = false;
    LedgerBalanceMutationGuard::run(function () use ($machine, &$ranOk) {
        $machine->balance = (float) $machine->balance + 100;
        $machine->save();
        $ranOk = true;
    });
    $machine->refresh();
    $check('mutation INSIDE LedgerBalanceMutationGuard is ALLOWED', $ranOk, $failures);
    $check(
        'balance increased by 100 inside the guard',
        (float) $machine->balance === ($beforeGuard + 100),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ④ FawryMachineRechargeService creates GL Transaction link
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ④ FawryMachineRechargeService creates GL link ━━';

    // Create a fawry cashbox to act as the source of the recharge
    $cashbox = Account::query()->where('type', AccountType::Cashbox)->where('module_type', 'fawry')->where('is_active', true)->first();
    if (! $cashbox) {
        $cashbox = Account::query()->create([
            'name' => 'P-B Fawry Cashbox',
            'type' => AccountType::Cashbox,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'owner_type' => 'office',
            'balance' => 100000,
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $out[] = "Created fawry cashbox #{$cashbox->id}";
    }

    $beforeRecharge = (float) $machine->fresh()->balance;
    $beforeCashbox = (float) $cashbox->fresh()->balance;

    $rechargeService = app(FawryMachineRechargeService::class);
    $rechargeResult = $rechargeService->rechargeFromAccount($machine->fresh(), $cashbox->fresh(), 1000.0, 'Phase B test');
    $machine->refresh();
    $cashbox->refresh();

    $check(
        'machine balance increased by 1000',
        (float) $machine->balance === ($beforeRecharge + 1000),
        $failures
    );
    $check(
        'cashbox balance decreased by 1000',
        (float) $cashbox->balance === ($beforeCashbox - 1000),
        $failures
    );
    $check(
        'recharge result has FawryMachineTransaction',
        $rechargeResult['machine_transaction'] instanceof FawryMachineTransaction,
        $failures
    );

    // Verify GL Transaction was created with related_type=FawryMachine::class
    $glTx = Transaction::where('related_type', FawryMachine::class)
        ->where('related_id', $machine->id)
        ->where('module', TransactionModule::Fawry->value)
        ->latest('id')
        ->first();
    $check('GL Transaction exists for FawryMachine recharge', $glTx !== null, $failures);
    if ($glTx) {
        // module is cast to TransactionModule enum; compare via ->value
        $glModuleValue = $glTx->module instanceof TransactionModule
            ? $glTx->module->value
            : (string) $glTx->module;
        $check(
            'GL Transaction.module = fawry',
            $glModuleValue === TransactionModule::Fawry->value,
            $failures
        );
        $check(
            'GL Transaction.from_account_id = cashbox',
            (int) $glTx->from_account_id === (int) $cashbox->id,
            $failures
        );
        $check(
            'GL Transaction.to_account_id = prepaid_fawry',
            (int) $glTx->to_account_id === (int) Account::where('module_type', 'fawry')->where('type', 'owner')->value('id'),
            $failures
        );
        $check('GL Transaction.amount = 1000', (float) $glTx->amount === 1000.0, $failures);
    }
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑤ Original FawryTransactionService still works (uses machine->debit)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑤ Original FawryTransactionService still works ━━';

    $walletType = \App\Models\Wallet\WalletType::query()->first();
    if (! $walletType) {
        $walletType = \App\Models\Wallet\WalletType::query()->create([
            'name' => 'P-B Wallet Type',
            'code' => 'p_b_wallet',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    $fawryTxService = app(FawryTransactionService::class);

    $beforeMachineBalance = (float) $machine->fresh()->balance;
    $fawryTx = $fawryTxService->createTransaction([
        'operation_type' => 'charge',
        'client_name' => 'P-B Walkin',
        'client_amount' => 100,
        'fawry_price' => 100,
        'selling_price' => 100,
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'payment_method' => 'cash',
        'amount' => 100,
        'fawry_machine_id' => $machine->id,
    ]);
    $machine->refresh();
    $check('FawryTransaction created with machine', $fawryTx->id > 0, $failures);
    $check(
        'machine balance decreased by fawry_price=100 (via debit)',
        (float) $machine->balance === ($beforeMachineBalance - 100),
        $failures
    );

    // ═══════════════════════════════════════════════════════════════
    // ⑥ deleteTransaction reverses the machine credit (no regression)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑥ deleteTransaction() reverses machine credit ━━';

    $beforeDeleteBalance = (float) $machine->fresh()->balance;
    $fawryTxService->deleteTransaction($fawryTx);
    $machine->refresh();
    $check(
        'machine balance restored after delete (via credit)',
        (float) $machine->balance === ($beforeDeleteBalance + 100),
        $failures
    );
    $echo();

} catch (\Throwable $e) {
    $failures[] = 'EXCEPTION: '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')';
    $out[] = '❌ EXCEPTION: '.$e->getMessage();
    $out[] = '   at '.$e->getFile().':'.$e->getLine();
    $echo();
} finally {
    DB::rollBack();
    $out[] = '═══ Final Verdict ═══';
    if ($failures === []) {
        $out[] = '✅ ALL CHECKS PASSED — Phase B contract honored';
    } else {
        $out[] = '❌ '.count($failures).' CHECK(S) FAILED:';
        foreach ($failures as $f) {
            $out[] = '   • '.$f;
        }
    }

    $result = [
        'success' => $failures === [],
        'failed_count' => count($failures),
        'failed_labels' => $failures,
        'ran_at' => now()->toIso8601String(),
    ];

    @file_put_contents(
        storage_path('logs/phase_b_fawry_result.json'),
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $out[] = '';
    $out[] = 'Result file: '.storage_path('logs/phase_b_fawry_result.json');
    echo implode(PHP_EOL, $out).PHP_EOL;

    exit($failures === [] ? 0 : 1);
}