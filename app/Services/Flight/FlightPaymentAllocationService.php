<?php

namespace App\Services\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPaymentAllocation;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightPaymentAllocationService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}

    /**
     * Allocate an income/receipt transaction to a single flight booking.
     */
    public function allocate(
        Transaction $transaction,
        FlightBooking $booking,
        float $amount,
        int $userId,
        ?string $notes = null
    ): FlightPaymentAllocation {
        $result = $this->allocateMultiple(
            $transaction,
            [
                [
                    'flight_booking_id' => $booking->id,
                    'amount' => $amount,
                    'notes' => $notes,
                ],
            ],
            $userId
        );

        return $result->first();
    }

    /**
     * Allocate an income/receipt transaction across multiple flight bookings atomically.
     *
     * @param  array<int, array{flight_booking_id: int, amount: float, notes?: string|null}>  $allocations
     * @return Collection<int, FlightPaymentAllocation>
     */
    public function allocateMultiple(
        Transaction $transaction,
        array $allocations,
        int $userId
    ): Collection {
        if (empty($allocations)) {
            throw new \InvalidArgumentException('يجب توفير حجز واحد على الأقل للتخصيص.');
        }

        return DB::transaction(function () use ($transaction, $allocations, $userId) {
            $lockedTx = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            $txNotes = (string) ($lockedTx->notes ?? '');
            if (str_starts_with($txNotes, 'عكس:') || str_starts_with($txNotes, 'عكس ')) {
                throw new \InvalidArgumentException('لا يمكن تخصيص سند قبض معكوس أو ملغي.');
            }

            $txAmount = (float) $lockedTx->amount;
            if ($txAmount <= 0) {
                throw new \InvalidArgumentException('مبلغ سند القبض يجب أن يكون أكبر من صفر.');
            }

            $alreadyAllocated = (float) FlightPaymentAllocation::query()
                ->where('transaction_id', $lockedTx->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->sum('amount');

            $unallocated = max(0.0, round($txAmount - $alreadyAllocated, 2));

            $totalRequested = 0.0;
            foreach ($allocations as $item) {
                $itemAmount = (float) ($item['amount'] ?? 0);
                if ($itemAmount <= 0.001) {
                    throw new \InvalidArgumentException('مبلغ التخصيص لكل حجز يجب أن يكون أكبر من صفر.');
                }
                $totalRequested += $itemAmount;
            }

            if ($totalRequested > $unallocated + 0.001) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'إجمالي مبالغ التخصيص المطلوبة (%.2f ج.م) يتجاوز الرصيد غير المخصص المتاح في سند القبض (%.2f ج.م).',
                        $totalRequested,
                        $unallocated
                    )
                );
            }

            // Customer ownership check
            $customerId = (int) $lockedTx->related_id;
            if ($lockedTx->related_type !== Customer::class || $customerId <= 0) {
                throw new \InvalidArgumentException('سند القبض يجب أن يكون مسجلاً لصالح عميل (Customer).');
            }

            $createdAllocations = collect();

            foreach ($allocations as $item) {
                $bookingId = (int) $item['flight_booking_id'];
                $itemAmount = round((float) $item['amount'], 2);

                $booking = FlightBooking::query()
                    ->lockForUpdate()
                    ->findOrFail($bookingId);

                if ((int) $booking->customer_id !== $customerId) {
                    throw new \InvalidArgumentException(
                        "الحجز #{$booking->booking_number} لا يتبع للعميل صاحب سند القبض."
                    );
                }

                if (in_array($booking->status, [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED], true)) {
                    throw new \InvalidArgumentException(
                        "لا يمكن تخصيص دفعات لحجز ملغي أو مسترد (#{$booking->booking_number})."
                    );
                }

                $currentPaid = (float) $booking->paid_amount;
                $sellingPrice = (float) $booking->selling_price;
                $remaining = max(0.0, round($sellingPrice - $currentPaid, 2));

                if ($itemAmount > $remaining + 0.001) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'مبلغ التخصيص (%.2f ج.م) يتجاوز المبلغ المتبقي المستحق على الحجز #%s (%.2f ج.م).',
                            $itemAmount,
                            $booking->booking_number,
                            $remaining
                        )
                    );
                }

                $allocation = FlightPaymentAllocation::create([
                    'transaction_id' => $lockedTx->id,
                    'flight_booking_id' => $booking->id,
                    'customer_id' => $customerId,
                    'amount' => $itemAmount,
                    'status' => 'active',
                    'notes' => $item['notes'] ?? "تخصيص من سند قبض #{$lockedTx->id}",
                    'created_by' => $userId,
                    'allocated_at' => now(),
                ]);

                // Status promotion if fully paid with PNR
                $newPaid = $currentPaid + $itemAmount;
                if ($newPaid >= $sellingPrice - 0.01 && $booking->status === FlightBookingStatus::PENDING && !empty($booking->pnr)) {
                    $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
                }

                Log::info('Flight payment allocation created', [
                    'allocation_id' => $allocation->id,
                    'transaction_id' => $lockedTx->id,
                    'flight_booking_id' => $booking->id,
                    'customer_id' => $customerId,
                    'amount' => $itemAmount,
                    'user_id' => $userId,
                ]);

                $createdAllocations->push($allocation);
            }

            return $createdAllocations;
        });
    }

    /**
     * Reverse all active payment allocations for a booking upon cancellation.
     * Preserves shared receipts for other bookings, only reversing this booking's share.
     */
    public function reverseAllocationsForBooking(FlightBooking $booking, int $userId): float
    {
        return DB::transaction(function () use ($booking, $userId) {
            $allocations = FlightPaymentAllocation::query()
                ->where('flight_booking_id', $booking->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            $totalReversed = 0.0;

            foreach ($allocations as $allocation) {
                $allocation->update([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                    'reversed_by' => $userId,
                ]);

                $totalReversed += (float) $allocation->amount;

                $parentTx = Transaction::query()
                    ->lockForUpdate()
                    ->find($allocation->transaction_id);

                if (! $parentTx) {
                    continue;
                }

                // Check if other active allocations exist on the parent transaction
                $otherActiveCount = FlightPaymentAllocation::query()
                    ->where('transaction_id', $parentTx->id)
                    ->where('status', 'active')
                    ->count();

                if ($otherActiveCount === 0 && abs((float) $parentTx->amount - (float) $allocation->amount) < 0.01) {
                    // Entire receipt belonged exclusively to this booking -> mark parent reversed
                    $this->transactionService->markTransactionReversed($parentTx);
                    Log::info('Parent payDebt transaction marked reversed (100% allocated to cancelled booking)', [
                        'transaction_id' => $parentTx->id,
                        'flight_booking_id' => $booking->id,
                        'amount' => $parentTx->amount,
                        'user_id' => $userId,
                    ]);
                } else {
                    // Shared receipt: do NOT mark parent reversed (would destroy other bookings' revenue).
                    // Instead, create a companion revenue-reversal transfer for this exact allocation.
                    $incomeClearingId = $this->clearingAccounts->incomeContraIdForFlightBooking();
                    $cashboxId = (int) ($parentTx->to_account_id ?: Account::getModuleVault('flights')?->id);

                    if ($incomeClearingId && $cashboxId) {
                        $companionTx = Transaction::create([
                            'type' => TransactionType::Transfer->value,
                            'amount' => $allocation->amount,
                            'currency' => $parentTx->currency ?? 'EGP',
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'from_account_id' => $cashboxId,
                            'to_account_id' => $incomeClearingId,
                            'created_by' => $userId,
                            'notes' => "عكس إيراد تخصيص دفعة حجز ملغي — حجز #{$booking->booking_number} — تخصيص #{$allocation->id}",
                        ]);

                        $allocation->update(['reversal_transaction_id' => $companionTx->id]);

                        Log::info('Companion revenue-reversal created for partial allocation reversal', [
                            'companion_transaction_id' => $companionTx->id,
                            'parent_transaction_id' => $parentTx->id,
                            'allocation_id' => $allocation->id,
                            'amount' => $allocation->amount,
                            'flight_booking_id' => $booking->id,
                        ]);
                    }
                }
            }

            return $totalReversed;
        });
    }

    /**
     * Get the unallocated amount remaining on a receipt transaction.
     */
    public function getUnallocatedAmount(Transaction $transaction): float
    {
        $allocated = (float) FlightPaymentAllocation::query()
            ->where('transaction_id', $transaction->id)
            ->where('status', 'active')
            ->sum('amount');

        return max(0.0, round((float) $transaction->amount - $allocated, 2));
    }
}
