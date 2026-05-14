<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\TreasuryTransaction;

/**
 * Creates a treasury_transactions row reflecting an existing GL inbound leg (cash account = transaction.to_account_id).
 * Keeps الخزينة auditable beside account_entries without double-posting balances.
 */
class TreasuryLedgerMirror
{
    public static function mirrorFlightInboundReceipt(
        Transaction $ledgerTransaction,
        int $flightBookingId,
        string $reason,
        string $agentDisplayName,
        ?string $toTreasuryDisplay = null,
    ): TreasuryTransaction {
        $cashAccountId = (int) ($ledgerTransaction->to_account_id ?? 0);
        if ($cashAccountId <= 0) {
            throw new \InvalidArgumentException('القيد المحاسبي لا يحتوي على حساب نقدي/خزينة (to_account_id).');
        }

        $account = Account::query()->findOrFail($cashAccountId);

        return TreasuryTransaction::create([
            'transaction_type' => 'credit',
            'account_id' => $account->id,
            'from_treasury' => null,
            'to_treasury' => $toTreasuryDisplay ?? $account->name,
            'amount' => (float) $ledgerTransaction->amount,
            'currency' => $account->currency ?? 'EGP',
            'reason' => $reason,
            'flight_booking_id' => $flightBookingId,
            'agent_name' => $agentDisplayName,
            'balance_before' => null,
            'balance_after' => $account->balance,
            'reference_number' => 'TRX-'.strtoupper(uniqid()),
            'ledger_transaction_id' => $ledgerTransaction->id,
        ]);
    }

    /**
     * مرآة حركة نقد صادر (الجنيه غادر حساباً نقدياً؛ leg في القيد هو from_account_id).
     */
    public static function mirrorFlightOutboundFromCash(
        Transaction $ledgerTransaction,
        int $flightBookingId,
        string $reason,
        string $agentDisplayName,
        ?string $fromTreasuryDisplay = null,
    ): TreasuryTransaction {
        $cashAccountId = (int) ($ledgerTransaction->from_account_id ?? 0);
        if ($cashAccountId <= 0) {
            throw new \InvalidArgumentException('القيد المحاسبي لا يحدد حساباً نقدياً مصدراً (from_account_id).');
        }

        $account = Account::query()->findOrFail($cashAccountId);

        return TreasuryTransaction::create([
            'transaction_type' => 'debit',
            'account_id' => $account->id,
            'from_treasury' => $fromTreasuryDisplay ?? $account->name,
            'to_treasury' => null,
            'amount' => (float) $ledgerTransaction->amount,
            'currency' => $account->currency ?? 'EGP',
            'reason' => $reason,
            'flight_booking_id' => $flightBookingId,
            'agent_name' => $agentDisplayName,
            'balance_before' => null,
            'balance_after' => $account->balance,
            'reference_number' => 'TRX-'.strtoupper(uniqid()),
            'ledger_transaction_id' => $ledgerTransaction->id,
        ]);
    }
}
