<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Fix: tx#303 imbalance (cross-currency transfer)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  CONTEXT
 *  -------
 *  tx#303 is a transfer: 23,925 EGP from acc#26 (EGP cashbox) to 145 KWD
 *  in acc#27 (KWD cashbox). The journal entries are:
 *    entry 607: acc#26 | debit 23,925 | credit 0     ← money out (EGP)
 *    entry 608: acc#27 | debit 0      | credit 145  ← money in (KWD)
 *
 *  The trial balance equation Σ debit = Σ credit fails (23,925 ≠ 145)
 *  because the entries are in DIFFERENT currencies.
 *
 *  The fix (Option B): add a write-off transaction that:
 *    1. Reverses the 23,925 EGP debit on acc#26 (cashbox restored)
 *    2. Records the 23,780 EGP gap as a currency exchange loss expense
 *
 *  New transaction:
 *    type: 'writeoff'
 *    module: 'general'
 *    amount: 23,780 EGP
 *    entries:
 *      debit 23,780 EGP → "Currency exchange loss" account
 *      credit 23,780 EGP → acc#26 (cashbox restored)
 *
 *  Side effect: creates a "Currency exchange loss" account if it doesn't exist.
 *
 *  CLI
 *  ---
 *  php scripts/fix_tx303.php               # dry-run (default)
 *  php scripts/fix_tx303.php --apply       # apply (with confirmation)
 *  php scripts/fix_tx303.php --apply --yes # apply without confirmation
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$yes = in_array('--yes', $argv, true);
$mode = $apply ? 'APPLY' : 'DRY-RUN';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║  " . ($apply ? '⚠️  APPLY MODE — will write to database' : '🔍 DRY-RUN MODE — read-only') . str_repeat(' ', 33 - strlen($mode)) . "║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

// ──────────────────────────────────────────────────────────────────────────
// [1] Environment + safety
// ──────────────────────────────────────────────────────────────────────────
$appEnv = config('app.env');
$dbDatabase = config('database.connections.'.config('database.default').'.database');

echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [1] Environment + safety\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo str_pad('Mode', 60, ' ') . " : {$mode}\n";
echo str_pad('APP_ENV', 60, ' ') . " : {$appEnv}\n";
echo str_pad('DB_DATABASE', 60, ' ') . " : {$dbDatabase}\n";

// Pre-snapshot
$preSnapshot = [
    'transactions_count' => DB::table('transactions')->count(),
    'account_entries_count' => DB::table('account_entries')->count(),
    'accounts_count' => DB::table('accounts')->count(),
];
echo str_pad('Pre-snapshot transactions', 60, ' ') . " : {$preSnapshot['transactions_count']}\n";
echo str_pad('Pre-snapshot account_entries', 60, ' ') . " : {$preSnapshot['account_entries_count']}\n";
echo str_pad('Pre-snapshot accounts', 60, ' ') . " : {$preSnapshot['accounts_count']}\n";

// ──────────────────────────────────────────────────────────────────────────
// [2] Verify tx#303 imbalance
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [2] Verify tx#303 imbalance\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$tx = DB::table('transactions')->where('id', 303)->first();
if (! $tx) {
    echo "  ❌ tx#303 not found. Aborting.\n";
    exit(1);
}

$entries = DB::table('account_entries')->where('transaction_id', 303)->get();
$totalDebit = $entries->sum('debit');
$totalCredit = $entries->sum('credit');
$diff = $totalDebit - $totalCredit;

echo str_pad('tx#303 amount', 60, ' ') . " : {$tx->amount} {$tx->currency}\n";
echo str_pad('tx#303 from_account_id', 60, ' ') . " : {$tx->from_account_id}\n";
echo str_pad('tx#303 to_account_id', 60, ' ') . " : {$tx->to_account_id}\n";
echo str_pad('Total debit', 60, ' ') . " : " . number_format($totalDebit, 2) . "\n";
echo str_pad('Total credit', 60, ' ') . " : " . number_format($totalCredit, 2) . "\n";
echo str_pad('Imbalance', 60, ' ') . " : " . number_format($diff, 2) . " EGP\n";

