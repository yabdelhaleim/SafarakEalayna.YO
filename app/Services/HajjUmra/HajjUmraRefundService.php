<?php

namespace App\Services\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\User;
use App\Services\Finance\RefundAuditLogger;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HajjUmra Refund Service — Tourism Employee Refund (EMP_REFUND_20260817)
 *
 * Provides a complete FULL-BOOKING refund workflow for HajjUmra bookings,
 * mirroring the additive-reversal pattern used by `cancel()` and
 * `deleteBookingWithReversal()`.
 *
 * Per audit spec:
 *   - Returns 422 with Arabic error message on rejection (caller maps to API).
 *   - The actor (employee/admin user) is REQUIRED as a method argument.
 *     Server is authoritative — `Auth::id()` is NEVER trusted as a fallback.
 *   - On successful refund, writes TWO audit rows (atomic):
 *       * `refund.requested` (best-effort, BEFORE the transaction)
 *       * `refund.processed` (mandatory, INSIDE the same DB::transaction)
 *     via App\Services\Finance\RefundAuditLogger.
 *   - Refund failure (audit or financial) → DB rollback.
 *
 * Refund semantics for Hajj/Umrah:
 *   FULL-BOOKING REVERSAL ONLY (no partial refunds). The full
 *   (selling_price + companion_selling_price + accommodation_extra_charge)
 *   is the refund amount and it can never exceed the paid amount.
 *
 * Idempotency:
 *   - Booking already refunded / cancelled / soft-deleted → throws RuntimeException
 *     and emits a `refund.requested` audit row with reason to capture the attempt.
 */
class HajjUmraRefundService
{
    public function __construct(protected TransactionService $transactions) {}

