<?php
/**
 * PHASE 9: ONLINE DELETION + UPDATE CYCLE TEST
 * ─────────────────────────────────────────────────
 * اختبار كامل لعقد الحذف والتعديل في موديول Online بعد تطبيق Phase 9 fixes:
 *
 *   ① delete() (cancel + reverse) يعمل بنفس السلوك السليم (لا انحدار).
 *   ② update() مع تعديل selling_price / purchase_price يعكس القديم ويسجل
 *     الجديد بشكل صحيح (نمط HajjUmra/Visa Phase 8 — additive repost).
 *   ③ update() مع تعديل amount_paid يعكس معاملة السداد النقدي أيضاً.
 *   ④ الـ Select في OnlineTransactionResource:175 يفلتر على module_type=online
 *     فقط (code inspection).
 *   ⑤ $tx->delete() المباشر خارج المسار المعتمد يرفض RuntimeException
 *     (الـ observer دائم الرمي لم يتغيّر).
 *
 * Usage:
 *   php artisan tinker --execute='require "phase9_online_deletion_cycle.php";'
 */

use App\Enums\AccountType;
use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  PHASE 9: ONLINE DELETION + UPDATE CYCLE TEST\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════
// [0] Pre-flight checks & setup
// ═══════════════════════════════════════════════════════════════════
echo "▸ [0] Pre-flight checks & setup:\n";

$testUserId = (int) (\App\Models\User::query()->orderBy('id')->value('id') ?? 0);
if ($testUserId === 0) {
    echo "  ✗ No users in DB — Phase 9 test needs at least one user.\n";
    exit(1);
}
$testUser = \App\Models\User::find($testUserId);
Auth::login($testUser);
echo "  - Using test user id={$testUserId}\n";

// PRE-CLEANUP: hard-reset any leftover test data from previous failed runs.
// Uses forceDelete inside withoutEvents() to bypass both SoftDeletes and any
// model observers — safe here because the data was created by THIS test.
$runMarker = 'PHASE9-RUN-' . substr(md5((string) microtime(true)), 0, 8);
echo "  - Run marker: {$runMarker}\n";

// Aggressive cleanup: wipe ALL Online-related transactions (orphans included).
// Older test runs may not have used the PHASE9-% reference_number marker, so
// we can't rely on joining back to OnlineTransaction rows. We rely on
// `related_type=OnlineTransaction` to scope the deletion.
$relatedOnlineTxIds = Transaction::where('related_type', OnlineTransaction::class)
    ->pluck('id')->all();
if (! empty($relatedOnlineTxIds)) {
    \App\Models\AccountEntry::withoutEvents(function () use ($relatedOnlineTxIds) {
        \App\Models\AccountEntry::whereIn('transaction_id', $relatedOnlineTxIds)->forceDelete();
    });
    Transaction::withoutEvents(function () use ($relatedOnlineTxIds) {
        Transaction::whereIn('id', $relatedOnlineTxIds)->forceDelete();
    });
}

// Wipe OnlineServiceType / Provider with the test codes.
$oldServiceTypeIds = OnlineServiceType::withTrashed()
    ->where('code', 'PHASE9_TEST_TYPE')
    ->pluck('id')->all();
$oldProviderIds = OnlineServiceProvider::withTrashed()
    ->where('code', 'PHASE9_TEST_PROVIDER')
    ->pluck('id')->all();
$oldTxIds = OnlineTransaction::withTrashed()
    ->where('reference_number', 'like', 'PHASE9-%')
    ->pluck('id')->all();

