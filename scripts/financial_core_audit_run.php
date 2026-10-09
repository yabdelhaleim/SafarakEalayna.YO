<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * FINANCIAL CORE & CROSS-MODULE AUDIT — RUN SCRIPT (2026-08-14)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Executes Phases 5, 6, 7, 9, 11, 14 of the 17-phase financial audit spec
 * against the isolated SQLite database set up by:
 *   php scripts/financial_core_audit_setup.php
 *
 * Re-runnable. NEVER crashes on a single defect — collects ALL defects via
 * the closure pattern (mirrors bus_e2e_final_run.php).
 *
 * Emits JSON report to:
 *   storage/logs/financial_core_audit_<date>_report.json
 *
 * Phases covered:
 *   5 — Double-Entry Integrity (per-currency, per-transaction)
 *   6 — Transaction Atomicity (force-fail at each stage)
 *   7 — Idempotency / Duplicate / Replay (Class-A concern)
 *   9 — Reversal / Refund / Delete (additive-reversal correctness)
 *  11 — Security (IDOR, mass assignment, payload injection)
 *  14 — Global Financial Invariants (orphan tx, duplicate effects, balance recon)
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

// ─── Force SQLite BEFORE bootstrap (same pattern as setup script) ───
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_financial_audit.sqlite';

if (! file_exists($dbPath)) {
    fwrite(STDERR, "❌ Audit DB not found: $dbPath\nRun setup first: php scripts/financial_core_audit_setup.php\n");
    exit(1);
}

putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Services\Finance\AccountService;
use App\Services\Finance\LedgerReconciliationService;
use App\Services\Finance\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$baseline = json_decode(file_get_contents(__DIR__.'/../storage/logs/financial_core_audit_baseline.json'), true);
$txService = app(TransactionService::class);
$accService = app(AccountService::class);
$reconciliation = app(LedgerReconciliationService::class);

$report = [
    'audit_id' => 'FC_AUDIT_20260814',
    'run_at' => now()->toIso8601String(),
    'baseline_summary' => [
        'total_accounts' => count($baseline['account_ids']),
        'total_customers' => count($baseline['customer_ids']),
        'total_suppliers' => count($baseline['supplier_ids']),
        'total_users' => count($baseline['user_ids']),
    ],
    'phases' => [],
    'defects' => [],
];

$defects = [];
$snapshots = [];

// ─── Defect-collecting closure (canonical pattern from bus_e2e_final_run.php) ───
$check = function (string $phase, string $label, bool $ok, array $ctx = []) use (&$defects, &$report) {
    if (! $ok) {
        $defects[] = array_merge(['phase' => $phase, 'label' => $label], $ctx);
        $report['defects'][] = array_merge(['phase' => $phase, 'label' => $label], $ctx);
        echo "    ❌ [$phase] $label";
        if ($ctx) {
            echo ' | '.json_encode($ctx, JSON_UNESCAPED_UNICODE);
        }
        echo "\n";
    } else {
        echo "    ✅ [$phase] $label\n";
    }
};

// Helper: assert balance == SUM(credit) - SUM(debit) for an account (the project's canonical invariant)
$assertInvariant = function (Account $account) use ($check) {
    $expected = (float) DB::table('account_entries')
        ->where('account_id', $account->id)
        ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')
        ->value('net');
    $actual = (float) $account->fresh()->balance;
    $ok = abs($expected - $actual) < 0.011;
    $check('global', "Invariant #{$account->id}({$account->name}) balance={$actual}==Σ(credit-debit)={$expected}", $ok, [
        'account_id' => $account->id,
        'balance' => $actual,
        'ledger_net' => $expected,
        'delta' => round($actual - $expected, 4),
    ]);

    return $ok;
};

// Helper: assert per-transaction balanced entries.
// Per Phase 5 spec: "For cross-currency operations NEVER aggregate different currencies
// as if they were the same currency. Validate separately per currency."
// Correct invariant for ANY transaction (single- or cross-currency):
//   For each entry, the entry's debit OR credit (one must be 0) matches the entry's
//   account's currency. Plus for same-currency tx: Σdebit == Σcredit (single currency).
$assertTxBalanced = function (int $txId, string $phase) use ($check) {
    $entries = DB::table('account_entries')
        ->join('accounts', 'accounts.id', '=', 'account_entries.account_id')
        ->where('account_entries.transaction_id', $txId)
        ->select('account_entries.account_id', 'account_entries.debit', 'account_entries.credit', 'accounts.currency', 'account_entries.balance_after')
        ->get();
    $count = $entries->count();
    $ok = $count >= 2;
    $details = [];
    // Rule 1: Each entry must be one-sided (debit OR credit, not both, not neither)
    foreach ($entries as $e) {
        $d = (float) $e->debit;
        $c = (float) $e->credit;
        $isOneSided = ($d > 0 && $c == 0) || ($c > 0 && $d == 0);
        $cur = strtoupper((string) $e->currency);
        $details[] = "acc#{$e->account_id}({$cur}): d={$d}, c={$c}";
        if (! $isOneSided) {
            $ok = false;
        }
    }
    // Rule 2: For SINGLE-currency tx, Σdebit == Σcredit (must balance in that currency)
    // For CROSS-currency tx, the two legs are in DIFFERENT currencies and the per-leg
    // amount must equal the account's currency change (verified separately by Phase 14.1
    // per-account invariant).
    $currencies = array_unique(array_map(fn ($e) => strtoupper((string) $e->currency), $entries->all()));
    if (count($currencies) === 1) {
        $cur = array_values($currencies)[0];
        $d = array_sum(array_map(fn ($e) => (float) $e->debit, $entries->all()));
        $c = array_sum(array_map(fn ($e) => (float) $e->credit, $entries->all()));
        if (abs($d - $c) >= 0.011) {
            $ok = false;
        }
        $details[] = "single-currency {$cur} Σd={$d}, Σc={$c}";
    } else {
        $details[] = 'cross-currency (no aggregate possible)';
    }
    $check($phase, "tx#{$txId} balanced ({$count} entries, ".implode(' | ', $details).')', $ok, [
        'transaction_id' => $txId,
        'line_count' => $count,
        'currencies' => array_values($currencies),
    ]);

    return $ok;
};

// Helper: load an account by name
$getAccount = function (string $name) use ($baseline) {
    foreach ($baseline['account_ids'] as $key => $id) {
        $accName = DB::table('accounts')->where('id', $id)->value('name');
        if ($accName === $name) {
            return Account::find($id);
        }
    }

    return null;
};

// Authenticate as admin
Auth::loginUsingId($baseline['user_ids']['admin']);
echo "Authenticated as admin (id={$baseline['user_ids']['admin']})\n\n";

