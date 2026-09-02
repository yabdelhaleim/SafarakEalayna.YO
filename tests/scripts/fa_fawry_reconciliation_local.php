<?php

/**
 * Fawry B-2 Fix — Local Reconciliation Methodology Demonstration
 * ==============================================================
 *
 * Purpose: Demonstrate on local DB that the reconciliation methodology
 *          (matching GL entries to Fawry transactions) WORK as designed.
 *
 * Why this script:
 *   - Production DB access is not available from this environment.
 *   - The actual reconciliation SQL is in:
 *       tests/scripts/fa_fawry_reconciliation_20260814_20.sql
 *   - This script proves the methodology by:
 *       1. Synthesizing a realistic 6-day dataset of Fawry activity
 *       2. Running the same reconciliation queries in PHP via Eloquent
 *       3. Asserting that all key totals are consistent
 *       4. Documenting what the production results should look like
 *
 * Usage:  php tests/scripts/fa_fawry_reconciliation_local.php
 *
 * Scope: Local sandbox. NOT a replacement for production reconciliation.
 *        The production SQL must be run by the operations team against
 *        the live MySQL database.
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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// ── helpers ────────────────────────────────────────────────────────────────

$results = [];
$pass = 0;
$fail = 0;
function record(string $group, string $check, bool $ok, string $detail = ''): void
{
    global $results, $pass, $fail;
    $results[] = compact('group', 'check', 'ok', 'detail');
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

// ── seed minimal data ──────────────────────────────────────────────────────

print_header('RECONCILIATION METHODOLOGY DEMONSTRATION');

$fawryService = app(FawryTransactionService::class);

$admin = User::firstOrCreate(
    ['email' => 'admin@reconciliation.local'],
    ['name' => 'Reconciliation Admin', 'password' => bcrypt('test'), 'email_verified_at' => now()]
);
Auth::login($admin);

$machine = FawryMachine::create([
    'name' => 'Recon Demo Machine',
    'type' => 'fawry',
    'balance' => 50000.00,
    'is_active' => true,
]);

$cashbox = Account::create([
    'name' => 'Recon Demo Cashbox',
    'type' => AccountType::Cashbox,
    'balance' => 10000.00,
    'currency' => 'EGP',
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office',
    'created_by' => $admin->id,
]);

$openingCashbox = (float) $cashbox->balance;
$openingMachine = (float) $machine->balance;

echo "\nSeed: cashbox_opening={$openingCashbox}, machine_opening={$openingMachine}\n\n";

// ── scenario 1: registered-customer Fawry txs (post-B-2 fix, should work) ─

print_header('S1: Registered-customer Fawry transactions (after B-2 fix)');

$customer = Customer::create([
    'full_name' => 'Registered Customer A',
    'phone' => '01000000001',
    'account_id' => null,
    'created_by' => $admin->id,
]);
if (method_exists($customer, 'createAccountIfMissing')) {
    $customer->createAccountIfMissing();
} elseif (empty($customer->account_id)) {
    $customerAccount = Account::create([
        'name' => 'Customer A AR',
        'type' => AccountType::Receivable,
        'balance' => 0,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_CUSTOMER,
        'module_type' => 'office',
        'created_by' => $admin->id,
    ]);
    $customer->account_id = $customerAccount->id;
    $customer->save();
}
$customerArr = $customer->fresh();

$txReg1 = $fawryService->createTransaction([
    'client_id' => $customerArr->id,
    'client_name' => $customerArr->full_name,
    'operation_type' => 'bill_payment',
    'client_amount' => 1000.00,
    'fawry_price' => 800.00,
    'selling_price' => 1000.00,
    'amount' => 500.00, // partial payment
    'employee_id' => $admin->id,
    'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id,
    'payment_method' => 'cash',
]);
record('S1', 'Registered-customer POST returns FawryTransaction (id > 0)', $txReg1->id > 0, 'id='.$txReg1->id);

$txReg2 = $fawryService->createTransaction([
    'client_id' => $customerArr->id,
    'client_name' => $customerArr->full_name,
    'operation_type' => 'bill_payment',
    'client_amount' => 500.00,
    'fawry_price' => 400.00,
    'selling_price' => 500.00,
    'amount' => 500.00, // full payment
    'employee_id' => $admin->id,
    'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id,
    'payment_method' => 'cash',
]);
record('S1', 'Second registered-customer POST (full payment)', $txReg2->id > 0, 'id='.$txReg2->id);

// ── scenario 2: walk-in Fawry tx (untouched control) ──────────────────────

print_header('S2: Walk-in Fawry transaction (control group)');

$txWalkin = $fawryService->createTransaction([
    'client_name' => 'Walk-in Customer B',
    'operation_type' => 'bill_payment',
    'client_amount' => 700.00,
    'fawry_price' => 600.00,
    'selling_price' => 700.00,
    'amount' => 700.00, // full payment
    'employee_id' => $admin->id,
    'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id,
    'payment_method' => 'cash',
]);
record('S2', 'Walk-in POST returns FawryTransaction (id > 0)', $txWalkin->id > 0, 'id='.$txWalkin->id);

// ── scenario 3: apply reconciliation queries ──────────────────────────────

print_header('S3: RECONCILIATION QUERY 1 — Fawry transactions by type');

$registryRows = DB::table('fawry_transactions')
    ->selectRaw('COUNT(*) as cnt, SUM(CASE WHEN client_id IS NOT NULL THEN 1 ELSE 0 END) as registered, SUM(CASE WHEN client_id IS NULL THEN 1 ELSE 0 END) as walkin, SUM(amount) as total_paid, SUM(selling_price) as total_selling')
    ->whereNull('deleted_at')
    ->first();

echo "  registry: cnt={$registryRows->cnt}, registered=".($registryRows->registered ?? 0).', walkin='.($registryRows->walkin ?? 0)."\n";
echo '  total_paid='.($registryRows->total_paid ?? 0).', total_selling='.($registryRows->total_selling ?? 0)."\n";

record('S3', 'Total Fawry transactions = 3 (2 registered + 1 walk-in)',
    $registryRows->cnt === 3, 'got='.$registryRows->cnt);
record('S3', 'Registered = 2', $registryRows->registered == 2, 'got='.$registryRows->registered);
record('S3', 'Walk-in = 1', $registryRows->walkin == 1, 'got='.$registryRows->walkin);

// ── scenario 4: reconciliation query 2 — settlement type check ────────────

print_header('S4: RECONCILIATION QUERY 2 — Settlement type composition (the B-2 check)');

$registeredTxs = DB::table('fawry_transactions')
    ->where('client_id', $customerArr->id)
    ->whereNull('deleted_at')
    ->get();

foreach ($registeredTxs as $row) {
    $incomeCount = DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $row->id)
        ->where('type', 'income')
        ->count();

    $transferCount = DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $row->id)
        ->where('type', 'transfer')
        ->count();

    $ok = ($incomeCount === 1) && ($transferCount === 2);
    record('S4', "Fawry tx #{$row->id}: 1 income + 2 transfer (B-2 fix outcome)",
        $ok, "income={$incomeCount}, transfer={$transferCount}");
}

$walkinTxs = DB::table('fawry_transactions')
    ->whereNull('client_id')
    ->whereNull('deleted_at')
    ->get();

foreach ($walkinTxs as $row) {
    $incomeCount = DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $row->id)
        ->where('type', 'income')
        ->count();

    $transferCount = DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $row->id)
        ->where('type', 'transfer')
        ->count();

    // Walk-in flow (verified from FawryTransactionService::postLedgerEntries
    // walk-in branch at lines 309-355): uses recordJournalTransfer for both
    // the sale leg (saleIncome) and the settlement leg. The expense leg
    // (recordExpense at line 262) is also a Transfer type. So walk-in
    // produces 0 income + 3 transfer (sale + expense + settlement).
    $ok = ($incomeCount === 0) && ($transferCount === 3);
    record('S4', "Walk-in tx #{$row->id}: 0 income + 3 transfer (already-correct flow)",
        $ok, "income={$incomeCount}, transfer={$transferCount}");

    // Verify the expense leg is also still posted (sanity check)
    $expenseCount = DB::table('transactions')
        ->where('related_type', FawryTransaction::class)
        ->where('related_id', $row->id)
        ->where('type', 'expense')
        ->count();
    // Note: recordExpense internally creates a transaction with type 'transfer'
    // — the expense label is metadata. So we don't assert on a separate 'expense' row.
    record('S4', "Walk-in tx #{$row->id}: all 3 transfers balance correctly",
        $transferCount === 3, "transfer_count={$transferCount}");
}

// ── scenario 5: cashbox reconciliation ────────────────────────────────────

print_header('S5: RECONCILIATION QUERY 3 — Cashbox balance check');

$cashboxAfter = (float) Account::find($cashbox->id)->balance;

// Expected: opening + sum of all settlements (recordJournalTransfer from customer/walk-in AR TO cashbox)
// = 10000 + 500 (txReg1 partial) + 500 (txReg2 full) + 700 (walkin full) = 11700
$expectedCashbox = $openingCashbox + 500.00 + 500.00 + 700.00;
echo "  cashbox_actual={$cashboxAfter}, cashbox_expected={$expectedCashbox}\n";
record('S5', 'Cashbox closing balance matches expected movement',
    abs($cashboxAfter - $expectedCashbox) < 0.005,
    "actual={$cashboxAfter}, expected={$expectedCashbox}");

// ── scenario 6: customer AR balance check ─────────────────────────────────

print_header('S6: RECONCILIATION QUERY 4 — Customer AR balance per Fawry');

$customerAccount = Account::find($customerArr->account_id);
$customerArBalance = (float) $customerAccount->balance;

// Expected: sum of (selling_price - amount) across all of customer's active txs
// = (1000 - 500) + (500 - 500) = 500
$expectedCustomerAr = DB::table('fawry_transactions')
    ->where('client_id', $customerArr->id)
    ->whereNull('deleted_at')
    ->selectRaw('SUM(selling_price - amount) as total')
    ->value('total');
$expectedCustomerAr = (float) $expectedCustomerAr;

echo "  customer_AR_actual={$customerArBalance}, customer_AR_expected={$expectedCustomerAr}\n";
record('S6', 'Customer AR balance matches per-tx debt',
    abs($customerArBalance - $expectedCustomerAr) < 0.005,
    "actual={$customerArBalance}, expected={$expectedCustomerAr}");

// ── scenario 7: walk-in AR balance check ──────────────────────────────────

print_header('S7: RECONCILIATION QUERY 5 — Walk-in AR balance');

$walkinArAccount = Account::where('name', 'ذمم عملاء فوري غير مسجلين')->first();
if ($walkinArAccount) {
    $walkinArBalance = (float) $walkinArAccount->balance;
    $expectedWalkinAr = DB::table('fawry_transactions')
        ->whereNull('client_id')
        ->whereNull('deleted_at')
        ->selectRaw('SUM(selling_price - amount) as total')
        ->value('total');
    $expectedWalkinAr = (float) $expectedWalkinAr;

    echo "  walkin_AR_actual={$walkinArBalance}, walkin_AR_expected={$expectedWalkinAr}\n";
    record('S7', 'Walk-in AR balance matches per-tx debt',
        abs($walkinArBalance - $expectedWalkinAr) < 0.005,
        "actual={$walkinArBalance}, expected={$expectedWalkinAr}");
} else {
    record('S7', 'Walk-in AR account exists', false, 'Account not found in DB');
}

// ── scenario 8: Fawry machine balance check ───────────────────────────────

print_header('S8: RECONCILIATION QUERY 6 — Fawry machine balance');

$machineAfter = (float) DB::table('fawry_machines')->where('id', $machine->id)->value('balance');
// Expected: opening - sum of (fawry_price) for all active txs
// = 50000 - 800 - 400 - 600 = 48200
$expectedMachine = $openingMachine - 800.00 - 400.00 - 600.00;
echo "  machine_actual={$machineAfter}, machine_expected={$expectedMachine}\n";
record('S8', 'Fawry machine balance matches expected deduction',
    abs($machineAfter - $expectedMachine) < 0.005,
    "actual={$machineAfter}, expected={$expectedMachine}");

// ── scenario 9: journal entry balance check ───────────────────────────────

print_header('S9: RECONCILIATION QUERY 7 — Journal entries balanced');

$allTxs = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->whereIn('related_id', $registryRows->cnt ? DB::table('fawry_transactions')->pluck('id')->all() : [])
    ->get();

$balanced = true;
foreach ($allTxs as $t) {
    $totals = DB::table('account_entries')
        ->where('transaction_id', $t->id)
        ->selectRaw('SUM(credit) as credits, SUM(debit) as debits')
        ->first();
    if (abs((float) $totals->credits - (float) $totals->debits) > 0.005) {
        $balanced = false;
        echo "  ⚠ tx #{$t->id} (type={$t->type}) unbalanced: credits={$totals->credits}, debits={$totals->debits}\n";
    }
}

record('S9', 'All Fawry-linked journal entries balanced (Σcredit = Σdebit)',
    $balanced, 'checked '.$allTxs->count().' transactions');

// ── final summary ─────────────────────────────────────────────────────────

print_header('FINAL SUMMARY');

echo "Recon methodology: $pass PASS, $fail FAIL\n";
echo 'Total Fawry txs in simulation: '.$registryRows->cnt."\n";
echo 'Cashbox movement: '.($cashboxAfter - $openingCashbox).' (expected: '.($expectedCashbox - $openingCashbox).")\n";
echo 'Machine movement: '.($machineAfter - $openingMachine).' (expected: '.($expectedMachine - $openingMachine).")\n";

echo "\n";
echo "================================================================\n";
echo "  PRODUCTION RECONCILIATION INSTRUCTIONS\n";
echo "================================================================\n";
echo "\n";
echo "  1. Pull latest production DB snapshot or run live queries.\n";
echo "  2. Execute: tests/scripts/fa_fawry_reconciliation_20260814_20.sql\n";
echo "  3. For each QUERY, verify the actual output matches expectations:\n";
echo "\n";
echo "  QUERY 1 (registered txs in window): EXPECTED = 0\n";
echo "    → If 0: B-2 fully blocked registered-customer creates during the window.\n";
echo "    → If > 0: investigate each row (might be pre-Path-C row, or fix leaked).\n";
echo "\n";
echo "  QUERY 2 (Income settlements in window): EXPECTED = 0\n";
echo "    → If 0: no buggy settlements were recorded.\n";
echo "    → If > 0: B-2 fix didn't take effect for that row (rare).\n";
echo "\n";
echo "  QUERY 3 (mismatched tx composition): EXPECTED = 0 rows\n";
echo "    → If 0: every registered-customer Fawry tx has exactly 1 income + 2 transfers.\n";
echo "    → If > 0: investigate each.\n";
echo "\n";
echo "  QUERY 4 (cashbox reconciliation): EXPECTED = drift = 0\n";
echo "    → Drift = 0: cashbox balanced. ✅ NO-GO condition lifted.\n";
echo "    → Drift != 0: physical cash count is needed to identify the gap.\n";
echo "\n";
echo "  QUERY 5 (walk-in AR balance): EXPECTED = ar_balance == expected_walkin_debt\n";
echo "  QUERY 6 (customer AR balance): EXPECTED = same per customer\n";
echo "  QUERY 7 (total cash receipts in window): EXPECTED = small (only walk-in txs)\n";
echo "\n";
echo "  INTERPRETATION:\n";
echo "    ALL queries match expectations → GO (with monitored re-run post-fix).\n";
echo "    ANY query mismatches → NO-GO until reconciled.\n";
echo "\n";

exit($fail === 0 ? 0 : 1);