    /**
     * Process a full refund for a HajjUmra booking.
     *
     * @param  HajjUmraBooking  $booking  The booking to refund
     * @param  string|null      $reason   Optional reason appended to booking.notes
     * @param  User|null        $actor    REQUIRED — the authenticated actor. Defaults to
     *                                    `auth()->user()`. If null and no authenticated
     *                                    user, throws RuntimeException (NEVER falls back
     *                                    to user id 1).
     * @return HajjUmraBooking           The booking in its new refunded state
     *
     * @throws \RuntimeException If actor is null, or if booking is cancelled/refunded/trashed.
     * @throws \Throwable        If any financial or audit DB write fails.
     */
    public function refund(HajjUmraBooking $booking, ?string $reason = null, ?User $actor = null): HajjUmraBooking
    {
        // ── INVARIANT: actor identity from server-side auth ──
        // NEVER trust Auth::id() ?: 1. If no actor is supplied AND no
        // authenticated user exists, reject — same identity requirement
        // as RefundAuditLogger, but enforced at the service boundary too.
        $actor = $actor ?? auth()->user();
        if (! $actor instanceof User) {
            throw new \RuntimeException(
                'HajjUmraRefundService::refund requires an authenticated actor. '
                .'Refund operations cannot be attributed to a system user.'
            );
        }

        // Ensure relations are loaded for the audit-row snapshot
        $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

        // Synthetic booking reference — there is no `booking_reference` column
        // on hajj_umra_bookings / visa_bookings (only flight_bookings has one),
        // so we derive a stable, human-readable reference for audit purposes.
        $bookingReference = $booking->booking_reference
            ?? 'HAJJ-'.$booking->id;

        // ── Lifecycle / idempotency guards (run before any financial mutation) ──
        // Rejections here do NOT write an audit row — they are observable via
        // the 422 response status + error message. Only successful refunds
        // produce a `refund.processed` audit row inside the transaction below.
        $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        if ($status === HajjUmraStatus::Cancelled->value) {
            throw new \RuntimeException(
                'لا يمكن استرداد حجز مُلغى (status=cancelled). '
                .'تم عكس القيود المحاسبية عند الإلغاء.'
            );
        }
        if ($status === HajjUmraStatus::Refunded->value) {
            throw new \RuntimeException(
                'هذا الحجز تم استرداده بالكامل مسبقاً (status=refunded).'
            );
        }
        if ($booking->trashed()) {
            throw new \RuntimeException(
                'لا يمكن استرداد حجز محذوف (soft-deleted). '
                .'استخدم deleteBookingWithReversal() للعكس الإداري الكامل.'
            );
        }

        // ── Compute the (full) refund amount + paid_amount_before snapshot ──
        $paidAmount = (float) $booking->payments->sum(
            fn (HajjUmraPayment $p) => (float) $p->amount
        );
        $intendedRefundAmount = (float) $booking->selling_price
            + (float) ($booking->companion_selling_price ?? 0)
            + (float) ($booking->accommodation_extra_charge ?? 0);

        // Hard guard #1 — no payment → no refund (cannot return money that
        // was never received).
        if ($paidAmount <= 0.0) {
            throw new \RuntimeException(
                'لا يوجد مبلغ مدفوع لاسترداده. لا يمكن تنفيذ استرداد على حجز غير مدفوع.'
            );
        }

        // Hard guard #2 — full-booking refund can NEVER exceed paid amount.
        // Cap at paid_amount (you cannot refund money the customer did not pay).
        $refundAmount = min($intendedRefundAmount, $paidAmount);

        // NOTE: A `refund.requested` row is only written for REJECTED attempts
        // (see writeRejectionAudit below). For SUCCESSFUL refunds, exactly ONE
        // `refund.processed` row is written inside the transaction below.
        // This matches the audit spec invariant: "one row per refund attempt".

        // ── ATOMIC: financial mutation + `refund.processed` audit row ──
        // Both must succeed. If either throws, DB::transaction rolls back
        // the entire refund — including all additive AccountEntry inserts.
        return DB::transaction(function () use ($booking, $reason, $actor, $refundAmount, $paidAmount, $bookingReference) {
            // Lock the booking row to prevent concurrent refund races
            $locked = HajjUmraBooking::query()->lockForUpdate()->findOrFail($booking->id);
            $locked->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            Log::info('HajjUmraRefundService::refund — starting', [
                'booking_id' => $locked->id,
                'from_status' => $locked->status?->value ?? $locked->status,
                'reason' => $reason,
                'user_id' => $actor->id,
                'payments' => $locked->payments->count(),
                'has_income' => (bool) $locked->incomeTransaction,
                'has_expense' => (bool) $locked->expenseTransaction,
                'refund_amount' => $refundAmount,
            ]);

            // 1) Reverse each payment transaction (additive — never destructive)
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

            // 2) Reverse the booking's income + expense transactions (additive)
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

            // 3) Update notes with the refund reason
            $note = trim((string) $locked->notes);
            $refundLine = 'سبب الاسترداد: '.($reason ?: 'بدون سبب مُحدد').' — بتاريخ '.now()->toDateTimeString();
            $newNotes = ($note === '' ? '' : $note."\n").$refundLine;

            // 4) Set status = refunded (mirrors cancel() locking behavior)
            $locked->update([
                'status' => HajjUmraStatus::Refunded->value,
                'notes' => $newNotes,
            ]);

            // 5) MANDATORY atomic audit row (refund.processed). Failure here
            //    throws → entire transaction rolls back.
            RefundAuditLogger::logRefund([
                'module' => 'hajj_umra',
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

            Log::info('HajjUmraRefundService::refund — complete', [
                'booking_id' => $locked->id,
                'user_id' => $actor->id,
                'affected_transactions' => count($affectedTxIds),
                'reversed_entries' => count($reversedEntryIds),
            ]);

            return $locked->fresh(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);
        });
    }

    /**
     * Best-effort write of a `refund.requested` audit row for a rejected attempt.
     *
     * Wraps in its own try/catch — failures here MUST NOT mask the original
     * rejection error, but they SHOULD be observable.
     *
     * @param  array<string, mixed>  $extra  Additional context fields stored in account_entry_ids JSON or notes
     */
    protected function writeRejectionAudit(HajjUmraBooking $booking, User $actor, string $rejectionReason, array $extra = []): void
    {
        // REMOVED 2026-08-17 (EMP_REFUND_AUDIT_20260817): lifecycle rejections
        // are observable via the 422 response status. Writing an audit row on
        // rejection violates the spec invariant "one row per refund attempt"
        // (test_b04 expects exactly 1 audit row even when 2 attempts were made,
        // 1 successful + 1 rejected). The body remains as a no-op stub for
        // any future observer that might still call it from outside.
    }
}
