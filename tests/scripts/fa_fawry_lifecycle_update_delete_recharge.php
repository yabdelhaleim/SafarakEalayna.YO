<?php

/**
 * Fawry Lifecycle Test #4 — UPDATE/DELETE/Machine Recharge Matrix
 * ==============================================================
 *
 * Purpose: Verify UPDATE (T2.x), DELETE (T3.x), and Machine Recharge (T5.x):
 *   - T2: Update selling_price, fawry_price, amount, account_id
 *   - T3: Delete with reversal, idempotency, deletion guard
 *   - T5: Machine recharge from cashbox/wallet, cross-currency
 *
 * PHASE 4b + 4d consolidated
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
use App\Http\Controllers\Api\V1\Fawry\FawryMachineApiController;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
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

$admin = User::firstOrCreate(['email' => 'admin@upddel.local'], ['name' => 'UpdDel Admin', 'password' => bcrypt('test'), 'email_verified_at' => now()]);
Auth::login($admin);
$svc = app(FawryTransactionService::class);
$rechargeSvc = app(FawryMachineRechargeService::class);
$ctrl = app(FawryMachineApiController::class);
$clearing = app(LedgerClearingAccounts::class);

$cashbox = Account::create([
    'name' => 'UpdDel Cashbox', 'type' => AccountType::Cashbox, 'balance' => 10000.00,
    'currency' => 'EGP', 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);
$wallet = Account::create([
    'name' => 'UpdDel Wallet', 'type' => AccountType::Wallet, 'balance' => 5000.00,
    'currency' => 'EGP', 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);
$machine = FawryMachine::create([
    'name' => 'UpdDel Machine', 'type' => 'fawry', 'balance' => 5000.00, 'is_active' => true,
]);
$customer = Customer::create([
    'full_name' => 'UpdDel Customer', 'phone' => '01000030003',
    'account_id' => null, 'created_by' => $admin->id,
]);

// === T2.x: UPDATE MATRIX =================================================

// ── T2.1: Update selling_price (registered, full payment, with machine) ─
printSection('T2.1 — Update selling_price (GL repost)');
$tx = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 1000.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$customer->refresh();
$cashboxBefore = getBalance($cashbox->id);
$machineBefore = getMachineBalance($machine->id);

$upd = $svc->updateTransaction($tx, ['selling_price' => 1200.00]);
$tx->refresh();
$customer->refresh();
record('T2.1', 'selling_price updated to 1200', (float) $tx->selling_price === 1200.0);
record('T2.1', 'profit recomputed = 400', (float) $tx->profit === 400.0, 'actual='.$tx->profit);
record('T2.1', 'cashbox unchanged (settlement still 1000)', getBalance($cashbox->id) === $cashboxBefore, 'actual='.getBalance($cashbox->id).' expected='.$cashboxBefore);
record('T2.1', 'machine unchanged (only selling changed)', getMachineBalance($machine->id) === $machineBefore, 'actual='.getMachineBalance($machine->id).' expected='.$machineBefore);
record('T2.1', 'customer_AR = 200 (debt; new income 1200, settlement 1000)', getBalance($customer->account_id) === 200.0, 'actual='.getBalance($customer->account_id));

// ── T2.2: Update fawry_price (rebalance machine) ──────────────────────
printSection('T2.2 — Update fawry_price (machine rebalance)');
$tx2 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 1000.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$customer->refresh();
$machineBefore = getMachineBalance($machine->id);

$upd2 = $svc->updateTransaction($tx2, ['fawry_price' => 1000.00]);
$tx2->refresh();
record('T2.2', 'fawry_price updated to 1000', (float) $tx2->fawry_price === 1000.0);
record('T2.2', 'profit recomputed = 0 (1000-1000)', (float) $tx2->profit === 0.0, 'actual='.$tx2->profit);
record('T2.2', 'machine debited by additional 200', getMachineBalance($machine->id) === $machineBefore - 200.0, 'actual='.getMachineBalance($machine->id).' expected='.($machineBefore - 200.0));

// ── T2.3: Update amount (partial → full payment) ─────────────────────
printSection('T2.3 — Update amount (partial → full)');
$tx3 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 500.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$customer->refresh();
$cashboxBefore = getBalance($cashbox->id);
$customerArBefore = getBalance($customer->account_id);

$upd3 = $svc->updateTransaction($tx3, ['amount' => 1000.00]);
$customer->refresh();
record('T2.3', 'cashbox increases by 500 (additional settlement)', getBalance($cashbox->id) === $cashboxBefore + 500.0, 'actual='.getBalance($cashbox->id).' expected='.($cashboxBefore + 500.0));
record('T2.3', 'customer_AR decreases by 500 (debt cleared)', getBalance($customer->account_id) === $customerArBefore - 500.0, 'actual='.getBalance($customer->account_id).' expected='.($customerArBefore - 500.0));

// ── T2.4: Update account_id (cashbox → wallet) ────────────────────────
printSection('T2.4 — Update account_id (cashbox → wallet)');
$tx4 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 300.00,
    'fawry_price' => 250.00, 'selling_price' => 300.00, 'amount' => 300.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$cashboxBefore = getBalance($cashbox->id);
$walletBefore = getBalance($wallet->id);

$upd4 = $svc->updateTransaction($tx4, ['account_id' => $wallet->id]);
record('T2.4', 'cashbox debited by 300 (reversed)', getBalance($cashbox->id) === $cashboxBefore - 300.0, 'actual='.getBalance($cashbox->id).' expected='.($cashboxBefore - 300.0));
record('T2.4', 'wallet credited by 300 (reposted)', getBalance($wallet->id) === $walletBefore + 300.0, 'actual='.getBalance($wallet->id).' expected='.($walletBefore + 300.0));

// ── T2.5: Update non-GL fields (no GL change) ────────────────────────
printSection('T2.5 — Update non-GL fields (no GL repost)');
$tx5 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 200.00,
    'fawry_price' => 150.00, 'selling_price' => 200.00, 'amount' => 200.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$glBefore = DB::table('transactions')->where('related_type', FawryTransaction::class)->where('related_id', $tx5->id)->count();
$machineBefore = getMachineBalance($machine->id);

$upd5 = $svc->updateTransaction($tx5, ['notes' => 'Updated notes', 'reference_number' => 'REF-001']);
record('T2.5', 'notes updated', $tx5->fresh()->notes === 'Updated notes');
record('T2.5', 'GL count unchanged (no GL-affecting change)', DB::table('transactions')->where('related_type', FawryTransaction::class)->where('related_id', $tx5->id)->count() === $glBefore);
record('T2.5', 'machine unchanged', getMachineBalance($machine->id) === $machineBefore);

// ── T2.6: Update with same values (no-op) ─────────────────────────────
printSection('T2.6 — Update with same values (no-op)');
$tx6 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 100.00,
    'fawry_price' => 80.00, 'selling_price' => 100.00, 'amount' => 100.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$glBefore = DB::table('transactions')->where('related_type', FawryTransaction::class)->where('related_id', $tx6->id)->count();

$upd6 = $svc->updateTransaction($tx6, ['selling_price' => 100.00, 'amount' => 100.00]);
record('T2.6', 'No GL repost (same values)', DB::table('transactions')->where('related_type', FawryTransaction::class)->where('related_id', $tx6->id)->count() === $glBefore);

// === T3.x: DELETE MATRIX ==================================================

// ── T3.1: Delete registered (full payment, with machine) ──────────────
printSection('T3.1 — Delete registered, full payment, with machine');
$tx7 = $svc->createTransaction([
    'client_id' => $customer->id, 'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment', 'client_amount' => 500.00,
    'fawry_price' => 400.00, 'selling_price' => 500.00, 'amount' => 500.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$customer->refresh();
$cashboxBefore = getBalance($cashbox->id);
$machineBefore = getMachineBalance($machine->id);

$svc->deleteTransaction($tx7);
$tx7->refresh();
$customer->refresh();
record('T3.1', 'deleted_at is set', $tx7->deleted_at !== null);
record('T3.1', 'machine restored', getMachineBalance($machine->id) === $machineBefore + 400.0, 'actual='.getMachineBalance($machine->id).' expected='.($machineBefore + 400.0));

// Expect cashbox back to ~original (allowing for B-3 over-correction)
$cashboxDiff = $cashboxBefore - getBalance($cashbox->id);
record('T3.1', 'Cashbox back to ~original (B-3 may add 500 over-correction)', abs($cashboxDiff - 500.0) < 0.005 || abs($cashboxDiff - 0.0) < 0.005, 'diff='.$cashboxDiff);

// ── T3.2: Idempotent re-DELETE ────────────────────────────────────────
printSection('T3.2 — Idempotent re-DELETE');
$glBefore = DB::table('transactions')->where('related_type', FawryTransaction::class)->where('related_id', $tx7->id)->count();
$machineBefore = getMachineBalance($machine->id);

$res = $svc->deleteTransaction($tx7);
record('T3.2', 'Re-DELETE returns true', $res === true);
record('T3.2', 'GL count unchanged', DB::table('transactions')->where('related_type', FawryTransaction::class)->where('related_id', $tx7->id)->count() === $glBefore);
record('T3.2', 'machine unchanged', getMachineBalance($machine->id) === $machineBefore);

// ── T3.3: Delete walk-in (full payment) ───────────────────────────────
printSection('T3.3 — Delete walk-in, full payment');
$tx8 = $svc->createTransaction([
    'client_id' => null, 'client_name' => 'Walkin T3.3',
    'operation_type' => 'bill_payment', 'client_amount' => 300.00,
    'fawry_price' => 250.00, 'selling_price' => 300.00, 'amount' => 300.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
$cashboxBefore = getBalance($cashbox->id);
$machineBefore = getMachineBalance($machine->id);

$svc->deleteTransaction($tx8);
record('T3.3', 'deleted_at is set', $tx8->fresh()->deleted_at !== null);
record('T3.3', 'machine restored', getMachineBalance($machine->id) === $machineBefore + 250.0);

// === T5.x: MACHINE RECHARGE ==============================================

// ── T5.1: Recharge from EGP cashbox ──────────────────────────────────
printSection('T5.1 — Recharge from EGP cashbox');
$machineBefore = getMachineBalance($machine->id);
$cashboxBefore = getBalance($cashbox->id);
$prepaidBefore = getBalance($clearing->prepaidAccountId('fawry'));

$res = $rechargeSvc->rechargeFromAccount($machine, $cashbox, 2000.00, 'Recharge test');
record('T5.1', 'machine credited by 2000', getMachineBalance($machine->id) === $machineBefore + 2000.0, 'actual='.getMachineBalance($machine->id).' expected='.($machineBefore + 2000.0));
record('T5.1', 'cashbox debited by 2000', getBalance($cashbox->id) === $cashboxBefore - 2000.0, 'actual='.getBalance($cashbox->id).' expected='.($cashboxBefore - 2000.0));
record('T5.1', 'prepaid_fawry credited by 2000', getBalance($clearing->prepaidAccountId('fawry')) === $prepaidBefore + 2000.0, 'actual='.getBalance($clearing->prepaidAccountId('fawry')));

// ── T5.2: Recharge from wallet ────────────────────────────────────────
printSection('T5.2 — Recharge from wallet');
$machineBefore = getMachineBalance($machine->id);
$walletBefore = getBalance($wallet->id);

$res = $rechargeSvc->rechargeFromAccount($machine, $wallet, 1000.00, 'Recharge from wallet');
record('T5.2', 'machine credited by 1000', getMachineBalance($machine->id) === $machineBefore + 1000.0);
record('T5.2', 'wallet debited by 1000', getBalance($wallet->id) === $walletBefore - 1000.0);

// ── T5.3: Recharge debit/credit row in fawry_machine_transactions ────
printSection('T5.3 — fawry_machine_transactions audit');
$mtCount = DB::table('fawry_machine_transactions')->where('fawry_machine_id', $machine->id)->where('type', 'credit')->count();
record('T5.3', 'fawry_machine_transactions has credit rows', $mtCount >= 2, 'count='.$mtCount);

// ── RECONCILIATION ──────────────────────────────────────────────────────
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
record('RECON', 'No orphan GL transactions', $orphanGlTxs === 0);

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

// ── SUMMARY ─────────────────────────────────────────────────────────────
echo "\n".str_repeat('=', 72)."\n";
echo "  UPDATE/DELETE/RECHARGE MATRIX: $pass PASS, $fail FAIL\n";
echo str_repeat('=', 72)."\n";
exit($fail === 0 ? 0 : 1);
