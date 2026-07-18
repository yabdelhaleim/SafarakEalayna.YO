<?php

namespace App\Services\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\HajjUmraBooking;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HajjUmra Refund Service — FIX (GAP #HJ-3, fixed 2026-07-16)
 *
 * Provides a complete refund workflow for HajjUmra bookings, mirroring the
 * additive-reversal pattern used by `cancel()` and `deleteBookingWithReversal()`.
 *
 * What it does:
 *   1. Reverse every payment transaction (additive — never destructive)
 *   2. Reverse the booking's income transaction (clears AR)
 *   3. Reverse the booking's expense transaction (clears AP)
 *   4. Set booking.status = 'refunded'
 *   5. Append the refund reason to booking.notes
 *   6. Log the action with user_id and timestamps
 *
 * Idempotency: throws RuntimeException if the booking is already 'refunded'.
 * Does NOT touch the original transactions (only adds inverse account_entries).
 *
 * This service does NOT soft-delete the booking — the booking row remains
 * visible for audit/historical purposes. If you want a full administrative
 * delete with reversal, use HajjUmraBookingService::deleteBookingWithReversal().
 */
class HajjUmraRefundService
{
    public function __construct(protected TransactionService $transactions) {}

    /**
     * Process a full refund for a HajjUmra booking.
     *
     * @param  HajjUmraBooking  $booking  The booking to refund (must be loaded with payments + transactions)
     * @param  string|null      $reason   Optional reason appended to booking.notes
     * @param  int|null         $userId   Acting user (defaults to auth()->id())
     * @return HajjUmraBooking           The booking in its new refunded state
     *
     * @throws \RuntimeException if the booking is already refunded
     */
    public function refund(HajjUmraBooking $booking, ?string $reason = null, ?int $userId = null): HajjUmraBooking
    {
        $userId = $userId ?: (int) (Auth::id() ?: 1);

        return DB::transaction(function () use ($booking, $reason, $userId) {
            // Load with all the relations we need
            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            // ── 1) Idempotency guard ──
            $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
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

            Log::info('HajjUmraRefundService::refund — starting', [
                'booking_id'   => $booking->id,
                'from_status'  => $status,
                'reason'       => $reason,
                'user_id'      => $userId,
                'payments'     => $booking->payments->count(),
                'has_income'   => (bool) $booking->incomeTransaction,
                'has_expense'  => (bool) $booking->expenseTransaction,
            ]);

            // ── 2) Reverse each payment transaction (additive — never destructive) ──
            foreach ($booking->payments as $payment) {
                if ($payment->transaction) {
                    $this->transactions->reverseTransaction($payment->transaction);
                }
            }

            // ── 3) Reverse the booking's income + expense transactions (additive) ──
            if ($booking->incomeTransaction) {
                $this->transactions->reverseTransaction($booking->incomeTransaction);
            }
            if ($booking->expenseTransaction) {
                $this->transactions->reverseTransaction($booking->expenseTransaction);
            }

            // ── 4) Update notes with the refund reason ──
            $note = trim((string) $booking->notes);
            $refundLine = 'سبب الاسترداد: ' . ($reason ?: 'بدون سبب مُحدد') . ' — بتاريخ ' . now()->toDateTimeString();
            $newNotes = ($note === '' ? '' : $note . "\n") . $refundLine;

            // ── 5) Set status = refunded (use the model's guarded write path) ──
            //    HajjUmraBooking's booted() saving observer checks `profit` mutability
            //    only — status is freely mutable, so this direct update is safe.
            $booking->update([
                'status' => HajjUmraStatus::Refunded->value,
                'notes'  => $newNotes,
                // Keep the *_transaction_id pointers — the original transactions
                // are still in the DB; reverseTransaction() added inverse entries
                // on the SAME transaction_id, so the FK references stay valid.
            ]);

            Log::info('HajjUmraRefundService::refund — complete', [
                'booking_id' => $booking->id,
                'user_id'    => $userId,
            ]);

            return $booking->fresh(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);
        });
    }
}