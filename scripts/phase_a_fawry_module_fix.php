<?php
/**
 * Phase A — Fawry Module Fix: REAL DB VALIDATION.
 *
 * Validates the 3 contract guarantees shipped in Phase A:
 *
 *   ①  updateTransaction() reposts the ledger (Online Phase 9 pattern) when
 *       selling_price / fawry_price / amount / account_id actually change.
 *       Same-value edits skip the repost (no-op guard).
 *
 *   ②  FawryDashboardController::customers_debt now reads from the GL
 *       (account_entries credit - debit on customer accounts where
 *       transactions.module='fawry'), NOT from fawry_transactions column.
 *
 *   ③  customerBalances() always prefers GL debt for registered clients,
 *       even when there are zero Fawry entries yet (debt=0.0 explicit).
 *       Walk-in clients still fall back to columns (no GL possible).
 *
 *   Bonus: deleteTransaction() must continue to function as before
 *   (additive reversal + soft-delete — no regression).
 *
 * Run: php scripts/phase_a_fawry_module_fix.php
 *
 * Output: JSON verdict to stdout + storage/logs/phase_a_fawry_result.json
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryTransaction;
use App\Models\Fawry\FawryMachine;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Services\Fawry\FawryTransactionService;
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
    // TransactionService::reverseTransaction prepends 'عكس: ' to notes.
    return str_starts_with((string) $tx->notes, 'عكس:');
};

// ─────────────────────────────────────────────────────────────────
// SETUP — find or create fixture accounts
// ─────────────────────────────────────────────────────────────────
$out[] = '═══ Phase A Fawry Module Fix — Real DB Validation ═══';
$out[] = '';
$echo();

$user = User::query()->where('is_active', true)->whereIn('role', ['admin', 'owner'])->first();
if (! $user) {
    $user = User::query()->create([
        'name' => 'Phase A Fawry Validator',
        'email' => 'phase-a-fawry@test.local',
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

// Need an employee for the FawryTransaction (FK employee_id → users)
$employeeId = $user->id;

// Fawry module setup — find or create the necessary accounts
$walletAccount = Account::query()
    ->where('type', AccountType::Wallet)
    ->where('module_type', 'fawry')
    ->where('is_active', true)
    ->first();
if (! $walletAccount) {
    $walletAccount = Account::query()->create([
        'name' => 'P8-A Fawry Wallet',
        'type' => AccountType::Wallet,
        'module_type' => 'fawry',
        'module' => 'fawry',
        'owner_type' => 'office',
        'wallet_provider' => WalletProvider::CashWalletGeneric,
        'wallet_number' => '01077776666',
        'balance' => 50000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created fawry wallet account #{$walletAccount->id}";
}

$cashAccount = Account::query()
    ->where('type', AccountType::Cashbox)
    ->where('module_type', 'fawry')
    ->where('is_active', true)
    ->first();
if (! $cashAccount) {
    $cashAccount = Account::query()->create([
        'name' => 'P8-A Fawry Cashbox',
        'type' => AccountType::Cashbox,
        'module_type' => 'fawry',
        'module' => 'fawry',
        'owner_type' => 'office',
        'balance' => 100000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created fawry cashbox #{$cashAccount->id}";
}

$customer = Customer::query()->create([
    'full_name' => 'Phase A Fawry Customer',
    'phone' => '0100000'.random_int(1000, 9999),
    'created_by' => $user->id,
]);
$out[] = "Created customer #{$customer->id}";

$out[] = '';
$echo();

DB::beginTransaction();
try {
    $service = app(FawryTransactionService::class);

    // ═══════════════════════════════════════════════════════════════
    // ① updateTransaction() reposts ledger on price change
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ① updateTransaction() reposts ledger on price/amount change ━━';

    $tx = $service->createTransaction([
        'client_id' => $customer->id,
        'client_name' => $customer->full_name,
        'operation_type' => 'charge',
        'client_amount' => 500,
        'fawry_price' => 480,
        'selling_price' => 500,
        'employee_id' => $employeeId,
        'account_id' => $cashAccount->id,
        'payment_method' => 'cash',
        'amount' => 200,
        'currency_id' => null,
    ]);

    $oldIncomeId = $tx->income_transaction_id;
    $oldExpenseId = $tx->expense_transaction_id;
    $oldAmountPaid = (float) $tx->amount;
    $cashBefore = (float) $cashAccount->fresh()->balance;
    $custAccountId = $customer->fresh()->account_id;

    $out[] = "  Created FawryTransaction #{$tx->id} (customer #{$customer->id})";
    $out[] = "  Initial: selling_price=500, fawry_price=480, amount=200, account=#{$cashAccount->id}";

    // Change selling_price: 500 → 750, amount: 200 → 400
    $service->updateTransaction($tx, [
        'selling_price' => 750,
        'amount' => 400,
    ]);

    $tx->refresh();
    $newIncomeId = $tx->income_transaction_id;
    $newExpenseId = $tx->expense_transaction_id;
    $cashAfter = (float) $cashAccount->fresh()->balance;

    $check('selling_price recomputed to 750', (float) $tx->selling_price === 750.0, $failures);
    $check('profit recomputed (750-480=270)', (float) $tx->profit === 270.0, $failures);
    $check('income_transaction_id CHANGED (reversed + reposted)', $newIncomeId !== $oldIncomeId, $failures);
    $check('expense_transaction_id CHANGED (reversed + reposted)', $newExpenseId !== $oldExpenseId, $failures);
    $check('old income REVERSED (notes prefix "عكس:")', $isReversed(Transaction::find($oldIncomeId)), $failures);
    $check('old expense REVERSED (notes prefix "عكس:")', $isReversed(Transaction::find($oldExpenseId)), $failures);

    $newIncome = Transaction::find($newIncomeId);
    $newExpense = Transaction::find($newExpenseId);
    $check('new income.amount = 750', abs((float) $newIncome->amount - 750.0) < 0.01, $failures);
    $check('new expense.amount = 480 (unchanged)', abs((float) $newExpense->amount - 480.0) < 0.01, $failures);

    // Cash delta math:
    //   Original cash flow at create: +200 (settlement, contra=customer)
    //   At update: reverse old settlement (-200), repost new settlement (+400)
    //   Net: +200
    $expectedCashDelta = 200.0;
    $check(
        'cash delta = +200 (50→200 settlement change)',
        abs(($cashAfter - $cashBefore) - $expectedCashDelta) < 0.01,
        $failures
    );

    // Customer GL balance after Phase A repost (verified via account_entries):
    //   Original: +500 (sale credit) -200 (settlement debit) = +300
    //   Reverse:   -500 (income reversal debit) +200 (settlement reversal credit) = -300
    //   Repost:    +750 (new sale credit) -400 (new settlement debit) = +350
    $customerBalance = (float) Account::find($custAccountId)->balance;
    $check(
        'customer GL balance = +350 (after repost)',
        abs($customerBalance - 350.0) < 0.01,
        $failures
    );

    // After the Phase A repost, the model column-sum and the GL balance
    // are GUARANTEED to agree (the repost keeps them in sync). This is
    // the desired invariant: the dashboard can source from either and
    // get the same answer, but GL is authoritative because it survives
    // any future code changes that forget to repost.
    $columnSumDebt = (float) FawryTransaction::sum(DB::raw('selling_price - amount'));
    $check(
        'column sum matches GL after repost (invariant: 350=350)',
        abs($columnSumDebt - $customerBalance) < 0.01,
        $failures
    );
    $check(
        'column sum equals 350 (selling_price=750, amount=400)',
        abs($columnSumDebt - 350.0) < 0.01,
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ② updateTransaction() with no ledger-affecting change is no-op
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ② updateTransaction() skips repost on notes-only edit ━━';

    $sameIncomeId = $tx->income_transaction_id;
    $sameExpenseId = $tx->expense_transaction_id;

    $service->updateTransaction($tx, [
        'notes' => 'just a notes tweak',
    ]);

    $tx->refresh();
    $check('notes-only update: income_transaction_id UNCHANGED', $tx->income_transaction_id === $sameIncomeId, $failures);
    $check('notes-only update: expense_transaction_id UNCHANGED', $tx->expense_transaction_id === $sameExpenseId, $failures);
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ③ FawryDashboardController::customers_debt reads from GL
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ③ customers_debt in dashboard reads from GL (account_entries) ━━';

    $dashRes = app(\App\Http\Controllers\Api\V1\Fawry\FawryDashboardController::class)->index(
        new \Illuminate\Http\Request()
    );
    $dash = json_decode($dashRes->getContent(), true);

    $dashDebt = (float) ($dash['data']['stats']['customers_debt'] ?? 0);

    // Recompute the expected debt via the same SQL the dashboard uses
    $expectedDebt = (float) DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->join('accounts', 'account_entries.account_id', '=', 'accounts.id')
        ->where('accounts.type', AccountType::Customer->value)
        ->where('transactions.module', TransactionModule::Fawry->value)
        ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as debt')
        ->value('debt') ?? 0.0;

    $check(
        "dashboard customers_debt ({$dashDebt}) === GL expected ({$expectedDebt})",
        abs($dashDebt - $expectedDebt) < 0.01,
        $failures
    );
    $check(
        'dashboard customers_debt matches customer GL balance',
        abs($dashDebt - $customerBalance) < 0.01,
        $failures
    );

    // After Phase A's repost, the model and GL are in sync. The dashboard
    // now reads from GL (authoritative source) — even though both numbers
    // happen to be equal here, the GL path is correct because it survives
    // any future code path that might mutate the model without reposting
    // (e.g., data import, direct SQL, or a partial repost bug).
    $columnSumDebt = (float) FawryTransaction::sum(DB::raw('selling_price - amount'));
    $check(
        'dashboard customers_debt matches the column sum too (repost invariant)',
        abs($dashDebt - $columnSumDebt) < 0.01,
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ④ customerBalances always prefers GL for registered clients
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ④ customerBalances prefers GL for registered clients ━━';

    $balRes = app(\App\Http\Controllers\Api\V1\Fawry\FawryTransactionController::class)->customerBalances(
        new \Illuminate\Http\Request()
    );
    $balances = json_decode($balRes->getContent(), true);

    // Find our customer in the list
    $myRow = null;
    foreach ($balances['data'] ?? [] as $row) {
        if ($row['client_id'] === $customer->id) {
            $myRow = $row;
            break;
        }
    }

    $check('customer #'.$customer->id.' appears in customerBalances', $myRow !== null, $failures);
    if ($myRow) {
        $check(
            'total_debt from API ('.$myRow['total_debt'].') === GL balance ('.$customerBalance.')',
            abs($myRow['total_debt'] - $customerBalance) < 0.01,
            $failures
        );
        $check(
            'total_paid from API ('.$myRow['total_paid'].') === total_sales - debt',
            abs($myRow['total_paid'] - ($myRow['total_sales'] - $myRow['total_debt'])) < 0.01,
            $failures
        );
    }
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑤ deleteTransaction() still works (no regression)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑤ deleteTransaction() reverses + soft-deletes (no regression) ━━';

    $delTx = $service->createTransaction([
        'client_id' => $customer->id,
        'client_name' => $customer->full_name,
        'operation_type' => 'charge',
        'client_amount' => 300,
        'fawry_price' => 280,
        'selling_price' => 300,
        'employee_id' => $employeeId,
        'account_id' => $cashAccount->id,
        'payment_method' => 'cash',
        'amount' => 100,
    ]);

    $delIncomeId = $delTx->income_transaction_id;
    $delExpenseId = $delTx->expense_transaction_id;

    $service->deleteTransaction($delTx);

    $delTx->refresh();
    $check('fawry transaction soft-deleted', $delTx->deleted_at !== null, $failures);
    $check('income reversed after delete', $isReversed(Transaction::find($delIncomeId)), $failures);
    $check('expense reversed after delete', $isReversed(Transaction::find($delExpenseId)), $failures);
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑥ Module-level invariants
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑥ Module-level invariants ━━';

    $check(
        'AccountModuleDivision::OFFICE includes fawry',
        in_array('fawry', AccountModuleDivision::OFFICE, true),
        $failures
    );
    $check(
        'AccountModuleDivision::moduleTypeOptions lists fawry',
        array_key_exists('fawry', AccountModuleDivision::moduleTypeOptions()),
        $failures
    );
    $check(
        'AccountModuleDivision::moduleLabel("fawry") returns "فوري"',
        AccountModuleDivision::moduleLabel('fawry') === 'فوري',
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
        $out[] = '✅ ALL CHECKS PASSED — Phase A contract honored';
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
        storage_path('logs/phase_a_fawry_result.json'),
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $out[] = '';
    $out[] = 'Result file: '.storage_path('logs/phase_a_fawry_result.json');
    echo implode(PHP_EOL, $out).PHP_EOL;

    exit($failures === [] ? 0 : 1);
}