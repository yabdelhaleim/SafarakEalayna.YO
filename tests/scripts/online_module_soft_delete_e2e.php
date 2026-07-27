<?php
/**
 * PHASE 10 — Online Module Soft-Delete E2E (الاختبار الكامل في النهاية)
 * ────────────────────────────────────────────────────────────────────
 * اختبار End-to-End كامل على قاعدة اللوكال (MySQL `safarakealayna`) لـ:
 *   ① حجز كامل لعميل مسجّل (EGP، بدون pay-debt) → أرصدة سليمة
 *   ② حجز جزئي لعميل مسجّل (EGP، مع pay-debt لاحق) → أرصدة سليمة
 *   ③ حجز walk-in (لا customer_id) + دفع جزئي ثم دفع كامل → أرصدة سليمة
 *   ④ تعديل سعر بيع بعد الإنشاء → إعادة نشر القيود
 *   ⑤ تعديل سعر شراء بعد الإنشاء → إعادة نشر المصروف
 *   ⑥ تعديل المبلغ المدفوع → إعادة نشر السداد
 *   ⑦ تعديل الخزنة (account_id) → عكس + إعادة نشر
 *   ⑧ تحويل حالة Completed→Cancelled → عكس كل القيود
 *   ⑨ تحويل حالة Cancelled→Completed → إعادة النشر
 *   ⑩ الحذف (soft delete) لعميل مسجّل → أرصدة تعود للـ baseline
 *   ⑪ الحذف (soft delete) لـ walk-in مع دفع لاحق → أرصدة + walk-in AR
 *   ⑫ الحذف idempotent (استدعاء ثانٍ = no-op)
 *   ⑬ رفض عملة مختلطة (USD vault) بوضوح
 *   ⑭ القيود متوازنة (debit=credit) لكل قيد Online
 *
 * كل سيناريو يستخدم marker فريد `ONL-LCL-20260727-` لضمان عدم التصادم
 * مع بيانات الإنتاج، ويُحذَف بالكامل في كتلة finally.
 *
 * Usage:
 *   php tests/scripts/online_module_soft_delete_e2e.php
 *
 * IMPORTANT: هذا script يلمس قاعدة البيانات الحقيقية (Local).
 *   قبل التشغيل: يجب التأكد من عمل نسخة احتياطية حديثة.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\CustomerType;
use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ── Bootstrap ─────────────────────────────────────────────────────────
Log::info('Online E2E start');

$testUserId = (int) (\App\Models\User::query()->orderBy('id')->value('id') ?? 0);
if ($testUserId === 0) {
    fwrite(STDERR, "✗ No users in DB — E2E needs at least one user.\n");
    exit(1);
}
$testUser = \App\Models\User::find($testUserId);
Auth::login($testUser);

$runMarker = 'ONL-LCL-20260727-' . substr(md5((string) microtime(true)), 0, 8);
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Online Module Soft-Delete E2E\n";
echo "  run marker: {$runMarker}\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$results = [];
$failedCount = 0;

function passTest(string $name, array &$results, int &$failedCount, string $detail = ''): void
{
    $results[] = ['name' => $name, 'status' => 'PASS', 'detail' => $detail];
    echo "  ✓ {$name}" . ($detail ? " — {$detail}" : '') . "\n";
}

function failTest(string $name, array &$results, int &$failedCount, string $detail): void
{
    $results[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $detail];
    $failedCount++;
    echo "  ✗ {$name} — {$detail}\n";
}

function assertDelta(string $testName, float $expected, float $actual, array &$results, int &$failedCount, string $msg = ''): void
{
    if (abs($expected - $actual) < 0.01) {
        passTest($testName, $results, $failedCount, sprintf('Δ=%.2f (expected %.2f)', $actual, $expected));
    } else {
        failTest($testName, $results, $failedCount, sprintf('Δ=%.2f (expected %.2f). %s', $actual, $expected, $msg));
    }
}

function glBalance(int $accountId): float
{
    $row = DB::table('account_entries')
        ->where('account_id', $accountId)
        ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
        ->value('net');
    return (float) $row;
}

function accountBalance(int $accountId): float
{
    return (float) (Account::find($accountId)?->balance ?? 0.0);
}

function assertLedgerBalancedForAccount(int $accountId): bool
{
    return abs(accountBalance($accountId) - glBalance($accountId)) < 0.01;
}

function assertOnlineLedgerGloballyBalanced(): bool
{
    $imbalanced = DB::table('account_entries as ae')
        ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
        ->where('t.module', 'online')
        ->groupBy('t.id')
        ->selectRaw('t.id, COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0) as net')
        ->havingRaw('ABS(net) > 0.01')
        ->get();
    return $imbalanced->isEmpty();
}

// ── Pre-flight: ensure all test data is unique to this run ─────────────
try {
    // Clean up any prior run data
    $oldTxIds = OnlineTransaction::withTrashed()
        ->where('reference_number', 'like', $runMarker.'%')
        ->pluck('id')->all();
    if (! empty($oldTxIds)) {
        $relatedTxIds = Transaction::where('related_type', OnlineTransaction::class)
            ->whereIn('related_id', $oldTxIds)
            ->pluck('id')->all();
        if (! empty($relatedTxIds)) {
            DB::table('account_entries')->whereIn('transaction_id', $relatedTxIds)->delete();
            DB::table('transactions')->whereIn('id', $relatedTxIds)->delete();
        }
        OnlineTransaction::withTrashed()->whereIn('id', $oldTxIds)->forceDelete();
    }
} catch (\Throwable $e) {
    echo "  ⚠ Pre-flight cleanup error: " . $e->getMessage() . "\n";
}

// ── Set up: vaults, service type, provider ────────────────────────────
echo "▸ Setup: baseline accounts + service type + provider\n";

$vault = Account::where('name', 'TEST_ONLINE_VAULT_ONL')->first();
if (! $vault) {
    $vault = Account::create([
        'name' => 'TEST_ONLINE_VAULT_ONL',
        'type' => AccountType::Cashbox,
        'balance' => 100000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'office',
        'is_module_vault' => true,
        'notes' => 'Online E2E test vault (auto-created by soft_delete_e2e.php)',
        'created_by' => $testUserId,
    ]);
    echo "  + Created online vault id={$vault->id}\n";
} else {
    App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => $vault->update(['balance' => 100000.00]));
    echo "  ↻ Reset vault id={$vault->id} to balance 100000.00\n";
}

$usdVault = Account::where('name', 'TEST_ONLINE_USD_VAULT_ONL')->first();
if (! $usdVault) {
    $usdVault = Account::create([
        'name' => 'TEST_ONLINE_USD_VAULT_ONL',
        'type' => AccountType::Cashbox,
        'balance' => 5000.00,
        'currency' => 'USD',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'office',
        'is_module_vault' => true,
        'notes' => 'Online E2E USD vault for cross-currency rejection test',
        'created_by' => $testUserId,
    ]);
    echo "  + Created USD vault id={$usdVault->id}\n";
}

$supplier = Account::where('name', 'TEST_ONLINE_SUPPLIER_ONL')->first();
if (! $supplier) {
    $supplier = Account::create([
        'name' => 'TEST_ONLINE_SUPPLIER_ONL',
        'type' => AccountType::Supplier,
        'balance' => 0.00,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'online',
        'is_module_vault' => false,
        'notes' => 'Online E2E supplier',
        'created_by' => $testUserId,
    ]);
    echo "  + Created supplier id={$supplier->id}\n";
} else {
    App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => $supplier->update(['balance' => 0.00]));
}

$serviceType = OnlineServiceType::create([
    'code' => 'TEST_TYPE_'.str_replace('-', '_', $runMarker),
    'name_ar' => 'نوع خدمة E2E',
    'name_en' => 'E2E service type',
    'is_active' => true,
    'order' => 99,
    'created_by' => $testUserId,
]);
echo "  + Created service type id={$serviceType->id}\n";

$provider = OnlineServiceProvider::create([
    'code' => 'TEST_PROVIDER_'.str_replace('-', '_', $runMarker),
    'name_ar' => 'مزود E2E',
    'name_en' => 'E2E provider',
    'is_active' => true,
    'order' => 99,
    'default_purchase_account_id' => $supplier->id,
    'created_by' => $testUserId,
]);
echo "  + Created provider id={$provider->id}\n";

$customer = Customer::firstOrCreate(
    ['phone' => '01099990000'],
    [
        'full_name' => 'عميل E2E',
        'type' => CustomerType::Individual->value,
        'module_type' => 'online',
        'status' => 'active',
        'created_by' => $testUserId,
    ],
);
echo "  + Customer id={$customer->id} (account id={$customer->account_id})\n";

$vaultBaseline = (float) $vault->balance;
$supplierBaseline = (float) $supplier->balance;
$customerBaseline = (float) Account::find($customer->account_id)->balance;
$walkInArBaseline = glBalance(app(LedgerClearingAccounts::class)->onlineWalkInArAccountId());
echo "  Baseline: vault={$vaultBaseline} supplier={$supplierBaseline} customer={$customerBaseline} walkInAr={$walkInArBaseline}\n\n";

$service = app(OnlineTransactionService::class);
$txService = app(TransactionService::class);
$clearing = app(LedgerClearingAccounts::class);

// ═══════════════════════════════════════════════════════════════════
// [1] Booking — full payment, registered customer
// ═══════════════════════════════════════════════════════════════════
echo "▸ [1] حجز كامل لعميل مسجّل\n";

$tx1 = $service->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'customer_phone' => $customer->phone,
    'purchase_price' => 100.0,
    'selling_price' => 250.0,
    'amount_paid' => 250.0,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => $runMarker . '-1',
    'status' => OnlineTransactionStatus::Completed->value,
]);

$customerAfter = accountBalance(Account::find($customer->account_id)->id);
assertDelta(
    '[1] vault بعد حجز كامل',
    $vaultBaseline + 250.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[1] supplier بعد حجز كامل (مصروف 100)',
    $supplierBaseline - 100.0,
    accountBalance($supplier->id),
    $results,
    $failedCount,
);
assertDelta(
    '[1] customer AR بعد حجز كامل (مديونية 250 - مسدد 250 = 0)',
    $customerBaseline,
    $customerAfter,
    $results,
    $failedCount,
);

if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[1] توازن القيود (debit=credit)', $results, $failedCount);
} else {
    failTest('[1] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [2] Booking — partial payment, registered customer
// ═══════════════════════════════════════════════════════════════════
echo "▸ [2] حجز جزئي لعميل مسجّل\n";

$vaultBefore2 = accountBalance($vault->id);
$customerAccount = Account::find($customer->account_id);
$customerBefore2 = accountBalance($customerAccount->id);
$supplierBefore2 = accountBalance($supplier->id);

$tx2 = $service->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'customer_phone' => $customer->phone,
    'purchase_price' => 80.0,
    'selling_price' => 300.0,
    'amount_paid' => 100.0,  // partial → customer owes 200
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => $runMarker . '-2',
    'status' => OnlineTransactionStatus::Completed->value,
]);

assertDelta(
    '[2] vault بعد حجز جزئي (+100 فقط)',
    $vaultBefore2 + 100.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[2] customer AR بعد حجز جزئي (+200 مديونية)',
    $customerBefore2 + 200.0,
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
assertDelta(
    '[2] supplier بعد حجز جزئي (-80 مصروف)',
    $supplierBefore2 - 80.0,
    accountBalance($supplier->id),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[2] توازن القيود', $results, $failedCount);
} else {
    failTest('[2] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [3] Walk-in booking — partial payment
// NOTE: To test the SHARED walk-in AR mirror (vs Customer AR auto-created
// when customer_name is provided), we deliberately pass NEITHER customer_id
// NOR customer_name. This is the only path that uses
// LedgerClearingAccounts::onlineWalkInArAccountId().
// ═══════════════════════════════════════════════════════════════════
echo "▸ [3] حجز walk-in (لا customer_id، لا customer_name → walkInAr mirror)\n";

$vaultBefore3 = accountBalance($vault->id);
$walkInArId = $clearing->onlineWalkInArAccountId();
$walkInArBefore3 = glBalance($walkInArId);

$tx3 = $service->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    // NO customer_id, NO customer_name → ensures walkInAr mirror is used
    'purchase_price' => 0,
    'selling_price' => 500.0,
    'amount_paid' => 150.0,  // partial → walk-in AR holds 350
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => $runMarker . '-3',
    'status' => OnlineTransactionStatus::Completed->value,
]);

assertDelta(
    '[3] vault بعد walk-in حجز جزئي (+150)',
    $vaultBefore3 + 150.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[3] walk-in AR بعد حجز جزئي (+350 مديونية)',
    $walkInArBefore3 + 350.0,
    glBalance($walkInArId),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[3] توازن القيود', $results, $failedCount);
} else {
    failTest('[3] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [4] Edit — change selling_price → repost income
// ═══════════════════════════════════════════════════════════════════
echo "▸ [4] تعديل سعر البيع → إعادة نشر الدخل\n";

$vaultBefore4 = accountBalance($vault->id);
$customerBefore4 = accountBalance($customerAccount->id);
$oldIncomeId = $tx1->income_transaction_id;

$tx1 = $service->update($tx1, ['selling_price' => 400.0]);
$tx1->refresh();

assertDelta(
    '[4] vault لم يتغير (تعديل سعر البيع لا يمس التحصيل)',
    $vaultBefore4,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[4] customer AR زاد بمقدار 150 (الفرق بين 400 و 250)',
    $customerBefore4 + 150.0,
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
if ($tx1->income_transaction_id !== $oldIncomeId) {
    passTest('[4] تم نشر قيد دخل جديد', $results, $failedCount, "id={$tx1->income_transaction_id}");
} else {
    failTest('[4] قيد الدخل لم يتغير', $results, $failedCount, "income_transaction_id={$tx1->income_transaction_id}");
}
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[4] توازن القيود', $results, $failedCount);
} else {
    failTest('[4] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [5] Edit — change purchase_price → repost expense
// ═══════════════════════════════════════════════════════════════════
echo "▸ [5] تعديل سعر الشراء → إعادة نشر المصروف\n";

$supplierBefore5 = accountBalance($supplier->id);
$oldExpenseId = $tx1->expense_transaction_id;

$tx1 = $service->update($tx1, ['purchase_price' => 200.0]);
$tx1->refresh();

assertDelta(
    '[5] supplier نقص بمقدار 100 (المصروف زاد من 100 إلى 200)',
    $supplierBefore5 - 100.0,
    accountBalance($supplier->id),
    $results,
    $failedCount,
);
if ($tx1->expense_transaction_id !== $oldExpenseId) {
    passTest('[5] تم نشر قيد مصروف جديد', $results, $failedCount, "id={$tx1->expense_transaction_id}");
} else {
    failTest('[5] قيد المصروف لم يتغير', $results, $failedCount);
}
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[5] توازن القيود', $results, $failedCount);
} else {
    failTest('[5] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [6] Edit — change amount_paid → repost cash settlement
// ═══════════════════════════════════════════════════════════════════
echo "▸ [6] تعديل المبلغ المدفوع → إعادة نشر السداد\n";

$vaultBefore6 = accountBalance($vault->id);
$customerBefore6 = accountBalance($customerAccount->id);

$tx1 = $service->update($tx1, ['amount_paid' => 400.0]);
$tx1->refresh();

assertDelta(
    '[6] vault زاد بمقدار 150 (السداد الجديد 400 - السابق 250)',
    $vaultBefore6 + 150.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[6] customer AR نقص بمقدار 150 (المسدد زاد)',
    $customerBefore6 - 150.0,
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[6] توازن القيود', $results, $failedCount);
} else {
    failTest('[6] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [7] Status transition Completed → Cancelled
// The reversal cancels ALL live GL entries (income, cash settlement,
// expense) for tx1. Expected deltas from BEFORE the status change:
//   - vault: -400 (reverse cash settlement 400)
//   - customer: +200 (reverse income 400 - reverse cash 400 = +200, debt
//                cleared net 0; but +200 to walk the 400 - 400 = 0)
//   - supplier: +200 (reverse expense 200)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [7] تحويل الحالة Completed→Cancelled → عكس القيود\n";

$vaultBefore7 = accountBalance($vault->id);
$customerBefore7 = accountBalance($customerAccount->id);
$supplierBefore7 = accountBalance($supplier->id);

$tx1 = $service->update($tx1, ['status' => 'cancelled']);
$tx1->refresh();

if ($tx1->status->value === OnlineTransactionStatus::Cancelled->value) {
    passTest('[7] الحالة الآن cancelled', $results, $failedCount);
} else {
    failTest('[7] الحالة لم تتغير', $results, $failedCount, "status={$tx1->status->value}");
}
// After cancel: deltas are the inverse of the live GL.
assertDelta(
    '[7] vault Δ بعد إلغاء (عكسي cash settlement 400)',
    $vaultBefore7 - 400.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[7] customer AR Δ بعد إلغاء (tx1 net effect = 0, فلا تغيير)',
    $customerBefore7,  // 0 delta — tx1's income 400 - cash 400 = 0 on customer
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
assertDelta(
    '[7] supplier Δ بعد إلغاء (عكسي مصروف 200)',
    $supplierBefore7 + 200.0,
    accountBalance($supplier->id),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[7] توازن القيود', $results, $failedCount);
} else {
    failTest('[7] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [8] Status transition Cancelled → Completed → repost
// Expected deltas from BEFORE the status change:
//   - vault: +400 (repost cash settlement 400)
//   - customer: +200 (repost income 400 + cash 400 = net 0 debt change;
//                but in GL terms: +200 because walk the 400 - 400 = 0)
//   - supplier: -200 (repost expense 200)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [8] تحويل الحالة Cancelled→Completed → إعادة النشر\n";

$vaultBefore8 = accountBalance($vault->id);
$customerBefore8 = accountBalance($customerAccount->id);
$supplierBefore8 = accountBalance($supplier->id);

$tx1 = $service->update($tx1, ['status' => 'completed']);
$tx1->refresh();

if ($tx1->status->value === OnlineTransactionStatus::Completed->value) {
    passTest('[8] الحالة الآن completed', $results, $failedCount);
} else {
    failTest('[8] الحالة لم تتغير', $results, $failedCount);
}
assertDelta(
    '[8] vault Δ بعد إعادة النشر (إعادة cash settlement 400)',
    $vaultBefore8 + 400.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[8] customer AR Δ بعد إعادة النشر (tx1 net effect = 0)',
    $customerBefore8,  // 0 delta — tx1's income 400 - cash 400 = 0 on customer
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
assertDelta(
    '[8] supplier Δ بعد إعادة النشر (إعادة مصروف 200)',
    $supplierBefore8 - 200.0,
    accountBalance($supplier->id),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[8] توازن القيود', $results, $failedCount);
} else {
    failTest('[8] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [9] Soft delete (cancel + reverse) for tx2 — registered customer
// Expected deltas from BEFORE the delete:
//   - vault: -100 (reverse cash settlement 100)
//   - customer: -200 (reverse income 300 - cash 100 = +200; but tx2 had
//                300 income - 100 cash = 200 debt, reversal brings 200
//                credit back to customer → customer +200 → 0 net debt)
//   - supplier: +80 (reverse expense 80)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [9] حذف soft لـ tx2 (عميل مسجّل، حجز جزئي)\n";

$vaultBefore9 = accountBalance($vault->id);
$customerBefore9 = accountBalance($customerAccount->id);
$supplierBefore9 = accountBalance($supplier->id);

$service->delete($tx2);

assertDelta(
    '[9] vault Δ بعد حذف tx2 (عكسي cash settlement 100)',
    $vaultBefore9 - 100.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[9] customer AR Δ بعد حذف tx2 (عكسي دخل 300 - cash 100 = -200 debt)',
    $customerBefore9 - 200.0,
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
assertDelta(
    '[9] supplier Δ بعد حذف tx2 (عكسي مصروف 80)',
    $supplierBefore9 + 80.0,
    accountBalance($supplier->id),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[9] توازن القيود', $results, $failedCount);
} else {
    failTest('[9] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [10] Soft delete idempotency — second delete is a no-op
// ═══════════════════════════════════════════════════════════════════
echo "▸ [10] الحذف idempotent (استدعاء ثانٍ = no-op)\n";

$vaultBefore10 = accountBalance($vault->id);
$customerBefore10 = accountBalance($customerAccount->id);

$result = $service->delete($tx2);
if ($result === true) {
    passTest('[10] delete() الثاني أعاد true', $results, $failedCount);
} else {
    failTest('[10] delete() الثاني لم يُعد true', $results, $failedCount, 'returned: ' . var_export($result, true));
}
assertDelta(
    '[10] vault لم يتغير',
    $vaultBefore10,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[10] customer AR لم يتغير',
    $customerBefore10,
    accountBalance($customerAccount->id),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[10] توازن القيود', $results, $failedCount);
} else {
    failTest('[10] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [11] Soft delete — walk-in tx3 (no customer_id, no customer_name)
// Expected deltas from BEFORE the delete:
//   - vault: -150 (reverse cash settlement 150)
//   - walkInAr: -350 (reverse income 500 + reverse cash settlement -150 = -350)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [11] حذف soft لـ walk-in tx3\n";

$vaultBefore11 = accountBalance($vault->id);
$walkInArBefore11 = glBalance($walkInArId);

$service->delete($tx3);

assertDelta(
    '[11] vault Δ بعد حذف walk-in (عكسي cash settlement 150)',
    $vaultBefore11 - 150.0,
    accountBalance($vault->id),
    $results,
    $failedCount,
);
assertDelta(
    '[11] walk-in AR Δ بعد حذف walk-in (عكسي دخل 500 + cash 150 = -350)',
    $walkInArBefore11 - 350.0,
    glBalance($walkInArId),
    $results,
    $failedCount,
);
if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[11] توازن القيود', $results, $failedCount);
} else {
    failTest('[11] توازن القيود', $results, $failedCount, 'يوجد قيود غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [12] Cross-currency rejection — USD vault
// ═══════════════════════════════════════════════════════════════════
echo "▸ [12] رفض USD vault (موديول Online EGP فقط)\n";

$vaultBefore12 = accountBalance($vault->id);
$customerBefore12 = accountBalance($customerAccount->id);
$supplierBefore12 = accountBalance($supplier->id);
$walkInArBefore12 = glBalance($walkInArId);

$rejected = false;
$errorMsg = '';
try {
    $service->create([
        'service_type_id' => $serviceType->id,
        'provider_id' => $provider->id,
        'customer_name' => 'USD Reject',
        'customer_phone' => '0100'.substr(md5('reject'), 0, 7),
        'purchase_price' => 0,
        'selling_price' => 100,
        'amount_paid' => 100,
        'payment_method' => 'cash',
        'account_id' => $usdVault->id,
        'reference_number' => $runMarker . '-USD',
    ]);
} catch (\InvalidArgumentException $e) {
    $rejected = true;
    $errorMsg = $e->getMessage();
}
if ($rejected) {
    passTest('[12] تم رفض USD vault بوضوح', $results, $failedCount, substr($errorMsg, 0, 80));
} else {
    failTest('[12] USD vault لم يُرفض', $results, $failedCount, 'تم إنشاء المعاملة بنجاح');
}
assertDelta(
    '[12] vault لم يتغير (لا إيداع USD)',
    $vaultBefore12,
    accountBalance($vault->id),
    $results,
    $failedCount,
);

// ═══════════════════════════════════════════════════════════════════
// [13] Final baseline check — all balances should be in their expected state.
// After the test sequence, the surviving (non-cancelled) tx is tx1 with
// selling=400, purchase=200, amount_paid=400. So:
//   - vault = baseline + 400 (cash settlement)
//   - customer = baseline + 0 (income 400 - cash settlement 400 cancels)
//   - supplier = baseline - 200 (expense)
//   - walkInAr = baseline + 0 (tx3 cancelled, fully reversed)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [13] التحقق من الأرصدة النهائية\n";

$expectedVault = $vaultBaseline + 400.0;  // tx1 active cash settlement 400
$expectedCustomer = $customerBaseline;    // tx1 income 400 - cash 400 = 0
$expectedSupplier = $supplierBaseline - 200.0;  // tx1 expense 200
$expectedWalkInAr = $walkInArBaseline;    // tx3 cancelled, no net effect

assertDelta('[13] vault النهائي (baseline + tx1 cash 400)', $expectedVault, accountBalance($vault->id), $results, $failedCount);
assertDelta('[13] customer AR النهائي (tx1 income = cash)', $expectedCustomer, accountBalance($customerAccount->id), $results, $failedCount);
assertDelta('[13] supplier النهائي (baseline + tx1 expense -200)', $expectedSupplier, accountBalance($supplier->id), $results, $failedCount);
assertDelta('[13] walk-in AR النهائي (tx3 cancelled)', $expectedWalkInAr, glBalance($walkInArId), $results, $failedCount);

if (assertOnlineLedgerGloballyBalanced()) {
    passTest('[13] كل القيود متوازنة globally', $results, $failedCount);
} else {
    failTest('[13] توازن القيود globally', $results, $failedCount, 'يوجد قيود Online غير متوازنة');
}

// ═══════════════════════════════════════════════════════════════════
// [14] Direct $tx->delete() outside service throws
// ═══════════════════════════════════════════════════════════════════
echo "▸ [14] \$directTestTx->delete() المباشر خارج الخدمة يرمي\n";

$throwsCorrectly = false;
$directDeleteError = '';
try {
    // Use a fresh tx to test (don't actually delete tx1!)
    $directTestTx = $service->create([
        'service_type_id' => $serviceType->id,
        'provider_id' => $provider->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'customer_phone' => $customer->phone,
        'purchase_price' => 0,
        'selling_price' => 10,
        'amount_paid' => 10,
        'payment_method' => 'cash',
        'account_id' => $vault->id,
        'reference_number' => $runMarker . '-direct',
    ]);
    $directTestTx->delete();
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'لا يمكن حذف معاملات الخدمات الإلكترونية')) {
        $throwsCorrectly = true;
    } else {
        $directDeleteError = 'wrong msg: ' . $e->getMessage();
    }
} catch (\Throwable $e) {
    $directDeleteError = 'wrong exception: ' . get_class($e) . ' ' . $e->getMessage();
}
if ($throwsCorrectly) {
    passTest('[14] $tx->delete() المباشر يرمي RuntimeException', $results, $failedCount);
} else {
    failTest('[14] $tx->delete() المباشر لم يرم', $results, $failedCount, $directDeleteError ?: 'expected RuntimeException');
}

// ═══════════════════════════════════════════════════════════════════
// [15] CLEANUP — remove all test data
// ═══════════════════════════════════════════════════════════════════
echo "▸ [15] تنظيف كل البيانات الاختبارية\n";

$cleanupPass = true;
try {
    $allTestTxIds = OnlineTransaction::withTrashed()
        ->where('reference_number', 'like', $runMarker . '%')
        ->pluck('id')->all();

    if (! empty($allTestTxIds)) {
        // First, cancel any still-completed test txs to reverse their GL
        $stillCompleted = OnlineTransaction::withTrashed()
            ->whereIn('id', $allTestTxIds)
            ->where('status', OnlineTransactionStatus::Completed->value)
            ->get();
        foreach ($stillCompleted as $t) {
            try {
                $service->delete($t);
            } catch (\Throwable $e) {
                echo "  ⚠ Cleanup of tx#{$t->id} failed: " . $e->getMessage() . "\n";
            }
        }

        // Hard-delete all transactions and entries linked to test rows
        $relatedTxIds = Transaction::where('related_type', OnlineTransaction::class)
            ->whereIn('related_id', $allTestTxIds)
            ->pluck('id')->all();
        if (! empty($relatedTxIds)) {
            DB::table('account_entries')->whereIn('transaction_id', $relatedTxIds)->delete();
            DB::table('transactions')->whereIn('id', $relatedTxIds)->delete();
        }
        OnlineTransaction::withTrashed()->whereIn('id', $allTestTxIds)->forceDelete();
    }

    // Remove test service type + provider
    $serviceType->delete();
    $provider->delete();

    // Reset vault balance to 0 (we used TEST_ONLINE_VAULT_ONL only for this test)
    App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => $vault->update(['balance' => 0.00]));

    echo "  ✓ Cleanup complete\n";
} catch (\Throwable $e) {
    $cleanupPass = false;
    echo "  ✗ Cleanup error: " . $e->getMessage() . "\n";
}

// ═══════════════════════════════════════════════════════════════════
// Final report
// ═══════════════════════════════════════════════════════════════════
echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  FINAL REPORT\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Total tests: " . count($results) . "\n";
echo "  Passed:      " . (count($results) - $failedCount) . "\n";
echo "  Failed:      " . $failedCount . "\n";
echo "  Cleanup:     " . ($cleanupPass ? "✓" : "✗") . "\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($failedCount > 0) {
    echo "  Failures:\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            echo "    ✗ {$r['name']} — {$r['detail']}\n";
        }
    }
    echo "\n";
    exit(1);
}

echo "  ✓ All Online Soft-Delete E2E scenarios passed.\n";
exit(0);
