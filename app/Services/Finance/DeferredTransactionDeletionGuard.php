<?php

namespace App\Services\Finance;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shared guard for deferred-payment transaction deletion.
 *
 * Business rule: a deferred transaction (Fawry / Online / Wallet) may NOT
 * be deleted if the customer has any debt payment recorded AFTER the
 * transaction's creation date. The guard throws a single
 * RuntimeException with the canonical Arabic message so every office
 * transaction module enforces the exact same business rule.
 *
 * Design constraints (production-safety):
 *  - NO schema changes (no new columns / tables / flags).
 *  - NO mutation of any data — the guard is read-only.
 *  - Reused across Fawry, Online, and Wallet via the same service.
 *  - Detection is purely on existing data:
 *      (a) per-transaction paid-amount column vs. original settlement
 *          computed from the original journal entries; AND/OR
 *      (b) any later debit on the customer account not from the
 *          operation's own original posting.
 */
class DeferredTransactionDeletionGuard
{
    /**
     * Canonical error message used across all modules.
     *
     * Wording is intentionally neutral: the production app does not
     * yet expose a per-leg "reverse entry" endpoint + Vue button, so
     * the message must NOT promise a workflow that does not exist.
     * Operators are directed to the system admin who can run the
     * canonical reverse-pipeline from the console or a follow-up
     * service (see follow-up tickets).
     */
    public const ERROR_MESSAGE = 'لا يمكن حذف العملية بعد تسجيل سداد لاحق على حساب العميل. يرجى التواصل مع مسؤول النظام لإجراء الإلغاء المحاسبي.';

    /**
     * Tolerance (EGP) when comparing paid amounts — same value used by
     * the existing Fawry / Online / Wallet reversal pipelines.
     */
    private const TOLERANCE = 0.005;

    /**
     * Throw if a later debt payment was recorded after the operation
     * was created.
     *
     * @param  DateTimeInterface  $transactionCreatedAt  When the original operation was created.
     * @param  float|null  $currentPaidAmount  Per-transaction paid amount (e.g. fawry_transactions.amount). null if not tracked.
     * @param  float  $originalPaidAtCreation  Sum of credits on the settlement account from the original posting (mirror entries excluded).
     * @param  int|null  $customerAccountId  Customer's GL account id (null for walk-in / no Customer record).
     * @param  string  $relatedType  FQCN of the related model (e.g. FawryTransaction::class).
     * @param  int  $relatedId  Primary key of the related model.
     *
     * @throws RuntimeException with self::ERROR_MESSAGE
     */
    public function ensureNoLaterPayment(
        DateTimeInterface $transactionCreatedAt,
        ?float $currentPaidAmount,
        float $originalPaidAtCreation,
        ?int $customerAccountId,
        string $relatedType,
        int $relatedId,
    ): void {
        // Skip every check if the operation has NO related transactions
        // in the GL — it is an orphan row (e.g. test fixtures, legacy
        // imports) and there is no audit trail to compare against.
        // The existing reversal pipeline handles these rows safely.
        if (! $this->hasOriginalPosting($relatedType, $relatedId)) {
            return;
        }

        // Check 1: per-transaction paid amount column increased after
        // creation. This is the canonical signal for walk-in customers
        // whose FIFO pay-debt flow updates $amount on the row.
        if ($currentPaidAmount !== null
            && ($currentPaidAmount - $originalPaidAtCreation) > self::TOLERANCE
        ) {
            throw new RuntimeException(self::ERROR_MESSAGE);
        }

        // Check 2: any later debit on the customer account not from this
        // operation's own original posting (pay-debt / refund / etc.).
        if ($customerAccountId !== null
            && $this->customerAccountHasLaterDebit(
                $customerAccountId,
                $transactionCreatedAt,
                $relatedType,
                $relatedId,
            )
        ) {
            throw new RuntimeException(self::ERROR_MESSAGE);
        }
    }

    /**
     * True if any transaction row in the journal is linked to the
     * operation via (related_type, related_id). Orphan rows (no
     * related transactions) are skipped by the guard.
     */
    private function hasOriginalPosting(string $relatedType, int $relatedId): bool
    {
        return DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->exists();
    }

