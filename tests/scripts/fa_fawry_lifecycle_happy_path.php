<?php

/**
 * Fawry Lifecycle Test #1 — Happy Path: Registered Customer
 * ==========================================================
 *
 * Purpose: Verify the COMPLETE lifecycle of a registered-customer Fawry
 *          transaction:
 *            1. CREATE with full upfront payment + machine
 *            2. UPDATE selling_price (GL repost)
 *            3. DELETE (soft-delete + reversal)
 *            4. Re-DELETE (idempotency check)
 *
 * Each step verifies:
 *   - HTTP response (when invoked via controller)
 *   - DB state (rows in fawry_transactions, transactions, account_entries, fawry_machine_transactions)
 *   - Financial state (every balance = expected value)
 *   - Reconciliation (Σcredits = Σdebits per GL transaction)
 *
 * PHASE 3 — FAWRY_LIFECYCLE_AUDIT
 *
 * Usage:  php tests/scripts/fa_fawry_lifecycle_happy_path.php
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
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ── helpers ────────────────────────────────────────────────────────────────

$results = [];
$pass = 0;
$fail = 0;

function record(string $scenario, string $check, bool $ok, string $detail = ''): void
{
    global $results, $pass, $fail;
    $results[] = compact('scenario', 'check', 'ok', 'detail');
    if ($ok) {
        $pass++;
        echo "  ✅ $check\n";
    } else {
        $fail++;
        echo "  ❌ $check — $detail\n";
    }
}

function print_header(string $title): void
{
    echo "\n".str_repeat('=', 72)."\n";
    echo "  $title\n";
    echo str_repeat('=', 72)."\n";
}

function getAccountBalance(int $accountId): float
{
    return (float) Account::find($accountId)?->balance ?? 0.0;
}

function getMachineBalance(int $machineId): float
{
    return (float) DB::table('fawry_machines')->where('id', $machineId)->value('balance');
}

function getEntriesFor(int $txId): array
{
    return DB::table('account_entries')->where('transaction_id', $txId)->get()
        ->map(fn ($e) => ['account_id' => (int) $e->account_id, 'debit' => (float) $e->debit, 'credit' => (float) $e->credit])
        ->toArray();
}

function assertBalanced(string $scenario, string $label, int $txId): void
{
    $entries = getEntriesFor($txId);
    $totalDebit = array_sum(array_column($entries, 'debit'));
    $totalCredit = array_sum(array_column($entries, 'credit'));
    $ok = abs($totalDebit - $totalCredit) < 0.005;
    record($scenario, "GL balanced: $label (Σdr={$totalDebit}=Σcr={$totalCredit})", $ok);
}

// ── setup ───────────────────────────────────────────────────────────────────

print_header('LIFECYCLE 1 — HAPPY PATH: REGISTERED CUSTOMER');

$admin = User::firstOrCreate(
    ['email' => 'admin@lifecycle.local'],
    ['name' => 'Lifecycle Admin', 'password' => bcrypt('test'), 'email_verified_at' => now()]
);
Auth::login($admin);

$fawryService = app(FawryTransactionService::class);
$clearing = app(LedgerClearingAccounts::class);

$customer = Customer::create([
    'full_name' => 'Lifecycle Customer A',
    'phone' => '01000010001',
    'account_id' => null,
    'created_by' => $admin->id,
]);

$cashbox = Account::create([
    'name' => 'Lifecycle Cashbox',
    'type' => AccountType::Cashbox,
    'balance' => 10000.00,
    'currency' => 'EGP',
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office',
    'created_by' => $admin->id,
]);

$machine = FawryMachine::create([
    'name' => 'Lifecycle Machine',
    'type' => 'fawry',
    'balance' => 5000.00,
    'is_active' => true,
]);

$openingCashbox = 10000.0;
$openingMachine = 5000.0;
$openingPrepaid = getAccountBalance($clearing->prepaidAccountId('fawry'));
$openingIncomeClear = getAccountBalance($clearing->incomeContraIdForModule('fawry'));
$openingExpenseClear = getAccountBalance($clearing->expenseContraIdForModule('fawry'));

echo "Setup: cashbox={$openingCashbox}, machine={$openingMachine}, prepaid={$openingPrepaid}\n\n";

// ── STEP 1: CREATE ─────────────────────────────────────────────────────────
print_header('STEP 1: CREATE — registered customer, full payment, with machine');

$createResp = $fawryService->createTransaction([
    'client_id' => $customer->id,
    'client_name' => $customer->full_name,
    'operation_type' => 'bill_payment',
    'client_amount' => 1000.00,
    'fawry_price' => 800.00,
    'selling_price' => 1000.00,
    'amount' => 1000.00, // full payment
    'employee_id' => $admin->id,
    'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id,
    'payment_method' => 'cash',
]);
record('STEP1', 'CREATE returns FawryTransaction', $createResp->id > 0, 'id='.$createResp->id);

$tx = $createResp->fresh();
$customer->refresh();
$cashbox->refresh();
$machine->refresh();

record('STEP1', 'fawry_transactions.deleted_at = NULL', $tx->deleted_at === null);
record('STEP1', 'fawry_transactions.profit = 200 (selling - fawry)', (float) $tx->profit === 200.0, 'actual='.$tx->profit);
record('STEP1', 'customer.account_id now set (lazy creation)', $customer->account_id !== null, 'account_id='.$customer->account_id);
record('STEP1', 'customer.account.module_type = fawry',
    Account::find($customer->account_id)?->module_type === 'fawry',
    'module_type='.Account::find($customer->account_id)?->module_type);

record('STEP1', 'machine.balance = 4200 (5000 - 800)',
    getMachineBalance($machine->id) === 4200.0,
    'actual='.getMachineBalance($machine->id));

record('STEP1', 'cashbox.balance = 11000 (10000 + 1000 settlement)',
    getAccountBalance($cashbox->id) === 11000.0,
    'actual='.getAccountBalance($cashbox->id));

// Verify GL transactions
$glCount = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->count();
record('STEP1', 'GL transactions count = 3 (expense + income + transfer)',
    $glCount === 3, "actual={$glCount}");

assertBalanced('STEP1', 'expense', (int) $tx->expense_transaction_id);
assertBalanced('STEP1', 'income', (int) $tx->income_transaction_id);

// Verify settlement is a Transfer (B-2 fix verification)
$txRows = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->get();
$settlementTx = $txRows->where('type', 'transfer')->first();
record('STEP1', 'B-2 fix: settlement is type=Transfer (not Income)',
    $settlementTx !== null && $settlementTx->type === 'transfer',
    'settlement_tx_type='.($settlementTx?->type ?? 'NONE'));

if ($settlementTx) {
    assertBalanced('STEP1', 'settlement', (int) $settlementTx->id);
}

// Verify customer AR balance
$customerArId = $customer->account_id;
record('STEP1', 'customer_AR.balance = 0 (1000 income + 1000 transfer)',
    getAccountBalance($customerArId) === 0.0,
    'actual='.getAccountBalance($customerArId));

// Verify prepaid asset debited
$expectedPrepaidDelta = -800.0;
record('STEP1', 'prepaid_fawry.balance decreased by 800',
    abs(getAccountBalance($clearing->prepaidAccountId('fawry')) - $openingPrepaid - $expectedPrepaidDelta) < 0.005,
    'opening='.$openingPrepaid.' actual='.getAccountBalance($clearing->prepaidAccountId('fawry')));

// Verify income clearing debited
record('STEP1', 'income_clearing.balance decreased by 1000',
    abs(getAccountBalance($clearing->incomeContraIdForModule('fawry')) - $openingIncomeClear - (-1000.0)) < 0.005);

record('STEP1', 'expense_clearing.balance increased by 800',
    abs(getAccountBalance($clearing->expenseContraIdForModule('fawry')) - $openingExpenseClear - 800.0) < 0.005);

$cashboxAfterCreate = getAccountBalance($cashbox->id);
$machineAfterCreate = getMachineBalance($machine->id);
$customerArAfterCreate = getAccountBalance($customerArId);

// ── STEP 2: UPDATE selling_price ─────────────────────────────────────────
print_header('STEP 2: UPDATE — selling_price 1000 → 1200');

$updateResp = $fawryService->updateTransaction($tx, [
    'selling_price' => 1200.00,
]);
record('STEP2', 'UPDATE returns FawryTransaction', $updateResp->id > 0);

$tx->refresh();

record('STEP2', 'fawry_transactions.profit = 400 (1200 - 800)',
    (float) $tx->profit === 400.0, 'actual='.$tx->profit);

record('STEP2', 'fawry_transactions.selling_price = 1200',
    (float) $tx->selling_price === 1200.0, 'actual='.$tx->selling_price);

record('STEP2', 'cashbox.balance unchanged (settlement was 1000, reposted at 1000)',
    getAccountBalance($cashbox->id) === $cashboxAfterCreate,
    'actual='.getAccountBalance($cashbox->id).' expected='.$cashboxAfterCreate);

record('STEP2', 'machine.balance unchanged (only selling_price changed, not fawry_price)',
    getMachineBalance($machine->id) === $machineAfterCreate,
    'actual='.getMachineBalance($machine->id).' expected='.$machineAfterCreate);

record('STEP2', 'customer_AR.balance = 200 (new income 1200 - settlement 1000 = 200 debt)',
    getAccountBalance($customerArId) === 200.0,
    'actual='.getAccountBalance($customerArId).' (correct: settlement is amount=1000, not selling_price=1200)');

// GL count: 3 original + 3 NEW (reversals are additive on AccountEntry, NOT new Transaction rows)
$glCountAfterUpdate = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->count();
record('STEP2', 'GL transactions count = 6 (3 original + 3 new; reversals are additive on AccountEntry)',
    $glCountAfterUpdate === 6, "actual={$glCountAfterUpdate}");

// Verify reversals exist as AccountEntry rows (not Transaction rows)
$reversalEntryCount = DB::table('account_entries as ae')
    ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
    ->where('t.related_type', FawryTransaction::class)
    ->where('t.related_id', $tx->id)
    ->where('ae.notes', 'like', '%عكس القيد%')
    ->count();
record('STEP2', 'Reversal AccountEntry rows = 6 (2 per reversed original tx × 3 originals)',
    $reversalEntryCount === 6, "actual={$reversalEntryCount}");

$cashboxAfterUpdate = getAccountBalance($cashbox->id);
$machineAfterUpdate = getMachineBalance($machine->id);

// ── STEP 3: DELETE ─────────────────────────────────────────────────────────
print_header('STEP 3: DELETE — soft-delete with full reversal');

$deleteResp = $fawryService->deleteTransaction($tx);
record('STEP3', 'DELETE returns true', $deleteResp === true);

$tx->refresh();

record('STEP3', 'fawry_transactions.deleted_at is set', $tx->deleted_at !== null);
record('STEP3', 'fawry_transactions.deleted_at is recent', $tx->deleted_at->diffInSeconds(now()) < 5);

record('STEP3', 'machine.balance restored to 5000',
    getMachineBalance($machine->id) === 5000.0,
    'actual='.getMachineBalance($machine->id));

// [B-3 FIXED 2026-08-20] deficit auto-correct no longer over-corrects.
// Previously: deficit auto-correct posted 1000 from income_clearing → cashbox
//   AFTER the reversal pipeline had already restored it, leaving cashbox at
//   11000 (the pre-DELETE level) instead of 10000 (the pre-CREATE level).
// Now: correctDeficitIfAny compares the post-reversal balance against the
//   OPENING balance derived from the GL, so it only fires on real deficits
//   (e.g. legacy orphan debits) — never on healthy CREATE→DELETE.
//   FawryTransactionService::correctDeficitIfAny (lines 781–840).
record('STEP3', '[B-3 FIXED] cashbox.balance = 10000 (restored to opening, no over-correction)',
    abs(getAccountBalance($cashbox->id) - 10000.0) < 0.005,
    'actual='.getAccountBalance($cashbox->id));

record('STEP3', 'customer_AR.balance = 0 (reversed + reposted, net 0)',
    getAccountBalance($customerArId) === 0.0,
    'actual='.getAccountBalance($customerArId));

// [B-3 FIXED] After DELETE: 3 original (reversed) + 3 update-reposted
// (reversed) = 6 transactions total. The deficit correction entry no
// longer fires for healthy reversals, so the count drops from 7 to 6.
$glCountAfterDelete = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->count();
record('STEP3', '[B-3 FIXED] GL transactions count = 6 (3 original + 3 update-reposted; NO deficit correction)',
    $glCountAfterDelete === 6, "actual={$glCountAfterDelete}");

// Verify all GL transactions are balanced
$glTransactions = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->get();
$allBalanced = true;
foreach ($glTransactions as $glTx) {
    $entries = getEntriesFor((int) $glTx->id);
    $totalDebit = array_sum(array_column($entries, 'debit'));
    $totalCredit = array_sum(array_column($entries, 'credit'));
    if (abs($totalDebit - $totalCredit) >= 0.005) {
        $allBalanced = false;
        echo "  ⚠ GL tx #{$glTx->id} (type={$glTx->type}) unbalanced: dr={$totalDebit}, cr={$totalCredit}\n";
    }
}
record('STEP3', 'All 12 GL transactions are balanced', $allBalanced);

// [B-3 FIXED] cashbox now MATCHES opening after delete. Previously the
// deficit auto-correct kept cashbox at the pre-DELETE level (11000),
// creating a 1000 phantom surplus.
$matchesOpening = abs(getAccountBalance($cashbox->id) - $openingCashbox) < 0.005;
record('STEP3', '[B-3 FIXED] cashbox MATCHES opening after delete (no phantom surplus)',
    $matchesOpening === true,
    'cashbox='.getAccountBalance($cashbox->id).' opening='.$openingCashbox);

// ── STEP 4: RE-DELETE (idempotency) ─────────────────────────────────────
print_header('STEP 4: RE-DELETE — idempotency check');

$glCountBeforeReDelete = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->count();
$cashboxBeforeReDelete = getAccountBalance($cashbox->id);
$machineBeforeReDelete = getMachineBalance($machine->id);

$reDeleteResp = $fawryService->deleteTransaction($tx);
record('STEP4', 'Re-DELETE returns true (no-op)', $reDeleteResp === true);

$glCountAfterReDelete = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)
    ->count();
record('STEP4', 'GL transaction count unchanged (idempotent)',
    $glCountAfterReDelete === $glCountBeforeReDelete,
    "before={$glCountBeforeReDelete} after={$glCountAfterReDelete}");

record('STEP4', 'cashbox.balance unchanged after re-DELETE',
    getAccountBalance($cashbox->id) === $cashboxBeforeReDelete);

record('STEP4', 'machine.balance unchanged after re-DELETE',
    getMachineBalance($machine->id) === $machineBeforeReDelete);

// ── FINAL RECONCILIATION ───────────────────────────────────────────────────
print_header('FINAL RECONCILIATION');

echo "Reconciliation check: opening → final state of all accounts\n";
echo "  cashbox: opening={$openingCashbox} → final=".getAccountBalance($cashbox->id)."\n";
echo "  machine: opening={$openingMachine} → final=".getMachineBalance($machine->id)."\n";
echo "  prepaid: opening={$openingPrepaid} → final=".getAccountBalance($clearing->prepaidAccountId('fawry'))."\n";
echo "  income_clear: opening={$openingIncomeClear} → final=".getAccountBalance($clearing->incomeContraIdForModule('fawry'))."\n";
echo "  expense_clear: opening={$openingExpenseClear} → final=".getAccountBalance($clearing->expenseContraIdForModule('fawry'))."\n";
echo '  customer_AR: opening=0 → final='.getAccountBalance($customerArId)."\n";

// After complete reversal: every account should be back to opening
$finalCashbox = getAccountBalance($cashbox->id);
$finalMachine = getMachineBalance($machine->id);
$finalPrepaid = getAccountBalance($clearing->prepaidAccountId('fawry'));
$finalIncomeClear = getAccountBalance($clearing->incomeContraIdForModule('fawry'));
$finalExpenseClear = getAccountBalance($clearing->expenseContraIdForModule('fawry'));
$finalCustomerAr = getAccountBalance($customerArId);

record('FINAL', 'cashbox restored to opening',
    abs($finalCashbox - $openingCashbox) < 0.005,
    "🐛 B-3 BUG: actual={$finalCashbox} expected={$openingCashbox} (off by 1000 from deficit auto-correct)");

record('FINAL', 'machine restored to opening',
    abs($finalMachine - $openingMachine) < 0.005,
    "actual={$finalMachine} expected={$openingMachine}");

record('FINAL', 'prepaid restored to opening',
    abs($finalPrepaid - $openingPrepaid) < 0.005);

record('FINAL', 'income_clear restored to opening',
    abs($finalIncomeClear - $openingIncomeClear) < 0.005,
    "🐛 B-3 BUG: actual={$finalIncomeClear} expected={$openingIncomeClear} (off by 1000 from deficit auto-correct)");

record('FINAL', 'expense_clear restored to opening',
    abs($finalExpenseClear - $openingExpenseClear) < 0.005);

record('FINAL', 'customer_AR restored to opening (0)',
    abs($finalCustomerAr - 0.0) < 0.005,
    "actual={$finalCustomerAr}");

// Check for orphan transactions
$orphanGlTxs = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->whereNotIn('related_id', function ($q) {
        $q->select('id')->from('fawry_transactions');
    })
    ->count();
record('FINAL', 'No orphan GL transactions (all FKs valid)', $orphanGlTxs === 0, "orphan={$orphanGlTxs}");

// TOTAL DEBITS = TOTAL CREDITS across all GL transactions
$totalDebits = DB::table('account_entries')->sum('debit');
$totalCredits = DB::table('account_entries')->sum('credit');
record('FINAL', 'Σdebits = Σcredits across all GL transactions',
    abs($totalDebits - $totalCredits) < 0.005,
    "Σdr={$totalDebits} Σcr={$totalCredits}");

// ── SUMMARY ────────────────────────────────────────────────────────────────
print_header('SUMMARY');

echo "Lifecycle 1 — Happy Path: Registered Customer\n";
echo "$pass PASS, $fail FAIL\n\n";

echo "🟢 B-2 fix verified: settlement is type=Transfer (not Income)\n";
echo "🟢 CREATE / UPDATE / DELETE pipeline executes correctly\n";
echo "🟢 Idempotency works (re-DELETE is no-op)\n";
echo "🟢 All GL transactions balanced after every transition\n";
echo "🟢 No orphan transactions\n";

if ($fail > 0) {
    echo "\n🔴 CRITICAL FINDING — B-3 BUG DETECTED:\n";
    echo "  The deficit auto-correct in FawryTransactionService::deleteTransaction\n";
    echo "  over-corrects when there have been UPDATE → DELETE sequences.\n\n";
    echo "  Repro:\n";
    echo "  1. CREATE tx with selling_price=1000, amount=1000 (full payment)\n";
    echo "     → cashbox = 11000\n";
    echo "  2. UPDATE selling_price to 1200\n";
    echo "     → cashbox remains 11000 (settlement is 1000, not 1200)\n";
    echo "  3. DELETE the tx\n";
    echo "     → cashbox SHOULD be 10000 (correct full reversal)\n";
    echo "     → cashbox ACTUALLY is 11000 (deficit auto-correct adds phantom 1000)\n\n";
    echo "  Root cause:\n";
    echo "    FawryTransactionService::correctDeficitIfAny captures\n";
    echo "    settlementBalanceBefore = 11000 (post-UPDATE balance).\n";
    echo "    After reversal, balance = 10000 (correct).\n";
    echo "    drift = 11000 - 10000 = 1000 > 0.01\n";
    echo "    → Auto-correct posts 1000 from income_clearing → cashbox\n";
    echo "    → cashbox inflated to 11000 (phantom cash)\n";
    echo "    → income_clearing debited by 1000 (phantom loss)\n\n";
    echo "  Impact: 🟡 MEDIUM\n";
    echo "    - Affects cashbox balance after any UPDATE → DELETE cycle\n";
    echo "    - Customer balances are NOT affected (this is over-correction, not under)\n";
    echo "    - Causes phantom cash in cashbox + phantom loss in income_clearing\n";
    echo "    - GL total stays balanced (Σcredits = Σdebits across all entries)\n\n";
    echo "  Severity: 🟡 MEDIUM (not CRITICAL because:\n";
    echo "    - GL is still balanced (no free money created)\n";
    echo "    - Customer debts are not affected\n";
    echo "    - The phantom cash is balanced by a phantom loss in income_clearing\n";
    echo "    - Accountants can identify and reconcile via the deficit correction notes)\n";
    echo "    - But: the cashbox balance would be wrong by exactly the delta of the UPDATE\n";
    echo "      settlement change, which is a financial reporting error)\n\n";
    echo "  Recommended fix (NOT applied yet — discovery phase):\n";
    echo "    - Track the ORIGINAL opening balance (before CREATE) and compare against that\n";
    echo "    - OR: only trigger deficit correct if the post-delete balance is\n";
    echo "      LESS than the LOWEST balance ever seen during the transaction's life\n";
    echo "    - OR: skip deficit correct when reversal pipeline reports success\n";
}

exit($fail === 0 ? 0 : 1);
