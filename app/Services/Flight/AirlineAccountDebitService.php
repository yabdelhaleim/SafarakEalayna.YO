<?php

namespace App\Services\Flight;

use App\Enums\TransactionModule;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\TicketModification;
use App\Services\Finance\PrepaidLedgerService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1v2: Safe debit لـ AirlineAccount مع تسجيل متوازن في الـ GL.
 *
 * المشكلة قبل Phase 1v2:
 *   - ProcessTicketModificationAccounting كان بيستدعي $airlineAccount->debit() بس
 *   - ده بيكتب على airline_accounts.balance مباشرة (بدون GL entries)
 *   - كده الرصيد التشغيلي بينزل لكن الـ prepaid GL ما بيتحركش
 *   - ده اللي كان بيسبب desync في الـ legacy airline accounts
 *
 * الحل هنا:
 *   - نعمل debit للـ AirlineAccount (آمن عبر debit() method بعد Phase 1v2)
 *   - + نسجّل GL entry متوازنة عبر PrepaidLedgerService::consumeCogs()
 *   - + نحط double-entry transaction على prepaid flight_carrier GL
 *
 * @see App\Models\Flight\AirlineAccount (Phase 1v2 observer)
 * @see App\Services\Finance\PrepaidLedgerService::consumeCogs
 * @see App\Listeners\ProcessTicketModificationAccounting
 */
class AirlineAccountDebitService
{
    public function __construct(
        protected PrepaidLedgerService $prepaidLedgerService,
    ) {}

