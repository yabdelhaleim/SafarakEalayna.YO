<?php

namespace App\Services\Visa;

use App\Enums\TransactionModule;
use App\Enums\VisaStatus;
use App\Models\Transaction;
use App\Models\VisaBooking;
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
     */
    public function refund(VisaBooking $booking, ?string $reason = null): VisaBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الاسترداد: '.$reason;
            }

            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            $this->reversePayments($booking);
            $this->reverseBookingTransactions($booking);

            $booking->update([
                'status' => VisaStatus::Refunded->value,
                'notes' => $note,
            ]);

            $booking->visaDetail?->update(['status' => VisaStatus::Refunded->value]);

            Log::info('Visa booking refunded (additive reversal applied)', [
                'booking_id' => $booking->id,
                'reason' => $reason,
            ]);

            return $booking->fresh(['payments', 'expenseTransaction', 'incomeTransaction']);
        });
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
