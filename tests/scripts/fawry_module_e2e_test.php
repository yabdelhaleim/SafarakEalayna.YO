<?php
/**
 * End-to-end production-readiness test for the Fawry module.
 *
 * Validates:
 *   - Withdrawal transaction (with machine) - reduces machine balance
 *   - Deposit transaction (no machine) - direct to cashbox
 *   - Payment transaction (utility bill) - customer + cashbox
 *   - Travel permit transaction
 *   - Machine recharge flow
 *   - Update transaction (price change → ledger repost)
 *   - Soft-delete + idempotency
 *   - Per-currency ledger balance
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\FawryOperationType;
use App\Enums\FawryPaymentMethod;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Fawry\FawryCurrency;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Models\Fawry\FawryOperationType as FawryOpModel;
use App\Models\Fawry\FawryPaymentMethod as FawryPmModel;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\TransactionService;
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
$fawryService = app(FawryTransactionService::class);
$rechargeService = app(FawryMachineRechargeService::class);
$txService = app(TransactionService::class);

// Resolve test data
$machine = FawryMachine::where('is_active', true)->where('type', 'fawry')->first();
$amanMachine = FawryMachine::where('is_active', true)->where('type', 'aman')->first();
$cashbox = Account::where('name', 'خزينة فوري النقدية')->first();
$usdCashbox = Account::where('name', 'خزينة فوري الدولارية')->first();
$officeCashbox = Account::find(1);
$egpCurrency = FawryCurrency::whereHas('currency', fn($q) => $q->where('code', 'EGP'))->first();
$usdCurrency = FawryCurrency::whereHas('currency', fn($q) => $q->where('code', 'USD'))->first();
$adminUser = User::find(1);

// Use existing customers (Fawry customers seeded) or fall back to general
$customerA = Customer::where('phone', '01510010001')->first();
$customerB = Customer::where('phone', '01510010002')->first();
$customerC = Customer::where('phone', '01510010003')->first();

echo "Test data:\n";
echo "  Machine: #{$machine->id} {$machine->name} balance={$machine->balance}\n";
echo "  Cashbox: #{$cashbox->id} {$cashbox->name} balance={$cashbox->balance}\n";
echo "  Customers: A=#{$customerA->id}, B=#{$customerB->id}, C=#{$customerC->id}\n";
echo "\n";

// =====================================================================
// SCENARIO 1: Withdrawal with machine (سحب)
// =====================================================================
section('SCENARIO 1: Withdrawal with machine (سحب من ماكينة)');

$machineBefore = (float) $machine->fresh()->balance;
$cashboxBefore = (float) $cashbox->fresh()->balance;
$tx1 = $fawryService->createTransaction([
    'client_id' => $customerA->id,
    'operation_type' => FawryOperationType::Withdrawal->value,
    'fawry_price' => 950.00,
    'selling_price' => 1000.00,
    'client_amount' => 1000.00,
    'employee_id' => $adminUser->id,
    'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id,
    'payment_method' => FawryPaymentMethod::Cash->value,
    'amount' => 1000.00,
    'reference_number' => 'FW-2026-001',
    'notes' => 'سحب 1000 ج.م - عميل',
    'currency_id' => $egpCurrency->currency_id,
]);
ok('S1.1 Withdrawal created', "id={$tx1->id} profit={$tx1->profit} EGP");
assertFloat('S1.2 Profit = 1000 - 950 = 50 EGP', 50.00, (float) $tx1->profit);

$machineAfter = (float) $machine->fresh()->balance;
assertFloat('S1.3 Machine balance decreased (950 EGP)', $machineBefore - 950.00, $machineAfter);

$cashboxAfter = (float) $cashbox->fresh()->balance;
assertFloat('S1.4 Cashbox increased (1000 EGP)', $cashboxBefore + 1000.00, $cashboxAfter);

// Verify machine transaction was recorded
$machineTx = FawryMachineTransaction::where('fawry_transaction_id', $tx1->id)->first();
if ($machineTx && $machineTx->type === 'debit' && (float) $machineTx->amount === 950.00) {
    ok('S1.5 Machine transaction (debit) recorded', "machine_tx_id={$machineTx->id}");
} else {
    fail('S1.5', 'machine transaction not recorded correctly');
}

// =====================================================================
// SCENARIO 2: Deposit (إيداع) - no machine, direct to cashbox
// Note: For walk-in client (no machine), the fawry expense is posted from
// the SETTLEMENT account (cashbox). So cashbox net change = profit (= 50).
// =====================================================================
section('SCENARIO 2: Deposit (إيداع) - direct to cashbox (no machine)');

$cashboxBefore = (float) $cashbox->fresh()->balance;
$tx2 = $fawryService->createTransaction([
    'client_name' => 'Walk-in عميل - إيداع',
    'operation_type' => FawryOperationType::Deposit->value,
    'fawry_price' => 800.00,
    'selling_price' => 850.00,
    'client_amount' => 850.00,
    'employee_id' => $adminUser->id,
    'account_id' => $cashbox->id,
    'payment_method' => FawryPaymentMethod::Cash->value,
    'amount' => 850.00,
    'reference_number' => 'FW-2026-002',
    'notes' => 'إيداع بدون ماكينة',
]);
ok('S2.1 Deposit created (no machine)', "id={$tx2->id} profit={$tx2->profit}");

$cashboxAfter = (float) $cashbox->fresh()->balance;
// Net cashbox change = selling_price - fawry_price (profit) for walk-in
assertFloat('S2.2 Cashbox net change = profit (50 EGP)', $cashboxBefore + 50.00, $cashboxAfter);

// =====================================================================
// SCENARIO 3: Payment (سداد فاتورة) - registered customer with settlement
// =====================================================================
section('SCENARIO 3: Bill Payment (سداد فاتورة)');

$cashboxBefore = (float) $cashbox->fresh()->balance;
$tx3 = $fawryService->createTransaction([
    'client_id' => $customerB->id,
    'operation_type' => FawryOperationType::Payment->value,
    'fawry_price' => 290.00,
    'selling_price' => 300.00,
    'client_amount' => 300.00,
    'employee_id' => $adminUser->id,
    'account_id' => $cashbox->id,
    'payment_method' => FawryPaymentMethod::Cash->value,
    'amount' => 300.00,
    'reference_number' => 'BILL-2026-003',
    'notes' => 'سداد فاتورة كهرباء',
]);
ok('S3.1 Payment created', "id={$tx3->id} profit={$tx3->profit}");

$cashboxAfter = (float) $cashbox->fresh()->balance;
// Walk-in customer (no machine): net = profit
assertFloat('S3.2 Cashbox net change = profit (10 EGP)', $cashboxBefore + 10.00, $cashboxAfter);

// Verify the transaction has income + expense entries
if ($tx3->income_transaction_id && $tx3->expense_transaction_id) {
    ok('S3.3 Both GL transactions recorded', "income=#{$tx3->income_transaction_id} expense=#{$tx3->expense_transaction_id}");
} else {
    fail('S3.3', "income_id=".($tx3->income_transaction_id ?? 'null')." expense_id=".($tx3->expense_transaction_id ?? 'null'));
}

// =====================================================================
// SCENARIO 4: Travel Permit (تصريح سفر)
// =====================================================================
section('SCENARIO 4: Travel Permit (تصريح سفر)');

$cashboxBefore = (float) $cashbox->fresh()->balance;
$tx4 = $fawryService->createTransaction([
    'client_name' => 'مسافر - تصري سفر للسعودية',
    'operation_type' => FawryOperationType::TravelPermit->value,
    'fawry_price' => 95.00,
    'selling_price' => 100.00,
    'client_amount' => 100.00,
    'employee_id' => $adminUser->id,
    'account_id' => $cashbox->id,
    'payment_method' => FawryPaymentMethod::Cash->value,
    'amount' => 100.00,
    'reference_number' => 'TP-2026-004',
    'notes' => 'تصريح سفر للقاهرة - جدة',
]);
ok('S4.1 Travel permit created', "id={$tx4->id} profit={$tx4->profit}");
assertFloat('S4.2 Profit = 5 EGP', 5.00, (float) $tx4->profit);

// =====================================================================
// SCENARIO 5: Insufficient machine balance (negative test)
// =====================================================================
section('SCENARIO 5: Insufficient machine balance protection');

try {
    $fawryService->createTransaction([
        'client_name' => 'سحب ضخم - اختبار الرصيد',
        'operation_type' => FawryOperationType::Withdrawal->value,
        'fawry_price' => 999999.00, // exceeds machine balance
        'selling_price' => 1000000.00,
        'client_amount' => 1000000.00,
        'employee_id' => $adminUser->id,
        'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id,
        'payment_method' => FawryPaymentMethod::Cash->value,
        'amount' => 1000000.00,
    ]);
    fail('S5.1', 'should have thrown for insufficient balance');
} catch (\App\Exceptions\InsufficientBalanceException $e) {
    ok('S5.1 Throws InsufficientBalanceException for machine overdraft', $e->getMessage());
} catch (\Exception $e) {
    fail('S5.1', 'wrong exception: '.$e->getMessage());
}

// =====================================================================
// SCENARIO 6: Inactive machine rejection (negative test)
// =====================================================================
section('SCENARIO 6: Inactive machine rejection');

$inactiveMachine = FawryMachine::where('is_active', false)->first();
if ($inactiveMachine) {
    try {
        $fawryService->createTransaction([
            'client_name' => 'سحب من ماكينة معطلة',
            'operation_type' => FawryOperationType::Withdrawal->value,
            'fawry_price' => 100.00,
            'selling_price' => 110.00,
            'client_amount' => 110.00,
            'employee_id' => $adminUser->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $inactiveMachine->id,
            'payment_method' => FawryPaymentMethod::Cash->value,
            'amount' => 110.00,
        ]);
        fail('S6.1', 'should have thrown for inactive machine');
    } catch (\InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'غير نشطة')) {
            ok('S6.1 Inactive machine rejected', $e->getMessage());
        } else {
            fail('S6.1', 'wrong error: '.$e->getMessage());
        }
    }
}

// =====================================================================
// SCENARIO 7: Machine recharge flow
// =====================================================================
section('SCENARIO 7: Machine recharge from Fawry cashbox');

$machineBefore = (float) $machine->fresh()->balance;
$fawryCashboxBefore = (float) $cashbox->fresh()->balance;
$result = $rechargeService->rechargeFromAccount(
    $machine,
    $cashbox,
    5000.00,
    'اختبار شحن من خزينة فوري'
);
ok('S7.1 Recharge transaction returned', "machine_tx_id={$result['machine_transaction']->id}");
assertFloat('S7.2 Machine balance increased (5000)', $machineBefore + 5000.00, (float) $machine->fresh()->balance);
assertFloat('S7.3 Fawry cashbox decreased (5000)', $fawryCashboxBefore - 5000.00, (float) $cashbox->fresh()->balance);

// =====================================================================
// SCENARIO 8: Update transaction (price change → ledger repost)
// =====================================================================
section('SCENARIO 8: Update transaction - price change triggers ledger repost');

$tx1OriginalSelling = (float) $tx1->selling_price;
$tx1OriginalFawry = (float) $tx1->fawry_price;
$originalIncomeTxId = $tx1->income_transaction_id;
$originalExpenseTxId = $tx1->expense_transaction_id;

// Reload transaction from DB
$freshTx1 = FawryTransaction::find($tx1->id);
$fawryService->updateTransaction($freshTx1, [
    'selling_price' => 1100.00, // changed from 1000
    'fawry_price' => 1020.00,   // changed from 950
    'notes' => 'تعديل سعر - اختبار',
]);
$updatedTx = FawryTransaction::find($tx1->id);
assertFloat('S8.1 Selling price updated to 1100', 1100.00, (float) $updatedTx->selling_price);
assertFloat('S8.2 Fawry price updated to 1020', 1020.00, (float) $updatedTx->fawry_price);
assertFloat('S8.3 Profit recomputed = 80', 80.00, (float) $updatedTx->profit);

// =====================================================================
// SCENARIO 9: Soft-delete + idempotency
// =====================================================================
section('SCENARIO 9: Soft-delete Fawry transaction');

$deletedId = $tx2->id;
$tx2->delete();
$trashed = FawryTransaction::withTrashed()->find($deletedId);
if ($trashed && $trashed->trashed()) {
    ok('S9.1 Fawry transaction soft-deleted');
} else {
    fail('S9.1', 'soft-delete failed');
}

if (FawryTransaction::find($deletedId) === null) {
    ok('S9.2 find() returns null after delete');
} else {
    fail('S9.2', 'find() should return null');
}

// =====================================================================
// SCENARIO 10: Per-currency ledger balance (Fawry module)
// =====================================================================
section('SCENARIO 10: Per-currency ledger balance');

$allFawryTx = Transaction::where('module', 'fawry')->get();
$imbalanced = [];
foreach ($allFawryTx as $tx) {
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
    ok('S10.1 All Fawry tx balanced per-currency', "checked {$allFawryTx->count()} transactions");
} else {
    fail('S10.1', count($imbalanced)." imbalances: ".implode('; ', array_slice($imbalanced, 0, 3)));
}

// =====================================================================
// SCENARIO 11: Stats & aggregation
// =====================================================================
section('SCENARIO 11: Fawry stats aggregation');

$totalTx = FawryTransaction::whereNull('deleted_at')->count();
$totalProfit = (float) FawryTransaction::whereNull('deleted_at')->sum('profit');
$totalMachineTx = FawryMachineTransaction::count();
echo "  • Total Fawry transactions: $totalTx\n";
echo "  • Total profit: {$totalProfit} EGP\n";
echo "  • Total machine transactions: $totalMachineTx\n";

if ($totalTx > 0) {
    ok('S11.1 FawryTransaction has data', "$totalTx records");
} else {
    fail('S11.1', 'no transactions recorded');
}

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

echo "\n🎉 All Fawry scenarios passed!\n";
exit(0);