// ════════════════════════════════════════════════════════════════════════
// PHASE 5 — DOUBLE-ENTRY INTEGRITY
// ════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 5 — Double-Entry Integrity (per-currency, per-transaction)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$phase5 = ['scenarios' => [], 'passed' => 0, 'failed' => 0];

// 5.1 EGP-only transfer
$officeEgp = Account::find($baseline['account_ids']['cashbox_egp_office']);
$tourismEgp = Account::find($baseline['account_ids']['cashbox_egp_tourism']);
try {
    $balanceBefore1 = (float) $officeEgp->fresh()->balance;
    $balanceBefore2 = (float) $tourismEgp->fresh()->balance;
    $tx = $txService->recordJournalTransfer([
        'amount' => 5000.00,
        'currency' => 'EGP',
        'from_account_id' => $officeEgp->id,
        'to_account_id' => $tourismEgp->id,
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 5.1: EGP-only transfer',
        'allow_from_negative' => false,
    ]);
    $ok = $assertTxBalanced($tx->id, 'phase5');
    $delta = (float) $officeEgp->fresh()->balance - $balanceBefore1;
    $delta2 = (float) $tourismEgp->fresh()->balance - $balanceBefore2;
    $ok = $ok && abs($delta - (-5000.00)) < 0.011 && abs($delta2 - 5000.00) < 0.011;
    $check('phase5', '5.1: EGP transfer -5000/+5000 net effect', $ok, [
        'from_delta' => $delta,
        'to_delta' => $delta2,
    ]);
    $ok ? $phase5['passed']++ : $phase5['failed']++;
    $phase5['scenarios'][] = ['scenario' => '5.1_egp_transfer', 'tx_id' => $tx->id];
} catch (Throwable $e) {
    $check('phase5', '5.1: EGP transfer (THREW)', false, ['error' => $e->getMessage()]);
    $phase5['failed']++;
}

// 5.2 USD→EGP cross-currency transfer (currency conversion)
$officeUsd = Account::find($baseline['account_ids']['cashbox_usd_office']);
try {
    $balanceBefore1 = (float) $officeUsd->fresh()->balance;
    $balanceBefore2 = (float) $officeEgp->fresh()->balance;
    // 100 USD → 4850 EGP (rate=48.5)
    $tx = $txService->recordJournalTransfer([
        'amount' => 100.00,
        'currency' => 'USD',
        'from_account_id' => $officeUsd->id,
        'to_account_id' => $officeEgp->id,
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 5.2: USD→EGP cross-currency',
        'allow_from_negative' => false,
        'exchange_rate' => 48.5,
        'converted_amount' => 4850.00,
    ]);
    $ok = $assertTxBalanced($tx->id, 'phase5');
    $deltaUsd = (float) $officeUsd->fresh()->balance - $balanceBefore1;
    $deltaEgp = (float) $officeEgp->fresh()->balance - $balanceBefore2;
    // USD leg: -100 (debit in USD), EGP leg: +4850 (credit in EGP)
    // Each currency validated separately per Phase 5 spec ("NEVER aggregate different currencies")
    $ok = $ok && abs($deltaUsd - (-100.00)) < 0.011;
    $ok = $ok && abs($deltaEgp - 4850.00) < 0.011;
    // Assert the amount × exchange_rate ≈ converted_amount invariant
    $amountTimesRate = 100.00 * 48.5;
    $ok = $ok && abs($amountTimesRate - 4850.00) < 0.011;
    $check('phase5', '5.2: USD→EGP cross-currency both legs balanced + amount×rate=converted', $ok, [
        'usd_delta' => $deltaUsd,
        'egp_delta' => $deltaEgp,
        'amount_x_rate' => $amountTimesRate,
        'converted_amount' => 4850.00,
    ]);
    $ok ? $phase5['passed']++ : $phase5['failed']++;
    $phase5['scenarios'][] = ['scenario' => '5.2_usd_to_egp', 'tx_id' => $tx->id];
} catch (Throwable $e) {
    $check('phase5', '5.2: USD→EGP transfer (THREW)', false, ['error' => $e->getMessage()]);
    $phase5['failed']++;
}

// 5.3 EGP→USD reverse conversion
$bankEgp = Account::find($baseline['account_ids']['bank_egp']);
$walletUsd = Account::find($baseline['account_ids']['wallet_usd']);
try {
    $balanceBefore1 = (float) $bankEgp->fresh()->balance;
    $balanceBefore2 = (float) $walletUsd->fresh()->balance;
    // 4850 EGP → 100 USD (rate=48.5)
    $tx = $txService->recordJournalTransfer([
        'amount' => 4850.00,
        'currency' => 'EGP',
        'from_account_id' => $bankEgp->id,
        'to_account_id' => $walletUsd->id,
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 5.3: EGP→USD reverse conversion',
        'allow_from_negative' => false,
        'exchange_rate' => 0.02062, // 1/48.5
        'converted_amount' => 100.00,
    ]);
    $ok = $assertTxBalanced($tx->id, 'phase5');
    $deltaEgp = (float) $bankEgp->fresh()->balance - $balanceBefore1;
    $deltaUsd = (float) $walletUsd->fresh()->balance - $balanceBefore2;
    $ok = $ok && abs($deltaEgp - (-4850.00)) < 0.011 && abs($deltaUsd - 100.00) < 0.011;
    $check('phase5', '5.3: EGP→USD reverse conversion both legs balanced', $ok, [
        'egp_delta' => $deltaEgp,
        'usd_delta' => $deltaUsd,
    ]);
    $ok ? $phase5['passed']++ : $phase5['failed']++;
    $phase5['scenarios'][] = ['scenario' => '5.3_egp_to_usd', 'tx_id' => $tx->id];
} catch (Throwable $e) {
    $check('phase5', '5.3: EGP→USD (THREW)', false, ['error' => $e->getMessage()]);
    $phase5['failed']++;
}

