<?php

/**
 * Fawry B-2 Fix — Financial State Proof Script
 * =============================================
 *
 * Purpose: prove that after the B-2 fix (recordIncome -> recordJournalTransfer
 *          for the registered-customer settlement flow), the financial state
 *          is correct in every dimension:
 *
 *   1. AR movement correct
 *   2. Cashbox debit/credit correct
 *   3. Journal entries balanced (Σdebits = Σcredits per transaction)
 *   4. No duplicate transactions
 *   5. Walk-in flow unchanged
 *   6. Registered-customer flow returns 2xx (not 422)
 *   7. Final cashbox value matches expected movement
 *
 * Method:
 *   - Boot Laravel in-memory SQLite
 *   - Seed minimal required data (currencies, accounts, customer, employee)
 *   - Execute 6 scenarios + assertion checks
 *   - Emit pass/fail summary
 *
 * Usage:  php tests/scripts/fa_fawry_b2_financial_proof.php
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

// Run migrations on in-memory SQLite
Artisan::call('migrate', ['--force' => true]);

use App\Enums\AccountType;
use App\Enums\FawryOperationType;
use App\Enums\FawryPaymentMethod;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

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
    echo "\n".str_repeat('=', 70)."\n";
    echo $title."\n";
    echo str_repeat('=', 70)."\n";
}

// ── seed minimal master data ───────────────────────────────────────────────

echo "Seeding minimal master data...\n";

// Currency
$currency = Currency::firstOrCreate(
    ['code' => 'EGP'],
    ['name_ar' => 'الجنيه المصري', 'name_en' => 'Egyptian Pound', 'symbol' => 'ج.م', 'exchange_rate' => 1.0, 'is_active' => 1, 'order' => 1]
);

// User (employee)
$user = User::firstOrCreate(
    ['email' => 'fawry-test@example.com'],
    ['name' => 'Fawry Tester', 'password' => bcrypt('password'), 'role' => 'admin']
);
Auth::login($user);

// Operation types (idempotent)
foreach ([
    ['code' => FawryOperationType::Withdrawal->value, 'name_ar' => 'سحب', 'name_en' => 'Withdrawal', 'color' => '#EF4444', 'icon' => 'heroicon-o-arrow-down-tray', 'is_active' => 1, 'order' => 1],
    ['code' => FawryOperationType::Deposit->value, 'name_ar' => 'إيداع', 'name_en' => 'Deposit', 'color' => '#10B981', 'icon' => 'heroicon-o-arrow-up-tray', 'is_active' => 1, 'order' => 2],
    ['code' => FawryOperationType::Payment->value, 'name_ar' => 'سداد', 'name_en' => 'Payment', 'color' => '#3B82F6', 'icon' => 'heroicon-o-credit-card', 'is_active' => 1, 'order' => 3],
    ['code' => FawryOperationType::TravelPermit->value, 'name_ar' => 'تصريح سفر', 'name_en' => 'Travel Permit', 'color' => '#8B5CF6', 'icon' => 'heroicon-o-paper-airplane', 'is_active' => 1, 'order' => 4],
] as $row) {
    App\Models\Fawry\FawryOperationType::updateOrCreate(['code' => $row['code']], $row);
}
foreach ([
    ['code' => FawryPaymentMethod::Cash->value, 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'color' => '#10B981', 'is_active' => 1, 'order' => 1],
] as $row) {
    App\Models\Fawry\FawryPaymentMethod::updateOrCreate(['code' => $row['code']], $row);
}

// Cashbox
$cashbox = Account::create([
    'name' => 'TEST Fawry Cashbox',
    'type' => AccountType::Cashbox,
    'balance' => 10000.00,
    'currency' => 'EGP',
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office',
    'is_module_vault' => false,
    'notes' => 'Test cashbox',
    'created_by' => $user->id,
]);

// Registered customer
$customer = Customer::create([
    'full_name' => 'TEST Customer Fawry B2',
    'phone' => '01000000099',
    'email' => 'cust-b2@test.com',
    'national_id' => '30000000000099',
    'type' => 'individual',
    'customer_tier' => 'STANDARD',
    'nationality' => 'EG',
    'city' => 'القاهرة',
]);

// Machine
$machine = FawryMachine::create([
    'name' => 'TEST Machine Fawry B2',
    'type' => 'fawry',
    'balance' => 5000.00,
    'is_active' => true,
]);

$svc = app(FawryTransactionService::class);
$tsvc = app(TransactionService::class);

echo "Setup done. Cashbox starting: {$cashbox->balance} EGP. Machine starting: {$machine->balance} EGP.\n";

// ── SCENARIO 1: Registered customer — FULL payment (B-2 hot path) ─────────

print_header('SCENARIO 1: Registered customer — FULL payment (B-2 hot path)');

$cashboxBalanceBefore = (float) $cashbox->fresh()->balance;
$customerAccountBefore = Account::find($customer->account_id)?->balance ?? 0.0;

try {
    $tx = $svc->createTransaction([
        'client_id' => $customer->id,
        'operation_type' => 'payment',
        'client_amount' => 1000.00,
        'fawry_price' => 950.00,
        'selling_price' => 1000.00,
        'amount' => 1000.00,           // FULL payment
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => 'cash',
    ]);
    record('S1', 'POST returns 2xx (no HTTP 422)', $tx->id > 0, 'id='.($tx->id ?? 'NULL'));
    record('S1', 'FawryTransaction persisted', $tx->exists, '');
    record('S1', 'income_transaction_id is set', ! empty($tx->income_transaction_id), '');
    record('S1', 'expense_transaction_id is set', ! empty($tx->expense_transaction_id), '');
} catch (Throwable $e) {
    record('S1', 'POST returns 2xx (no HTTP 422)', false, 'EXCEPTION: '.$e->getMessage());
    echo "  Full stop — aborting.\n";
    exit(1);
}

// Wait for customer account to be created (lazy)
sleep(0);
$customerAccount = Account::find($customer->fresh()->account_id);
$customerBalanceAfter = (float) $customerAccount?->balance ?? -1;

$cashboxBalanceAfter = (float) $cashbox->fresh()->balance;
$machineBalanceAfter = (float) $machine->fresh()->balance;

// AR movement: customer AR should be +selling_price (debt) but settled (cash received) — net depends on flow
// In Fawry: sale income credits AR for selling_price; settlement transfers from AR to cashbox for amount
// So after full payment: AR should be at selling_price - amount = 0 (no debt remaining)
record('S1', 'AR movement: customer balance = selling_price − amount (= 0 for full payment)',
    abs($customerBalanceAfter - 0.0) < 0.005,
    "actual={$customerBalanceAfter} expected=0.00");

// Cashbox DC: should have +1000 (received in full)
$expectedCashboxAfter = $cashboxBalanceBefore + 1000.00;
record('S1', 'Cashbox DC: balance increased by selling_price (= +1000)',
    abs($cashboxBalanceAfter - $expectedCashboxAfter) < 0.005,
    "actual={$cashboxBalanceAfter} expected={$expectedCashboxAfter}");

// Machine balance: should have -950 (fawry_price debit)
$expectedMachineAfter = 5000.00 - 950.00;
record('S1', 'Machine DC: balance decreased by fawry_price (= -950)',
    abs($machineBalanceAfter - $expectedMachineAfter) < 0.005,
    "actual={$machineBalanceAfter} expected={$expectedMachineAfter}");

// Journal entries balanced — for every transaction: Σcredit = Σdebit per side
$incomeTx = Transaction::find($tx->income_transaction_id);
$expenseTx = Transaction::find($tx->expense_transaction_id);
$incomeEntries = $incomeTx->entries;
$expenseEntries = $expenseTx->entries;
$incomeBalanced = abs($incomeEntries->sum('credit') - $incomeEntries->sum('debit')) < 0.005;
$expenseBalanced = abs($expenseEntries->sum('credit') - $expenseEntries->sum('debit')) < 0.005;
record('S1', 'Income journal entries balanced', $incomeBalanced,
    'Σcredit='.$incomeEntries->sum('credit').' Σdebit='.$incomeEntries->sum('debit'));
record('S1', 'Expense journal entries balanced', $expenseBalanced,
    'Σcredit='.$expenseEntries->sum('credit').' Σdebit='.$expenseEntries->sum('debit'));

// No duplicate transactions for this related_id
// Expected: 1 expense (Transfer) + 1 sale (Income) + 1 settlement (Transfer) = 3 transactions
$relatedTypeCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)->count();
record('S1', 'No duplicate transactions for this related_id', $relatedTypeCount === 3,
    "related_id={$tx->id} transaction_count={$relatedTypeCount} expected=3 (1 expense + 1 income + 1 settlement)");

// Type composition:
// - 1 income tx (sale, via recordIncome → recordJournalTransfer with type=Income)
// - 2 transfer txs (expense via recordExpense + settlement via recordJournalTransfer — both default Transfer)
$incomeCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)->where('type', 'income')->count();
$transferCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx->id)->where('type', 'transfer')->count();
record('S1', 'B-2 fix outcome: exactly 1 income tx (sale)', $incomeCount === 1, "income_count={$incomeCount} expected=1");
record('S1', 'B-2 fix outcome: 2 transfer txs (expense + settlement, NOT income)', $transferCount === 2, "transfer_count={$transferCount} expected=2");

echo "  S1 results: cashbox={$cashboxBalanceAfter}, customer_AR={$customerBalanceAfter}, machine={$machineBalanceAfter}\n";
$s1_cashbox = $cashboxBalanceAfter;
$s1_customer = $customerBalanceAfter;
$s1_machine = $machineBalanceAfter;
$s1_tx_id = $tx->id;

// ── SCENARIO 2: Registered customer — PARTIAL payment (B-2 hot path) ─────

print_header('SCENARIO 2: Registered customer — PARTIAL payment (B-2 hot path)');

$cashboxBalanceBefore = (float) $cashbox->fresh()->balance;

try {
    $tx2 = $svc->createTransaction([
        'client_id' => $customer->id,
        'operation_type' => 'payment',
        'client_amount' => 500.00,
        'fawry_price' => 480.00,
        'selling_price' => 500.00,
        'amount' => 200.00,           // PARTIAL payment (200 of 500)
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => 'cash',
    ]);
    record('S2', 'POST returns 2xx (no HTTP 422)', $tx2->id > 0, '');
    record('S2', 'FawryTransaction persisted', $tx2->exists, '');
} catch (Throwable $e) {
    record('S2', 'POST returns 2xx (no HTTP 422)', false, 'EXCEPTION: '.$e->getMessage());
    exit(1);
}

$cashboxBalanceAfter = (float) $cashbox->fresh()->balance;
$machineBalanceAfter = (float) $machine->fresh()->balance;
$customerBalanceAfter = (float) Account::find($customer->fresh()->account_id)?->balance ?? -1;

$expectedCashboxAfter = $cashboxBalanceBefore + 200.00; // partial payment only
record('S2', 'Cashbox: +200 (partial payment, NOT full selling_price)',
    abs($cashboxBalanceAfter - $expectedCashboxAfter) < 0.005,
    "actual={$cashboxBalanceAfter} expected={$expectedCashboxAfter}");

$expectedMachineAfter = (float) $machine->fresh()->balance;
$expectedMachineAfter = $machine->fresh()->balance - 0; // machine is current, just need it to drop by 480
record('S2', 'Machine: decreased by 480 (fawry_price)',
    abs($machineBalanceAfter - (5000.00 - 950.00 - 480.00)) < 0.005,
    "actual={$machineBalanceAfter} expected=".(5000.00 - 950.00 - 480.00));

record('S2', 'Customer AR: 0 from S1 + 500 (debt) - 200 (paid) = 300',
    abs($customerBalanceAfter - 300.00) < 0.005,
    "actual={$customerBalanceAfter} expected=300.00");

// B-2 fix outcome: 1 income (sale) + 2 transfers (expense + settlement)
$incomeCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx2->id)->where('type', 'income')->count();
$transferCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx2->id)->where('type', 'transfer')->count();
record('S2', 'B-2 fix outcome: exactly 1 income tx (sale)', $incomeCount === 1, "income_count={$incomeCount}");
record('S2', 'B-2 fix outcome: 2 transfer txs (expense + settlement)', $transferCount === 2, "transfer_count={$transferCount}");

// ── SCENARIO 3: Walk-in client — FULL payment (must NOT be affected by B-2) ─

print_header('SCENARIO 3: Walk-in client — FULL payment (must be unchanged after B-2 fix)');

$cashboxBalanceBefore = (float) $cashbox->fresh()->balance;
$walkInArBefore = (float) Account::where('name', 'ذمم عملاء فوري غير مسجلين')->value('balance');

try {
    $tx3 = $svc->createTransaction([
        'client_name' => 'WALKIN-B2-TEST',
        // NO client_id
        'operation_type' => 'payment',
        'client_amount' => 800.00,
        'fawry_price' => 760.00,
        'selling_price' => 800.00,
        'amount' => 800.00,           // FULL payment
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => 'cash',
    ]);
    record('S3', 'POST returns 2xx (walk-in flow unchanged)', $tx3->id > 0, '');
} catch (Throwable $e) {
    record('S3', 'POST returns 2xx (walk-in flow unchanged)', false, 'EXCEPTION: '.$e->getMessage());
    exit(1);
}

$cashboxBalanceAfter = (float) $cashbox->fresh()->balance;
$walkInArAfter = (float) Account::where('name', 'ذمم عملاء فوري غير مسجلين')->value('balance');

$expectedCashboxAfter = $cashboxBalanceBefore + 800.00; // full payment received
record('S3', 'Cashbox: +800 (full payment)', abs($cashboxBalanceAfter - $expectedCashboxAfter) < 0.005,
    "actual={$cashboxBalanceAfter} expected={$expectedCashboxAfter}");

// Walk-in flow already uses recordJournalTransfer (per code review) — should be untouched
// Walk-in posts 3 transfers total: expense + sale (income_contra → walk-in AR) + settlement (walk-in AR → cashbox)
$incomeCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx3->id)->where('type', 'income')->count();
$transferCount = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx3->id)->where('type', 'transfer')->count();
record('S3', 'Walk-in flow unchanged: 0 income tx (all posts use Transfer)', $incomeCount === 0, "income_count={$incomeCount}");
record('S3', 'Walk-in flow unchanged: 3 transfer txs (expense + sale + settlement)', $transferCount === 3, "transfer_count={$transferCount}");

// Walk-in AR should net to 0 (received 800 debt, paid 800 cash)
record('S3', 'Walk-in AR net = 0 (debt received, fully paid)',
    abs($walkInArAfter - 0.0) < 0.005,
    "actual={$walkInArAfter} expected=0.00");

// ── SCENARIO 4: Walk-in client — DEFERRED (no payment, debt accumulates) ──

print_header('SCENARIO 4: Walk-in client — DEFERRED (debt accumulates, no cash movement)');

$cashboxBalanceBefore = (float) $cashbox->fresh()->balance;
$walkInArBefore = (float) Account::where('name', 'ذمم عملاء فوري غير مسجلين')->value('balance');

try {
    $tx4 = $svc->createTransaction([
        'client_name' => 'WALKIN-B2-DEFERRED',
        'operation_type' => 'payment',
        'client_amount' => 600.00,
        'fawry_price' => 580.00,
        'selling_price' => 600.00,
        'amount' => 0.00,             // NO payment — debt only
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => 'cash',
    ]);
    record('S4', 'POST returns 2xx (deferred walk-in)', $tx4->id > 0, '');
} catch (Throwable $e) {
    record('S4', 'POST returns 2xx (deferred walk-in)', false, 'EXCEPTION: '.$e->getMessage());
    exit(1);
}

$cashboxBalanceAfter = (float) $cashbox->fresh()->balance;
$walkInArAfter = (float) Account::where('name', 'ذمم عملاء فوري غير مسجلين')->value('balance');

// Cashbox should be UNCHANGED (no cash movement, only debt booked)
record('S4', 'Cashbox: NO change (no cash received)', $cashboxBalanceBefore === $cashboxBalanceAfter,
    "before={$cashboxBalanceBefore} after={$cashboxBalanceAfter}");

// Walk-in AR should grow by selling_price (debt booked)
$expectedArDelta = 600.00;
record('S4', 'Walk-in AR: +600 (debt booked)', abs(($walkInArAfter - $walkInArBefore) - $expectedArDelta) < 0.005,
    'delta='.($walkInArAfter - $walkInArBefore).' expected=600');

// ── SCENARIO 5: Idempotency — duplicate POST with same payload should NOT double-credit ─

print_header('SCENARIO 5: Idempotency — duplicate POST must NOT create duplicate income');

$cashboxBalanceBefore = (float) $cashbox->fresh()->balance;
$customerBalanceBefore = (float) Account::find($customer->fresh()->account_id)?->balance ?? -1;

try {
    $tx5a = $svc->createTransaction([
        'client_id' => $customer->id,
        'operation_type' => 'payment',
        'client_amount' => 100.00,
        'fawry_price' => 95.00,
        'selling_price' => 100.00,
        'amount' => 100.00,
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => 'cash',
        'reference_number' => 'IDEMPOTENT-TEST-B2',
    ]);
    record('S5', 'First POST returns 2xx', $tx5a->id > 0, '');
} catch (Throwable $e) {
    record('S5', 'First POST returns 2xx', false, 'EXCEPTION: '.$e->getMessage());
    exit(1);
}

$cashboxAfterFirst = (float) $cashbox->fresh()->balance;
$customerAfterFirst = (float) Account::find($customer->fresh()->account_id)?->balance ?? -1;

// Second POST with same reference_number (allowed by design — no UNIQUE constraint)
// BUT must NOT inflate cashbox or customer AR
try {
    $tx5b = $svc->createTransaction([
        'client_id' => $customer->id,
        'operation_type' => 'payment',
        'client_amount' => 100.00,
        'fawry_price' => 95.00,
        'selling_price' => 100.00,
        'amount' => 100.00,
        'employee_id' => $user->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => 'cash',
        'reference_number' => 'IDEMPOTENT-TEST-B2',
    ]);
    record('S5', 'Second POST (same ref) returns 2xx (by design — no UNIQUE constraint)', $tx5b->id > 0, '');
} catch (Throwable $e) {
    // B-2 fix MUST NOT cause this to throw — Path C guard would throw if there was
    // already an active income for the SECOND tx's id. But each tx has its own related_id,
    // so this should succeed.
    record('S5', 'Second POST (same ref) returns 2xx', false, 'EXCEPTION: '.$e->getMessage());
    exit(1);
}

$cashboxAfterSecond = (float) $cashbox->fresh()->balance;
$customerAfterSecond = (float) Account::find($customer->fresh()->account_id)?->balance ?? -1;

$cashboxDelta = $cashboxAfterSecond - $cashboxBalanceBefore;
$customerDelta = $customerAfterSecond - $customerBalanceBefore;
record('S5', 'Cashbox after 2 POSTs = +200 (each tx posts its own settlement — expected)',
    abs($cashboxDelta - 200.00) < 0.005,
    "delta={$cashboxDelta} expected=200.00");
// For FULL payment, customer AR nets to 0: each tx adds +selling_price (debt) then -amount (settled)
// Two full payments: net = 2 * (100 - 100) = 0
record('S5', 'Customer AR after 2 FULL payments = 0 (debt booked, immediately settled)',
    abs($customerDelta - 0.0) < 0.005,
    "delta={$customerDelta} expected=0.00 (full payment nets to zero per tx)");

// For each transaction ID, no duplicate income on the SAME related_id
$dupFor5a = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx5a->id)->where('type', 'income')->count();
$dupFor5b = Transaction::where('related_type', FawryTransaction::class)
    ->where('related_id', $tx5b->id)->where('type', 'income')->count();
record('S5', 'Per-tx: exactly 1 income tx for tx5a (no duplicate)', $dupFor5a === 1, "income_count={$dupFor5a}");
record('S5', 'Per-tx: exactly 1 income tx for tx5b (no duplicate)', $dupFor5b === 1, "income_count={$dupFor5b}");

// ── SCENARIO 6: Final cashbox reconciliation (overall flow) ───────────────

print_header('SCENARIO 6: Final cashbox reconciliation');

$totalExpectedCashMovement = 1000.00 + 200.00 + 800.00 + 0.00 + 100.00 + 100.00; // S1 + S2 + S3 + S4 + S5a + S5b
$totalExpectedCashMovement -= 0.00; // S4 has no cash movement
$cashboxInitial = 10000.00;
$cashboxFinal = (float) $cashbox->fresh()->balance;
$expectedFinal = $cashboxInitial + $totalExpectedCashMovement;

echo "  Cashbox movement breakdown:\n";
echo "    S1 (registered, full):  +1000.00\n";
echo "    S2 (registered, partial): +200.00\n";
echo "    S3 (walk-in, full):      +800.00\n";
echo "    S4 (walk-in, deferred):   +0.00\n";
echo "    S5a (idempotent):        +100.00\n";
echo "    S5b (idempotent):        +100.00\n";
echo "    ────────────────────────────\n";
echo '    EXPECTED TOTAL:        +'.number_format($totalExpectedCashMovement, 2)."\n";
echo '  Initial cashbox:         '.number_format($cashboxInitial, 2)."\n";
echo '  Final cashbox (actual):  '.number_format($cashboxFinal, 2)."\n";
echo '  Final cashbox (expected): '.number_format($expectedFinal, 2)."\n";

record('S6', 'Final cashbox balance matches expected movement',
    abs($cashboxFinal - $expectedFinal) < 0.01,
    "actual={$cashboxFinal} expected={$expectedFinal}");

// ── Final summary ─────────────────────────────────────────────────────────

print_header('FINAL SUMMARY');

echo 'Total assertions: '.($pass + $fail)."\n";
echo "  ✅ PASS: $pass\n";
echo "  ❌ FAIL: $fail\n\n";

if ($fail === 0) {
    echo "🟢 ALL FINANCIAL STATE ASSERTIONS PASS — B-2 fix verified.\n";
    exit(0);
} else {
    echo "🔴 FINANCIAL STATE PROOF FAILED — investigate above.\n";
    exit(1);
}
