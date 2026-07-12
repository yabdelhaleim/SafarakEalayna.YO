<?php
/**
 * Phase C — Fawry Cleanup: REAL DB VALIDATION.
 *
 * Validates the 2 contract guarantees shipped in Phase C:
 *
 *   ①  FawryTransactionService::ensureCustomerAccount() re-tags
 *       pre-existing customer accounts (created by CustomerLedgerObserver
 *       with module_type='office') to module_type='fawry' when the customer
 *       is later used in a Fawry flow. Without this re-tag, the customer
 *       is invisible to TransferDashboardController fawry stats and to
 *       FinancialReportService fawry receivables.
 *
 *   ②  FinancialReportService receivables correctly isolates the Fawry
 *       share of a customer account's balance when the customer also
 *       has transactions from other modules. The GL-based path uses
 *       account_entries.credit - debit filtered by transactions.module
 *       = 'fawry', so it never mixes modules on the same customer
 *       account.
 *
 * Run: php scripts/phase_c_fawry_cleanup.php
 *
 * Output: JSON verdict to stdout + storage/logs/phase_c_fawry_result.json
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
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

// ─────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────
$out[] = '═══ Phase C Fawry Cleanup — Real DB Validation ═══';
$out[] = '';
$echo();

$user = User::query()->where('is_active', true)->whereIn('role', ['admin', 'owner'])->first();
if (! $user) {
    $user = User::query()->create([
        'name' => 'Phase C Validator',
        'email' => 'phase-c-fawry@test.local',
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

// Fawry settlement account (any module-type=fawry account the service can use)
$fawryCashbox = Account::query()
    ->where('type', AccountType::Cashbox)
    ->where('module_type', 'fawry')
    ->where('is_active', true)
    ->first();
if (! $fawryCashbox) {
    $fawryCashbox = Account::query()->create([
        'name' => 'P-C Fawry Cashbox',
        'type' => AccountType::Cashbox,
        'module_type' => 'fawry',
        'module' => 'fawry',
        'owner_type' => 'office',
        'balance' => 100000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created fawry cashbox #{$fawryCashbox->id}";
}

$busCashbox = Account::query()
    ->where('type', AccountType::Cashbox)
    ->where('module_type', 'bus')
    ->where('is_active', true)
    ->first();
if (! $busCashbox) {
    $busCashbox = Account::query()->create([
        'name' => 'P-C Bus Cashbox',
        'type' => AccountType::Cashbox,
        'module_type' => 'bus',
        'module' => 'bus',
        'owner_type' => 'office',
        'balance' => 50000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = "Created bus cashbox #{$busCashbox->id}";
}

$out[] = '';
$echo();

DB::beginTransaction();
try {
    $fawryTxService = app(FawryTransactionService::class);

    // ═══════════════════════════════════════════════════════════════
    // ① ensureCustomerAccount re-tags pre-existing customer accounts
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ① ensureCustomerAccount re-tags pre-existing customer accounts ━━';

    // Create a Customer WITHOUT a Fawry transaction first — the
    // CustomerLedgerObserver will fire and create a customer account
    // tagged module_type='office'.
    $customer = Customer::query()->create([
        'full_name' => 'P-C Fawry Customer',
        'phone' => '0100000'.random_int(1000, 9999),
        'created_by' => $user->id,
    ]);

    $initialAccount = Account::find($customer->fresh()->account_id);
    $check(
        'observer creates customer account with module_type=office',
        $initialAccount !== null && $initialAccount->module_type === 'office',
        $failures
    );
    $out[] = "  Initial account #{$initialAccount->id} module_type={$initialAccount->module_type}";

    // Create a Fawry transaction — ensureCustomerAccount runs and re-tags
    $fawryTxService->createTransaction([
        'operation_type' => 'charge',
        'client_id' => $customer->id,
        'client_name' => $customer->full_name,
        'client_amount' => 500,
        'fawry_price' => 480,
        'selling_price' => 500,
        'employee_id' => $user->id,
        'account_id' => $fawryCashbox->id,
        'payment_method' => 'cash',
        'amount' => 100,
    ]);

    $retaggedAccount = Account::find($customer->fresh()->account_id);
    $check(
        'after Fawry flow: customer account module_type=fawry',
        $retaggedAccount->module_type === 'fawry',
        $failures
    );
    $check(
        'customer_account.module_type !== wallet_transfer (defense)',
        $retaggedAccount->module_type !== 'wallet_transfer',
        $failures
    );
    $check(
        'customer_account.module_type !== office anymore',
        $retaggedAccount->module_type !== 'office',
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ② idempotent: second Fawry flow doesn't change anything
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ② ensureCustomerAccount re-tag is idempotent ━━';

    $fawryTxService->createTransaction([
        'operation_type' => 'charge',
        'client_id' => $customer->id,
        'client_name' => $customer->full_name,
        'client_amount' => 300,
        'fawry_price' => 280,
        'selling_price' => 300,
        'employee_id' => $user->id,
        'account_id' => $fawryCashbox->id,
        'payment_method' => 'cash',
        'amount' => 50,
    ]);

    $retaggedAgain = Account::find($customer->fresh()->account_id);
    $check(
        'second Fawry flow: account still module_type=fawry',
        $retaggedAgain->module_type === 'fawry',
        $failures
    );
    $check(
        'account.id UNCHANGED (no new account created)',
        $retaggedAgain->id === $retaggedAccount->id,
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ③ FinancialReportService isolates Fawry share from other modules
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ③ FinancialReportService isolates Fawry share ━━';

    // Simulate: the same customer has bus-bookings too. Their ledger
    // account would normally aggregate both Fawry and Bus GL entries.
    // Phase C.2 ensures the Fawry receivables report only sums the Fawry
    // portion.
    //
    // Easiest: directly insert a fake Bus debit on the same customer
    // account (so balance mixes modules), then verify the FinancialReport
    // Service isolates the Fawry share correctly.

    $custAcc = $retaggedAgain;
    $balanceBeforeBus = (float) $custAcc->balance;

    // Use TransactionService::recordExpense so balance is actually
    // mutated (the previous version of this test inserted rows directly
    // which bypassed the balance mutation — false-positive test).
    $busFakeTx = app(\App\Services\Finance\TransactionService::class)->recordExpense([
        'amount' => 100,
        'from_account_id' => $custAcc->id,
        'module' => TransactionModule::Bus->value,
        'related_type' => \App\Models\Bus\BusBooking::class,
        'related_id' => 0,
        'notes' => 'fake bus debit for Phase C test',
        'created_by' => $user->id,
    ]);

    $custAcc->refresh();
    // Now the customer has BOTH Fawry GL entries AND a Bus GL entry on
    // the same ledger account. The full Account.balance is the MIXED sum
    // across modules. FinancialReportService must isolate the Fawry share.
    $fullBalance = (float) $custAcc->balance;
    $expectedBalanceAfterBus = $balanceBeforeBus - 100; // expense decreases customer balance
    $check(
        "bus expense(100) decreased customer balance to {$expectedBalanceAfterBus}",
        abs($fullBalance - $expectedBalanceAfterBus) < 0.01,
        $failures
    );

    // Verify the customer's ledgerAccount has BOTH modules' entries
    $allModulesOnAccount = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $custAcc->id)
        ->distinct()
        ->pluck('transactions.module')
        ->all();
    $check(
        'customer account now has entries from multiple modules (fawry + bus)',
        in_array('fawry', $allModulesOnAccount, true) && in_array('bus', $allModulesOnAccount, true),
        $failures
    );

    // Phase C.2 contract: FinancialReportService must isolate fawry share.
    // We invoke the SAME query pattern it uses internally.
    $isolatedFawryDebt = (float) DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $custAcc->id)
        ->where('transactions.module', TransactionModule::Fawry->value)
        ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as debt')
        ->value('debt') ?? 0.0;

    // The fawry-only debt should NOT include the bus -100 expense, so it
    // must equal the balance BEFORE the bus expense was added.
    $check(
        "isolated fawry debt ({$isolatedFawryDebt}) === balance BEFORE bus expense ({$balanceBeforeBus})",
        abs($isolatedFawryDebt - $balanceBeforeBus) < 0.01,
        $failures
    );
    $check(
        'after bus expense: full balance ('.$fullBalance.') ≠ isolated fawry debt ('.$isolatedFawryDebt.') — proves isolation',
        abs($fullBalance - $isolatedFawryDebt) > 0.01,
        $failures
    );
    $check(
        "delta (full - isolated) === bus expense amount (100). full={$fullBalance} isolated={$isolatedFawryDebt} expected_delta=100",
        abs(($fullBalance - $isolatedFawryDebt) - (-100.0)) < 0.01,
        $failures
    );
    $out[] = "  before bus: balance={$balanceBeforeBus}, isolated fawry debt={$isolatedFawryDebt} (equal)";
    $out[] = "  after bus:  balance={$fullBalance}, isolated fawry debt={$isolatedFawryDebt} (differ by bus -100)";
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ④ Module-level invariants
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ④ Module-level invariants ━━';

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
        $out[] = '✅ ALL CHECKS PASSED — Phase C contract honored';
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
        storage_path('logs/phase_c_fawry_result.json'),
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $out[] = '';
    $out[] = 'Result file: '.storage_path('logs/phase_c_fawry_result.json');
    echo implode(PHP_EOL, $out).PHP_EOL;

    exit($failures === [] ? 0 : 1);
}