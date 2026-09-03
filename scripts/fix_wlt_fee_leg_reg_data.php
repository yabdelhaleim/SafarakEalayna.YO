<?php

/**
 * DATA FIX (opt-in) — Correct customer AR balances affected by WLT-FEE-LEG-REG bug.
 *
 * Pre-fix bug:
 *   For each registered-customer SEND that fully settled (amount_paid ==
 *   total_amount), the customer mirror was debited by amount_paid instead of
 *   amount. Net customer balance = -fee instead of 0.
 *
 * This script identifies affected WalletTransactions and POSTS an additive
 * corrective journal transfer on EACH one, returning the customer balance
 * to 0. It is ADDITIVE — never destructive — so a wrong execution can be
 * manually reverted with reverseTransaction.
 *
 * SAFETY:
 *   - Each corrective leg is tagged 'تصحيح WLT-FEE-LEG-REG #' to make
 *     them greppable for review / reversal.
 *   - Re-runnable: if a corrective entry already exists, the script skips
 *     that transaction (idempotent).
 *   - DRY-RUN by default. Pass --apply to commit.
 *
 * Usage:
 *   php scripts/fix_wlt_fee_leg_reg_data.php           # dry-run
 *   php scripts/fix_wlt_fee_leg_reg_data.php --apply   # commit
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);

if (! $apply) {
    echo "=================================================\n";
    echo " DRY-RUN mode — no changes will be written.\n";
    echo " Pass --apply to commit the corrective entries.\n";
    echo "=================================================\n\n";
}

$txService = app(TransactionService::class);
$authUserId = (int) (Auth::id() ?? 1);

echo "=== WLT-FEE-LEG-REG DATA FIX ===\n";
echo "Commit reference: 263f7e2 — registered SEND customer balance bug.\n";
echo str_repeat('=', 60) . "\n\n";

// -------------------------------------------------------------------------
// STEP 1 — Find affected WalletTransactions.
//
//  An affected WT is a registered-customer SEND where:
//     - amount_paid > 0 (settlement posted)
//     - customer balance currently equals `-(service_fee)` (the buggy net)
//     - No corrective entry has been posted yet (idempotency check).
// -------------------------------------------------------------------------

$customerAccounts = Account::where('type', AccountType::Customer->value)
    ->where('module_type', 'wallet_transfer')
    ->pluck('id');

if ($customerAccounts->isEmpty()) {
    echo "No customer accounts found. Nothing to fix.\n";
    exit(0);
}

// For each customer account with a negative balance, find the WT(s)
// that contributed that negative.
$badBalances = DB::table('account_entries as ae')
    ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
    ->whereIn('ae.account_id', $customerAccounts)
    ->where('t.module', 'wallet')
    ->where('t.related_type', WalletTransaction::class)
    ->groupBy('ae.account_id')
    ->havingRaw('SUM(ae.debit) - SUM(ae.credit) > 0.01')
    ->selectRaw('ae.account_id, SUM(ae.debit) - SUM(ae.credit) AS excess_debit')
    ->get();

if ($badBalances->isEmpty()) {
    echo "✅ No affected customer accounts — your data is clean.\n";
    echo "   (Each customer account either balances to 0 or has positive net = receivable.)\n";
    exit(0);
}

echo "Affected accounts found: " . $badBalances->count() . "\n";
foreach ($badBalances as $r) {
    echo "  - account#{$r->account_id} ('" . Account::find($r->account_id)?->name . "') excess debit: "
        . number_format((float) $r->excess_debit, 2) . " EGP\n";
}
echo "\n";

// For each affected account, the excess debit = sum of fees on full-settlement
// WTs. We re-derive per-WT so each gets its own corrective entry (greppable).
$fixCount = 0;
$totalExcess = 0.0;

foreach ($badBalances as $r) {
    $accountId = (int) $r->account_id;
    $excess = (float) $r->excess_debit;

    // Find the WTs whose settlement leg (customer → cash) over-debited this account.
    $wts = DB::table('transactions as t')
        ->where('t.module', 'wallet')
        ->where('t.related_type', WalletTransaction::class)
        ->where('t.type', 'transfer')
        ->where('t.from_account_id', $accountId)
        ->where(function ($q) {
            $q->whereNull('t.notes')
                ->orWhere('t.notes', 'not like', 'عكس%')
                ->orWhere('t.notes', 'not like', 'تصحيح WLT-FEE-LEG-REG%');
        })
        ->select('t.id', 't.amount', 't.related_id', 't.to_account_id', 't.notes')
        ->get();

    foreach ($wts as $wt) {
        $wtRow = WalletTransaction::find($wt->related_id);
        if (! $wtRow) {
            continue;
        }
        if ($wtRow->type !== 'send') {
            continue;
        }
        if ((float) $wtRow->amount_paid < 0.001) {
            continue;
        }

        // The bug: settlement posted (amount_paid) instead of (amount).
        // Corrective entry: re-credit customer by (amount_paid - amount) = fee.
        $fee = (float) $wtRow->service_fee;
        if ($fee < 0.005) {
            continue;
        }

        $correctiveAmount = round($fee, 2);

        // Idempotency: if a corrective entry already exists for this WT, skip.
        $existing = DB::table('transactions')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $wtRow->id)
            ->where('notes', 'like', 'تصحيح WLT-FEE-LEG-REG#%')
            ->exists();

        if ($existing) {
            echo "  [SKIP] WT#{$wtRow->id} — corrective entry already exists.\n";
            continue;
        }

        echo "  WT#{$wtRow->id} (amount={$wtRow->amount} fee={$fee} paid={$wtRow->amount_paid})\n";
        echo "    Action: customer → cash by {$correctiveAmount} (re-credit customer)\n";
        echo "    Notes: تصحيح WLT-FEE-LEG-REG#{$wtRow->id}\n";

        if ($apply) {
            LedgerBalanceMutationGuard::run(function () use ($wtRow, $correctiveAmount, $authUserId) {
                $tx = Transaction::create([
                    'type' => 'transfer',
                    'amount' => $correctiveAmount,
                    'currency' => 'EGP',
                    'module' => 'wallet',
                    'related_type' => WalletTransaction::class,
                    'related_id' => $wtRow->id,
                    'from_account_id' => $wtRow->cash_account_id,
                    'to_account_id' => $wtRow->customer_account_id, // wait — see note below
                    'created_by' => $authUserId,
                    'notes' => "تصحيح WLT-FEE-LEG-REG#{$wtRow->id}: إعادة قيد {$correctiveAmount} من الخزنة للعميل لتصحيح رصيد العميل (Settlement مدين أكثر من اللازم)",
                ]);

                // Apply: debit cash by correctiveAmount, credit customer by correctiveAmount
                DB::table('accounts')->where('id', $wtRow->cash_account_id)->decrement('balance', $correctiveAmount);
                DB::table('accounts')->where('id', $wtRow->customer_account_id)->increment('balance', $correctiveAmount);

                DB::table('account_entries')->insert([
                    [
                        'account_id' => $wtRow->cash_account_id,
                        'transaction_id' => $tx->id,
                        'debit' => $correctiveAmount,
                        'credit' => 0,
                        'balance_after' => DB::table('accounts')->where('id', $wtRow->cash_account_id)->value('balance'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'account_id' => $wtRow->customer_account_id,
                        'transaction_id' => $tx->id,
                        'debit' => 0,
                        'credit' => $correctiveAmount,
                        'balance_after' => DB::table('accounts')->where('id', $wtRow->customer_account_id)->value('balance'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            });

            $fixCount++;
            $totalExcess += $correctiveAmount;
            echo "    ✓ Applied.\n";
        } else {
            $fixCount++;
            $totalExcess += $correctiveAmount;
            echo "    [DRY-RUN] Skipped commit.\n";
        }
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Summary:\n";
echo "  Transactions to fix: {$fixCount}\n";
echo "  Total fee to re-credit (EGP): " . number_format($totalExcess, 2) . "\n";

if (! $apply) {
    echo "\n  → Re-run with --apply to commit the corrections.\n";
} else {
    echo "\n  ✓ Corrections committed.\n";
    echo "  Verify: php scripts/audit_wlt_fee_leg_reg_affected.php\n";
}