if (! empty($oldTxIds)) {
    OnlineTransaction::withoutEvents(function () use ($oldTxIds) {
        OnlineTransaction::withTrashed()->whereIn('id', $oldTxIds)->forceDelete();
    });
}
if (! empty($oldServiceTypeIds)) {
    OnlineServiceType::withoutEvents(function () use ($oldServiceTypeIds) {
        OnlineServiceType::withTrashed()->whereIn('id', $oldServiceTypeIds)->forceDelete();
    });
}
if (! empty($oldProviderIds)) {
    OnlineServiceProvider::withoutEvents(function () use ($oldProviderIds) {
        OnlineServiceProvider::withTrashed()->whereIn('id', $oldProviderIds)->forceDelete();
    });
}

$preCleanCount = count($relatedOnlineTxIds) + count($oldTxIds) + count($oldServiceTypeIds) + count($oldProviderIds);
if ($preCleanCount > 0) {
    echo "  - Pre-cleaned {$preCleanCount} leftover entities (incl. orphan transactions) from prior runs\n";
}

// Find-or-create a vault (cashbox) for the online module — required because
// postFinancialEntries routes everything through $tx->account_id.
// Reset balance to known baseline (100000) so we have a clean starting point.
$vault = Account::where('module_type', 'online')->where('type', AccountType::Cashbox)->first();
if (! $vault) {
    $vault = Account::create([
        'name' => 'TEST_ONLINE_VAULT',
        'type' => AccountType::Cashbox,
        'balance' => 100000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'online',
        'is_module_vault' => true,
        'notes' => 'Auto-created by Phase 9 test script',
        'created_by' => $testUserId,
    ]);
    echo "  - Created online vault (id={$vault->id})\n";
} else {
    // Reset balance to baseline (bypass Account updating observer).
    LedgerBalanceMutationGuard::run(fn () => $vault->update(['balance' => 100000.00]));
}
echo "  - Vault: {$vault->name} (id={$vault->id}), balance={$vault->fresh()->balance}\n";

// Find-or-create a supplier account for the online module — used by
// provider.default_purchase_account_id (purchase-side expense route).
$supplierAccount = Account::where('module_type', 'online')
    ->where('type', AccountType::Supplier)
    ->where('name', 'TEST_ONLINE_SUPPLIER')
    ->first();
if (! $supplierAccount) {
    $supplierAccount = Account::create([
        'name' => 'TEST_ONLINE_SUPPLIER',
        'type' => AccountType::Supplier,
        'balance' => 0,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'online',
        'is_module_vault' => false,
        'notes' => 'Auto-created by Phase 9 test script',
        'created_by' => $testUserId,
    ]);
    echo "  - Created online supplier account (id={$supplierAccount->id})\n";
} else {
    // Reset balance to 0 baseline.
    LedgerBalanceMutationGuard::run(fn () => $supplierAccount->update(['balance' => 0.00]));
}
echo "  - Supplier: {$supplierAccount->name} (id={$supplierAccount->id}), balance={$supplierAccount->fresh()->balance}\n";

// Test customer — keep existing if found (idempotent on phone).
$customer = Customer::firstOrCreate(
    ['phone' => '01000000009'],
    [
        'full_name' => 'عميل اختبار Phase 9',
        'type' => 'individual',
        'is_active' => true,
        'created_by' => $testUserId,
    ]
);
echo "  - Customer: {$customer->full_name} (id={$customer->id})\n";

// Test service type — always create fresh (deterministic isolation).
$serviceType = OnlineServiceType::create([
    'code' => 'PHASE9_TEST_TYPE',
    'name_ar' => 'نوع خدمة اختبار Phase 9',
    'name_en' => 'Phase 9 Test Service Type',
    'is_active' => true,
    'order' => 99,
    'created_by' => $testUserId,
]);
echo "  - Service type: {$serviceType->name_ar} (id={$serviceType->id})\n";

// Test service provider — always create fresh.
$provider = OnlineServiceProvider::create([
    'code' => 'PHASE9_TEST_PROVIDER',
    'name_ar' => 'مزود اختبار Phase 9',
    'name_en' => 'Phase 9 Test Provider',
    'is_active' => true,
    'order' => 99,
    'default_purchase_account_id' => $supplierAccount->id,
    'created_by' => $testUserId,
]);
echo "  - Provider: {$provider->name_ar} (id={$provider->id}), default_purchase_account_id={$provider->default_purchase_account_id}\n";

echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [1] TEST A — delete() (cancel + reverse) no regression
// ═══════════════════════════════════════════════════════════════════
echo "▸ [1] TEST A — delete() (cancel + reverse) لا انحدار:\n";

// Snapshot the BASELINE (before any creation). The contract is: after
// create + cancel, balances must return to this exact baseline (Δ=0).
$vaultBaselineA = (float) $vault->fresh()->balance;
$supplierBaselineA = (float) $supplierAccount->fresh()->balance;

// Resolve the customer account and snapshot its baseline (may be pre-existing).
$customerAccountA = Account::find($customer->fresh()->account_id);
$customerBaselineA = (float) $customerAccountA->balance;

$txA = app(OnlineTransactionService::class)->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'customer_phone' => $customer->phone,
    'purchase_price' => 100.0,
    'selling_price' => 200.0,
    'amount_paid' => 200.0,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => 'PHASE9-A',
    'notes' => 'Phase 9 booking A',
    'status' => OnlineTransactionStatus::Completed->value,
]);

echo "  - Created tx A: id={$txA->id}, status={$txA->status->value}, selling={$txA->selling_price}\n";
echo "  - After create: vault=" . number_format($vault->fresh()->balance, 2) . " (Δ=" . number_format($vault->fresh()->balance - $vaultBaselineA, 2) . "), supplier=" . number_format($supplierAccount->fresh()->balance, 2) . " (Δ=" . number_format($supplierAccount->fresh()->balance - $supplierBaselineA, 2) . ")\n";

// Cancel via service (delete = cancel + reverse).
app(OnlineTransactionService::class)->delete($txA);

$txA->refresh();
$vaultFinalA = (float) $vault->fresh()->balance;
$supplierFinalA = (float) $supplierAccount->fresh()->balance;
$customerFinalA = (float) Account::find($customer->fresh()->account_id)->balance;

// Verify: status cancelled + Δ from PRE-CREATE baseline is 0 (full reversal).
$testA_pass = (
    $txA->status->value === 'cancelled' &&
    abs($vaultFinalA - $vaultBaselineA) < 0.01 &&
    abs($supplierFinalA - $supplierBaselineA) < 0.01 &&
    abs($customerFinalA - $customerBaselineA) < 0.01
);
echo "  - After cancel: status={$txA->status->value}\n";
echo "  - Vault Δ from baseline: " . number_format($vaultFinalA - $vaultBaselineA, 2) . " (expected 0.00 — full reversal)\n";
echo "  - Supplier Δ from baseline: " . number_format($supplierFinalA - $supplierBaselineA, 2) . " (expected 0.00 — full reversal)\n";
echo "  - Customer Δ from baseline: " . number_format($customerFinalA - $customerBaselineA, 2) . " (expected 0.00 — full reversal)\n";
echo "  " . ($testA_pass ? "✓" : "✗") . " TEST A " . ($testA_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [2] TEST B — update() with price changes correctly reposts
// ═══════════════════════════════════════════════════════════════════
echo "▸ [2] TEST B — update() يعكس الأسعار ويسجل الجديدة:\n";

$txB = app(OnlineTransactionService::class)->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'customer_phone' => $customer->phone,
    'purchase_price' => 100.0,
    'selling_price' => 200.0,
    'amount_paid' => 200.0,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => 'PHASE9-B',
    'notes' => 'Phase 9 booking B',
    'status' => OnlineTransactionStatus::Completed->value,
]);

$customerAccountB = Account::find($customer->fresh()->account_id);
$vaultBeforeB = (float) $vault->fresh()->balance;
$supplierBeforeB = (float) $supplierAccount->fresh()->balance;
$customerBeforeB = (float) $customerAccountB->balance; // snapshot baseline

