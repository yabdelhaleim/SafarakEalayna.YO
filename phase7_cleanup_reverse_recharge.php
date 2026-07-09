<?php
/**
 * Phase 7 — Cleanup: Reverse a test recharge on production
 *
 * Usage:
 *   DB_DATABASE=safarakealayna php artisan tinker --execute='require "phase7_cleanup_reverse_recharge.php";'
 *
 * What it does:
 *   - Finds the latest airline_transaction of type "recharge" on FlightSystem id=1
 *   - Adds reversing account_entries (credits the source, debits the prepaid GL)
 *   - Updates both account balances and flight_system balance
 *   - Marks the original airline_transaction notes with [REVERSED]
 *   - All inside one DB::transaction for atomicity
 */

use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

$system = FlightSystem::find(1);
if (! $system) {
    echo "❌ FlightSystem id=1 not found\n";
    exit(1);
}

$currentBalance = (float) $system->balance;
echo "FlightSystem #1 ({$system->name}) current balance: {$currentBalance}\n";

// Safe target: -26,174.65 (original pre-write-off balance)
$targetBalance = -26174.65;

if (abs($currentBalance - $targetBalance) < 0.01) {
    echo "✅ Already at target balance ({$targetBalance}). Nothing to do.\n";
    exit(0);
}

$reversalAmount = $targetBalance - $currentBalance;
echo "Reversal needed: {$reversalAmount} EGP\n";

// Find the last recharge transaction for this system
$lastRecharge = DB::table('airline_transactions')
    ->where('system_id', $system->id)
    ->where(function ($q) {
        $q->where('transaction_type', 'recharge')
          ->orWhere('type', 'recharge');
    })
    ->where('created_at', '>=', now()->subHours(2))
    ->orderByDesc('id')
    ->first();

if (! $lastRecharge) {
    echo "⚠️ No recent recharge transaction found. Will do balance-only reversal.\n";
}

LedgerBalanceMutationGuard::run(function () use ($system, $reversalAmount, $lastRecharge) {
    DB::transaction(function () use ($system, $reversalAmount, $lastRecharge) {
        // ① Reverse flight_system balance
        $oldSystemBalance = (float) $system->balance;
        $system->balance = (float) $system->balance + $reversalAmount;
        $system->save();
        echo "  ✓ FlightSystem balance: {$oldSystemBalance} → {$system->balance}\n";

        // ② Find source account: it's the account that was debited during the original recharge
        //    We look for the AccountEntry from the original recharge
        if ($lastRecharge) {
            $rechargeAmount = (float) abs($lastRecharge->amount);
            // Get debit entries from the recharge (source account was debited)
            $originalEntries = DB::table('account_entries')
                ->where('transaction_id', $lastRecharge->transaction_id)
                ->orderBy('id')
                ->get();

            foreach ($originalEntries as $entry) {
                // Reverse the entry: flip debit/credit
                DB::table('account_entries')->insert([
                    'account_id'     => $entry->account_id,
                    'transaction_id' => null,  // Reversal — no TX, just balance adjustment
                    'debit'          => (float) $entry->credit,  // flip
                    'credit'         => (float) $entry->debit,  // flip
                    'balance_after'  => (float) $entry->balance_after + $reversalAmount,
                    'notes'          => "REVERSAL of account_entry #{$entry->id} (recharge test cleanup). Original TX #{$lastRecharge->transaction_id}",
                    'created_at'     => now(),
                    'updated_at'     => now(),
                    'deleted_at'     => null,
                ]);

                // Update account balance
                $account = Account::find($entry->account_id);
                if ($account) {
                    $oldBal = (float) $account->balance;
                    $account->balance = (float) $account->balance + $reversalAmount;
                    $account->save();
                    echo "  ✓ Account #{$account->id} ({$account->name}): {$oldBal} → {$account->balance}\n";
                }
            }

            // Mark the original recharge transaction as reversed
            DB::table('airline_transactions')
                ->where('id', $lastRecharge->id)
                ->update([
                    'notes' => '[REVERSED by Phase 7 cleanup] ' . ($lastRecharge->notes ?? ''),
                ]);
            echo "  ✓ Original airline_transaction #{$lastRecharge->id} marked as [REVERSED]\n";
        } else {
            echo "  ⚠️ No recent recharge found — only balance was adjusted. account_entries not touched.\n";
        }
    });
});

$system->refresh();
$finalBalance = (float) $system->balance;
echo "\n";
echo "FlightSystem #1 final balance: {$finalBalance}\n";
echo "Target was:                    {$targetBalance}\n";
echo 'Match: ' . (abs($finalBalance - $targetBalance) < 0.01 ? "✅ YES\n" : "❌ NO\n");
