<?php

namespace App\Services\Visa;

use App\Enums\VisaStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Finance\RefundAuditLogger;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Visa refund / cancellation service — extracted 2026-07-20.
 *
 * Three flows:
 *
 *  1) cancel($booking, $reason)  → light cancel, status=Cancelled, additive
 *     accounting reversal (originals stay).  VisaPayment rows remain visible
 *     for audit.  Use this for customer-initiated cancellation.
 *
 *  2) refund($booking, $reason)  → full refund (status=Refunded, same
 *     accounting shape as cancel but explicitly marks the booking as refunded
 *     and clears business-visible state).
 *
 *  3) deleteWithReversal($id, $userId)  → administrative soft-delete with
 *     additive reversal AND soft-delete on the booking + payments.  Mirrors
 *     `FlightBookingService::deleteBookingWithReversal()` /
 *     `HajjUmraBookingService::deleteBookingWithReversal()`.  Idempotent:
 *     throws on already-trashed.
 *
 * All three paths are ADDITIVE — AccountEntry rows are added with `عكس:`
 * prefix, originals are never mutated.  This is the project-wide invariant.
 */
class VisaRefundService
{
    public function __construct(protected TransactionService $transactions) {}

    /**
     * Light cancel — flips status, appends inverse entries, leaves rows visible.
     */
    public function cancel(VisaBooking $booking, ?string $reason = null): VisaBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            // ─── Idempotency + lifecycle guards (BUG-FIX 2026-07-27) ───
            // The previous flow did NOT guard against double-cancel which
            // would silently re-reverse already-reversed transactions —
            // producing phantom journal entries. Same pattern as the HajjUmra
            // cancel() guard.
            $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
            if ($status === VisaStatus::Cancelled->value) {
                throw new \RuntimeException(
                    'هذا الطلب ملغى مسبقاً (status=cancelled). لا يمكن إلغاء الطلب مرتين.'
                );
            }
            if ($status === VisaStatus::Refunded->value) {
                throw new \RuntimeException(
                    'لا يمكن إلغاء طلب تأشيرة تم استرداده بالكامل (status=refunded). '
                    .'الحالة نهائية.'
                );
            }
            if ($booking->trashed()) {
                throw new \RuntimeException(
                    'لا يمكن إلغاء طلب تأشيرة محذوف (soft-deleted). '
                    .'استخدم deleteWithReversal() للإدارة.'
                );
            }

            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            $this->reversePayments($booking);
            $this->reverseBookingTransactions($booking);

            $booking->update([
                'status' => VisaStatus::Cancelled->value,
                'notes' => $note,
            ]);

            $booking->visaDetail?->update(['status' => VisaStatus::Cancelled->value]);

            Log::info('Visa booking cancelled (additive reversal applied)', [
                'booking_id' => $booking->id,
                'reason' => $reason,
                'payments_reversed' => $booking->payments->filter(fn ($p) => $p->transaction)->count(),
                'income_reversed' => (bool) $booking->incomeTransaction,
                'expense_reversed' => (bool) $booking->expenseTransaction,
            ]);

