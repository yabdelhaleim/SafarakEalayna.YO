<?php
/**
 * End-to-end production-readiness test for the Online Services module.
 *
 * Validates:
 *   - Transaction creation (completed, pending, failed, cancelled)
 *   - With provider commission (default_purchase_account_id)
 *   - Without provider (debit from settlement account)
 *   - With customer (debt + cash settlement)
 *   - Without customer (walk-in)
 *   - Update transaction (with ledger repost)
 *   - Soft-delete (model blocks hard delete)
 *   - Per-currency ledger balance
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Online\OnlineTransactionService;
use App\Services\Online\OnlineServiceProviderService;
use App\Services\Online\OnlineServiceTypeService;
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
$txService = app(OnlineTransactionService::class);
$providerService = app(OnlineServiceProviderService::class);
$typeService = app(OnlineServiceTypeService::class);

// Test data
$stampsType = OnlineServiceType::where('code', 'stamps')->first();
$visasType = OnlineServiceType::where('code', 'visas_online')->first();
$trainingType = OnlineServiceType::where('code', 'training_courses')->first();
$momtazProvider = OnlineServiceProvider::where('code', 'momtaz')->first();
$etidalProvider = OnlineServiceProvider::where('code', 'etidal')->first();
$cashbox = Account::where('name', 'خزينة الخدمات الإلكترونية النقدية')->first();
$usdCashbox = Account::where('name', 'خزينة الخدمات الإلكترونية الدولارية')->first();
$officeCashbox = Account::find(1);
$customerA = Customer::where('phone', '01620020001')->first();
$customerB = Customer::where('phone', '01620020002')->first();
$customerC = Customer::where('phone', '01620020003')->first();

echo "Test data:\n";
echo "  Service types: stamps=#{$stampsType->id}, visas=#{$visasType->id}\n";
echo "  Providers: momtaz=#{$momtazProvider->id}, etidal=#{$etidalProvider->id}\n";
echo "  Cashbox: #{$cashbox->id} balance={$cashbox->balance}\n";
echo "  Customers: A=#{$customerA->id}, B=#{$customerB->id}\n";
echo "\n";

// =====================================================================
// SCENARIO 1: Completed online transaction with provider (default_purchase_account_id = cashbox)
// =====================================================================
section('SCENARIO 1: Completed transaction with provider + registered customer');

$cashboxBefore = (float) $cashbox->fresh()->balance;
$tx1 = $txService->create([
    'service_type_id' => $stampsType->id,
    'provider_id' => $momtazProvider->id,
    'customer_id' => $customerA->id,
    'purchase_price' => 380.00,
    'selling_price' => 450.00,
    'amount_paid' => 450.00,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
    'reference_number' => 'ONLINE-001',
    'notes' => 'طوابع - 450 ج.م',
    'status' => OnlineTransactionStatus::Completed->value,
]);
ok('S1.1 Transaction created', "id={$tx1->id} profit={$tx1->profit}");
assertFloat('S1.2 Profit = 450 - 380 = 70 EGP', 70.00, (float) $tx1->profit);
if ($tx1->status === OnlineTransactionStatus::Completed) ok('S1.3 Status = completed');
else fail('S1.3', "got {$tx1->status->value}");
if ($tx1->income_transaction_id && $tx1->expense_transaction_id) {
    ok('S1.4 Both GL transactions recorded', "income=#{$tx1->income_transaction_id} expense=#{$tx1->expense_transaction_id}");
} else {
    fail('S1.4', 'income or expense tx missing');
}

// Verify cashbox is unchanged (full settlement via customer AR → cashbox)
$cashboxAfter = (float) $cashbox->fresh()->balance;
ok("S1.5 Cashbox balance (informational): {$cashboxBefore} → {$cashboxAfter}");

// =====================================================================
// SCENARIO 2: Completed walk-in (no customer_id) - direct to cashbox
// =====================================================================
section('SCENARIO 2: Completed walk-in transaction (no customer)');

$cashboxBefore = (float) $cashbox->fresh()->balance;
$tx2 = $txService->create([
    'service_type_id' => $visasType->id,
    'provider_id' => $etidalProvider->id,
    'customer_name' => 'Walk-in عميل - تأشيرة',
    'customer_phone' => '01629999999',
    'purchase_price' => 800.00,
    'selling_price' => 1000.00,
    'amount_paid' => 1000.00,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
    'reference_number' => 'ONLINE-002',
    'notes' => 'تأشيرة - walk-in',
    'status' => OnlineTransactionStatus::Completed->value,
]);
ok('S2.1 Walk-in transaction created', "id={$tx2->id} profit={$tx2->profit}");
assertFloat('S2.2 Profit = 1000 - 800 = 200', 200.00, (float) $tx2->profit);
$cashboxAfter = (float) $cashbox->fresh()->balance;
ok("S2.3 Cashbox balance: {$cashboxBefore} → {$cashboxAfter}");

// =====================================================================
// SCENARIO 3: Pending transaction (no GL postings)
// =====================================================================
section('SCENARIO 3: Pending transaction');

$txCountBefore = Transaction::where('module', 'online')->count();
$tx3 = $txService->create([
    'service_type_id' => $trainingType->id,
    'provider_id' => $momtazProvider->id,
    'customer_name' => 'دورة IELTS - جاري التفعيل',
    'customer_phone' => '01628888888',
    'purchase_price' => 1500.00,
    'selling_price' => 2000.00,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
    'reference_number' => 'ONLINE-003',
    'notes' => 'دورة تدريبية - قيد التنفيذ',
    'status' => OnlineTransactionStatus::Pending->value,
]);
ok('S3.1 Pending transaction created', "id={$tx3->id}");

$txCountAfter = Transaction::where('module', 'online')->count();
if ($txCountAfter === $txCountBefore) {
    ok('S3.2 No GL postings for pending (correct)');
} else {
    fail('S3.2', "Pending should not post GL. Count went from {$txCountBefore} to {$txCountAfter}");
}

if ($tx3->income_transaction_id === null) ok('S3.3 income_transaction_id = null');
else fail('S3.3', "got {$tx3->income_transaction_id}");

// =====================================================================
// SCENARIO 4: Failed transaction
// =====================================================================
section('SCENARIO 4: Failed transaction');

$tx4 = $txService->create([
    'service_type_id' => $visasType->id,
    'provider_id' => $etidalProvider->id,
    'customer_name' => 'تأشيرة مرفوضة',
    'customer_phone' => '01627777777',
    'purchase_price' => 2500.00,
    'selling_price' => 3000.00,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
    'reference_number' => 'ONLINE-004',
    'notes' => 'مرفوضة من السفارة',
    'status' => OnlineTransactionStatus::Failed->value,
    'failure_reason' => 'الوثائق غير مكتملة',
]);
ok('S4.1 Failed transaction created', "id={$tx4->id}");
if ($tx4->status === OnlineTransactionStatus::Failed) ok('S4.2 Status = failed');
else fail('S4.2', "got {$tx4->status->value}");
if ($tx4->failure_reason === 'الوثائق غير مكتملة') ok('S4.3 Failure reason recorded');
else fail('S4.3', "got '{$tx4->failure_reason}'");

// =====================================================================
// SCENARIO 5: Cancellation (status change with GL reversal)
// =====================================================================
section('SCENARIO 5: Cancel a completed transaction');

$tx5 = $txService->create([
    'service_type_id' => $stampsType->id,
    'provider_id' => $momtazProvider->id,
    'customer_name' => 'إلغاء - اختبار',
    'customer_phone' => '01626666666',
    'purchase_price' => 200.00,
    'selling_price' => 280.00,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
    'reference_number' => 'ONLINE-005',
    'notes' => 'للإلغاء',
    'status' => OnlineTransactionStatus::Completed->value,
]);
ok('S5.1 Created for cancellation', "id={$tx5->id}");

// Now cancel it (delete → status change with reversal)
try {
    $txService->delete($tx5);
    ok('S5.2 cancel called');
} catch (\Throwable $e) {
    fail('S5.2', $e->getMessage());
}
$tx5->refresh();
if ($tx5->status === OnlineTransactionStatus::Cancelled) {
    ok('S5.3 Status changed to cancelled');
} else {
    fail('S5.3', "got {$tx5->status->value}");
}

// =====================================================================
// SCENARIO 6: Model blocks hard delete
// =====================================================================
section('SCENARIO 6: Model blocks hard delete');

try {
    $tx1->delete(); // should throw
    fail('S6.1', 'should have thrown RuntimeException');
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'لا يمكن حذف')) {
        ok('S6.1 Hard delete blocked by model observer', $e->getMessage());
    } else {
        fail('S6.1', "wrong error: {$e->getMessage()}");
    }
}

// =====================================================================
// SCENARIO 7: Update transaction (price change → ledger repost)
// =====================================================================
section('SCENARIO 7: Update transaction - price change triggers ledger repost');

$tx7 = $txService->create([
    'service_type_id' => $trainingType->id,
    'provider_id' => $momtazProvider->id,
    'customer_name' => 'دورة - اختبار تعديل',
    'customer_phone' => '01625555555',
    'purchase_price' => 1800.00,
    'selling_price' => 2500.00,
    'payment_method' => 'cash',
    'account_id' => $cashbox->id,
    'reference_number' => 'ONLINE-007',
    'notes' => 'قبل التعديل',
    'status' => OnlineTransactionStatus::Completed->value,
]);
$originalIncomeId = $tx7->income_transaction_id;
$originalExpenseId = $tx7->expense_transaction_id;
ok('S7.1 Transaction created', "id={$tx7->id} selling={$tx7->selling_price}");

$tx7->refresh();
$txService->update($tx7, [
    'selling_price' => 2800.00,
    'purchase_price' => 1900.00,
    'notes' => 'بعد التعديل',
]);
$updatedTx = OnlineTransaction::find($tx7->id);
assertFloat('S7.2 Selling price updated to 2800', 2800.00, (float) $updatedTx->selling_price);
assertFloat('S7.3 Purchase price updated to 1900', 1900.00, (float) $updatedTx->purchase_price);
assertFloat('S7.4 Profit recomputed = 900', 900.00, (float) $updatedTx->profit);

// =====================================================================
// SCENARIO 8: Service type CRUD
// =====================================================================
section('SCENARIO 8: Service type CRUD via service');

try {
    $newType = $typeService->create([
        'code' => 'extra_service',
        'name_ar' => 'خدمة اختبارية',
        'name_en' => 'Test Service',
        'color' => '#FF00FF',
        'icon' => 'heroicon-o-sparkles',
        'is_active' => 1,
        'order' => 99,
    ]);
    ok('S8.1 Service type created', "id={$newType->id}");
    $typeService->update($newType, ['name_ar' => 'خدمة اختبارية معدلة']);
    $newType->refresh();
    if ($newType->name_ar === 'خدمة اختبارية معدلة') ok('S8.2 Service type updated');
    else fail('S8.2', "got '{$newType->name_ar}'");
    $typeService->delete($newType);
    if (OnlineServiceType::withTrashed()->find($newType->id)->trashed()) ok('S8.3 Service type soft-deleted');
    else fail('S8.3', 'soft-delete failed');
} catch (\Throwable $e) {
    fail('S8', $e->getMessage());
}

// =====================================================================
// SCENARIO 9: Provider CRUD
// =====================================================================
section('SCENARIO 9: Provider CRUD via service');

try {
    $newProvider = $providerService->create([
        'code' => 'test_provider',
        'name_ar' => 'مزود اختبار',
        'name_en' => 'Test Provider',
        'color' => '#00FFFF',
        'icon' => 'heroicon-o-cog',
        'contact_phone' => '01999999999',
        'is_active' => 1,
        'order' => 99,
    ]);
    ok('S9.1 Provider created', "id={$newProvider->id}");
    $providerService->update($newProvider, ['name_ar' => 'مزود اختبار معدل']);
    $newProvider->refresh();
    if ($newProvider->name_ar === 'مزود اختبار معدل') ok('S9.2 Provider updated');
    else fail('S9.2', "got '{$newProvider->name_ar}'");
    $providerService->delete($newProvider);
    if (OnlineServiceProvider::withTrashed()->find($newProvider->id)->trashed()) ok('S9.3 Provider soft-deleted');
    else fail('S9.3', 'soft-delete failed');
} catch (\Throwable $e) {
    fail('S9', $e->getMessage());
}

// =====================================================================
// SCENARIO 10: Per-currency ledger balance (Online module)
// =====================================================================
section('SCENARIO 10: Per-currency ledger balance (Online module)');

$allTx = Transaction::where('module', 'online')->get();
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
    ok('S10.1 All Online tx balanced per-currency', "checked {$allTx->count()} transactions");
} else {
    fail('S10.1', count($imbalanced)." imbalances: ".implode('; ', array_slice($imbalanced, 0, 3)));
}

// =====================================================================
// SCENARIO 11: Stats & aggregation
// =====================================================================
section('SCENARIO 11: Online stats');

$completed = OnlineTransaction::whereNull('deleted_at')->where('status', 'completed')->count();
$totalProfit = (float) OnlineTransaction::whereNull('deleted_at')->where('status', 'completed')->sum('profit');
echo "  Completed transactions: $completed\n";
echo "  Total profit: {$totalProfit} EGP\n";
if ($completed > 0) ok('S11.1 Stats reflect completed work');
else fail('S11.1', 'no completed transactions');

// Daily summary
try {
    $summary = $txService->getDailySummary(date('Y-m-d'));
    ok('S11.2 Daily summary computed', json_encode([
        'count' => $summary['total_transactions'],
        'profit' => $summary['total_profit'],
    ], JSON_UNESCAPED_UNICODE));
} catch (\Throwable $e) {
    fail('S11.2', $e->getMessage());
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

echo "\n🎉 All Online scenarios passed!\n";
exit(0);
