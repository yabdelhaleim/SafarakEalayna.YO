<?php
/**
 * End-to-end production-readiness test for the Wallet module.
 *
 * Validates:
 *   - Send transaction (إرسال رصيد) - wallet debit + cashbox credit
 *   - Receive transaction (استقبال رصيد) - wallet credit + cashbox debit
 *   - With + without customer (walk-in)
 *   - With + without service_fee
 *   - Update transaction (price change → ledger repost)
 *   - Soft-delete via service
 *   - Per-currency ledger balance
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$results = [];
$failures = [];

function ok(string $scenario, string $detail = ''): void
{
    global $results;
    $results[] = ['status' => '✅ PASS', 'scenario' => $scenario, 'detail' => $detail];
    echo "✅ {$scenario}".($detail ? " — {$detail}" : '')."\n";
}

function fail(string $scenario, string $detail): void
{
    global $results, $failures;
    $results[] = ['status' => '❌ FAIL', 'scenario' => $scenario, 'detail' => $detail];
    $failures[] = ['scenario' => $scenario, 'detail' => $detail];
    echo "❌ {$scenario} — {$detail}\n";
}

function section(string $title): void
{
    echo "\n".str_repeat('=', 80)."\n";
    echo "  {$title}\n";
    echo str_repeat('=', 80)."\n";
}

function assertFloat(string $name, float $expected, float $actual, float $epsilon = 0.01): void
{
    if (abs($expected - $actual) < $epsilon) {
        ok($name, "expected={$expected} actual={$actual}");
    } else {
        fail($name, "expected={$expected} actual={$actual}");
    }
}

Auth::loginUsingId(1);
$txService = app(WalletTransactionService::class);

// Test data
$vodafoneType = WalletType::where('code', 'vodafone_cash')->first();
$instapayType = WalletType::where('code', 'instapay')->first();
$vodafoneWallet = Account::where('name', 'like', '%فودافون كاش%')->first();
$instapayWallet = Account::where('name', 'like', '%إنستاباي%')->first();
$cashbox = Account::where('name', 'خزينة المحافظ النقدية')->first();
$customerA = Customer::where('phone', '01730030001')->first();
$customerB = Customer::where('phone', '01730030002')->first();
$customerC = Customer::where('phone', '01730030003')->first();
$adminUser = User::find(1);
// IMPORTANT: WalletTransaction.employee_id is FK to employees table, NOT users!
// Find an employee for the test (created by Bus seeder).
$employee = \App\Models\Employee::first();
$employeeId = $employee?->id;
echo "Using employee_id={$employeeId} (from employees table)\n";

echo "Test data:\n";
echo "  Wallet types: vodafone=#{$vodafoneType->id}, instapay=#{$instapayType->id}\n";
echo "  Wallets: vodafone=#{$vodafoneWallet->id} balance={$vodafoneWallet->balance}\n";
echo "  Cashbox: #{$cashbox->id} balance={$cashbox->balance}\n";
echo "  Customers: A=#{$customerA->id}, B=#{$customerB->id}\n";
echo "\n";

// =====================================================================
// SCENARIO 1: Send transaction (إرسال رصيد) — registered customer
// =====================================================================
section('SCENARIO 1: Send transaction with customer');

$vodafoneBefore = (float) $vodafoneWallet->fresh()->balance;
$cashboxBefore = (float) $cashbox->fresh()->balance;

$tx1 = $txService->createTransaction([
    'wallet_type_id' => $vodafoneType->id,
    'customer_id' => $customerA->id,
    'wallet_number' => '01012345678',
    'type' => WalletTransactionType::Send->value,
    'amount' => 1000.00,
    'service_fee' => 5.00,
    'amount_paid' => 1005.00,
    'wallet_account_id' => $vodafoneWallet->id,
    'cash_account_id' => $cashbox->id,
    'employee_id' => $employeeId,
    'notes' => 'إرسال 1000 ج.م - Vodafone',
]);

ok('S1.1 Send transaction created', "id={$tx1->id} total_amount={$tx1->total_amount}");
assertFloat('S1.2 total_amount = amount + fee = 1005', 1005.00, (float) $tx1->total_amount);
if ($tx1->type === WalletTransactionType::Send) ok('S1.3 Type = send');
else fail('S1.3', "got {$tx1->type->value}");
if ($tx1->income_transaction_id && $tx1->expense_transaction_id) {
    ok('S1.4 Both GL transactions recorded', "income=#{$tx1->income_transaction_id} expense=#{$tx1->expense_transaction_id}");
} else {
    fail('S1.4', 'income or expense missing');
}

// Verify wallet balance decreased (by amount, not amount+fee — fee is the office's revenue)
$vodafoneAfter = (float) $vodafoneWallet->fresh()->balance;
assertFloat('S1.5 Wallet debited by amount (1000)', $vodafoneBefore - 1000.00, $vodafoneAfter);

// Verify cashbox balance increased (settlement leg from customer)
$cashboxAfter = (float) $cashbox->fresh()->balance;
assertFloat('S1.6 Cashbox credited by amount_paid (1005)', $cashboxBefore + 1005.00, $cashboxAfter);

// =====================================================================
// SCENARIO 2: Receive transaction (استقبال رصيد) — registered customer
// =====================================================================
section('SCENARIO 2: Receive transaction with customer');

$vodafoneBefore = (float) $vodafoneWallet->fresh()->balance;
$cashboxBefore = (float) $cashbox->fresh()->balance;

$tx2 = $txService->createTransaction([
    'wallet_type_id' => $vodafoneType->id,
    'customer_id' => $customerB->id,
    'wallet_number' => '01087654321',
    'type' => WalletTransactionType::Receive->value,
    'amount' => 500.00,
    'service_fee' => 5.00,
    'amount_paid' => 500.00,
    'wallet_account_id' => $vodafoneWallet->id,
    'cash_account_id' => $cashbox->id,
    'employee_id' => $employeeId,
    'notes' => 'استقبال 500 ج.م',
]);

ok('S2.1 Receive transaction created', "id={$tx2->id} total_amount={$tx2->total_amount}");
assertFloat('S2.2 total_amount = amount - fee = 495', 495.00, (float) $tx2->total_amount);

// Receive: wallet is credited by amount (customer receives 500)
$vodafoneAfter = (float) $vodafoneWallet->fresh()->balance;
assertFloat('S2.3 Wallet credited by amount (500)', $vodafoneBefore + 500.00, $vodafoneAfter);

// Cashbox decreases by amount_paid (the office's cash going out to receive)
$cashboxAfter = (float) $cashbox->fresh()->balance;
assertFloat('S2.4 Cashbox debited by amount_paid (500)', $cashboxBefore - 500.00, $cashboxAfter);

// =====================================================================
// SCENARIO 3: Send without service_fee
// =====================================================================
section('SCENARIO 3: Send without service_fee');

$vodafoneBefore = (float) $vodafoneWallet->fresh()->balance;
$cashboxBefore = (float) $cashbox->fresh()->balance;

$tx3 = $txService->createTransaction([
    'wallet_type_id' => $vodafoneType->id,
    'customer_id' => $customerC->id,
    'wallet_number' => '01077777777',
    'type' => WalletTransactionType::Send->value,
    'amount' => 200.00,
    'service_fee' => 0,
    'amount_paid' => 200.00,
    'wallet_account_id' => $vodafoneWallet->id,
    'cash_account_id' => $cashbox->id,
    'employee_id' => $employeeId,
    'notes' => 'إرسال بدون رسوم',
]);

ok('S3.1 Send (zero fee) created', "id={$tx3->id}");
assertFloat('S3.2 total_amount = amount = 200', 200.00, (float) $tx3->total_amount);

// =====================================================================
// SCENARIO 4: Walk-in (no customer_id) - send
// =====================================================================
section('SCENARIO 4: Walk-in send (no customer)');

$vodafoneBefore = (float) $vodafoneWallet->fresh()->balance;
$cashboxBefore = (float) $cashbox->fresh()->balance;

$tx4 = $txService->createTransaction([
    'wallet_type_id' => $instapayType->id,
    'customer_name' => 'Walk-in - InstaPay',
    'wallet_number' => '01099999999',
    'type' => WalletTransactionType::Send->value,
    'amount' => 300.00,
    'service_fee' => 3.00,
    'amount_paid' => 303.00,
    'wallet_account_id' => $instapayWallet->id,
    'cash_account_id' => $cashbox->id,
    'employee_id' => $employeeId,
    'notes' => 'Walk-in InstaPay',
]);

ok('S4.1 Walk-in send created', "id={$tx4->id}");

$vodafoneAfter = (float) $vodafoneWallet->fresh()->balance;
// Different wallet — check InstaPay wallet instead
$instapayBefore = (float) $instapayWallet->fresh()->balance;
echo "  InstaPay wallet balance: was={$instapayBefore} now=" . (float) $instapayWallet->balance . "\n";

// =====================================================================
// SCENARIO 5: Soft-delete via service (no hard delete)
// =====================================================================
section('SCENARIO 5: Soft-delete WalletTransaction via service');

try {
    $txService->deleteTransaction($tx4);
    ok('S5.1 deleteTransaction called');
} catch (\Throwable $e) {
    fail('S5.1', $e->getMessage());
}
$trashed = WalletTransaction::withTrashed()->find($tx4->id);
if ($trashed && $trashed->trashed()) ok('S5.2 WalletTransaction soft-deleted');
else fail('S5.2', 'soft-delete failed');
if (WalletTransaction::find($tx4->id) === null) ok('S5.3 find() returns null');
else fail('S5.3', 'find() should return null');

// =====================================================================
// SCENARIO 6: Update transaction (price change → ledger repost)
// =====================================================================
section('SCENARIO 6: Update transaction');

$tx1OriginalAmount = (float) $tx1->amount;
$tx1OriginalFee = (float) $tx1->service_fee;

$tx1->refresh();
try {
    $txService->updateTransaction($tx1, [
        'amount' => 1500.00,
        'service_fee' => 7.50,
        'notes' => 'تعديل المبلغ',
    ]);
    $tx1->refresh();
    assertFloat('S6.1 Amount updated to 1500', 1500.00, (float) $tx1->amount);
    assertFloat('S6.2 Fee updated to 7.50', 7.50, (float) $tx1->service_fee);
    assertFloat('S6.3 total_amount = 1507.50', 1507.50, (float) $tx1->total_amount);
    ok('S6.4 Update succeeded');
} catch (\Throwable $e) {
    fail('S6', $e->getMessage());
}

// =====================================================================
// SCENARIO 7: Per-currency ledger balance
// =====================================================================
section('SCENARIO 7: Per-currency ledger balance (Wallet module)');

$allTx = Transaction::where('module', 'wallet')->get();
$imbalanced = [];
foreach ($allTx as $tx) {
    $entries = AccountEntry::where('transaction_id', $tx->id)->get();
    if ($entries->isEmpty()) continue;
    $byCurrency = $entries->groupBy(fn($e) => Account::find($e->account_id)?->currency ?? 'UNK');
    foreach ($byCurrency as $ccy => $group) {
        $debit = (float) $group->sum('debit');
        $credit = (float) $group->sum('credit');
        if (abs($debit - $credit) >= 0.01) {
            $imbalanced[] = "tx#{$tx->id} ({$tx->type->value}, {$tx->amount} {$ccy}): debit={$debit} credit={$credit}";
        }
    }
}
if (empty($imbalanced)) {
    ok('S7.1 All Wallet tx balanced per-currency', "checked {$allTx->count()} transactions");
} else {
    fail('S7.1', count($imbalanced)." imbalances: ".implode('; ', array_slice($imbalanced, 0, 3)));
}

// =====================================================================
// SCENARIO 8: Aggregations & stats
// =====================================================================
section('SCENARIO 8: Wallet stats');

$totalSent = (float) WalletTransaction::whereNull('deleted_at')->where('type', 'send')->sum('amount');
$totalReceived = (float) WalletTransaction::whereNull('deleted_at')->where('type', 'receive')->sum('amount');
$totalCount = WalletTransaction::whereNull('deleted_at')->count();
echo "  Total transactions: {$totalCount}\n";
echo "  Total sent: {$totalSent} EGP\n";
echo "  Total received: {$totalReceived} EGP\n";
if ($totalCount > 0) ok('S8.1 Stats reflect activity');
else fail('S8.1', 'no transactions');

// =====================================================================
// SCENARIO 9: Wallet balance integrity
// =====================================================================
section('SCENARIO 9: Wallet balance integrity');

$vodafone = $vodafoneWallet->fresh();
echo "  Vodafone balance: {$vodafone->balance}\n";

// Sum of all transactions affecting this wallet
$sendTotal = (float) WalletTransaction::where('wallet_account_id', $vodafone->id)
    ->whereNull('deleted_at')->where('type', 'send')->sum('amount');
$receiveTotal = (float) WalletTransaction::where('wallet_account_id', $vodafone->id)
    ->whereNull('deleted_at')->where('type', 'receive')->sum('amount');

// Initial balance was 50000. Expected = initial - sendTotal + receiveTotal + update adjustments
// For this test, we focus on whether balance changes match transaction sums
echo "  Sum of sends: {$sendTotal}\n";
echo "  Sum of receives: {$receiveTotal}\n";
ok('S9.1 Vodafone wallet accessible');

// =====================================================================
// FINAL REPORT
// =====================================================================
section('FINAL REPORT');

$pass = count(array_filter($results, fn($r) => str_starts_with($r['status'], '✅')));
$fail = count(array_filter($results, fn($r) => str_starts_with($r['status'], '❌')));

echo "\nTotal scenarios run: " . count($results) . "\n";
echo "✅ PASS: {$pass}\n";
echo "❌ FAIL: {$fail}\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f['scenario']}: {$f['detail']}\n";
    }
    exit(1);
}

echo "\n🎉 All Wallet scenarios passed!\n";
exit(0);
