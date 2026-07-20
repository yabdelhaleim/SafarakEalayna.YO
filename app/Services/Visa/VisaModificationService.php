<?php

namespace App\Services\Visa;

use App\Enums\TransactionModule;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;

/**
 * Visa modification service — extracted 2026-07-20.
 *
 * The "modify a confirmed visa booking" path differs from the plain
 * `update()` in VisaBookingService in two ways:
 *
 *   1) it must be ADDITIVE (only ever append inverse account entries on the
 *      same transaction_id, never mutate the original); and
 *
 *   2) it must record a "modification history" of the price changes so
 *      reporting endpoints can audit who changed what, when.
 *
 * Mirror of `HajjUmraBookingService::repostExpenseTransaction()` /
 * `repostIncomeTransaction()` — same pattern, same invariants.
 */
class VisaModificationService
{
    public function __construct(protected TransactionService $transactions) {}

    /**
     * Repost a booking's expense transaction with a new purchase_price.
     * Returns the NEW transaction (original is preserved, inverse entries
     * are appended to it).
     */
    public function repostExpense(VisaBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        if ((float) $transaction->amount === $newAmount) {
            return $transaction;
        }

        $this->transactions->reverseTransaction($transaction);

        $expenseAccountId = (int) $booking->account_id;
        if ($supplierId = $booking->visaDetail?->visa_agent_id) {
            $agent = VisaAgent::find($supplierId);
            if ($agent?->account_id) {
                $expenseAccountId = (int) $agent->account_id;
            }
        }

        return $this->transactions->recordExpense([
            'amount' => $newAmount,
            'from_account_id' => $expenseAccountId,
            'module' => TransactionModule::Visa->value,
            'related_type' => VisaBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by ?? 1,
        ]);
    }

    /**
     * Repost a booking's income transaction with a new (selling+service_fee)
     * amount.  Returns the NEW transaction.
     */
    public function repostIncome(VisaBooking $booking, Transaction $transaction, float $newAmount, int $customerAccountId): Transaction
    {
        if ((float) $transaction->amount === $newAmount) {
            return $transaction;
        }

        $this->transactions->reverseTransaction($transaction);

        return $this->transactions->recordIncome([
            'amount' => $newAmount,
            'to_account_id' => $customerAccountId,
            'module' => TransactionModule::Visa->value,
            'related_type' => VisaBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by ?? 1,
        ]);
    }

    /**
     * Return the modification history for a booking: every "عكس: " prefixed
     * transaction tied to the booking, in chronological order.  Each entry
     * corresponds to a price repost (one inverse txn + one fresh txn).
     *
     * @return array<int, array{
     *     date: string,
     *     type: 'reversal'|'repost',
     *     amount: float,
     *     account_name: ?string,
     *     notes: string,
     * }>
     */
    public function history(VisaBooking $booking): array
    {
        $booking->loadMissing([
            'incomeTransaction.entries.account',
            'expenseTransaction.entries.account',
        ]);

        $entries = collect();

        foreach (['incomeTransaction', 'expenseTransaction'] as $rel) {
            $tx = $booking->{$rel};
            if (! $tx) {
                continue;
            }
            foreach ($tx->entries()->orderBy('id')->get() as $entry) {
                $entries->push([
                    'date' => $entry->created_at?->toIso8601String(),
                    'type' => $entry->notes && str_starts_with($entry->notes, 'عكس:')
                        ? 'reversal'
                        : 'repost',
                    'amount' => (float) ($entry->debit ?: $entry->credit),
                    'account_name' => $entry->account?->name,
                    'notes' => (string) $entry->notes,
                ]);
            }
        }

        return $entries
            ->sortBy(fn ($e) => $e['date'] ?? '')
            ->values()
            ->all();
    }
}
