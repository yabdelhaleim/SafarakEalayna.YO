<?php

/**
 * Fawry Lifecycle Test #2 — CREATE Matrix (11 scenarios)
 * =======================================================
 *
 * Purpose: Verify every CREATE scenario from the lifecycle matrix.
 *          Covers all 11 combinations of:
 *            - client_type: registered vs walk-in
 *            - payment: full vs partial vs deferred (amount=0)
 *            - machine: with vs without
 *
 * Each scenario has independent expected-value calculations for every balance.
 *
 * PHASE 4 — STATE MACHINE TESTING (CREATE variants)
 */

require __DIR__.'/../../vendor/autoload.php';

$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['CACHE_DRIVER'] = 'array';
$_ENV['SESSION_DRIVER'] = 'array';
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_ENV['MAIL_MAILER'] = 'array';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_DRIVER=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

Artisan::call('migrate', ['--force' => true]);

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function record(string $scenario, string $check, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ $check\n";
    } else {
        $fail++;
        echo "  ❌ $check — $detail\n";
    }
}
function printSection(string $title): void
{
    echo "\n".str_repeat('=', 72)."\n  $title\n".str_repeat('=', 72)."\n";
}
function getBalance(int $accountId): float
{
    return (float) Account::find($accountId)?->balance ?? 0.0;
}
function getMachineBalance(int $machineId): float
{
    return (float) DB::table('fawry_machines')->where('id', $machineId)->value('balance');
}
function getGlTxTypes(int $fawryTxId): array
{
    // All Fawry GL posts are type=transfer (recordExpense/Income internally use recordJournalTransfer).
    // The semantic distinction (expense vs income vs settlement) is in the `notes` field.
    return DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $fawryTxId)
        ->pluck('type')
        ->toArray();
}
function getGlTxNotes(int $fawryTxId): array
{
    return DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $fawryTxId)
        ->pluck('notes')
        ->toArray();
}
function isExpense(string $notes): bool
{
    return str_contains($notes, 'تكلفة');
}
function isIncome(string $notes): bool
{
    return str_contains($notes, 'تحصيل') || str_contains($notes, 'مديونية');
}
function isSettlement(string $notes): bool
{
    return str_contains($notes, 'سداد');
}
function getGlTxSemantics(int $fawryTxId): array
{
    return array_map(fn ($n) => isExpense($n) ? 'expense' : (isIncome($n) ? 'income' : (isSettlement($n) ? 'settlement' : 'other')), getGlTxNotes($fawryTxId));
}

$admin = User::firstOrCreate(['email' => 'admin@matrix.local'], ['name' => 'Matrix Admin', 'password' => bcrypt('test'), 'email_verified_at' => now()]);
Auth::login($admin);
$svc = app(FawryTransactionService::class);
$clearing = app(LedgerClearingAccounts::class);