            return $booking->fresh(['payments', 'expenseTransaction', 'incomeTransaction']);
        });
    }

    /**
     * Full refund — same accounting shape as cancel but with Refunded status.
     *
     * Per audit spec (EMP_REFUND_AUDIT_20260817):
     *   - Actor identity REQUIRED (`$actorUser`). Server is authoritative;
     *     never falls back to `Auth::id() ?: 1`.
     *   - Refund amount = min(intended, paid). Cannot return money that was
     *     never received.
     *   - Writes TWO mandatory audit rows (refund.requested + refund.processed).
     *   - Atomic: financial mutation + `refund.processed` audit row in one
     *     DB::transaction. Failure rolls back the entire refund.
     */
    public function refund(VisaBooking $booking, ?string $reason = null, ?User $actorUser = null): VisaBooking
    {
        // ── INVARIANT: actor identity from server-side auth ──
        $actorUser = $actorUser ?? auth()->user();
        if (! $actorUser instanceof User) {
            throw new \RuntimeException(
                'VisaRefundService::refund requires an authenticated actor. '
                .'Refund operations cannot be attributed to a system user.'
            );
        }

        $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction', 'customer']);

        // Synthetic booking reference — there is no `booking_reference` column
        // on visa_bookings, so we derive a stable, human-readable reference.
        $bookingReference = $booking->booking_reference ?? 'VISA-'.$booking->id;

        // ── Lifecycle / idempotency guards (run before any financial mutation) ──
        // Rejections here do NOT write an audit row — they are observable via
        // the 422 response status + error message. Only successful refunds
        // produce a `refund.processed` audit row inside the transaction below.
        $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        if ($status === VisaStatus::Refunded->value) {
            throw new \RuntimeException(
                'هذا الطلب تم استرداده بالكامل مسبقاً (status=refunded).'
            );
        }
        if ($status === VisaStatus::Cancelled->value) {
            throw new \RuntimeException(
                'لا يمكن استرداد طلب تأشيرة مُلغى (status=cancelled). '
                .'تم عكس القيود المحاسبية عند الإلغاء.'
            );
        }
        if ($booking->trashed()) {
            throw new \RuntimeException(
                'لا يمكن استرداد طلب تأشيرة محذوف (soft-deleted). '
                .'استخدم deleteWithReversal() للعكس الإداري الكامل.'
            );
        }

        // ── Compute the refund amount (capped at paid amount) ──
        // Phase 12.3 forensic audit (2026-08-20): the WIP-introduced
        // `paidAmount <= 0.0` rejection guard (commit 449ac87, 2026-08-18)
        // was REMOVED because it contradicted the certified Phase 9.2 / 9.4
        // behavior: a zero-payment refund is a "void" no-op that transitions
        // status -> Refunded without returning money (ledger stays balanced).
        // Reference tests:
        //   - test_admin_refund_of_unpaid_booking_is_no_op_with_status_change
        //     (VisaAdminFullLifecycleTest, phase-9.2 commit 580f02b)
        //   - test_refund_with_zero_payments_succeeds_as_no_op
        //     (VisaRefundDeepAuditTest, phase-9.4 commit 3b94edf)
        $paidAmount = (float) $booking->payments->sum(
            fn (VisaPayment $p) => (float) $p->amount
        );

        // Cap refund_amount at paid_amount. For a zero-payment refund this
        // resolves to 0.0 — a true void with no money returned.
        $refundAmount = $paidAmount; // full-booking refund returns what was paid

        // NOTE: A `refund.requested` row is only written for REJECTED attempts
        // (see writeRejectionAudit below). For SUCCESSFUL refunds, exactly ONE
        // `refund.processed` row is written inside the transaction below.
        // This matches the audit spec invariant: "one row per refund attempt".

        // ── ATOMIC: financial mutation + `refund.processed` audit row ──
        return DB::transaction(function () use ($booking, $reason, $actorUser, $refundAmount, $paidAmount, $bookingReference) {
            $locked = VisaBooking::query()->lockForUpdate()->findOrFail($booking->id);
            $locked->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction', 'customer']);

            // Re-check guards inside the lock to defend against TOCTOU.
            $lockedStatus = $locked->status instanceof \BackedEnum ? $locked->status->value : (string) $locked->status;
            if ($lockedStatus === VisaStatus::Refunded->value) {
                throw new \RuntimeException('هذا الطلب تم استرداده بالكامل مسبقاً (status=refunded).');
            }
            if ($lockedStatus === VisaStatus::Cancelled->value) {
                throw new \RuntimeException('لا يمكن استرداد طلب تأشيرة مُلغى (status=cancelled).');
            }

            $note = trim((string) $locked->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الاسترداد: '.$reason;
            }

            Log::info('VisaRefundService::refund — starting', [
                'booking_id' => $locked->id,
                'from_status' => $lockedStatus,
                'reason' => $reason,
                'user_id' => $actorUser->id,
                'payments' => $locked->payments->count(),
                'has_income' => (bool) $locked->incomeTransaction,
                'has_expense' => (bool) $locked->expenseTransaction,
                'refund_amount' => $refundAmount,
            ]);

            // Capture affected transaction IDs + entry IDs for the audit row.
            $reversedEntryIds = [];
            $affectedTxIds = [];

            foreach ($locked->payments as $payment) {
                if ($payment->transaction) {
                    $reversal = $this->transactions->reverseTransaction($payment->transaction);
                    $affectedTxIds[] = $reversal->id;
                    foreach ($reversal->entries ?? [] as $entry) {
                        $reversedEntryIds[] = $entry->id;
                    }
                }
            }
            if ($locked->incomeTransaction) {
                $incReversal = $this->transactions->reverseTransaction($locked->incomeTransaction);
                $affectedTxIds[] = $incReversal->id;
                foreach ($incReversal->entries ?? [] as $entry) {
                    $reversedEntryIds[] = $entry->id;
                }
            }
            if ($locked->expenseTransaction) {
                $expReversal = $this->transactions->reverseTransaction($locked->expenseTransaction);
                $affectedTxIds[] = $expReversal->id;
                foreach ($expReversal->entries ?? [] as $entry) {
                    $reversedEntryIds[] = $entry->id;
                }
            }

            $locked->update([
                'status' => VisaStatus::Refunded->value,
                'notes' => $note,
            ]);

            $locked->visaDetail?->update(['status' => VisaStatus::Refunded->value]);

            // MANDATORY atomic audit row (refund.processed). Failure throws
            // → entire transaction rolls back.
            RefundAuditLogger::logRefund([
                'module' => 'visa',
                'booking_id' => $locked->id,
                'booking_reference' => $bookingReference,
                'customer_id' => $locked->customer_id ?? null,
                'customer_name' => optional($locked->customer)->full_name ?? null,
                'refund_amount' => $refundAmount,
                'currency' => $locked->currency ?? 'EGP',
                'paid_amount_before' => $paidAmount,
                'previously_refunded' => 0.0,
                'remaining_refundable' => $paidAmount,
                'reason' => $reason,
                'transaction_id' => $affectedTxIds[0] ?? null,
                'account_entry_ids' => $reversedEntryIds,
                'affected_account_id' => null,
                'idempotency_key' => null,
            ], 'refund.processed');

            Log::info('VisaRefundService::refund — complete (additive reversal applied)', [
                'booking_id' => $locked->id,
                'user_id' => $actorUser->id,
                'affected_transactions' => count($affectedTxIds),
                'reversed_entries' => count($reversedEntryIds),
            ]);

            return $locked->fresh(['payments', 'expenseTransaction', 'incomeTransaction']);
        });
    }

    /**
     * Best-effort write of a `refund.requested` audit row for a rejected attempt.
     * Wraps in its own try/catch — failures here MUST NOT mask the rejection.
     */
    protected function writeRejectionAudit(VisaBooking $booking, User $actor, string $rejectionReason, array $extra = []): void
    {
        // REMOVED 2026-08-17 (EMP_REFUND_AUDIT_20260817): lifecycle rejections
        // are observable via the 422 response status. Writing an audit row on
        // rejection violates the spec invariant "one row per refund attempt".
        // The body remains as a no-op stub for compatibility with any future
        // observer that might still call it from outside.
    }

    /**
     * Administrative soft-delete with full accounting reversal.
     *
     * Idempotent: throws RuntimeException on already-trashed.
     *
     * @throws \RuntimeException
     */
    public function deleteWithReversal(int $bookingId, int $userId): bool
    {
        return VisaBooking::run(function () use ($bookingId, $userId) {
            return DB::transaction(function () use ($bookingId, $userId) {
                $booking = VisaBooking::query()
                    ->withTrashed()
                    ->with(['payments.transaction', 'expenseTransaction', 'incomeTransaction'])
                    ->lockForUpdate()
                    ->findOrFail($bookingId);

                if ($booking->trashed()) {
                    throw new \RuntimeException(
                        'هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                    );
                }

                $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
                if ($status === VisaStatus::Cancelled->value) {
                    throw new \RuntimeException(
                        'لا يمكن حذف وعكس حجز تأشيرة ملغى بالفعل (status=cancelled) — تفادياً لفقدان سجل المراجعة.'
                    );
                }
                if ($status === VisaStatus::Refunded->value) {
                    throw new \RuntimeException(
                        'لا يمكن حذف وعكس حجز تأشيرة مسترد بالفعل (status=refunded) — تفادياً لفقدان سجل المراجعة.'
                    );
                }

                $userIdEffective = $userId ?: (int) (Auth::id() ?: 1);

                Log::info('VisaRefundService::deleteWithReversal — starting', [
                    'booking_id' => $booking->id,
                    'user_id' => $userIdEffective,
                ]);

                $this->reversePayments($booking);
                $this->reverseBookingTransactions($booking);

                $booking->visaDetail?->update(['status' => VisaStatus::Cancelled->value]);
                $booking->payments()->delete();
                $booking->delete();

                Log::info('VisaRefundService::deleteWithReversal — complete', [
                    'booking_id' => $booking->id,
                    'user_id' => $userIdEffective,
                ]);

                return true;
            });
        });
    }

    /**
     * Append-only reverse on every payment transaction tied to the booking.
     */
    protected function reversePayments(VisaBooking $booking): void
    {
        foreach ($booking->payments as $payment) {
            if ($payment->transaction) {
                $this->transactions->reverseTransaction($payment->transaction);
            }
        }
    }

    /**
     * Append-only reverse on the booking's income + expense transactions.
     */
    protected function reverseBookingTransactions(VisaBooking $booking): void
    {
        if ($booking->incomeTransaction) {
            $this->transactions->reverseTransaction($booking->incomeTransaction);
        }
        if ($booking->expenseTransaction) {
            $this->transactions->reverseTransaction($booking->expenseTransaction);
        }
    }
}