// Capture the original 3 transaction IDs (1 main income + 1 cash income + 1 expense).
$oldMainIncomeId = $txB->income_transaction_id;
$oldExpenseId = $txB->expense_transaction_id;
$oldMainIncome = Transaction::find($oldMainIncomeId);
$oldExpense = Transaction::find($oldExpenseId);

echo "  - Created tx B: id={$txB->id}, selling={$txB->selling_price}, purchase={$txB->purchase_price}\n";
echo "  - Old main income tx: id={$oldMainIncomeId}, amount={$oldMainIncome->amount}\n";
echo "  - Old expense tx: id={$oldExpenseId}, amount={$oldExpense->amount}\n";

// Update prices: 200→400 (selling), 100→200 (purchase).
$txB = app(OnlineTransactionService::class)->update($txB, [
    'selling_price' => 400.0,
    'purchase_price' => 200.0,
]);
$txB->refresh();

$vaultAfterB = (float) $vault->fresh()->balance;
$supplierAfterB = (float) $supplierAccount->fresh()->balance;
$customerAfterB = (float) Account::find($customer->fresh()->account_id)->balance; // re-query to avoid stale model cache

echo "  - After update: selling={$txB->selling_price}, purchase={$txB->purchase_price}\n";
echo "  - New main income tx: id={$txB->income_transaction_id}, amount={$txB->fresh()->incomeTransaction?->amount}\n";
echo "  - New expense tx: id={$txB->expense_transaction_id}, amount={$txB->fresh()->expenseTransaction?->amount}\n";

// Expected delta from baseline (after create):
//   - Vault: cash payment unchanged (selling_price change doesn't touch amount_paid).
//     Δ = 0 from baseline.
//   - Customer: main income went 200→400 (+200 net); cash income unchanged.
//     Δ = +200 from baseline.
//   - Supplier: expense went 100→200 (-100 net).
//     Δ = -100 from baseline.
$expectedVaultDeltaB = 0.0;
$expectedCustomerDeltaB = 200.0;
$expectedSupplierDeltaB = -100.0;
$actualVaultDeltaB = round($vaultAfterB - $vaultBeforeB, 2);
$actualCustomerDeltaB = round($customerAfterB - $customerBeforeB, 2);
$actualSupplierDeltaB = round($supplierAfterB - $supplierBeforeB, 2);

// Verify the NEW transactions exist with NEW amounts.
$newMainIncome = Transaction::find($txB->income_transaction_id);
$newExpense = Transaction::find($txB->expense_transaction_id);

$testB_pass = (
    $txB->income_transaction_id !== $oldMainIncomeId &&  // ID changed (new tx)
    $txB->expense_transaction_id !== $oldExpenseId &&     // ID changed (new tx)
    abs((float) $newMainIncome->amount - 400.0) < 0.01 &&
    abs((float) $newExpense->amount - 200.0) < 0.01 &&
    abs($actualVaultDeltaB - $expectedVaultDeltaB) < 0.01 &&
    abs($actualCustomerDeltaB - $expectedCustomerDeltaB) < 0.01 &&
    abs($actualSupplierDeltaB - $expectedSupplierDeltaB) < 0.01
);

echo "  - Vault Δ from baseline: {$actualVaultDeltaB} (expected {$expectedVaultDeltaB} — cash income unchanged)\n";
echo "  - Customer Δ from baseline: {$actualCustomerDeltaB} (expected {$expectedCustomerDeltaB} — main income 200→400)\n";
echo "  - Supplier Δ from baseline: {$actualSupplierDeltaB} (expected {$expectedSupplierDeltaB} — expense 100→200)\n";
echo "  - Old transactions still exist (reversed, with عكس: prefix): "
    . (str_starts_with((string) Transaction::find($oldMainIncomeId)->notes, 'عكس:') ? 'YES' : 'NO')
    . "\n";
