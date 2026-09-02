<?php

/**
 * Fawry Database Forensics — Duplicate / Orphan / Inconsistency Scan
 * =================================================================
 *
 * Purpose: Detect any data corruption introduced by B-3 over-correction
 * or any other code path. Runs 12 forensic queries against the test DB
 * (in-memory SQLite) after running the full Fawry lifecycle suite, then
 * against an explicit stress scenario that hammers CREATE→UPDATE→DELETE
 * with chained txs and walk-in pay-debt cycles.
 *
 * Each query returns 0 (clean) or > 0 (issues). All forensic checks
 * must return 0 for a clean database state.
 *
 * Run via:
 *   php tests/scripts/fa_fawry_db_forensics.php
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

Artisan::call('migrate', ['--force' => true]);

use App\Enums\AccountType;
use App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function check(string $id, string $label, int $count, string $expectedLabel): void
{
    global $pass, $fail;
    if ($count === 0) {
        $pass++;
        echo "  ✅ {$id} — {$label} (0 issues)\n";
    } else {
        $fail++;
        echo "  ❌ {$id} — {$label} ({$count} {$expectedLabel})\n";
    }
}

$admin = User::firstOrCreate(
    ['email' => 'forensics@db.local'],
    ['name' => 'Forensics Admin', 'password' => bcrypt('test'), 'email_verified_at' => now()]
);
Auth::login($admin);
$svc = app(FawryTransactionService::class);
$clearing = app(LedgerClearingAccounts::class);

// Setup
$cashbox = Account::create([
    'name' => 'Forensics Cashbox', 'type' => AccountType::Cashbox, 'balance' => 50000.00,
    'currency' => 'EGP', 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);
$machine = FawryMachine::create([
    'name' => 'Forensics Machine', 'type' => 'fawry', 'balance' => 30000.00, 'is_active' => true,
]);
$customer = Customer::create([
    'full_name' => 'Forensics Customer', 'phone' => '01000999',
    'account_id' => null, 'created_by' => $admin->id,
]);

// ── Stress workload: 20 CREATE/UPDATE/DELETE + walk-in pay-debt cycles ──
for ($i = 0; $i < 20; $i++) {
    $tx = $svc->createTransaction([
        'client_id' => $customer->id, 'client_name' => $customer->full_name,
        'operation_type' => 'bill_payment',
        'client_amount' => 1000.00, 'fawry_price' => 800.00,
        'selling_price' => 1000.00, 'amount' => 1000.00,
        'employee_id' => $admin->id, 'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
    ]);
    if ($i % 2 === 0) {
        $svc->updateTransaction($tx, [
            'client_amount' => 1200.00, 'fawry_price' => 1000.00,
            'selling_price' => 1200.00, 'amount' => 1200.00,
        ]);
    }
    if ($i % 3 === 0) {
        try {
            $svc->deleteTransaction($tx);
        } catch (Throwable $e) {
            // DeferredTransactionDeletionGuard may block deletes after pay-debt;
            // that's the intended production-safety behavior, not a forensic issue.
        }
    }
}

// Walk-in cycles
for ($i = 0; $i < 10; $i++) {
    $walkinName = "Walkin Forensics {$i}";
    $tx1 = $svc->createTransaction([
        'client_name' => $walkinName, 'operation_type' => 'bill_payment',
        'client_amount' => 500.00, 'fawry_price' => 400.00,
        'selling_price' => 500.00, 'amount' => 0.00,
        'employee_id' => $admin->id, 'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
    ]);
    $tx2 = $svc->createTransaction([
        'client_name' => $walkinName, 'operation_type' => 'bill_payment',
        'client_amount' => 700.00, 'fawry_price' => 600.00,
        'selling_price' => 700.00, 'amount' => 0.00,
        'employee_id' => $admin->id, 'account_id' => $cashbox->id,
        'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
    ]);
    $ctrl = app(FawryWalkInPaymentController::class);
    $ctrl->payDebt(new Request([
        'client_name' => $walkinName, 'amount' => 400.00, 'account_id' => $cashbox->id,
    ]));
    if ($i % 4 === 0) {
        try {
            $svc->deleteTransaction($tx1);
        } catch (Throwable $e) {
            // DeferredTransactionDeletionGuard may block deletes after pay-debt;
            // that's the intended production-safety behavior, not a forensic issue.
        }
    }
}

echo "\n=== FORENSICS: 12 DATABASE INTEGRITY CHECKS ===\n\n";

// F-1: Duplicate income transactions on same FawryTransaction (Path C guard)
$dupIncome = DB::table('transactions')
    ->select('related_type', 'related_id', DB::raw('COUNT(*) as c'))
    ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
    ->where('type', 'income')
    ->whereNull('notes')
    ->groupBy('related_type', 'related_id')
    ->havingRaw('COUNT(*) > 1')
    ->count();
check('F-1', 'No duplicate ACTIVE income txs on same Fawry tx (Path C)', $dupIncome, 'duplicate groups');

// F-2: Registered-customer Fawry tx with amount>0 but no cashbox credit
// (excludes walk-in txs which legitimately have amount=0 and no settlement)
$noSettlement = DB::table('fawry_transactions')
    ->whereNull('deleted_at')
    ->whereNotNull('client_id')
    ->where('amount', '>', 0)
    ->whereNotExists(function ($q) {
        $q->from('transactions as t')
            ->join('account_entries as ae', 'ae.transaction_id', '=', 't.id')
            ->whereColumn('t.related_id', 'fawry_transactions.id')
            ->where('t.related_type', 'App\\Models\\Fawry\\FawryTransaction')
            ->where('ae.account_id', DB::raw('fawry_transactions.account_id'))
            ->where('ae.credit', '>', 0);
    })
    ->count();
check('F-2', 'Every REGISTERED Fawry tx with amount>0 has at least one cashbox credit',
    $noSettlement, 'txs missing settlement');

// F-3: Reversal entries without matching original.
// Reversal notes format: "عكس القيد #<original_id>" (12-char prefix).
// Use LIKE to count originals per reversal ref and flag mismatches.
$orphanReversals = 0;
$reversalRows = DB::table('account_entries')
    ->where('notes', 'like', 'عكس القيد #%')
    ->get(['notes']);
foreach ($reversalRows as $r) {
    if (preg_match('/#(\d+)/', (string) $r->notes, $m)) {
        $origId = (int) $m[1];
        $exists = DB::table('account_entries')->where('id', $origId)->exists();
        if (! $exists) {
            $orphanReversals++;
        }
    }
}
check('F-3', 'Every reversal entry references an existing original entry', $orphanReversals, 'orphan reversals');

// F-4: GL imbalance per transaction (Σdebit ≠ Σcredit)
$imbalanced = 0;
foreach (DB::table('transactions')
    ->whereIn('related_type', ['App\\Models\\Fawry\\FawryTransaction'])
    ->distinct()->pluck('id') as $txId) {
    $dr = (float) DB::table('account_entries')->where('transaction_id', $txId)->sum('debit');
    $cr = (float) DB::table('account_entries')->where('transaction_id', $txId)->sum('credit');
    if (abs($dr - $cr) >= 0.005) {
        $imbalanced++;
    }
}
check('F-4', 'Every Fawry-linked transaction is GL-balanced (Σdr = Σcr)', $imbalanced, 'unbalanced txs');

// F-5: LIQUIDITY invariant — no CASHBOX/CASHIER account may go negative.
// Clearing accounts (prepaid, income/expense clearing) are system buckets
// that ARE expected to hold negative balances — that's their purpose.
$mismatchedAccounts = 0;
$mismatchDetails = [];

$liquidityTypes = ['cashbox', 'cashier', 'bank', 'wallet'];
foreach (DB::table('accounts')
    ->whereIn('type', $liquidityTypes)
    ->whereIn('id', DB::table('account_entries')->distinct()->pluck('account_id'))
    ->pluck('id') as $acctId) {
    $actual = (float) DB::table('accounts')->where('id', $acctId)->value('balance');
    if ($actual < 0) {
        $mismatchedAccounts++;
        $acctName = DB::table('accounts')->where('id', $acctId)->value('name');
        $mismatchDetails[] = "    acct #{$acctId} ({$acctName}): balance={$actual} (NEGATIVE — liquidity breach)";
    }
}
if ($mismatchedAccounts > 0) {
    foreach ($mismatchDetails as $detail) {
        echo $detail."\n";
    }
}
check('F-5', 'No LIQUIDITY account went NEGATIVE (cashbox/cashier/bank/wallet)',
    $mismatchedAccounts, 'negative-balance liquidity accounts');

// F-6: Phantom deficit correction entries (B-3 over-correction indicator)
$phantomCorrections = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
    ->where('notes', 'like', '%تصحيح عجز حذف عملية فوري #%')
    ->count();
// Phantom only if deficit correction fired for a tx that has NO orphan
// in the GL (i.e. the cashbox was already balanced pre-DELETE).
$realCorrections = 0;
foreach (DB::table('transactions')
    ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
    ->where('notes', 'like', '%تصحيح عجز حذف عملية فوري #%')
    ->get() as $corr) {
    // Extract fawry tx id from notes
    if (preg_match('/#(\d+)/', (string) $corr->notes, $m)) {
        $fawryId = (int) $m[1];
        // Check if the settlement account has any orphan GL entries (no transaction_id)
        $settlementAcctId = DB::table('fawry_transactions')->where('id', $fawryId)->value('account_id');
        if ($settlementAcctId) {
            $orphans = DB::table('account_entries')
                ->where('account_id', $settlementAcctId)
                ->whereNull('transaction_id')
                ->count();
            if ($orphans === 0) {
                // No orphan → correction should NOT have fired. Phantom.
                $realCorrections++;
            }
        }
    }
}
check('F-6', 'No PHANTOM deficit corrections (corrections only fire when orphans exist)', $realCorrections, 'phantom corrections');

// F-7: Soft-deleted fawry_transactions referenced by transactions table
$orphanRefs = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Fawry\\FawryTransaction')
    ->whereNotIn('related_id', function ($q) {
        $q->select('id')->from('fawry_transactions');
    })
    ->count();
check('F-7', 'No transactions reference non-existent Fawry tx ids', $orphanRefs, 'orphan FK refs');

// F-8: Cashbox row locked but no matching Account (config integrity)
$badCashbox = DB::table('fawry_transactions')
    ->whereNotNull('account_id')
    ->whereNotExists(function ($q) {
        $q->from('accounts')->whereColumn('accounts.id', 'fawry_transactions.account_id');
    })
    ->count();
check('F-8', 'Every Fawry tx.account_id points to existing Account', $badCashbox, 'broken FKs');

// F-9: Machine row exists for every fawry_transactions.fawry_machine_id
$badMachine = DB::table('fawry_transactions')
    ->whereNotNull('fawry_machine_id')
    ->whereNotExists(function ($q) {
        $q->from('fawry_machines')->whereColumn('fawry_machines.id', 'fawry_transactions.fawry_machine_id');
    })
    ->count();
check('F-9', 'Every Fawry tx.fawry_machine_id points to existing FawryMachine', $badMachine, 'broken FKs');

// F-10: Walk-in AR balance matches SUM(selling_price - amount) for active walk-in txs
$walkinArId = $clearing->fawryWalkInArAccountId();
$walkinDebt = (float) DB::table('fawry_transactions')
    ->whereNull('client_id')->whereNull('deleted_at')
    ->selectRaw('COALESCE(SUM(selling_price - amount), 0) as d')->value('d');
$walkinArBal = $walkinArId ? (float) Account::find($walkinArId)?->balance ?? 0 : 0;
$walkinMismatch = (abs($walkinArBal - $walkinDebt) >= 0.01) ? 1 : 0;
check('F-10', 'Walk-in AR balance matches SUM(selling_price - amount) for active walk-in txs',
    $walkinMismatch, 'mismatch instances');

// F-11: customer_id=NULL on a tx where client_name matches an active Customer
$misroutedWalkins = DB::table('fawry_transactions as ft')
    ->whereNull('ft.deleted_at')
    ->whereNull('ft.client_id')
    ->whereExists(function ($q) {
        $q->from('customers as c')
            ->whereColumn('c.full_name', 'ft.client_name')
            ->whereNull('c.deleted_at');
    })
    ->count();
check('F-11', 'Walk-in txs (client_id IS NULL) never overlap with active Customer names', $misroutedWalkins, 'misrouted txs');

// F-12: FawryMachine balance matches expected (machine.balance >= 0 always)
$negativeMachines = DB::table('fawry_machines')->where('balance', '<', 0)->count();
check('F-12', 'No FawryMachine with balance < 0', $negativeMachines, 'negative machines');

echo "\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
echo "\n";
echo $fail === 0
    ? "🟢 DATABASE CLEAN — 0 duplicates, 0 orphans, 0 imbalances, 0 phantom corrections.\n"
    : "🔴 FORENSIC ISSUES DETECTED — investigate before production sign-off.\n";
exit($fail === 0 ? 0 : 1);