if (abs($diff) < 0.01) {
    echo "  ✅ tx#303 is already balanced. Nothing to do.\n";
    exit(0);
}

// ──────────────────────────────────────────────────────────────────────────
// [3] Check / create the loss account
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [3] Check / create the loss account\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$lossAccount = DB::table('accounts')
    ->where('name', 'Currency exchange loss')
    ->where('currency', 'EGP')
    ->first();

if ($lossAccount) {
    echo str_pad('Loss account exists', 60, ' ') . " : id={$lossAccount->id} | balance={$lossAccount->balance}\n";
} else {
    echo "  Loss account does NOT exist. Will be created on apply.\n";
    echo "  Suggested: id=auto | name='Currency exchange loss' | type='expense' | currency='EGP' | module_type='general'\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [4] Dry-run / apply
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [4] " . ($apply ? 'Applying the fix' : 'Dry-run summary') . "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

if ($apply) {
    if (! $yes) {
        echo "\n  ⚠️  PRESS ENTER TO CONFIRM, OR Ctrl+C TO ABORT\n\n  > ";
        $confirm = trim(fgets(STDIN));
        $confirmLower = strtolower($confirm);
        if ($confirmLower !== 'yes' && $confirmLower !== 'y' && $confirmLower !== '') {
            echo "\n  ❌ Aborted.\n";
            exit(0);
        }
    }

    try {
        DB::transaction(function () use (&$lossAccount) {
            // 1. Create the loss account if it doesn't exist
            if (! $lossAccount) {
                $lossAccountId = DB::table('accounts')->insertGetId([
                    'name' => 'Currency exchange loss',
                    'type' => 'expense',
                    'currency' => 'EGP',
                    'module_type' => 'general',
                    'module' => 'general',
                    'owner_type' => 'office',
                    'balance' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $lossAccount = DB::table('accounts')->where('id', $lossAccountId)->first();
                echo "  ✅ Created loss account (id={$lossAccountId})\n";
            }

            // 2. Create the write-off transaction
            $writeOffTxId = DB::table('transactions')->insertGetId([
                'type' => 'writeoff',
                'module' => 'general',
                'amount' => 23780,
                'currency' => 'EGP',
                'from_account_id' => 26,
                'to_account_id' => $lossAccount->id,
                'related_type' => DB::table('transactions')->where('id', 303)->value('related_type'),
                'related_id' => 303,
                'notes' => 'Currency exchange adjustment for tx#303 cross-currency transfer — 23,780 EGP gap',
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "  ✅ Created write-off tx (id={$writeOffTxId})\n";

            // 3. Add entry 1: debit 23,780 EGP on loss account
            DB::table('account_entries')->insert([
                'account_id' => $lossAccount->id,
                'transaction_id' => $writeOffTxId,
                'debit' => 23780,
                'credit' => 0,
                'balance_after' => $lossAccount->balance + 23780,
                'notes' => 'Translation loss for tx#303',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "  ✅ Added entry: debit 23,780 on loss account\n";

            // 4. Add entry 2: credit 23,780 EGP on acc#26 (cashbox restored)
            $acc26 = DB::table('accounts')->where('id', 26)->first();
            DB::table('account_entries')->insert([
                'account_id' => 26,
                'transaction_id' => $writeOffTxId,
                'debit' => 0,
                'credit' => 23780,
                'balance_after' => $acc26->balance + 23780,
                'notes' => 'Cashbox restoration for tx#303 currency exchange',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "  ✅ Added entry: credit 23,780 on acc#26 (cashbox restored)\n";

            // 5. Update the loss account balance
            DB::table('accounts')->where('id', $lossAccount->id)->update([
                'balance' => $lossAccount->balance + 23780,
                'updated_at' => now(),
            ]);
            echo "  ✅ Updated loss account balance: {$lossAccount->balance} → " . ($lossAccount->balance + 23780) . "\n";

            // 6. Update acc#26 balance
            DB::table('accounts')->where('id', 26)->update([
                'balance' => $acc26->balance + 23780,
                'updated_at' => now(),
            ]);
            echo "  ✅ Updated acc#26 balance: {$acc26->balance} → " . ($acc26->balance + 23780) . "\n";
        });

        echo "  ✅ All updates succeeded.\n";
    } catch (\Throwable $e) {
        echo "  ❌ Error: " . $e->getMessage() . "\n";
        echo "  ⚠️  Transaction rolled back. No changes applied.\n";
        exit(1);
    }
} else {
    echo "  [DRY-RUN] Plan:\n";
    echo "    1. Create account 'Currency exchange loss' (type=expense, module_type=general, currency=EGP) if missing\n";
    echo "    2. Create write-off transaction (type=writeoff, amount=23,780 EGP)\n";
    echo "    3. Add entry 1: debit 23,780 EGP on loss account\n";
    echo "    4. Add entry 2: credit 23,780 EGP on acc#26 (cashbox restored)\n";
    echo "    5. Update account balances\n";
    echo "\n";
    echo "  [DRY-RUN] Expected impact:\n";
    echo "    tx#303 imbalance: 23,780 → 23,780 (unchanged — only the new write-off tx is balanced)\n";
    echo "    New write-off tx: balanced (debit 23,780 = credit 23,780)\n";
    echo "    acc#26 balance: +23,780 EGP (cashbox restored from 118,093 to 141,873)\n";
    echo "    Loss account: NEW, balance 23,780 EGP (expense)\n";
    echo "    Variance: depends on whether the loss account is counted in current_capital\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [5] Post-snapshot + integrity check
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [5] Post-snapshot + integrity check\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$postSnapshot = [
    'transactions_count' => DB::table('transactions')->count(),
    'account_entries_count' => DB::table('account_entries')->count(),
    'accounts_count' => DB::table('accounts')->count(),
];

echo str_pad('Post-snapshot transactions', 60, ' ') . " : {$postSnapshot['transactions_count']}\n";
echo str_pad('Post-snapshot account_entries', 60, ' ') . " : {$postSnapshot['account_entries_count']}\n";
echo str_pad('Post-snapshot accounts', 60, ' ') . " : {$postSnapshot['accounts_count']}\n";

$expectedDelta = $apply ? ['transactions' => 1, 'account_entries' => 2, 'accounts' => $lossAccount ? 0 : 1] : ['transactions' => 0, 'account_entries' => 0, 'accounts' => 0];
$actualDelta = [
    'transactions' => $postSnapshot['transactions_count'] - $preSnapshot['transactions_count'],
    'account_entries' => $postSnapshot['account_entries_count'] - $preSnapshot['account_entries_count'],
    'accounts' => $postSnapshot['accounts_count'] - $preSnapshot['accounts_count'],
];

if ($actualDelta == $expectedDelta) {
    echo "  ✅ Integrity check passed (delta {transactions:{$actualDelta['transactions']}, entries:{$actualDelta['account_entries']}, accounts:{$actualDelta['accounts']}}).\n";
} else {
    echo "  ⚠️  Integrity check FAILED — expected delta: " . json_encode($expectedDelta) . " | actual: " . json_encode($actualDelta) . "\n";
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  📋 NEXT STEPS\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
if (!$apply) {
    echo "  1. Review the dry-run plan above.\n";
    echo "  2. If OK, run:\n";
    echo "       php scripts/fix_tx303.php --apply\n";
} else {
    echo "  1. Run the diagnostic to verify the variance change:\n";
    echo "       php scripts/diag_office_profit_breakdown.php\n";
    echo "  2. (Optional) Re-run the dry-run tx#303 to confirm tx#303 is still flagged:\n";
    echo "       php scripts/dryrun_fix_tx303.php\n";
}
echo "══════════════════════════════════════════════════════════════════════════\n\n";