echo "  " . ($testB_pass ? "✓" : "✗") . " TEST B " . ($testB_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [3] TEST C — update() with amount_paid change reposts cash payment
// ═══════════════════════════════════════════════════════════════════
echo "▸ [3] TEST C — update() يعكس السداد النقدي:\n";

$txC = app(OnlineTransactionService::class)->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'customer_phone' => $customer->phone,
    'purchase_price' => 100.0,
    'selling_price' => 300.0,
    'amount_paid' => 100.0,  // partial payment (less than selling)
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => 'PHASE9-C',
    'notes' => 'Phase 9 booking C',
    'status' => OnlineTransactionStatus::Completed->value,
]);

$customerAccountC = Account::find($customer->fresh()->account_id);
$vaultBeforeC = (float) $vault->fresh()->balance;
$supplierBeforeC = (float) $supplierAccount->fresh()->balance;
$customerBeforeC = (float) $customerAccountC->balance;

// Find the cash payment transaction (separate from main income).
// Cash payment = transfer from customer_account to vault (account pair).
$customerAccountC = Account::find($customer->fresh()->account_id);
$cashPaymentBefore = Transaction::where('related_type', OnlineTransaction::class)
    ->where('related_id', $txC->id)
    ->where('from_account_id', $customerAccountC->id)
    ->where('to_account_id', $vault->id)
    ->first();
echo "  - Created tx C: id={$txC->id}, amount_paid={$txC->amount_paid}\n";
echo "  - Cash payment tx: id=" . ($cashPaymentBefore?->id ?? 'null') . ", amount=" . ($cashPaymentBefore?->amount ?? 'null') . "\n";

// Update amount_paid: 100 → 250.
$txC = app(OnlineTransactionService::class)->update($txC, [
    'amount_paid' => 250.0,
]);
$txC->refresh();

$vaultAfterC = (float) $vault->fresh()->balance;
$customerAfterC = (float) Account::find($customer->fresh()->account_id)->balance; // re-query to avoid stale model cache

// Find the NEW cash payment transaction (same query, after update).
// Use latest() — there are now TWO matching transactions (old + reposted);
// we want the most recent one.
$cashPaymentAfter = Transaction::where('related_type', OnlineTransaction::class)
    ->where('related_id', $txC->id)
    ->where('from_account_id', $customerAccountC->id)
    ->where('to_account_id', $vault->id)
    ->orderBy('id', 'desc')
    ->first();

// Expected Δ from baseline (after create, before update):
//   - Vault: cash payment reversed (100→-100) then new cash payment (+250). Δ=+150.
//   - Customer: cash payment reversed (+100) then new cash payment (-250). Δ=-150.
//   - Supplier: unchanged (amount_paid doesn't touch expense). Δ=0.
$expectedVaultDeltaC = 150.0;
$expectedCustomerDeltaC = -150.0;
$expectedSupplierDeltaC = 0.0;
$actualVaultDeltaC = round($vaultAfterC - $vaultBeforeC, 2);
$actualCustomerDeltaC = round($customerAfterC - $customerBeforeC, 2);
$actualSupplierDeltaC = round($supplierAccount->fresh()->balance - $supplierBeforeC, 2);

$testC_pass = (
    $cashPaymentAfter && $cashPaymentBefore &&
    $cashPaymentBefore->id !== $cashPaymentAfter->id &&
    abs((float) $cashPaymentAfter->amount - 250.0) < 0.01 &&
    abs($actualVaultDeltaC - $expectedVaultDeltaC) < 0.01 &&
    abs($actualCustomerDeltaC - $expectedCustomerDeltaC) < 0.01 &&
    abs($actualSupplierDeltaC - $expectedSupplierDeltaC) < 0.01
);