    /**
     * Sum the original settlement (credits on the settlement account)
     * from the operation's original posting. Mirror entries stamped
     * with "عكس:" / "عكس " are excluded so a previous reversal
     * (e.g. from updateTransaction) does not poison the comparison.
     */
    public function computeOriginalSettlement(
        string $relatedType,
        int $relatedId,
        int $settlementAccountId,
    ): float {
        return (float) DB::table('account_entries as ae')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where('t.related_type', $relatedType)
            ->where('t.related_id', $relatedId)
            ->where('ae.account_id', $settlementAccountId)
            ->where('ae.credit', '>', 0)
            ->whereRaw('(ae.notes IS NULL OR (ae.notes NOT LIKE ? AND ae.notes NOT LIKE ?))', [
                'عكس:%',
                'عكس %',
            ])
            ->sum('ae.credit');
    }

    /**
     * Return true if any debit was posted on the customer account AFTER
     * the operation's creation date AND against this operation's debt
     * specifically. This catches pay-debt flows, manual settlement
     * entries, refunds-replayed-as-debits, etc.
     *
     * IMPORTANT exclusions (production-safety):
     *
     *  (a) Reverse / mirror entries (notes LIKE 'عكس%') are EXCLUDED.
     *      These are bookkeeping rows produced by the update / cancel
     *      reverse-pipeline and are not customer payments. Without this
     *      exclusion, any update to a different operation for the same
     *      customer would spuriously block the cancellation of every
     *      later transaction for that customer (the inverses show up as
     *      "later debits" and trip the guard).
     *
     *  (b) Settlements whose parent transaction belongs to ANOTHER
     *      operation (different related_id) are EXCLUDED. Their job is
     *      to settle the other operation's debt; they have no relevance
     *      to this one and would otherwise leak across customers with
     *      many transactions. This is the second half of the same
     *      cross-operation interference that the older guard suffered
     *      from — both halves are required.
     *
     * See tests/scripts/fawry_module_DEEP_E2E for the regression
     * scenario captured on 2026-07-29.
     */
    private function customerAccountHasLaterDebit(
        int $customerAccountId,
        DateTimeInterface $createdAt,
        string $relatedType,
        int $relatedId,
    ): bool {
        $originalTxIds = DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->pluck('id');

        // No original transactions → nothing to exclude; treat as "no
        // later payment" to avoid blocking deletion of orphan rows.
        if ($originalTxIds->isEmpty()) {
            return false;
        }

        return DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('ae.account_id', $customerAccountId)
            ->where('ae.debit', '>', 0)
            ->where('ae.created_at', '>', $createdAt)
            ->whereNotIn('ae.transaction_id', $originalTxIds)
            // (a) Exclude bookkeeping reverse / mirror entries
            ->where(function ($q) {
                $q->whereNull('ae.notes')
                    ->orWhere(function ($inner) {
                        $inner->where('ae.notes', 'not like', 'عكس%')
                            ->where('ae.notes', 'not like', 'عكس %')
                            ->where('ae.notes', 'not like', 'عكس:%');
                    });
            })
            // (b) Only COUNT entries whose parent transaction is a real
            // payment against THIS operation:
            //    - Walk-in / FIFO pay-debt rows (parent.related_id IS NULL),
            //      which represent aggregate debt payments on the customer
            //      account and are always a "later payment" against the
            //      operation's debt.
            //    - Settlements whose (related_type, related_id) exactly
            //      matches THIS operation. These entries are already in
            //      $originalTxIds so they get double-excluded by the
            //      whereNotIn clause — the guard therefore never fires on
            //      them, but including the clause documents intent.
            // Entries belonging to OTHER operations (parent.related_id
            // points elsewhere) are EXCLUDED — those are NOT payments
            // against this operation and would otherwise leak across
            // customers with multiple transactions (regression captured
            // on 2026-07-29 when an update to a prior tx blocked the
            // cancellation of a later, otherwise-clean tx for the same
            // customer).
            ->where(function ($q) use ($relatedType, $relatedId) {
                $q->whereNull('t.related_id')
                    ->orWhere(function ($inner) use ($relatedType, $relatedId) {
                        $inner->where('t.related_type', $relatedType)
                            ->where('t.related_id', $relatedId);
                    });
            })
            ->exists();
    }
}
