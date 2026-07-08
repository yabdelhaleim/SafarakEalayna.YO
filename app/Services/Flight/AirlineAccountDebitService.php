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
        return LedgerBalanceMutationGuard::run(function () use ($airlineAccount, $booking, $modification, $userId) {
            return DB::transaction(function () use ($airlineAccount, $booking, $modification, $userId) {
                // ① Lock الـ airline account row
                AirlineAccount::query()
                    ->whereKey($airlineAccount->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ② Debit الـ AirlineAccount (safe route — model method)
                // الـ Observer هيسمح بالتعديل لأن mutateBalanceInternal flag بره
                $airlineTx = $airlineAccount->debit(
                    (float) $modification->airline_change_fee,
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
                    amount: (float) $modification->airline_change_fee,
                    notes: "غرامة تعديل تذكرة #{$booking->booking_reference} (AirlineAccount #{$airlineAccount->id})",
                    relatedType: TicketModification::class,
                    relatedId: $modification->id,
                );

                Log::info('Phase 1v2: AirlineAccount debit + GL entries recorded', [
                    'airline_account_id' => $airlineAccount->id,
                    'booking_id' => $booking->id,
                    'modification_id' => $modification->id,
                    'amount' => (float) $modification->airline_change_fee,
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