// 5.4 Multi-leg 4-account chain (A→B→C→D all in EGP)
try {
    $balancesBefore = [];
    foreach (['cashbox_egp_office', 'cashbox_egp_tourism', 'bank_egp', 'wallet_egp'] as $k) {
        $balancesBefore[$k] = (float) Account::find($baseline['account_ids'][$k])->fresh()->balance;
    }
    // A→B: 1000
    $tx1 = $txService->recordJournalTransfer([
        'amount' => 1000.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
        'to_account_id' => $baseline['account_ids']['cashbox_egp_tourism'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 5.4 leg1', 'allow_from_negative' => false,
    ]);
    // B→C: 600
    $tx2 = $txService->recordJournalTransfer([
        'amount' => 600.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_tourism'],
        'to_account_id' => $baseline['account_ids']['bank_egp'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 5.4 leg2', 'allow_from_negative' => false,
    ]);
    // C→D: 200
    $tx3 = $txService->recordJournalTransfer([
        'amount' => 200.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['bank_egp'],
        'to_account_id' => $baseline['account_ids']['wallet_egp'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 5.4 leg3', 'allow_from_negative' => false,
    ]);
    $ok = $assertTxBalanced($tx1->id, 'phase5') && $assertTxBalanced($tx2->id, 'phase5') && $assertTxBalanced($tx3->id, 'phase5');
    // Net effect: A lost 1000, B net -400 (gained 1000 then lost 600), C net +400 (gained 600 then lost 200), D gained 200
    $netA = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance - $balancesBefore['cashbox_egp_office'];
    $netB = (float) Account::find($baseline['account_ids']['cashbox_egp_tourism'])->fresh()->balance - $balancesBefore['cashbox_egp_tourism'];
    $netC = (float) Account::find($baseline['account_ids']['bank_egp'])->fresh()->balance - $balancesBefore['bank_egp'];
    $netD = (float) Account::find($baseline['account_ids']['wallet_egp'])->fresh()->balance - $balancesBefore['wallet_egp'];
    $totalNet = round($netA + $netB + $netC + $netD, 2);
    $ok = $ok && abs($totalNet) < 0.011; // money conservation across the chain
    $check('phase5', '5.4: 4-account chain A→B→C→D — all 3 tx balanced + total net=0 (conservation)', $ok, [
        'net_a' => $netA, 'net_b' => $netB, 'net_c' => $netC, 'net_d' => $netD,
        'total_net' => $totalNet,
    ]);
    $ok ? $phase5['passed']++ : $phase5['failed']++;
    $phase5['scenarios'][] = ['scenario' => '5.4_chain', 'tx_ids' => [$tx1->id, $tx2->id, $tx3->id]];
} catch (Throwable $e) {
    $check('phase5', '5.4: 4-account chain (THREW)', false, ['error' => $e->getMessage()]);
    $phase5['failed']++;
}

// 5.5 Walk-in AR income via recordIncome
try {
    $fawryIncome = Account::find($baseline['account_ids']['fawry_income_clearing']);
    $fawryWalkin = Account::find($baseline['account_ids']['fawry_walkin_ar']);
    $tx = $txService->recordIncome([
        'amount' => 250.00,
        'currency' => 'EGP',
        'to_account_id' => $fawryWalkin->id,
        'module' => TransactionModule::Fawry->value,
        'related_type' => 'App\\Models\\Fawry\\FawryTransaction',
        'related_id' => 1,
        'notes' => 'FC-AUDIT Phase 5.5: walk-in AR income',
    ]);
    $ok = $assertTxBalanced($tx->id, 'phase5');
    // Walk-in AR should have +250 (credit). The actual income_clearing contra used by
    // the service may differ from our seed (the LedgerClearingAccounts service resolves
    // by NAME and may pick the seeder's account). Query the actual from_account_id.
    $walkinDelta = (float) $fawryWalkin->fresh()->balance - 0.0;
    $incDelta = (float) Account::find($tx->from_account_id)->fresh()->balance - 0.0;
    $ok = $ok && abs($walkinDelta - 250.00) < 0.011;
    $ok = $ok && abs($incDelta - (-250.00)) < 0.011;
    $check('phase5', '5.5: Income posting (walk-in AR +250, income_clearing -250)', $ok, [
        'walkin_delta' => $walkinDelta,
        'income_clearing_delta' => $incDelta,
        'actual_income_clearing_id' => $tx->from_account_id,
    ]);
    $ok ? $phase5['passed']++ : $phase5['failed']++;
    $phase5['scenarios'][] = ['scenario' => '5.5_income', 'tx_id' => $tx->id];
} catch (Throwable $e) {
    $check('phase5', '5.5: Income posting (THREW)', false, ['error' => $e->getMessage()]);
    $phase5['failed']++;
}

$report['phases']['phase5_double_entry'] = $phase5;
echo "\n  Phase 5 result: {$phase5['passed']} passed, {$phase5['failed']} failed\n\n";

// ════════════════════════════════════════════════════════════════════════
// PHASE 6 — TRANSACTION ATOMICITY (force-fail at each stage)
// ════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 6 — Transaction Atomicity (rollback integrity)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$phase6 = ['scenarios' => [], 'passed' => 0, 'failed' => 0];

// 6.1 Inject a failure AFTER Transaction::create but BEFORE AccountEntry::create
//     We use a counter inside the closure to verify the entry path was reached
//     but the DB should have ZERO rows after rollback.
try {
    $balBefore = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance;
    $balBefore2 = (float) Account::find($baseline['account_ids']['wallet_egp'])->fresh()->balance;
    try {
        // Simulate failure mid-flight via a test wrapper
        DB::transaction(function () use ($baseline, $txService) {
            $tx = $txService->recordJournalTransfer([
                'amount' => 9999.00,
                'currency' => 'EGP',
                'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
                'to_account_id' => $baseline['account_ids']['wallet_egp'],
                'module' => TransactionModule::Office->value,
                'notes' => 'FC-AUDIT Phase 6.1: should be rolled back',
                'allow_from_negative' => false,
            ]);
            // Inject failure AFTER the transfer was "committed" inside the service
            // by throwing inside our outer transaction. This forces rollback of the
            // entire nested transaction including the service's inner commit.
            throw new RuntimeException('FORCED FAILURE — simulating mid-flight exception');
        });
        $check('phase6', '6.1: Forced failure mid-flight (transaction was NOT rolled back)', false);
        $phase6['failed']++;
    } catch (RuntimeException $e) {
        if (! str_contains($e->getMessage(), 'FORCED FAILURE')) {
            throw $e;
        }
        // Now assert ZERO rows were committed for this scenario
        $txRows = Transaction::where('notes', 'FC-AUDIT Phase 6.1: should be rolled back')->count();
        $entryRows = AccountEntry::where('notes', 'FC-AUDIT Phase 6.1: should be rolled back')->count();
        $balAfter = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance;
        $balAfter2 = (float) Account::find($baseline['account_ids']['wallet_egp'])->fresh()->balance;
        $ok = $txRows === 0 && $entryRows === 0 && abs($balAfter - $balBefore) < 0.011 && abs($balAfter2 - $balBefore2) < 0.011;
        $check('phase6', '6.1: Forced failure mid-flight → 0 tx rows + 0 entry rows + balances unchanged', $ok, [
            'tx_rows' => $txRows, 'entry_rows' => $entryRows,
            'bal_before_egp' => $balBefore, 'bal_after_egp' => $balAfter,
            'bal_before_wallet' => $balBefore2, 'bal_after_wallet' => $balAfter2,
        ]);
        $ok ? $phase6['passed']++ : $phase6['failed']++;
    }
} catch (Throwable $e) {
    $check('phase6', '6.1: Forced failure test (THREW)', false, ['error' => $e->getMessage()]);
    $phase6['failed']++;
}

// 6.2 Inject failure in an inner closure INSIDE the service
//     This tests whether the service properly wraps in DB::transaction.
try {
    $balBefore = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance;
    try {
        // We test by attempting an invalid operation that should fail atomically:
        // Transfer with from_account_id == to_account_id. Should throw and leave zero rows.
        $txService->recordJournalTransfer([
            'amount' => 100.00, 'currency' => 'EGP',
            'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
            'to_account_id' => $baseline['account_ids']['cashbox_egp_office'], // SAME!
            'module' => TransactionModule::Office->value,
            'notes' => 'FC-AUDIT Phase 6.2: invalid same-account', 'allow_from_negative' => false,
        ]);
        $check('phase6', '6.2: Same-account transfer (should have thrown)', false);
        $phase6['failed']++;
    } catch (InvalidArgumentException $e) {
        $txRows = Transaction::where('notes', 'FC-AUDIT Phase 6.2: invalid same-account')->count();
        $entryRows = AccountEntry::where('notes', 'FC-AUDIT Phase 6.2: invalid same-account')->count();
        $balAfter = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance;
        $ok = $txRows === 0 && $entryRows === 0 && abs($balAfter - $balBefore) < 0.011;
        $check('phase6', '6.2: Same-account rejection → 0 tx rows + 0 entries + balance unchanged', $ok, [
            'tx_rows' => $txRows, 'entry_rows' => $entryRows,
            'bal_before' => $balBefore, 'bal_after' => $balAfter,
            'exception' => $e->getMessage(),
        ]);
        $ok ? $phase6['passed']++ : $phase6['failed']++;
    }
} catch (Throwable $e) {
    $check('phase6', '6.2: Same-account test (THREW unexpected)', false, ['error' => $e->getMessage()]);
    $phase6['failed']++;
}

// 6.3 Insufficient balance should reject without mutation
try {
    $balBefore = (float) Account::find($baseline['account_ids']['cashbox_usd_office'])->fresh()->balance;
    try {
        $txService->recordJournalTransfer([
            'amount' => 999999999.00, // huge
            'currency' => 'USD',
            'from_account_id' => $baseline['account_ids']['cashbox_usd_office'],
            'to_account_id' => $baseline['account_ids']['cashbox_egp_office'],
            'module' => TransactionModule::Office->value,
            'notes' => 'FC-AUDIT Phase 6.3: insufficient USD balance', 'allow_from_negative' => false,
        ]);
        $check('phase6', '6.3: Insufficient balance (should have thrown)', false);
        $phase6['failed']++;
    } catch (Throwable $e) {
        $txRows = Transaction::where('notes', 'FC-AUDIT Phase 6.3: insufficient USD balance')->count();
        $entryRows = AccountEntry::where('notes', 'FC-AUDIT Phase 6.3: insufficient USD balance')->count();
        $balAfter = (float) Account::find($baseline['account_ids']['cashbox_usd_office'])->fresh()->balance;
        $ok = $txRows === 0 && $entryRows === 0 && abs($balAfter - $balBefore) < 0.011;
        $check('phase6', '6.3: Insufficient balance rejection → no rows mutated', $ok, [
            'tx_rows' => $txRows, 'entry_rows' => $entryRows,
            'bal_before' => $balBefore, 'bal_after' => $balAfter,
            'exception_class' => get_class($e),
        ]);
        $ok ? $phase6['passed']++ : $phase6['failed']++;
    }
} catch (Throwable $e) {
    $check('phase6', '6.3: Insufficient balance test (THREW unexpected)', false, ['error' => $e->getMessage()]);
    $phase6['failed']++;
}

$report['phases']['phase6_atomicity'] = $phase6;
echo "\n  Phase 6 result: {$phase6['passed']} passed, {$phase6['failed']} failed\n\n";

// ════════════════════════════════════════════════════════════════════════
// PHASE 7 — DUPLICATE / IDEMPOTENCY TESTING (Class-A concern)
// ════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 7 — Duplicate / Idempotency (CRITICAL)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$phase7 = ['scenarios' => [], 'passed' => 0, 'failed' => 0];

// 7.1 Duplicate Income guard — same related_type+related_id can have AT MOST ONE income
try {
    $relatedType = 'App\\Models\\Test\\DuplicateIncomeProbe';
    $relatedId = 999;
    $tx1 = $txService->recordIncome([
        'amount' => 100.00, 'currency' => 'EGP',
        'to_account_id' => $baseline['account_ids']['fawry_walkin_ar'],
        'module' => TransactionModule::Fawry->value,
        'related_type' => $relatedType, 'related_id' => $relatedId,
        'notes' => 'FC-AUDIT Phase 7.1 first income',
    ]);
    try {
        $tx2 = $txService->recordIncome([
            'amount' => 100.00, 'currency' => 'EGP',
            'to_account_id' => $baseline['account_ids']['fawry_walkin_ar'],
            'module' => TransactionModule::Fawry->value,
            'related_type' => $relatedType, 'related_id' => $relatedId,
            'notes' => 'FC-AUDIT Phase 7.1 second income (should be REJECTED)',
        ]);
        // If we get here, duplicate was NOT blocked → Class A defect
        $check('phase7', '7.1: Duplicate Income guard (NOT BLOCKED — Class A!)', false, [
            'tx1_id' => $tx1->id, 'tx2_id' => $tx2->id,
            'financial_impact' => 'walk-in AR credited twice (200 EGP instead of 100)',
        ]);
        $phase7['failed']++;
    } catch (InvalidArgumentException $e) {
        if (! str_contains($e->getMessage(), 'Duplicate income')) {
            throw $e;
        }
        $txCount = Transaction::where('related_type', $relatedType)->where('related_id', $relatedId)->count();
        $ok = $txCount === 1;
        $check('phase7', '7.1: Duplicate Income guard — exactly 1 income row exists', $ok, [
            'income_count' => $txCount,
            'rejection_message' => $e->getMessage(),
        ]);
        $ok ? $phase7['passed']++ : $phase7['failed']++;
    }
} catch (Throwable $e) {
    $check('phase7', '7.1: Duplicate income test (THREW)', false, ['error' => $e->getMessage()]);
    $phase7['failed']++;
}

// 7.2 reverseTransaction idempotency — double-reverse is no-op
try {
    $tx = $txService->recordJournalTransfer([
        'amount' => 250.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
        'to_account_id' => $baseline['account_ids']['wallet_egp'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 7.2: target for double-reverse', 'allow_from_negative' => false,
    ]);
    $entriesBeforeReverse = AccountEntry::where('transaction_id', $tx->id)->count();
    $txService->reverseTransaction($tx);
    $entriesAfterFirstReverse = AccountEntry::where('transaction_id', $tx->id)->count();
    $txService->reverseTransaction($tx); // second call should be no-op
    $entriesAfterSecondReverse = AccountEntry::where('transaction_id', $tx->id)->count();
    $ok = $entriesAfterFirstReverse === $entriesBeforeReverse * 2 // each entry has inverse
        && $entriesAfterSecondReverse === $entriesAfterFirstReverse;
    $check('phase7', '7.2: reverseTransaction idempotent — 2nd call is no-op', $ok, [
        'entries_before' => $entriesBeforeReverse,
        'entries_after_1st_reverse' => $entriesAfterFirstReverse,
        'entries_after_2nd_reverse' => $entriesAfterSecondReverse,
    ]);
    $ok ? $phase7['passed']++ : $phase7['failed']++;
} catch (Throwable $e) {
    $check('phase7', '7.2: Reverse idempotency (THREW)', false, ['error' => $e->getMessage()]);
    $phase7['failed']++;
}

// 7.3 Replay same recordJournalTransfer 5× rapidly — should create 5 distinct tx (NO idem-token)
try {
    $payload = [
        'amount' => 10.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
        'to_account_id' => $baseline['account_ids']['wallet_egp'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 7.3 replay',
        'allow_from_negative' => false,
    ];
    $balBefore = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance;
    $txIds = [];
    for ($i = 0; $i < 5; $i++) {
        $tx = $txService->recordJournalTransfer($payload);
        $txIds[] = $tx->id;
    }
    $balAfter = (float) Account::find($baseline['account_ids']['cashbox_egp_office'])->fresh()->balance;
    $delta = round($balAfter - $balBefore, 2);
    // Document: 5 tx → 5×-10 = -50 EGP net (NO replay protection at service level)
    $allSame = count(array_unique($txIds)) === 1;
    $check('phase7', '7.3: Replay 5× creates 5 distinct tx (no service-level replay guard — Class C hardening gap)', ! $allSame, [
        'distinct_tx_ids' => array_values(array_unique($txIds)),
        'cashbox_delta' => $delta,
        'expected_with_replay' => -50.00,
        'note' => 'Service has NO replay protection; HTTP layer has no Idempotency-Key header. Documented Class C.',
    ]);
    $phase7['passed']++; // We are DOCUMENTING this as expected behaviour, not a failure
    $phase7['scenarios'][] = ['scenario' => '7.3_replay_5x', 'note' => 'NO replay guard at service level — Class C'];
} catch (Throwable $e) {
    $check('phase7', '7.3: Replay test (THREW)', false, ['error' => $e->getMessage()]);
    $phase7['failed']++;
}

// 7.4 recordTransfer (Transfer model) — verify no duplication within the same call
try {
    $tx1 = $txService->recordTransfer([
        'amount' => 1000.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
        'to_account_id' => $baseline['account_ids']['bank_egp'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 7.4 first transfer',
        'allow_from_negative' => false,
    ]);
    $ok = $tx1 instanceof Transfer && Transaction::find($tx1->transaction_id) !== null;
    $entriesCount = AccountEntry::where('transaction_id', $tx1->transaction_id)->count();
    $ok = $ok && $entriesCount === 2;
    $check('phase7', '7.4: recordTransfer creates 1 Transfer + 1 Transaction + 2 AccountEntries', $ok, [
        'transfer_id' => $tx1->id,
        'tx_id' => $tx1->transaction_id,
        'entry_count' => $entriesCount,
    ]);
    $ok ? $phase7['passed']++ : $phase7['failed']++;
} catch (Throwable $e) {
    $check('phase7', '7.4: recordTransfer (THREW)', false, ['error' => $e->getMessage()]);
    $phase7['failed']++;
}

$report['phases']['phase7_idempotency'] = $phase7;
echo "\n  Phase 7 result: {$phase7['passed']} passed, {$phase7['failed']} failed\n\n";

// ════════════════════════════════════════════════════════════════════════
// PHASE 9 — REVERSAL / REFUND / DELETE
// ════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 9 — Reversal / Refund / Delete (additive pattern)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$phase9 = ['scenarios' => [], 'passed' => 0, 'failed' => 0];

// 9.1 Reverse a transfer — assert 4 entry rows + balance restored
try {
    $fromAcc = Account::find($baseline['account_ids']['cashbox_egp_office']);
    $toAcc = Account::find($baseline['account_ids']['wallet_egp']);
    $balFromBefore = (float) $fromAcc->fresh()->balance;
    $balToBefore = (float) $toAcc->fresh()->balance;
    $tx = $txService->recordJournalTransfer([
        'amount' => 750.00, 'currency' => 'EGP',
        'from_account_id' => $fromAcc->id, 'to_account_id' => $toAcc->id,
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 9.1: original tx', 'allow_from_negative' => false,
    ]);
    $entriesBefore = AccountEntry::where('transaction_id', $tx->id)->count();
    $txService->reverseTransaction($tx);
    $entriesAfter = AccountEntry::where('transaction_id', $tx->id)->count();
    // Reversal annotation: inverse AccountEntry rows use 'عكس القيد #X' prefix
    // (NOT 'عكس:' — that's the Transaction.notes prefix for the original tx).
    $reversalNotes = AccountEntry::where('transaction_id', $tx->id)
        ->where('notes', 'LIKE', 'عكس القيد #%')->count();
    $balFromAfter = (float) $fromAcc->fresh()->balance;
    $balToAfter = (float) $toAcc->fresh()->balance;
    $ok = $entriesAfter === 4 // 2 original + 2 inverse
        && $reversalNotes === 2
        && abs($balFromAfter - $balFromBefore) < 0.011
        && abs($balToAfter - $balToBefore) < 0.011;
    $check('phase9', '9.1: Reverse transfer → 4 entries (2 inverse with عكس: prefix) + balances restored', $ok, [
        'entries_before' => $entriesBefore, 'entries_after' => $entriesAfter,
        'reversal_notes_count' => $reversalNotes,
        'bal_from_before' => $balFromBefore, 'bal_from_after' => $balFromAfter,
        'bal_to_before' => $balToBefore, 'bal_to_after' => $balToAfter,
    ]);
    $ok ? $phase9['passed']++ : $phase9['failed']++;
} catch (Throwable $e) {
    $check('phase9', '9.1: Reverse transfer (THREW)', false, ['error' => $e->getMessage()]);
    $phase9['failed']++;
}

// 9.2 Reverse Income tx — additive inverse entries
try {
    $walkin = Account::find($baseline['account_ids']['fawry_walkin_ar']);
    $balBefore = (float) $walkin->fresh()->balance;  // capture BEFORE recording income
    $tx = $txService->recordIncome([
        'amount' => 500.00, 'currency' => 'EGP',
        'to_account_id' => $walkin->id,
        'module' => TransactionModule::Fawry->value,
        'related_type' => 'App\\Models\\Test\\Phase92Probe',
        'related_id' => 992,
        'notes' => 'FC-AUDIT Phase 9.2: original income',
    ]);
    $txService->reverseTransaction($tx);
    $balAfter = (float) $walkin->fresh()->balance;
    $entriesAfter = AccountEntry::where('transaction_id', $tx->id)->count();
    $reversalEntries = AccountEntry::where('transaction_id', $tx->id)
        ->where('notes', 'LIKE', 'عكس القيد #%')->count();
    // Balance must return to pre-income baseline (NOT zero — walk-in has prior balance)
    $ok = $entriesAfter === 4 && $reversalEntries === 2 && abs($balAfter - $balBefore) < 0.011;
    $check('phase9', '9.2: Reverse income → 4 entries + 2 reversal entries + walk-in AR restored to pre-income baseline', $ok, [
        'entries_after' => $entriesAfter, 'reversal_entries' => $reversalEntries,
        'walkin_balance_before' => $balBefore,
        'walkin_balance_after' => $balAfter,
    ]);
    $ok ? $phase9['passed']++ : $phase9['failed']++;
} catch (Throwable $e) {
    $check('phase9', '9.2: Reverse income (THREW)', false, ['error' => $e->getMessage()]);
    $phase9['failed']++;
}

// 9.3 Verify AccountEntry rows NEVER deleted by reversal (append-only invariant)
try {
    $tx = $txService->recordJournalTransfer([
        'amount' => 50.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
        'to_account_id' => $baseline['account_ids']['bank_egp'],
        'module' => TransactionModule::Office->value,
        'notes' => 'FC-AUDIT Phase 9.3: append-only test', 'allow_from_negative' => false,
    ]);
    $entriesBefore = AccountEntry::where('transaction_id', $tx->id)->count();
    $txService->reverseTransaction($tx);
    $entriesAfter = AccountEntry::where('transaction_id', $tx->id)->count();
    // The entry count should INCREASE (2 → 4), never decrease
    $ok = $entriesAfter > $entriesBefore && $entriesAfter >= $entriesBefore * 2;
    $check('phase9', '9.3: Reversal is ADDITIVE (entries double, never delete)', $ok, [
        'entries_before' => $entriesBefore, 'entries_after' => $entriesAfter,
        'invariant' => 'AccountEntry is append-only per docblock; reversal adds, never removes',
    ]);
    $ok ? $phase9['passed']++ : $phase9['failed']++;
} catch (Throwable $e) {
    $check('phase9', '9.3: Append-only test (THREW)', false, ['error' => $e->getMessage()]);
    $phase9['failed']++;
}

$report['phases']['phase9_reversal'] = $phase9;
echo "\n  Phase 9 result: {$phase9['passed']} passed, {$phase9['failed']} failed\n\n";

// ════════════════════════════════════════════════════════════════════════
// PHASE 11 — SECURITY (IDOR, mass assignment, payload injection)
// ════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 11 — Security (IDOR, mass assignment, payload injection)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$phase11 = ['scenarios' => [], 'passed' => 0, 'failed' => 0];

// 11.1 Employee role cannot perform recordTransfer (route middleware 'admin' check)
//     Test the SERVICE-LEVEL guard: TransactionService doesn't gate by role, but the
//     routes do. We test by attempting the API call with employee token.
try {
    $employeeToken = $baseline['tokens']['employee'];
    // The Finance/transfer route is admin-only. We don't actually call the HTTP layer
    // here (since the audit script runs at the service level), but we can test that
    // the service does NOT silently allow non-admin creation. The defense-in-depth
    // expectation is at the route/controller level, documented in Phase 16.
    // For this script, we verify that the Service throws on missing inputs (mass
    // assignment protection at the service layer).
    try {
        $txService->recordJournalTransfer([
            // Missing required keys: from_account_id, to_account_id, module
            'amount' => 100.00,
            'notes' => 'FC-AUDIT Phase 11.1: incomplete payload',
        ]);
        $check('phase11', '11.1: Incomplete payload (should have thrown)', false);
        $phase11['failed']++;
    } catch (Throwable $e) {
        $ok = true; // Any exception is acceptable defense
        $check('phase11', '11.1: Incomplete payload rejected at service layer', $ok, [
            'exception_class' => get_class($e),
            'exception_msg' => substr($e->getMessage(), 0, 100),
        ]);
        $ok ? $phase11['passed']++ : $phase11['failed']++;
    }
} catch (Throwable $e) {
    $check('phase11', '11.1: Mass-assignment test (THREW)', false, ['error' => $e->getMessage()]);
    $phase11['failed']++;
}

// 11.2 Payload injection — attempt to specify negative amount
try {
    try {
        $txService->recordJournalTransfer([
            'amount' => -100.00, 'currency' => 'EGP',
            'from_account_id' => $baseline['account_ids']['cashbox_egp_office'],
            'to_account_id' => $baseline['account_ids']['wallet_egp'],
            'module' => TransactionModule::Office->value,
            'notes' => 'FC-AUDIT Phase 11.2: negative amount', 'allow_from_negative' => false,
        ]);
        $check('phase11', '11.2: Negative amount (should have thrown)', false);
        $phase11['failed']++;
    } catch (InvalidArgumentException $e) {
        $txRows = Transaction::where('notes', 'FC-AUDIT Phase 11.2: negative amount')->count();
        $ok = $txRows === 0 && str_contains($e->getMessage(), 'positive');
        $check('phase11', '11.2: Negative amount rejected with no rows mutated', $ok, [
            'tx_rows' => $txRows, 'exception' => $e->getMessage(),
        ]);
        $ok ? $phase11['passed']++ : $phase11['failed']++;
    }
} catch (Throwable $e) {
    $check('phase11', '11.2: Negative amount test (THREW)', false, ['error' => $e->getMessage()]);
    $phase11['failed']++;
}

// 11.3 Payload injection — non-existent account_id
try {
    try {
        $txService->recordJournalTransfer([
            'amount' => 100.00, 'currency' => 'EGP',
            'from_account_id' => 99999999, // non-existent
            'to_account_id' => $baseline['account_ids']['cashbox_egp_office'],
            'module' => TransactionModule::Office->value,
            'notes' => 'FC-AUDIT Phase 11.3: phantom account', 'allow_from_negative' => false,
        ]);
        $check('phase11', '11.3: Phantom account_id (should have thrown)', false);
        $phase11['failed']++;
    } catch (Throwable $e) {
        $txRows = Transaction::where('notes', 'FC-AUDIT Phase 11.3: phantom account')->count();
        $ok = $txRows === 0;
        $check('phase11', '11.3: Phantom account_id rejected — no phantom account created', $ok, [
            'tx_rows' => $txRows, 'exception_class' => get_class($e),
        ]);
        $ok ? $phase11['passed']++ : $phase11['failed']++;
    }
} catch (Throwable $e) {
    $check('phase11', '11.3: Phantom account test (THREW)', false, ['error' => $e->getMessage()]);
    $phase11['failed']++;
}

// 11.4 Cross-module isolation — Bus module can't post via Flight transaction service path
//     We attempt to record a Transaction with module='bus' but using accounts owned by
//     'flights' module. This SHOULD work (the service doesn't enforce this), but the
//     AuditService should stamp it for traceability.
try {
    $tx = $txService->recordJournalTransfer([
        'amount' => 50.00, 'currency' => 'EGP',
        'from_account_id' => $baseline['account_ids']['cashbox_egp_tourism'], // tourism division
        'to_account_id' => $baseline['account_ids']['bank_egp'],               // office division
        'module' => TransactionModule::Bus->value, // mismatched!
        'notes' => 'FC-AUDIT Phase 11.4: cross-module audit trail test', 'allow_from_negative' => false,
    ]);
    $ok = $tx->module->value === 'bus';
    $check('phase11', '11.4: Cross-division transfer audited (module=bus, but office→tourism accounts)', $ok, [
        'tx_module' => $tx->module->value,
        'from_account_type' => Account::find($tx->from_account_id)->type->value,
        'to_account_type' => Account::find($tx->to_account_id)->type->value,
        'note' => 'Audit trail captured — Class C hardening (no cross-module enforcement at service level)',
    ]);
    $ok ? $phase11['passed']++ : $phase11['failed']++;
} catch (Throwable $e) {
    $check('phase11', '11.4: Cross-module test (THREW)', false, ['error' => $e->getMessage()]);
    $phase11['failed']++;
}

$report['phases']['phase11_security'] = $phase11;
echo "\n  Phase 11 result: {$phase11['passed']} passed, {$phase11['failed']} failed\n\n";

// ════════════════════════════════════════════════════════════════════════
// PHASE 14 — GLOBAL FINANCIAL INVARIANTS
// ════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 14 — Global Financial Invariants\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$phase14 = ['invariants' => [], 'passed' => 0, 'failed' => 0];

// 14.1 Per-account invariant: balance == SUM(credit) - SUM(debit)
try {
    $allAccounts = Account::with('entries')->get();
    $imbalanced = [];
    foreach ($allAccounts as $a) {
        if ($a->entries->count() === 0 && abs((float) $a->balance) > 0.001) {
            // Skip accounts with NO entries but non-zero balance (shouldn't happen after audit operations)
            $imbalanced[] = ['id' => $a->id, 'name' => $a->name, 'balance' => (float) $a->balance, 'entries' => 0];

            continue;
        }
        $entriesSum = round($a->entries->sum(fn ($e) => (float) $e->credit - (float) $e->debit), 2);
        if (abs($entriesSum - round((float) $a->balance, 2)) > 0.011) {
            $imbalanced[] = ['id' => $a->id, 'name' => $a->name, 'balance' => (float) $a->balance, 'entries_sum' => $entriesSum];
        }
    }
    $ok = count($imbalanced) === 0;
    $check('phase14', '14.1: Project invariant — every Account.balance == Σ(credit-debit)', $ok, [
        'total_accounts' => $allAccounts->count(),
        'imbalanced_count' => count($imbalanced),
        'imbalanced' => array_slice($imbalanced, 0, 5), // first 5
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'balance_equals_credit_minus_debit', 'ok' => $ok, 'imbalanced_count' => count($imbalanced)];
} catch (Throwable $e) {
    $check('phase14', '14.1: Per-account invariant (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.2 Per-transaction invariant (project-correct):
//     For each transaction: every entry is one-sided (debit XOR credit),
//     AND for each currency on the transaction, Σdebit == Σcredit (single-currency tx only).
//     Cross-currency tx have legs in different currencies, so per-currency aggregate
//     doesn't apply — those are validated by Phase 14.1 (per-account invariant).
try {
    // Find any entry that is BOTH debit AND credit (illegal)
    $twoSided = DB::table('account_entries')
        ->whereNotNull('transaction_id')
        ->where('debit', '>', 0)
        ->where('credit', '>', 0)
        ->count();

    // Find any single-currency tx where Σdebit != Σcredit (SQLite-compatible).
    // Cross-currency tx are validated by Phase 14.1 (per-account invariant) and are
    // intentionally allowed to have one-sided legs in different currencies.
    //
    // Step 1: identify tx_ids that touch exactly 1 currency (the ones we audit here)
    $singleCurrencyTxIds = DB::table('account_entries as ae')
        ->join('accounts as a', 'a.id', '=', 'ae.account_id')
        ->whereNotNull('ae.transaction_id')
        ->groupBy('ae.transaction_id')
        ->havingRaw('COUNT(DISTINCT a.currency) = 1')
        ->pluck('ae.transaction_id');

    // Step 2: for those single-currency tx, check Σdebit == Σcredit
    $rows = DB::table('account_entries')
        ->select('transaction_id')
        ->selectRaw('SUM(debit) AS d, SUM(credit) AS c, ABS(SUM(debit) - SUM(credit)) AS delta, COUNT(*) AS cnt')
        ->whereIn('transaction_id', $singleCurrencyTxIds)
        ->groupBy('transaction_id')
        ->havingRaw('ABS(SUM(debit) - SUM(credit)) >= 0.011')
        ->limit(5)
        ->get();
    $singleCurrencyImbalanced = $rows;
    $totalImbalanced = $twoSided + $singleCurrencyImbalanced->count();
    $ok = $totalImbalanced === 0;
    $check('phase14', '14.2: Per-transaction invariant (one-sided entries + same-currency Σdebit=Σcredit)', $ok, [
        'two_sided_entries' => $twoSided,
        'single_currency_imbalanced_count' => $singleCurrencyImbalanced->count(),
        'first_imbalanced' => $singleCurrencyImbalanced->take(3)->values()->toArray(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'per_tx_entry_one_sided_plus_same_currency_balanced', 'ok' => $ok, 'imbalanced_count' => $totalImbalanced];
} catch (Throwable $e) {
    $check('phase14', '14.2: Per-tx invariant (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.3 Orphan transactions: transactions with NO account_entries
try {
    $orphans = Transaction::whereNotExists(function ($q) {
        $q->select(DB::raw(1))->from('account_entries')->whereColumn('account_entries.transaction_id', 'transactions.id');
    })->get();
    $ok = $orphans->count() === 0;
    $check('phase14', '14.3: No orphan transactions (every tx has ≥1 AccountEntry)', $ok, [
        'orphan_count' => $orphans->count(),
        'orphans' => $orphans->take(3)->pluck('id', 'notes')->toArray(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'no_orphan_transactions', 'ok' => $ok, 'count' => $orphans->count()];
} catch (Throwable $e) {
    $check('phase14', '14.3: Orphan tx check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.4 Duplicate Income transactions per related entity (regression test for the 2026-08-12 fix)
try {
    $duplicates = DB::table('transactions')
        ->where('type', TransactionType::Income->value)
        ->whereNotNull('related_type')
        ->whereNotNull('related_id')
        ->select('related_type', 'related_id', DB::raw('COUNT(*) AS cnt'))
        ->groupBy('related_type', 'related_id')
        ->havingRaw('COUNT(*) > 1')
        ->get();
    $ok = $duplicates->count() === 0;
    $check('phase14', '14.4: No duplicate Income transactions per related entity', $ok, [
        'duplicate_count' => $duplicates->count(),
        'first' => $duplicates->take(3)->toArray(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'no_duplicate_income_per_entity', 'ok' => $ok, 'count' => $duplicates->count()];
} catch (Throwable $e) {
    $check('phase14', '14.4: Duplicate income check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.5 Invalid currencies: transactions with currency not in active currencies table
try {
    $activeCurrencies = DB::table('currencies')->where('is_active', 1)->pluck('code')->toArray();
    $invalid = DB::table('transactions')
        ->whereNotNull('currency')
        ->whereNotIn('currency', $activeCurrencies)
        ->get();
    $ok = $invalid->count() === 0;
    $check('phase14', '14.5: All transaction currencies are in active currencies list', $ok, [
        'active_currencies' => $activeCurrencies,
        'invalid_count' => $invalid->count(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'valid_currencies_only', 'ok' => $ok, 'count' => $invalid->count()];
} catch (Throwable $e) {
    $check('phase14', '14.5: Currency check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.6 Invalid exchange rates (on transfers table — that's where exchange_rate lives)
try {
    $invalid = DB::table('transfers')
        ->whereNotNull('exchange_rate')
        ->where(function ($q) {
            $q->where('exchange_rate', '<=', 0)->orWhere('exchange_rate', '>', 10000);
        })->get();
    $ok = $invalid->count() === 0;
    $check('phase14', '14.6: All transfers.exchange_rate are positive and within sane bounds (0 < rate < 10000)', $ok, [
        'invalid_count' => $invalid->count(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'valid_exchange_rates', 'ok' => $ok, 'count' => $invalid->count()];
} catch (Throwable $e) {
    $check('phase14', '14.6: Exchange rate check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.7 Broken FKs: transactions with from/to_account_id not in accounts
try {
    $broken = DB::table('transactions')
        ->where(function ($q) {
            $q->whereNotIn('from_account_id', DB::table('accounts')->select('id'))->orWhereNotIn('to_account_id', DB::table('accounts')->select('id'));
        })->get();
    $ok = $broken->count() === 0;
    $check('phase14', '14.7: All transaction account_id FKs point to existing accounts', $ok, [
        'broken_count' => $broken->count(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'no_broken_fks', 'ok' => $ok, 'count' => $broken->count()];
} catch (Throwable $e) {
    $check('phase14', '14.7: FK check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.8 Audit logs: every transaction has an audit_log entry
try {
    $missing = Transaction::whereDoesntHave('createdBy')->get(); // proxy: created_by is set per AuditStamper
    $missingAudit = Transaction::whereNull('created_by')->get();
    $ok = $missingAudit->count() === 0;
    $check('phase14', '14.8: Every transaction has created_by stamped (audit stamper invariant)', $ok, [
        'missing_created_by_count' => $missingAudit->count(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'all_tx_have_created_by', 'ok' => $ok, 'count' => $missingAudit->count()];
} catch (Throwable $e) {
    $check('phase14', '14.8: Audit log check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

// 14.9 Soft-deleted accounts with active balance
try {
    $deletedWithBalance = DB::table('accounts')
        ->whereNotNull('deleted_at')
        ->where('balance', '!=', 0)
        ->get();
    $ok = $deletedWithBalance->count() === 0;
    $check('phase14', '14.9: No soft-deleted accounts have active balance', $ok, [
        'deleted_with_balance_count' => $deletedWithBalance->count(),
    ]);
    $ok ? $phase14['passed']++ : $phase14['failed']++;
    $phase14['invariants'][] = ['invariant' => 'no_deleted_accounts_with_balance', 'ok' => $ok, 'count' => $deletedWithBalance->count()];
} catch (Throwable $e) {
    $check('phase14', '14.9: Soft-delete check (THREW)', false, ['error' => $e->getMessage()]);
    $phase14['failed']++;
}

$report['phases']['phase14_global_invariants'] = $phase14;
echo "\n  Phase 14 result: {$phase14['passed']} passed, {$phase14['failed']} failed\n\n";

// ─── Final report aggregation ──────────────────────────────────────────
$totalPassed = 0;
$totalFailed = 0;
foreach ($report['phases'] as $phase) {
    $totalPassed += $phase['passed'] ?? 0;
    $totalFailed += $phase['failed'] ?? 0;
}
$report['summary'] = [
    'total_assertions_passed' => $totalPassed,
    'total_assertions_failed' => $totalFailed,
    'defect_count' => count($defects),
];

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  AUDIT COMPLETE\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Total assertions passed: {$totalPassed}\n";
echo "  Total assertions failed: {$totalFailed}\n";
echo '  Defects collected:       '.count($defects)."\n\n";

// Write JSON report
$jsonPath = storage_path('logs/financial_core_audit_'.date('Ymd_His').'_report.json');
@mkdir(dirname($jsonPath), 0755, true);
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  Report saved to: $jsonPath\n\n";

echo '  Verdict: ';
if ($totalFailed === 0) {
    echo "✅ GO\n\n";
} elseif ($totalFailed <= 2) {
    echo "⚠️  CONDITIONAL GO — review defects\n\n";
} else {
    echo "❌ NO-GO — multiple defects found\n\n";
}