    /**
     * Debit airline account for a ticket modification + register GL entries.
     *
     * @return array{airline_tx_id: int, prepaid_tx_id: ?int, balance_after: float}
     *
     * @throws \RuntimeException لو الرصيد غير كافٍ
     * @throws \App\Exceptions\InsufficientBalanceException لو الـ prepaid GL غير كافٍ
     */
    public function debitForModification(
        AirlineAccount $airlineAccount,
        FlightBooking $booking,
        TicketModification $modification,
        int $userId,
    ): array {
        // Bug #C1 fix: validate currency match between booking and AirlineAccount
        // BEFORE any side effect. Without this, a USD booking could post USD-denominated
        // debit to an EGP AirlineAccount balance (or vice versa) — silent balance desync.
        $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
        $accountCurrency = strtoupper((string) $airlineAccount->currency);

        if ($bookingCurrency !== $accountCurrency
            && $bookingCurrency !== 'EGP'
            && $accountCurrency !== 'EGP') {
            throw new \RuntimeException(
                "عملة التعديل ({$bookingCurrency}) لا تطابق عملة حساب الطيران ({$accountCurrency}). ".
                "لا يمكن خصم غرامة بعملة مختلفة عن عملة الحساب."
            );
        }

        // Bug #C1 fix: convert fee to account currency if needed.
        // For booking=EGP, account=foreign: convert via booking exchange rate.
        // For booking=foreign, account=EGP: convert via booking exchange rate (reverse).
        // For same currency or one-side EGP: no conversion needed.
        $fee = (float) $modification->airline_change_fee;
        $debitAmount = $this->convertToAccountCurrency(
            $fee,
            $bookingCurrency,
            $accountCurrency,
            (float) ($booking->exchange_rate_used ?? $booking->exchange_rate ?? 1.0)
        );

        return LedgerBalanceMutationGuard::run(function () use ($airlineAccount, $booking, $modification, $userId, $debitAmount, $bookingCurrency, $accountCurrency) {
            return DB::transaction(function () use ($airlineAccount, $booking, $modification, $userId, $debitAmount, $bookingCurrency, $accountCurrency) {
                // ① Lock الـ airline account row
                AirlineAccount::query()
                    ->whereKey($airlineAccount->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ② Debit الـ AirlineAccount (safe route — model method)
                // الـ Observer هيسمح بالتعديل لأن mutateBalanceInternal flag بره
                $airlineTx = $airlineAccount->debit(
                    $debitAmount,
                    $booking->id,
                    $userId,
                );
                $airlineTx->description = "غرامة تعديل تذكرة #{$booking->booking_reference}";
                $airlineTx->save();

                // ③ Register GL entries متوازنة على prepaid flight_carrier + COGS expense
                // الـ consumeCogs() بتاخد من prepaid GL (رصيد مسبق — ناقلو الطيران)
                // وبتضيف لـ expense contra account (COGS)
                $prepaidTx = $this->prepaidLedgerService->consumeCogs(
                    prepaidKey: 'flight_carrier',
                    module: TransactionModule::Flight,
                    amount: $debitAmount,
                    notes: "غرامة تعديل تذكرة #{$booking->booking_reference} (AirlineAccount #{$airlineAccount->id})",
                    relatedType: TicketModification::class,
                    relatedId: $modification->id,
                );

                Log::info('Phase 1v2: AirlineAccount debit + GL entries recorded', [
                    'airline_account_id' => $airlineAccount->id,
                    'booking_id' => $booking->id,
                    'modification_id' => $modification->id,
                    'amount' => $debitAmount,
                    'original_fee' => (float) $modification->airline_change_fee,
                    'booking_currency' => $bookingCurrency,
                    'account_currency' => $accountCurrency,
                    'airline_tx_id' => $airlineTx->id,
                    'prepaid_tx_id' => $prepaidTx?->id,
                    'balance_after' => $airlineAccount->fresh()->balance,
                    'user_id' => $userId,
                ]);

                return [
                    'airline_tx_id' => $airlineTx->id,
                    'prepaid_tx_id' => $prepaidTx?->id,
                    'balance_after' => (float) $airlineAccount->fresh()->balance,
                ];
            });
        });
    }

    /**
     * تحويل مبلغ من عملة إلى أخرى باستخدام سعر صرف الحجز.
     * نفس منطق purchaseAmountInBalanceCurrency في FlightBookingService.
     */
    private function convertToAccountCurrency(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        float $exchangeRate
    ): float {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return round($amount, 2);
        }

        if ($from === 'EGP') {
            // EGP → foreign: divide by exchange rate (rate = EGP per 1 foreign)
            if ($exchangeRate <= 0) {
                throw new \RuntimeException("لا يوجد سعر صرف صالح لتحويل EGP → {$to}.");
            }
            return round($amount / $exchangeRate, 4);
        }

        if ($to === 'EGP') {
            // foreign → EGP: multiply by exchange rate
            if ($exchangeRate <= 0) {
                throw new \RuntimeException("لا يوجد سعر صرف صالح لتحويل {$from} → EGP.");
            }
            return round($amount * $exchangeRate, 2);
        }

        // both foreign, different currencies — not supported here (handled by C1 above)
        throw new \RuntimeException("لا يمكن التحويل بين {$from} و {$to} بدون EGP كعملة وسيطة.");
    }