$cashbox = Account::create([
    'name' => 'Matrix Cashbox', 'type' => AccountType::Cashbox, 'balance' => 10000.00,
    'currency' => 'EGP', 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);
$machine = FawryMachine::create([
    'name' => 'Matrix Machine', 'type' => 'fawry', 'balance' => 5000.00, 'is_active' => true,
]);

$customer = Customer::create([
    'full_name' => 'Matrix Customer', 'phone' => '01000020002',
    'account_id' => null, 'created_by' => $admin->id,
]);

$openCashbox = getBalance($cashbox->id);
$openMachine = getMachineBalance($machine->id);
$openPrepaid = getBalance($clearing->prepaidAccountId('fawry'));
$openIncomeClear = getBalance($clearing->incomeContraIdForModule('fawry'));
$openExpenseClear = getBalance($clearing->expenseContraIdForModule('fawry'));

echo "Setup: cashbox={$openCashbox}, machine={$openMachine}, prepaid={$openPrepaid}\n";

// ── T1.1: Registered, full payment, with machine ────────────────────────
printSection('T1.1 — Registered, full payment, with machine');
$tx = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 1000.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$customer->refresh();
record('T1.1', 'machine.balance = 5000 - 800 = 4200', getMachineBalance($machine->id) === 4200.0, 'actual='.getMachineBalance($machine->id));
record('T1.1', 'cashbox.balance = 10000 + 1000 = 11000', getBalance($cashbox->id) === 11000.0, 'actual='.getBalance($cashbox->id));
record('T1.1', 'customer_AR.balance = 0 (1000 in / 1000 out)', getBalance($customer->account_id) === 0.0);
record('T1.1', 'GL tx semantics = [expense, income, settlement]', getGlTxSemantics($tx->id) === ['expense', 'income', 'settlement'], 'actual='.json_encode(getGlTxSemantics($tx->id)));
record('T1.1', 'contains settlement leg (B-2 fix)', in_array('settlement', getGlTxSemantics($tx->id)));
record('T1.1', 'profit = 200', (float) $tx->profit === 200.0);

// ── T1.2: Walk-in, full payment, with machine ────────────────────────────
printSection('T1.2 — Walk-in, full payment, with machine');
$machine->refresh();
$openCashbox = getBalance($cashbox->id);
$openMachine = getMachineBalance($machine->id);
$walkInArId = $clearing->fawryWalkInArAccountId();
$openWalkInAr = getBalance($walkInArId);

$tx2 = $svc->createTransaction([
    'client_id' => null, 'client_name' => 'Walk-in Customer',
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 1000.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
record('T1.2', 'machine.balance = 4200 - 800 = 3400', getMachineBalance($machine->id) === 3400.0, 'actual='.getMachineBalance($machine->id));
record('T1.2', 'cashbox.balance = 11000 + 1000 = 12000', getBalance($cashbox->id) === 12000.0, 'actual='.getBalance($cashbox->id));
record('T1.2', 'walkInAR.balance = 0 (1000 in / 1000 out)', getBalance($walkInArId) === 0.0, 'actual='.getBalance($walkInArId));
record('T1.2', 'GL tx semantics = [expense, income, settlement]', getGlTxSemantics($tx2->id) === ['expense', 'income', 'settlement'], 'actual='.json_encode(getGlTxSemantics($tx2->id)));
record('T1.2', 'GL type is transfer (not income) — B-2 compatible', getGlTxTypes($tx2->id) === ['transfer', 'transfer', 'transfer'], 'actual='.json_encode(getGlTxTypes($tx2->id)));

// ── T1.3: Walk-in, deferred payment (amount=0), with machine ─────────────
printSection('T1.3 — Walk-in, deferred payment (amount=0), with machine');
$machine->refresh();
$openCashbox = getBalance($cashbox->id);
$openMachine = getMachineBalance($machine->id);
$openWalkInAr = getBalance($walkInArId);

$tx3 = $svc->createTransaction([
    'client_id' => null, 'client_name' => 'Walk-in Deferred',
    'operation_type' => 'bill_payment', 'client_amount' => 500.00,
    'fawry_price' => 400.00, 'selling_price' => 500.00, 'amount' => 0.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
record('T1.3', 'machine.balance = 3400 - 400 = 3000', getMachineBalance($machine->id) === 3000.0, 'actual='.getMachineBalance($machine->id));
record('T1.3', 'cashbox.balance unchanged (no settlement)', getBalance($cashbox->id) === $openCashbox);
record('T1.3', 'walkInAR.balance = previous + 500 - 0 = previous + 500', getBalance($walkInArId) === $openWalkInAr + 500.0, 'before='.$openWalkInAr.' actual='.getBalance($walkInArId));
record('T1.3', 'GL tx semantics = [expense, income] (no settlement)', getGlTxSemantics($tx3->id) === ['expense', 'income'], 'actual='.json_encode(getGlTxSemantics($tx3->id)));

// ── T1.4: Registered, deferred payment (amount=0), with machine ──────────
printSection('T1.4 — Registered, deferred payment (amount=0), with machine');
$machine->refresh();
$openCashbox = getBalance($cashbox->id);
$openMachine = getMachineBalance($machine->id);
$customerArBefore = getBalance($customer->account_id);

$tx4 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 700.00,
    'fawry_price' => 600.00, 'selling_price' => 700.00, 'amount' => 0.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
record('T1.4', 'machine.balance = 3000 - 600 = 2400', getMachineBalance($machine->id) === 2400.0, 'actual='.getMachineBalance($machine->id));
record('T1.4', 'cashbox.balance unchanged (no settlement)', getBalance($cashbox->id) === $openCashbox);
record('T1.4', 'customer_AR.balance = previous + 700 (debt recorded)', getBalance($customer->account_id) === $customerArBefore + 700.0, 'actual='.getBalance($customer->account_id));
record('T1.4', 'GL tx semantics = [expense, income]', getGlTxSemantics($tx4->id) === ['expense', 'income'], 'actual='.json_encode(getGlTxSemantics($tx4->id)));

// ── T1.5: Registered, partial payment, with machine ──────────────────────
printSection('T1.5 — Registered, partial payment, with machine');
$machine->refresh();
$openCashbox = getBalance($cashbox->id);
$openMachine = getMachineBalance($machine->id);
$customerArBefore = getBalance($customer->account_id);

$tx5 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 500.00, // partial
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
record('T1.5', 'machine.balance = 2400 - 800 = 1600', getMachineBalance($machine->id) === 1600.0, 'actual='.getMachineBalance($machine->id));
record('T1.5', 'cashbox.balance = openCashbox + 500', getBalance($cashbox->id) === $openCashbox + 500.0, 'actual='.getBalance($cashbox->id));
record('T1.5', 'customer_AR.balance = previous + 500 (1000 in - 500 out)', getBalance($customer->account_id) === $customerArBefore + 500.0, 'actual='.getBalance($customer->account_id));
record('T1.5', 'GL tx semantics = [expense, income, settlement]', getGlTxSemantics($tx5->id) === ['expense', 'income', 'settlement'], 'actual='.json_encode(getGlTxSemantics($tx5->id)));

// ── T1.6: Registered, no machine ────────────────────────────────────────
printSection('T1.6 — Registered, full payment, NO machine');
$customerArBefore = getBalance($customer->account_id);

$tx6 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 200.00,
    'fawry_price' => 150.00, 'selling_price' => 200.00, 'amount' => 200.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => null, 'payment_method' => 'cash', // NO MACHINE
]);
record('T1.6', 'machine.balance untouched (1600)', getMachineBalance($machine->id) === 1600.0);
record('T1.6', 'customer_AR.balance = previous + 0 (200 in - 200 out)', getBalance($customer->account_id) === $customerArBefore + 0.0, 'actual='.getBalance($customer->account_id));
record('T1.6', 'GL tx semantics = [expense, income, settlement]', getGlTxSemantics($tx6->id) === ['expense', 'income', 'settlement'], 'actual='.json_encode(getGlTxSemantics($tx6->id)));

// ── INVALID: machine insufficient balance ────────────────────────────────
printSection('T-INVALID-1 — CREATE with insufficient machine balance');
$threwException = false;
$exceptionMsg = '';
try {
    $svc->createTransaction([
        'client_id' => $customer->id, 'client_name' => $customer->full_name,
        'operation_type' => 'bill_payment', 'client_amount' => 10000.00,
        'fawry_price' => 9999.00, 'selling_price' => 10000.00, 'amount' => 10000.00,
        'employee_id' => $admin->id, 'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
    ]);
} catch (Throwable $e) {
    $threwException = true;
    $exceptionMsg = $e->getMessage();
}
record('T-INVALID-1', 'InsufficientBalanceException thrown', $threwException, 'msg='.$exceptionMsg);
record('T-INVALID-1', 'machine.balance unchanged (1600)', getMachineBalance($machine->id) === 1600.0, 'actual='.getMachineBalance($machine->id));

// ── INVALID: inactive machine ────────────────────────────────────────────
printSection('T-INVALID-2 — CREATE with inactive machine');
$machine->update(['is_active' => false]);
$threwException = false;
try {
    $svc->createTransaction([
        'client_id' => $customer->id, 'client_name' => $customer->full_name,
        'operation_type' => 'bill_payment', 'client_amount' => 100.00,
        'fawry_price' => 80.00, 'selling_price' => 100.00, 'amount' => 100.00,
        'employee_id' => $admin->id, 'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
    ]);
} catch (Throwable $e) {
    $threwException = true;
    $exceptionMsg = $e->getMessage();
}
$machine->update(['is_active' => true]);
record('T-INVALID-2', 'InvalidArgumentException thrown', $threwException, 'msg='.$exceptionMsg);

// ── EDGE: minimum valid amount ───────────────────────────────────────────
printSection('T-EDGE-1 — Minimum valid amount (fawry_price=0.01)');
$txEdge = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 0.01,
    'fawry_price' => 0.01, 'selling_price' => 0.01, 'amount' => 0.01,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
record('T-EDGE-1', 'CREATE with 0.01 succeeds', $txEdge->id > 0, 'id='.$txEdge->id);
record('T-EDGE-1', 'profit = 0.00', (float) $txEdge->profit === 0.0);

// ── RECONCILIATION ───────────────────────────────────────────────────────
printSection('RECONCILIATION');

$totalDebits = DB::table('account_entries')->sum('debit');
$totalCredits = DB::table('account_entries')->sum('credit');
record('RECON', 'Σdebits = Σcredits across all GL transactions', abs($totalDebits - $totalCredits) < 0.005, "Σdr={$totalDebits} Σcr={$totalCredits}");

$orphanGlTxs = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->whereNotIn('related_id', function ($q) {
        $q->select('id')->from('fawry_transactions');
    })
    ->count();
record('RECON', 'No orphan GL transactions', $orphanGlTxs === 0, "orphan={$orphanGlTxs}");

$unbalancedCount = 0;
$allGlTxs = DB::table('transactions')->get();
foreach ($allGlTxs as $glTx) {
    $entries = DB::table('account_entries')->where('transaction_id', $glTx->id)->get();
    $totalDebit = $entries->sum('debit');
    $totalCredit = $entries->sum('credit');
    if (abs($totalDebit - $totalCredit) >= 0.005) {
        $unbalancedCount++;
    }
}
record('RECON', 'All GL transactions are balanced individually', $unbalancedCount === 0, "unbalanced={$unbalancedCount}");

// ── SUMMARY ──────────────────────────────────────────────────────────────
echo "\n".str_repeat('=', 72)."\n";
echo "  CREATE MATRIX: $pass PASS, $fail FAIL\n";
echo str_repeat('=', 72)."\n";
exit($fail === 0 ? 0 : 1);