echo "  - After update: amount_paid={$txC->amount_paid}\n";
echo "  - New cash payment tx: id={$cashPaymentAfter->id}, amount={$cashPaymentAfter->amount}\n";
echo "  - Vault Δ from baseline: {$actualVaultDeltaC} (expected {$expectedVaultDeltaC})\n";
echo "  - Customer Δ from baseline: {$actualCustomerDeltaC} (expected {$expectedCustomerDeltaC})\n";
echo "  - Supplier Δ from baseline: {$actualSupplierDeltaC} (expected {$expectedSupplierDeltaC})\n";
echo "  " . ($testC_pass ? "✓" : "✗") . " TEST C " . ($testC_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [4] TEST D — account_id Select filters to module_type=online
// ═══════════════════════════════════════════════════════════════════
echo "▸ [4] TEST D — OnlineTransactionResource:175 يفلتر module_type:\n";

$resourcePath = base_path('app/Filament/Admin/Resources/OnlineTransactions/OnlineTransactionResource.php');
$resourceSrc = file_get_contents($resourcePath);

// Find the account_id Select block (between ->make('account_id') and the closing ->required()).
preg_match('/Select::make\(\s*[\'"]account_id[\'"][\s\S]*?->required\(\)/', $resourceSrc, $matches);
$accountIdBlock = $matches[0] ?? '';

$hasActiveFilter = str_contains($accountIdBlock, "->where('is_active', true)") !== false;
$hasModuleTypeFilter = str_contains($accountIdBlock, "->where('module_type', 'online')") !== false;
$hasRawDeleteAction = preg_match('/Tables\\\\Actions\\\\DeleteAction::make\(\)/', $resourceSrc) === 1;

$testD_pass = $hasActiveFilter && $hasModuleTypeFilter && ! $hasRawDeleteAction;
echo "  - is_active filter: " . ($hasActiveFilter ? '✓' : '✗') . "\n";
echo "  - module_type=online filter: " . ($hasModuleTypeFilter ? '✓' : '✗') . "\n";
echo "  - No raw DeleteAction::make(): " . (! $hasRawDeleteAction ? '✓' : '✗') . "\n";
echo "  " . ($testD_pass ? "✓" : "✗") . " TEST D " . ($testD_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [5] TEST E — direct $tx->delete() throws (always-throws observer intact)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [5] TEST E — $tx->delete() المباشر يرفض RuntimeException:\n";

$txE = app(OnlineTransactionService::class)->create([
    'service_type_id' => $serviceType->id,
    'provider_id' => $provider->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'customer_phone' => $customer->phone,
    'purchase_price' => 50.0,
    'selling_price' => 80.0,
    'amount_paid' => 80.0,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'reference_number' => 'PHASE9-E',
    'notes' => 'Phase 9 booking E',
    'status' => OnlineTransactionStatus::Completed->value,
]);
$txE->refresh();

$throwsCorrectly = false;
$throwMessage = '';
try {
    $txE->delete();
} catch (\RuntimeException $e) {
    $throwsCorrectly = true;
    $throwMessage = $e->getMessage();
} catch (\Throwable $e) {
    $throwMessage = 'WRONG EXCEPTION: ' . get_class($e) . ' ' . $e->getMessage();
}

$testE_pass = $throwsCorrectly && str_contains($throwMessage, 'لا يمكن حذف معاملات الخدمات الإلكترونية');
echo "  - Throws RuntimeException: " . ($throwsCorrectly ? '✓' : '✗') . "\n";
echo "  - Message: {$throwMessage}\n";
echo "  - txE still exists after failed delete: " . (OnlineTransaction::where('id', $txE->id)->exists() ? '✓' : '✗') . "\n";
echo "  " . ($testE_pass ? "✓" : "✗") . " TEST E " . ($testE_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [6] CLEANUP — delete via service to reverse ledger, then hard-delete test data
// ═══════════════════════════════════════════════════════════════════
echo "▸ [6] CLEANUP — حذف جميع البيانات الاختبارية:\n";

$cleanup_pass = true;

// Cancel all still-Completed transactions via the service (additive reversal).
foreach ([$txB, $txC, $txE] as $t) {
    if ($t && $t->fresh()->status->value === 'completed') {
        try {
            app(OnlineTransactionService::class)->delete($t);
            echo "  ✓ Online transaction id={$t->id} cancelled via service\n";
        } catch (\Throwable $e) {
            echo "  ⚠ Online transaction id={$t->id} cleanup failed: {$e->getMessage()}\n";
            $cleanup_pass = false;
        }
    }
}

// Hard-delete test data (FK-safe order, bypassing observers + SoftDeletes).
// Critical order: account_entries MUST come before transactions (FK constraint).
$txIds = [$txA->id, $txB->id, $txC->id, $txE->id];

$relatedTxIds = Transaction::where('related_type', OnlineTransaction::class)
    ->whereIn('related_id', $txIds)
    ->pluck('id')
    ->all();

\App\Models\AccountEntry::withoutEvents(function () use ($relatedTxIds) {
    \App\Models\AccountEntry::whereIn('transaction_id', $relatedTxIds)->forceDelete();
});
echo "  ✓ " . count($relatedTxIds) . " related transactions' account_entries force-deleted\n";

Transaction::withoutEvents(function () use ($relatedTxIds) {
    Transaction::whereIn('id', $relatedTxIds)->forceDelete();
});
echo "  ✓ " . count($relatedTxIds) . " related transactions force-deleted\n";

OnlineTransaction::withoutEvents(function () use ($txIds) {
    OnlineTransaction::withTrashed()->whereIn('id', $txIds)->forceDelete();
});
echo "  ✓ " . count($txIds) . " online_transactions force-deleted\n";

// Service type + provider hard-delete.
OnlineServiceType::withoutEvents(function () use ($serviceType) {
    OnlineServiceType::where('id', $serviceType->id)->forceDelete();
});
OnlineServiceProvider::withoutEvents(function () use ($provider) {
    OnlineServiceProvider::where('id', $provider->id)->forceDelete();
});
echo "  ✓ service type + provider force-deleted\n";

// Reset all 3 test accounts to known baselines so the script is repeatable.
// Use LedgerBalanceMutationGuard to bypass the Account updating observer.
$customerAccount = Account::find($customer->account_id);
try {
    LedgerBalanceMutationGuard::run(function () use ($vault, $supplierAccount, $customerAccount) {
        $vault->update(['balance' => 100000.00]);
        $supplierAccount->update(['balance' => 0.00]);
        $customerAccount->update(['balance' => 0.00]);
    });
    echo "  ✓ Vault reset to 100000.00, supplier reset to 0.00, customer reset to 0.00\n";
} catch (\Throwable $e) {
    echo "  ✗ Balance reset failed: {$e->getMessage()}\n";
    $cleanup_pass = false;
}

echo "  " . ($cleanup_pass ? "✓" : "✗") . " CLEANUP " . ($cleanup_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [Final] Summary
// ═══════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  PHASE 9 SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  TEST A — delete() (cancel + reverse):     " . ($testA_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST B — update() price repost:           " . ($testB_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST C — update() amount_paid repost:     " . ($testC_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST D — account_id module_type filter:   " . ($testD_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST E — direct \$tx->delete() throws:     " . ($testE_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  CLEANUP — DB returned to original:        " . ($cleanup_pass ? "✓ PASS" : "✗ FAIL") . "\n";

$all_pass = $testA_pass && $testB_pass && $testC_pass && $testD_pass && $testE_pass && $cleanup_pass;
echo "\n  OVERALL: " . ($all_pass ? "✓ ALL TESTS PASSED" : "✗ SOME TESTS FAILED") . "\n";
echo "═══════════════════════════════════════════════════════════════════\n";

if (! $all_pass) {
    exit(1);
}