    /**
     * Credit back the AirlineAccount after a ticket modification reversal +
     * record a paired GL reversal entry on the prepaid flight_carrier GL.
     *
     * This is the EXACT MIRROR of `debitForModification()`:
     *   - debitForModification(): AirlineAccount.balance -= X, prepaid -X, expense contra +X
     *   - creditBackForModification(): AirlineAccount.balance += X, expense contra -X, prepaid +X
     *
     * Calling both in sequence on the same modification should net to zero on
     * every account (sub-ledger AND GL), restoring the system to its
     * pre-modification state.
     *
     * Used by `ModificationService::reverseConfirmation()` to close the
     * original GAP (see docs/ARCHITECTURE.md § 8.5 + Phase 1v2).
     *
     * @return array{airline_tx_id: int, prepaid_tx_id: ?int, balance_after: float}
     *
     * @throws \RuntimeException لو الـ reverse guard فشل (idempotency / trashed)
     */
    public function creditBackForModification(
        AirlineAccount $airlineAccount,
        FlightBooking $booking,
        TicketModification $modification,
        int $userId,
    ): array {
        // Bug #C1 fix: same currency check as debit side
        $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
        $accountCurrency = strtoupper((string) $airlineAccount->currency);

        if ($bookingCurrency !== $accountCurrency
            && $bookingCurrency !== 'EGP'
            && $accountCurrency !== 'EGP') {
            throw new \RuntimeException(
                "عملة التعديل ({$bookingCurrency}) لا تطابق عملة حساب الطيران ({$accountCurrency})."
            );
        }

        // Use currency_snapshot if available (B11 fix), fall back to live fields.
        // The snapshot is what was used at confirmation time and is the
        // authoritative value for reversal — protects against mid-flow currency
        // mutations on the booking.
        $fee = (float) ($modification->airline_change_fee_snapshot ?? $modification->airline_change_fee);
        $snapshotCurrency = strtoupper((string) ($modification->currency_snapshot ?? $bookingCurrency));

        $creditAmount = $this->convertToAccountCurrency(
            $fee,
            $snapshotCurrency,
            $accountCurrency,
            (float) ($booking->exchange_rate_used ?? $booking->exchange_rate ?? 1.0)
        );

        return LedgerBalanceMutationGuard::run(function () use ($airlineAccount, $booking, $modification, $userId, $creditAmount, $fee, $snapshotCurrency, $accountCurrency) {
            return DB::transaction(function () use ($airlineAccount, $booking, $modification, $userId, $creditAmount, $fee, $snapshotCurrency, $accountCurrency) {
                // ① Lock the airline account row (same as debit side)
                AirlineAccount::query()
                    ->whereKey($airlineAccount->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ② Credit the AirlineAccount (sub-ledger) — mirror of debit()
                //    Uses mutateBalanceInternal internally → boot guard allows the save.
                if ($creditAmount <= 0) {
                    // Nothing was debited originally → nothing to reverse.
                    Log::info('AirlineAccount creditBackForModification — converted amount is 0, no balance change', [
                        'modification_id' => $modification->id,
                        'airline_account_id' => $airlineAccount->id,
                    ]);

                    return [
                        'airline_tx_id' => 0,
                        'prepaid_tx_id' => null,
                        'balance_after' => (float) $airlineAccount->fresh()->balance,
                    ];
                }

                $airlineTx = $airlineAccount->credit(
                    amount: $creditAmount,
                    description: 'عكس غرامة تعديل تذكرة #'.$booking->booking_reference,
                    userId: $userId,
                    bookingId: $booking->id,
                );

                // ③ Register paired GL reversal entry — mirror of consumeCogs()
                //    This is the previously-missing piece (the original GAP).
                //    Refund moves money OUT of expense contra and BACK INTO the
                //    prepaid flight_carrier GL pool, restoring it to its pre-modification balance.
                $prepaidTx = $this->prepaidLedgerService->refundCogs(
                    prepaidKey: 'flight_carrier',
                    module: TransactionModule::Flight,
                    amount: $creditAmount,
                    notes: 'عكس غرامة تعديل تذكرة #'.$booking->booking_reference.' (AirlineAccount #'.$airlineAccount->id.')',
                    relatedType: TicketModification::class,
                    relatedId: $modification->id,
                );

                Log::info('Phase 1v2: AirlineAccount credit-back + GL reversal entries recorded', [
                    'airline_account_id' => $airlineAccount->id,
                    'booking_id' => $booking->id,
                    'modification_id' => $modification->id,
                    'amount' => $creditAmount,
                    'original_fee' => $fee,
                    'snapshot_currency' => $snapshotCurrency,
                    'account_currency' => $accountCurrency,
                    'airline_tx_id' => $airlineTx->id,
                    'prepaid_tx_id' => $prepaidTx?->id,
                    'balance_after' => $airlineAccount->fresh()->balance,
                    'user_id' => $userId,
                ]);

                return [
                    'airline_tx_id' => $airlineTx->id,
                    'prepaid_tx_id' => $prepaidTx?->id,
                    'balance_after' => (float) $airlineAccount->fresh()->balance,
                ];
            });
        });
    }
}
