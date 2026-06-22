<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;

DB::beginTransaction();

try {
    $clearing = app(LedgerClearingAccounts::class);
    $expenseContraId = $clearing->expenseContraIdForModule(TransactionModule::Flight);
    $transactionService = app(TransactionService::class);

    LedgerBalanceMutationGuard::run(function () use ($clearing, $expenseContraId, $transactionService) {
        echo "=== STEP 1: Creating Supplier Accounts for Flight Groups ===\n";
        $groups = FlightGroup::all();
        foreach ($groups as $g) {
            if ($g->account_id === null) {
                $g->loadMissing('carrier');
                $currency = $g->carrier?->currency ?: 'EGP';
                
                $account = Account::create([
                    'name' => 'حساب مجموعة طيران: ' . ($g->name ?: 'غير مسمى'),
                    'type' => AccountType::Supplier->value,
                    'currency' => $currency,
                    'balance' => 0.00,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'flights',
                    'notes' => 'حساب مجموعة تلقائي مضاف من النظام (تصحيح تلقائي).',
                    'created_by' => 1,
                ]);
                
                $g->account_id = $account->id;
                $g->save();
                echo "Created supplier account for group ID {$g->id} ({$g->name}): Account ID {$account->id} ({$currency})\n";
            } else {
                echo "Group ID {$g->id} ({$g->name}) already has Account ID {$g->account_id}\n";
            }
        }

        echo "\n=== STEP 2: Creating Missing COGS Transactions ===\n";
        $debtTxs = FlightGroupTransaction::where('type', 'debt')->get();
        foreach ($debtTxs as $dt) {
            // Check if there is already a ledger transaction pointing to this FlightGroupTransaction
            $exists = Transaction::where('related_type', FlightGroupTransaction::class)
                ->where('related_id', $dt->id)
                ->exists();
                
            if (!$exists) {
                $group = $dt->group;
                $booking = $dt->booking;
                
                if (!$booking) {
                    echo "Warning: Debt transaction ID {$dt->id} has no linked flight booking! Skipping COGS generation.\n";
                    continue;
                }
                
                $purchasePriceEgp = (float)($booking->purchase_price_egp ?? $booking->purchase_price);
                
                echo "Generating missing COGS entry for booking {$booking->booking_number} (Group: {$group->name}): Amount {$dt->amount} (EGP equivalent: {$purchasePriceEgp})\n";
                
                $transactionService->recordJournalTransfer([
                    'amount' => (float)$dt->amount,
                    'converted_amount' => $purchasePriceEgp,
                    'from_account_id' => $group->account_id,
                    'to_account_id' => $expenseContraId,
                    'allow_from_negative' => true,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => FlightGroupTransaction::class,
                    'related_id' => $dt->id,
                    'notes' => 'تكلفة شراء بالأجل — حجز #' . $booking->booking_number . ' — مجموعة: ' . $group->name . ' (تصحيح تلقائي COGS)',
                    'created_by' => 1,
                ]);
            } else {
                echo "COGS entry for debt transaction ID {$dt->id} already exists in ledger.\n";
            }
        }

        echo "\n=== STEP 3: Repairing Legacy Payment Transactions ===\n";
        $paymentTxs = FlightGroupTransaction::where('type', 'payment')->get();
        foreach ($paymentTxs as $pt) {
            $group = $pt->group;
            $booking = $pt->booking;
            
            // Find existing ledger transaction
            $ledgerTx = Transaction::where('related_type', FlightGroupTransaction::class)
                ->where('related_id', $pt->id)
                ->first();
                
            if ($ledgerTx) {
                // Check if it was wrongly sent to expenseContra instead of the group's account
                if ((int)$ledgerTx->to_account_id === (int)$expenseContraId) {
                    echo "Repairing legacy payment transaction ID {$ledgerTx->id} (Group: {$group->name}, Amount: {$pt->amount})\n";
                    
                    $oldToAccount = Account::lockForUpdate()->findOrFail($expenseContraId);
                    $newToAccount = Account::lockForUpdate()->findOrFail($group->account_id);
                    
                    $fromAccount = Account::findOrFail($ledgerTx->from_account_id);
                    $fromCurrency = strtoupper($fromAccount->currency);
                    $toCurrency = strtoupper($newToAccount->currency);
                    
                    $convertedAmount = (float)$pt->amount;
                    
                    // Deduct balance from old expenseContra account
                    $oldToAccount->balance -= $ledgerTx->amount;
                    $oldToAccount->save();
                    
                    // Add balance to new supplier group account (paying off debt)
                    $newToAccount->balance += $convertedAmount;
                    $newToAccount->save();
                    
                    // Update transaction record
                    $ledgerTx->to_account_id = $newToAccount->id;
                    $ledgerTx->notes = $ledgerTx->notes ? ($ledgerTx->notes . ' (تم تصحيح الحساب المورد)') : ('سند صرف - دفع لمجموعة طيران: '.$group->name . ' (تصحيح)');
                    $ledgerTx->save();
                    
                    // Update account entries for the destination leg
                    $entry = AccountEntry::where('transaction_id', $ledgerTx->id)
                        ->where('account_id', $expenseContraId)
                        ->first();
                    if ($entry) {
                        $entry->account_id = $newToAccount->id;
                        $entry->balance_after = $newToAccount->balance;
                        $entry->credit = $convertedAmount;
                        $entry->save();
                    }
                    
                    echo "  --> Destination account changed to {$newToAccount->name}. Adjusted balances.\n";
                } else {
                    echo "Payment transaction ID {$ledgerTx->id} is already correctly mapped to Account ID {$ledgerTx->to_account_id}.\n";
                }
            } else {
                // If no ledger transaction exists (e.g. for booking cancellations), create a journal transfer
                if ($booking) {
                    $purchaseEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
                    // Simple reversal
                    echo "Generating missing reversal entry for cancellation of booking {$booking->booking_number} (Group: {$group->name}): Amount {$pt->amount}\n";
                    
                    $transactionService->recordJournalTransfer([
                        'amount' => $purchaseEgp,
                        'converted_amount' => (float)$pt->amount,
                        'from_account_id' => $expenseContraId,
                        'to_account_id' => $group->account_id,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightGroupTransaction::class,
                        'related_id' => $pt->id,
                        'notes' => 'إلغاء شراء تذكرة طيران (إرجاع رصيد) — حجز #'.$booking->booking_number.' — مجموعة: ' . $group->name . ' (تصحيح)',
                        'created_by' => 1,
                    ]);
                } else {
                    echo "Warning: Payment transaction ID {$pt->id} has no ledger entry and no booking! Manual check required.\n";
                }
            }
        }
        
        // Finally, let's recalculate and show balances of all supplier accounts
        echo "\n=== STEP 4: Ledger Reconciliation check ===\n";
        $groupsList = FlightGroup::all();
        foreach ($groupsList as $g) {
            $g->refresh();
            $totalDebt = (float)$g->groupTransactions()->where('type', 'debt')->sum('amount');
            $totalPayment = (float)$g->groupTransactions()->where('type', 'payment')->sum('amount');
            $expectedBalance = $totalDebt - $totalPayment;
            
            $acc = Account::find($g->account_id);
            $actualBalance = $acc ? (float)$acc->balance : 0.0;
            
            // Supplier accounts have negative balance when we owe them, so expectedBalance = -actualBalance
            echo "Group: {$g->name} | Expected B2B Balance: {$expectedBalance} | Actual Ledger Account Balance: {$actualBalance}\n";
        }
    });

    DB::commit();
    echo "\n=== REPAIR COMPLETED SUCCESSFULLY ===\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "\nError during repair: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
