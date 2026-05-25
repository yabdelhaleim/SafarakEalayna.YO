<?php

namespace App\Services\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Models\Treasury;
use App\Services\Finance\TransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusRefundService
{
    protected TransactionService $transactionService;
    protected LedgerClearingAccounts $ledgerClearingAccounts;

    public function __construct(
        TransactionService $transactionService,
        LedgerClearingAccounts $ledgerClearingAccounts
    ) {
        $this->transactionService = $transactionService;
        $this->ledgerClearingAccounts = $ledgerClearingAccounts;
    }

    /**
     * إنشاء طلب استرجاع جديد لحجز باص.
     */
    public function createRefundRequest(array $data, int $userId): BusRefundRequest
    {
        $booking = BusBooking::with('inventory.company')->findOrFail($data['bus_booking_id']);

        if ($booking->status === BusBookingStatus::Refunded) {
            throw new \RuntimeException('هذا الحجز تم استرداده بالكامل مسبقاً.');
        }

        $originalCurrency = 'EGP'; // Bus system is primarily EGP
        $originalAmount = (float) $booking->total_price;

        $cancellationFee = (float) ($data['cancellation_fee'] ?? 0);
        $refundAmount = $originalAmount - $cancellationFee;

        if ($refundAmount < 0) {
            throw new \InvalidArgumentException('رسوم الإلغاء لا يمكن أن تتجاوز المبلغ الأصلي للحجز.');
        }

        $refundCurrency = $data['refund_currency'] ?? $originalCurrency;
        $refundExchangeRate = (float) ($data['refund_exchange_rate'] ?? 1.0);
        $baseCurrencyRefund = $refundAmount * $refundExchangeRate;

        $destination = $data['destination'] ?? 'agency_treasury';

        return BusRefundRequest::create([
            'bus_booking_id' => $booking->id,
            'company_id' => $booking->inventory->company_id,
            'refund_type' => $data['refund_type'] ?? 'cash_to_agency',
            'original_currency' => $originalCurrency,
            'original_amount' => $originalAmount,
            'cancellation_fee' => $cancellationFee,
            'refund_amount' => $refundAmount,
            'refund_currency' => $refundCurrency,
            'refund_exchange_rate' => $refundExchangeRate,
            'base_currency_refund' => $baseCurrencyRefund,
            'destination' => $destination,
            'treasury_id' => $destination === 'agency_treasury' ? ($data['treasury_id'] ?? null) : null,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * معالجة واعتماد طلب الاسترجاع.
     */
    public function processRefundRequest(int $refundRequestId, int $userId): BusRefundRequest
    {
        return DB::transaction(function () use ($refundRequestId, $userId) {
            $refundRequest = BusRefundRequest::lockForUpdate()->findOrFail($refundRequestId);

            if ($refundRequest->status === 'processed') {
                return $refundRequest;
            }

            $booking = BusBooking::with('inventory.company')->lockForUpdate()->findOrFail($refundRequest->bus_booking_id);
            $inventory = $booking->inventory;
            $company = $inventory->company;

            // 1. زيادة عدد التذاكر المتاحة في المخزون
            // ملاحظة: قد يكون الاسترجاع جزئياً في المبلغ ولكن كامل في المقاعد، أو جزئياً في المقاعد.
            // حالياً نفترض استرجاع كامل للمقاعد المرتبطة بهذا الحجز عند معالجة طلب الاسترجاع.
            $inventory->increment('available_tickets', $booking->quantity);

            // 2. معالجة الجانب المالي (المورد)
            if ($company && $company->account_id) {
                $costPerTicket = $inventory->cost_per_ticket;
                $totalCostToReverse = $costPerTicket * $booking->quantity;
                $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);

                if ($clearingAccountId && $totalCostToReverse > 0) {
                    $this->transactionService->recordJournalTransfer([
                        'amount' => $totalCostToReverse,
                        'from_account_id' => $clearingAccountId, // Debit clearing (reverse)
                        'to_account_id' => $company->account_id, // Credit company (reverse/decrease debt)
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'استرجاع تكلفة حجز باص #' . $booking->id . ' من المورد',
                        'allow_from_negative' => true,
                    ]);
                }
            }

            // 3. معالجة الجانب المالي (الخزينة - إذا كان الوجهة خزينة)
            if ($refundRequest->destination === 'agency_treasury') {
                $treasury = Treasury::lockForUpdate()->find($refundRequest->treasury_id);

                if (! $treasury) {
                    throw new \RuntimeException('خزينة الوجهة المحددة غير موجودة.');
                }

                if (! $treasury->is_active) {
                    throw new \RuntimeException("الخزينة المحددة ({$treasury->name}) غير نشطة حالياً.");
                }

                if (strtoupper($treasury->currency) !== strtoupper($refundRequest->refund_currency)) {
                    throw new \RuntimeException(
                        "تضارب في العملة: لا يمكن إيداع استرجاع بعملة ({$refundRequest->refund_currency}) " .
                        "في خزينة تعمل بعملة ({$treasury->currency})."
                    );
                }

                // إيداع المبلغ في الخزينة
                $treasury->credit((float) $refundRequest->refund_amount);

                // توثيق الحركة في حركات الخزينة
                $treasury->transactions()->create([
                    'transaction_type' => 'receipt',
                    'amount' => $refundRequest->refund_amount,
                    'currency' => $refundRequest->refund_currency,
                    'balance_before' => $treasury->current_balance - $refundRequest->refund_amount,
                    'balance_after' => $treasury->current_balance,
                    'agent_name' => $booking?->customer?->full_name ?? 'System',
                    'reason' => 'استرجاع حجز باص',
                    'bus_booking_id' => $booking->id,
                    'type' => 'credit',
                    'exchange_rate' => $refundRequest->refund_exchange_rate,
                    'base_amount' => $refundRequest->base_currency_refund,
                    'description' => "إيداع استرجاع حجز باص #{$booking->id}",
                ]);
            }

            // 4. تحديث حالة الحجز
            $isPartial = $refundRequest->cancellation_fee > 0 || $refundRequest->refund_amount < $refundRequest->original_amount;
            $booking->status = $isPartial ? BusBookingStatus::PartiallyRefunded : BusBookingStatus::Refunded;
            $booking->save();

            // 5. تحديث حالة طلب الاسترجاع
            $refundRequest->status = 'processed';
            $refundRequest->processed_at = now();
            $refundRequest->save();

            Log::info('Bus refund processed successfully', [
                'refund_request_id' => $refundRequest->id,
                'booking_id' => $booking->id,
                'user_id' => $userId,
            ]);

            return $refundRequest;
        });
    }
}
