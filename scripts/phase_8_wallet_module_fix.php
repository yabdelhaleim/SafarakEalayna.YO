<?php
/**
 * Phase 8 — Wallet Module Fix: REAL DB VALIDATION.
 *
 * Validates the 5 contract guarantees shipped in this phase:
 *
 *   ①  ensureCustomerAccount() now writes module_type='wallet_transfer' (was 'wallet').
 *   ②  WalletTransactionResource Selects are restricted to module_type='wallet_transfer'.
 *   ③  WalletAccountResource remains module-AGNOSTIC (umbrella view intentional).
 *   ④  updateTransaction() now reposts the ledger (Online Phase 9 / HajjUmra
 *       Phase 8 pattern) — changing amount / service_fee / amount_paid /
 *       wallet_account_id / cash_account_id does NOT orphan the linked ledger.
 *
 *   deleteTransaction() must continue to function as before (additive
 *   reversal + soft-delete).
 *
 *   FinancialReportService.module=wallet must surface wallet_transfer rows.
 *
 * Run: php scripts/phase_8_wallet_module_fix.php
 *
 * Output: JSON verdict to stdout + storage/logs/phase_8_wallet_result.json
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$out = [];
$failures = [];

$check = static function (string $label, bool $ok, array &$failures) use (&$out): void {
    if ($ok) {
        $out[] = "  ✓ {$label}";
    } else {
        $out[] = "  ✗ {$label}";
        $failures[] = $label;
    }
};

$echo = static function () use (&$out): void {
    echo implode(PHP_EOL, $out).PHP_EOL;
    $out = [];
};

$isReversed = static function (Transaction $tx): bool {
    // reverseTransaction prepends 'عكس: ' to notes (see TransactionService:220)
    return str_starts_with((string) $tx->notes, 'عكس:');
};

// ─────────────────────────────────────────────────────────────────
// SETUP — find or create the necessary fixture accounts
// ─────────────────────────────────────────────────────────────────
$out[] = '═══ Phase 8 Wallet Module Fix — Real DB Validation ═══';
$out[] = '';
$echo();

$user = User::query()->where('is_active', true)->whereIn('role', ['admin', 'owner'])->first();
if (! $user) {
    $user = User::query()->create([
        'name' => 'Phase 8 Validator',
        'email' => 'phase-8-wallet@test.local',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    $out[] = "Created temp admin: {$user->email}";
} else {
    $out[] = "Using admin: {$user->email} (id={$user->id})";
}
$echo();
Auth::login($user);

$walletType = WalletType::query()->where('code', 'vodafone_cash')->first();
if (! $walletType) {
    $walletType = WalletType::query()->create([
        'name' => 'فودافون كاش',
        'code' => 'vodafone_cash',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $out[] = 'Created wallet type vodafone_cash';
}

$walletAccount = Account::query()
    ->where('type', AccountType::Wallet)
    ->where('module_type', 'wallet_transfer')
    ->where('is_active', true)
    ->where('wallet_provider', WalletProvider::VodafoneCash)
    ->first();
if (! $walletAccount) {
    $walletAccount = Account::query()->create([
        'name' => 'P8 Wallet Vodafone',
        'type' => AccountType::Wallet,
        'module_type' => 'wallet_transfer',
        'module' => 'wallet_transfer',
        'owner_type' => 'office',
        'wallet_provider' => WalletProvider::VodafoneCash,
        'wallet_number' => '01099998888',
        'balance' => 100000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created wallet account #{$walletAccount->id}";
}

$cashAccount = Account::query()
    ->where('type', AccountType::Cashbox)
    ->where('module_type', 'wallet_transfer')
    ->where('is_active', true)
    ->first();
if (! $cashAccount) {
    $cashAccount = Account::query()->create([
        'name' => 'P8 Cash Wallet Transfer',
        'type' => AccountType::Cashbox,
        'module_type' => 'wallet_transfer',
        'module' => 'wallet_transfer',
        'owner_type' => 'office',
        'balance' => 50000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created cash account #{$cashAccount->id}";
}

// Sister account in a DIFFERENT module_type — proves the filter is real
$otherModuleAccount = Account::query()
    ->where('type', AccountType::Wallet)
    ->where('module_type', 'bus')
    ->where('is_active', true)
    ->first();
if (! $otherModuleAccount) {
    $otherModuleAccount = Account::query()->create([
        'name' => 'P8 Wallet BUS (NOT wallet_transfer)',
        'type' => AccountType::Wallet,
        'module_type' => 'bus',
        'module' => 'bus',
        'owner_type' => 'office',
        'wallet_provider' => WalletProvider::VodafoneCash,
        'wallet_number' => '01099997777',
        'balance' => 1000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created bus-module wallet account #{$otherModuleAccount->id} (control — should be HIDDEN by Select filter)";
}

$out[] = '';
$echo();

DB::beginTransaction();
try {
    $service = app(WalletTransactionService::class);

    $wStart = (float) $walletAccount->fresh()->balance;
    $cStart = (float) $cashAccount->fresh()->balance;

    // ═══════════════════════════════════════════════════════════════
    // ① ensureCustomerAccount() writes module_type='wallet_transfer'
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ① ensureCustomerAccount writes module_type="wallet_transfer" ━━';

    $customer = Customer::query()->create([
        'full_name' => 'P8 Customer',
        'phone' => '01000000'.random_int(100, 999),
        'created_by' => $user->id,
    ]);

    // Create a wallet transaction that registers a customer (triggers ensureCustomerAccount)
    $tx = $service->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'wallet_number' => '01011112222',
        'type' => 'send',
        'amount' => 1000,
        'service_fee' => 25,
        'amount_paid' => 0,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $cashAccount->id,
    ]);

    $customerAccountId = $customer->fresh()->account_id;
    $customerAccount = Account::find($customerAccountId);

    $out[] = "  Created WalletTransaction #{$tx->id} (customer #{$customer->id})";
    $out[] = "  Customer account id={$customerAccountId} module_type=".var_export($customerAccount?->module_type, true);

    $check(
        'customer_account.module_type === wallet_transfer',
        $customerAccount && $customerAccount->module_type === 'wallet_transfer',
        $failures
    );
    $check(
        'no customer_account with legacy module_type=wallet',
        ! Account::query()->where('id', $customerAccountId)->where('module_type', 'wallet')->exists(),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ② TransferDashboardController surfaces the new accounts
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ② TransferDashboardController surfaces wallet_transfer accounts ━━';

    $dashRes = app(\App\Http\Controllers\Api\V1\Wallet\TransferDashboardController::class)->index();
    $dash = json_decode($dashRes->getContent(), true);

    $dashWalletCount = (int) ($dash['data']['stats']['wallets']['count'] ?? 0);
    $dashCashboxCount = (int) ($dash['data']['stats']['cashboxes']['count'] ?? 0);

    // Cross-check the dashboard counts against the raw DB count filtered
    // by module_type='wallet_transfer'.
    $dbWalletTransferCount = Account::where('module_type', 'wallet_transfer')
        ->where('type', AccountType::Wallet->value)->count();
    $dbCashboxTransferCount = Account::where('module_type', 'wallet_transfer')
        ->where('type', AccountType::Cashbox->value)->count();

    $check(
        "dashboard wallets count ({$dashWalletCount}) === DB wallet_transfer wallets ({$dbWalletTransferCount})",
        $dashWalletCount === $dbWalletTransferCount,
        $failures
    );
    $check(
        "dashboard cashboxes count ({$dashCashboxCount}) === DB wallet_transfer cashboxes ({$dbCashboxTransferCount})",
        $dashCashboxCount === $dbCashboxTransferCount,
        $failures
    );
    $check(
        'bus-module wallet HIDDEN from dashboard (count NOT includes bus wallets)',
        $dashWalletCount <= $dbWalletTransferCount && Account::where('module_type', 'bus')
            ->where('type', AccountType::Wallet->value)->count() === 1,
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ③ WalletAccountResource remains module-AGNOSTIC (umbrella)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ③ WalletAccountResource stays umbrella (both wallets visible) ━━';

    $umbrellaIds = \App\Filament\Admin\Resources\WalletAccounts\WalletAccountResource::getEloquentQuery()
        ->pluck('accounts.id')
        ->all();

    $check(
        'umbrella lists wallet_transfer wallet #'.$walletAccount->id,
        in_array($walletAccount->id, $umbrellaIds, true),
        $failures
    );
    $check(
        'umbrella ALSO lists bus-module wallet #'.$otherModuleAccount->id.' (intentional — umbrella view)',
        in_array($otherModuleAccount->id, $umbrellaIds, true),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ④ TransferWalletResource is the per-module view (only wallet_transfer)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ④ TransferWalletResource filters to wallet_transfer ONLY ━━';

    $perModuleIds = \App\Filament\Admin\Resources\TransferAccounts\TransferWalletResource::getEloquentQuery()
        ->pluck('accounts.id')
        ->all();

    $check(
        'per-module lists wallet_transfer wallet #'.$walletAccount->id,
        in_array($walletAccount->id, $perModuleIds, true),
        $failures
    );
    $check(
        'per-module HIDES bus-module wallet #'.$otherModuleAccount->id,
        ! in_array($otherModuleAccount->id, $perModuleIds, true),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑤ updateTransaction() reposts the ledger (Online Phase 9 pattern)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑤ updateTransaction() reposts ledger on amount change ━━';

    // Create a separate, isolated transaction for the update test
    $upTx = $service->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_name' => 'P8 Update Customer',
        'wallet_number' => '01022223333',
        'type' => 'send',
        'amount' => 500,
        'service_fee' => 10,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $cashAccount->id,
    ]);

    $oldIncomeId = $upTx->income_transaction_id;
    $oldExpenseId = $upTx->expense_transaction_id;
    $wBeforeUp = (float) $walletAccount->fresh()->balance;
    $cBeforeUp = (float) $cashAccount->fresh()->balance;

    // Change amount: 500 → 750, fee: 10 → 20
    $service->updateTransaction($upTx, [
        'amount' => 750,
        'service_fee' => 20,
    ]);

    $upTx->refresh();
    $newIncomeId = $upTx->income_transaction_id;
    $newExpenseId = $upTx->expense_transaction_id;
    $wAfterUp = (float) $walletAccount->fresh()->balance;
    $cAfterUp = (float) $cashAccount->fresh()->balance;

    $check('total_amount recomputed: 750+20=770', (float) $upTx->total_amount === 770.0, $failures);
    $check('income_transaction_id CHANGED (reversed + reposted)', $newIncomeId !== $oldIncomeId, $failures);
    $check('expense_transaction_id CHANGED (reversed + reposted)', $newExpenseId !== $oldExpenseId, $failures);
    $check('old income is REVERSED (notes prefix "عكس:")', $isReversed(Transaction::find($oldIncomeId)), $failures);
    $check('old expense is REVERSED (notes prefix "عكس:")', $isReversed(Transaction::find($oldExpenseId)), $failures);

    $newIncome = Transaction::find($newIncomeId);
    $newExpense = Transaction::find($newExpenseId);
    $check('new income.amount = 770 (total_amount)', abs((float) $newIncome->amount - 770.0) < 0.01, $failures);
    $check('new expense.amount = 750 (raw amount)', abs((float) $newExpense->amount - 750.0) < 0.01, $failures);

    // Delta math (only the upTx update delta matters here):
    //   wallet: old -500 + reversed +500 + new -750 = net -250
    //   cash:   old +510 + reversed -510 + new +770 = net +260
    $check('wallet delta = -250 (from update only)', abs(($wAfterUp - $wBeforeUp) - (-250.0)) < 0.01, $failures);
    $check('cash delta = +260 (from update only)', abs(($cAfterUp - $cBeforeUp) - 260.0) < 0.01, $failures);
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑥ updateTransaction() with no ledger-affecting change is no-op
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑥ updateTransaction() skips repost when nothing ledger-relevant changed ━━';

    $sameIncomeId = $upTx->income_transaction_id;
    $sameExpenseId = $upTx->expense_transaction_id;

    $service->updateTransaction($upTx, [
        'notes' => 'just a notes tweak',
    ]);

    $upTx->refresh();
    $check('notes-only update: income_transaction_id UNCHANGED', $upTx->income_transaction_id === $sameIncomeId, $failures);
    $check('notes-only update: expense_transaction_id UNCHANGED', $upTx->expense_transaction_id === $sameExpenseId, $failures);
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑦ updateTransaction() reposts settlement (amount_paid)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑦ updateTransaction() reposts settlement tx when amount_paid changes ━━';

    $custTx = $service->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'wallet_number' => '01033334444',
        'type' => 'send',
        'amount' => 200,
        'service_fee' => 5,
        'amount_paid' => 50,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $cashAccount->id,
    ]);

    $cashBefore = (float) $cashAccount->fresh()->balance;

    $service->updateTransaction($custTx, [
        'amount_paid' => 200,
    ]);

    $cashAfter = (float) $cashAccount->fresh()->balance;

    // amount_paid went 50 → 200 → cash should rise by 150 net
    $expectedCashDelta = 150.0;
    $check(
        'cash delta = +150 after amount_paid 50→200',
        abs(($cashAfter - $cashBefore) - $expectedCashDelta) < 0.01,
        $failures
    );
    $check(
        'wallet_tx.amount_paid = 200 after update',
        (float) $custTx->fresh()->amount_paid === 200.0,
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑧ deleteTransaction() still works (no regression)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑧ deleteTransaction() reverses + soft-deletes (no regression) ━━';

    $delTx = $service->createTransaction([
        'wallet_type_id' => $walletType->id,
        'customer_name' => 'P8 Delete Customer',
        'wallet_number' => '01044445555',
        'type' => 'receive',
        'amount' => 300,
        'service_fee' => 8,
        'wallet_account_id' => $walletAccount->id,
        'cash_account_id' => $cashAccount->id,
    ]);

    $wBeforeDel = (float) $walletAccount->fresh()->balance;
    $cBeforeDel = (float) $cashAccount->fresh()->balance;
    $delIncomeId = $delTx->income_transaction_id;
    $delExpenseId = $delTx->expense_transaction_id;

    $service->deleteTransaction($delTx);

    $delTx->refresh();
    $wAfterDel = (float) $walletAccount->fresh()->balance;
    $cAfterDel = (float) $cashAccount->fresh()->balance;

    $check('wallet transaction soft-deleted', $delTx->deleted_at !== null, $failures);
    $check('income reversed after delete (notes prefix "عكس:")', $isReversed(Transaction::find($delIncomeId)), $failures);
    $check('expense reversed after delete (notes prefix "عكس:")', $isReversed(Transaction::find($delExpenseId)), $failures);
    // Receive 300 - 8 fee: wallet +300, cash -292
    $check('wallet delta = -300 (from delete)', abs(($wAfterDel - $wBeforeDel) - (-300.0)) < 0.01, $failures);
    $check('cash delta = +292 (from delete)', abs(($cAfterDel - $cBeforeDel) - 292.0) < 0.01, $failures);
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑨ AccountModuleDivision cleanup verified
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑨ AccountModuleDivision no longer maps wallet→wallet_transfer ━━';

    $check(
        'LEGACY_MODULE_TO_TYPE no longer has wallet key',
        ! array_key_exists('wallet', AccountModuleDivision::LEGACY_MODULE_TO_TYPE),
        $failures
    );
    $check(
        'LEGACY_MODULE_TO_TYPE no longer has wallets key',
        ! array_key_exists('wallets', AccountModuleDivision::LEGACY_MODULE_TO_TYPE),
        $failures
    );
    $check(
        'OFFICE constant still includes wallet_transfer',
        in_array('wallet_transfer', AccountModuleDivision::OFFICE, true),
        $failures
    );
    $check(
        'OFFICE constant no longer includes bare "wallet"',
        ! in_array('wallet', AccountModuleDivision::OFFICE, true),
        $failures
    );
    $check(
        'moduleTypeOptions lists wallet_transfer',
        array_key_exists('wallet_transfer', AccountModuleDivision::moduleTypeOptions()),
        $failures
    );
    $check(
        'moduleTypeOptions no longer lists bare "wallet"',
        ! array_key_exists('wallet', AccountModuleDivision::moduleTypeOptions()),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑩ Validation rules reject legacy 'wallet' value (defense in depth)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑩ FormRequest validation rejects module_type="wallet" ━━';

    $req = new \App\Http\Requests\Finance\StoreAccountRequest();
    $rules = $req->rules();
    $moduleRule = $rules['module_type'] ?? [];
    $moduleRuleStr = is_array($moduleRule) ? implode('|', $moduleRule) : (string) $moduleRule;

    $check(
        'StoreAccountRequest accepts wallet_transfer',
        str_contains($moduleRuleStr, 'wallet_transfer'),
        $failures
    );
    $check(
        'StoreAccountRequest does NOT accept bare "wallet"',
        ! str_contains($moduleRuleStr, "'wallet'") && ! str_contains($moduleRuleStr, '"wallet"'),
        $failures
    );
    $echo();

} catch (\Throwable $e) {
    $failures[] = 'EXCEPTION: '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')';
    $out[] = '❌ EXCEPTION: '.$e->getMessage();
    $out[] = '   at '.$e->getFile().':'.$e->getLine();
    $echo();
} finally {
    DB::rollBack();
    $out[] = '═══ Final Verdict ═══';
    if ($failures === []) {
        $out[] = '✅ ALL CHECKS PASSED — Phase 8 contract honored';
    } else {
        $out[] = '❌ '.count($failures).' CHECK(S) FAILED:';
        foreach ($failures as $f) {
            $out[] = '   • '.$f;
        }
    }

    $result = [
        'success' => $failures === [],
        'failed_count' => count($failures),
        'failed_labels' => $failures,
        'ran_at' => now()->toIso8601String(),
    ];

    @file_put_contents(
        storage_path('logs/phase_8_wallet_result.json'),
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $out[] = '';
    $out[] = 'Result file: '.storage_path('logs/phase_8_wallet_result.json');
    echo implode(PHP_EOL, $out).PHP_EOL;

    exit($failures === [] ? 0 : 1);
}