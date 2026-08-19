<?php

namespace App\Services\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Models\Treasury;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
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
        return DB::transaction(function () use ($data, $userId) {
            $booking = BusBooking::query()
                ->with(['inventoryWithTrashed.company'])
                ->lockForUpdate()
                ->findOrFail($data['bus_booking_id']);

            if (in_array($booking->status, [
                BusBookingStatus::Cancelled,
                BusBookingStatus::Refunded,
                BusBookingStatus::PartiallyRefunded,
            ], true)) {
                throw new \RuntimeException('هذا الحجز ملغي أو مسترد بالفعل.');
            }

            $totalPaid = (float) $booking->payments()->sum('amount');
            if ($totalPaid <= 0.001) {
                throw new \RuntimeException('لا يمكن إنشاء استرداد لحجز غير مدفوع.');
            }

            $activeRefunded = (float) $booking->refundRequests()
                ->whereIn('status', ['pending', 'processed'])
                ->sum('refund_amount');
            $originalCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
            $originalAmount = min((float) $booking->total_price, $totalPaid);

            $cancellationFee = (float) ($data['cancellation_fee'] ?? 0);
            $refundAmount = $originalAmount - $cancellationFee;

            if ($refundAmount < 0) {
                throw new \InvalidArgumentException('رسوم الإلغاء لا يمكن أن تتجاوز المبلغ الأصلي للحجز.');
            }
            if ($activeRefunded + $refundAmount > $totalPaid + 0.001) {
                throw new \InvalidArgumentException('إجمالي الاستردادات يتجاوز المبلغ المدفوع للحجز.');
            }

            $refundCurrency = strtoupper((string) ($data['refund_currency'] ?? $originalCurrency));
            $refundExchangeRate = (float) ($data['refund_exchange_rate'] ?? ($booking->exchange_rate_to_egp ?: 1.0));
            $baseCurrencyRefund = $refundAmount * $refundExchangeRate;
            $destination = $data['destination'] ?? 'agency_treasury';

            return BusRefundRequest::create([
                'bus_booking_id' => $booking->id,
                'company_id' => $booking->inventory?->company_id,
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
        });
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

            $booking = BusBooking::with(['inventoryWithTrashed.company', 'customer'])->lockForUpdate()->findOrFail($refundRequest->bus_booking_id);
            $inventory = $booking->inventoryWithTrashed;
            if (! $inventory) {
                throw new \RuntimeException('مخزون الحجز غير موجود ولا يمكن عكس العملية بأمان.');
            }
            $company = $inventory->company;
            $customer = $booking->customer;

            // 1. زيادة عدد التذاكر المتاحة في المخزون
            // ملاحظة: قد يكون الاسترجاع جزئياً في المبلغ ولكن كامل في المقاعد، أو جزئياً في المقاعد.
            // حالياً نفترض استرجاع كامل للمقاعد المرتبطة بهذا الحجز عند معالجة طلب الاسترجاع.
            $inventory->increment('available_tickets', $booking->quantity);

            // 2. معالجة الجانب المالي (المورد)
            if ($company && $company->account_id) {
                $costPerTicket = (float) $inventory->cost_per_ticket;
                $totalCostToReverse = $costPerTicket * $booking->quantity;
                if (strtoupper((string) ($booking->currency ?? 'EGP')) !== 'EGP') {
                    $totalCostToReverse = round(
                        $totalCostToReverse * (float) ($booking->exchange_rate_to_egp ?: 1.0),
                        2
                    );
                }
                $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);

                if ($clearingAccountId && $totalCostToReverse > 0) {
                    $glTransaction = $this->transactionService->recordJournalTransfer([
                        'amount' => $totalCostToReverse,
                        'from_account_id' => $clearingAccountId, // Debit clearing (reverse)
                        'to_account_id' => $company->account_id, // Credit company (reverse/decrease debt)
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'استرجاع تكلفة حجز باص #'.$booking->id.' من المورد',
                        'allow_from_negative' => true,
                    ]);
                }
            }

            // 2.5 (Step 4 fix): عكس قيد العميل (customer AR) — symmetric to Step 2 (supplier).
            //
            // The supplier reversal posts (from=clearing, to=company). The symmetric
            // customer-side reversal must post (from=customer, to=income_clearing) so
            // the customer AR swings from 0 (post-payment) into a NEGATIVE credit
            // balance = office owes the customer back.
            //
            // Money convention (mirrors `recordSaleToCustomer` with roles swapped):
            //   recordSaleToCustomer → from=income_clearing (EGP), to=customer (foreign)
            //                         amount           = EGP-equivalent (clearing debit)
            //                         converted_amount = foreign       (customer credit)
            //   processRefundRequest → from=customer (foreign), to=income_clearing (EGP)
            //                         amount           = foreign       (customer debit — AR goes negative)
            //                         converted_amount = EGP-equivalent (clearing credit)
            //
            // Skip when: no customer linked, no customer account, no income_clearing,
            //            or refund_amount is 0 (e.g. 100% penalty → no refund → no AR swing).
            if ($customer && $customer->account_id && (float) $refundRequest->refund_amount > 0) {
                $incomeClearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus);
                if ($incomeClearingAccountId && $incomeClearingAccountId !== $customer->account_id) {
                    $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                    $refundArgs = [
                        'amount' => round((float) $refundRequest->refund_amount, 2),
                        'from_account_id' => (int) $customer->account_id, // Debit customer → AR goes negative
                        'to_account_id'   => (int) $incomeClearingAccountId, // Credit income clearing
                        'module' => TransactionModule::Bus->value,
                        // Step 4 fix: tag this Transfer as type=Refund so audit
                        // queries (Transaction::where('type', Refund)) catch the
                        // event. Mirrors the docblock expectation in
                        // BusRefundCustomerArReversalTest ("a Refund-type
                        // transaction must exist for this booking").
                        'type' => TransactionType::Refund->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'عكس قيد عميل لاسترجاع حجز باص #'.$booking->id,
                        'allow_from_negative' => true, // customer AR can go negative (office owes customer)
                    ];
                    if ($bookingCurrency !== 'EGP') {
                        $egpEquivalent = round(
                            (float) $refundRequest->refund_amount * (float) ($booking->exchange_rate_to_egp ?: 1.0),
                            2
                        );
                        $refundArgs['converted_amount'] = $egpEquivalent;
                        $refundArgs['exchange_rate'] = (float) ($booking->exchange_rate_to_egp ?: 1.0);
                    }
                    $this->transactionService->recordJournalTransfer($refundArgs);
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
                        "تضارب في العملة: لا يمكن إيداع استرجاع بعملة ({$refundRequest->refund_currency}) ".
                        "في خزينة تعمل بعملة ({$treasury->currency})."
                    );
                }

                // إيداع المبلغ في الخزينة
                $treasury->credit((float) $refundRequest->refund_amount);

                // توثيق الحركة في حركات الخزينة
                // NOTE: الـ ledger_transaction_id + account_id يتم ربطهما عبر ->linkToGl()
                // — الـ GL Transaction الموجود هنا هو قيد المورد (supplier side) لأن الـ Bus flow
                // ما عندوش قيد GL مخصص للخزينة (نفس pattern الـ orphan القديم). للـ traceability
                // نربط بأي حال، وده بيخلي الـ audit trail موجود بدل null.
                $treasuryTransaction = $treasury->transactions()->create([
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

                // NEW (2026-07-11): اربط بالـ GL Transaction الموجود (supplier-side)
                // لأن الـ Bus flow ما عندوش GL tx مخصص للخزينة — الحل الكامل هيتم
                // في task منفصل لما يتم توحيد الـ supplier/treasury GL flow.
                if (isset($glTransaction)) {
                    $treasuryTransaction->linkToGl($glTransaction, $company?->account_id);
                }
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
