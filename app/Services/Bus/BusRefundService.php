<?php

namespace App\Services\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Models\Treasury;
use App\Services\Bus\Concerns\BusEgpOnly;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusRefundService
{
    use BusEgpOnly;

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
            // EGP-only contract: every Bus booking is EGP. The booking's currency
            // column is asserted here so any historical non-EGP booking would be
            // refused at the refund-creation layer (defence in depth).
            $this->assertBusCurrency((string) ($booking->currency ?? self::BUS_CURRENCY), 'booking.currency');
            $this->assertBusExchangeRate((float) ($booking->exchange_rate_to_egp ?? self::BUS_EXCHANGE_RATE_TO_EGP), 'booking.exchange_rate_to_egp');

            $originalAmount = min((float) $booking->total_price, $totalPaid);

            $cancellationFee = (float) ($data['cancellation_fee'] ?? 0);
            $refundAmount = $originalAmount - $cancellationFee;

            if ($refundAmount < 0) {
                throw new \InvalidArgumentException('رسوم الإلغاء لا يمكن أن تتجاوز المبلغ الأصلي للحجز.');
            }
            if ($activeRefunded + $refundAmount > $totalPaid + 0.001) {
                throw new \InvalidArgumentException('إجمالي الاستردادات يتجاوز المبلغ المدفوع للحجز.');
            }

            // EGP-only contract: refund currency is always EGP. Any caller-supplied
            // refund_currency is rejected here (defence in depth on top of the
            // BusRefundController::store validation).
            $requestedRefundCurrency = $data['refund_currency'] ?? null;
            if ($requestedRefundCurrency !== null) {
                $this->assertBusCurrency((string) $requestedRefundCurrency, 'refund_currency');
            }
            $requestedRefundRate = $data['refund_exchange_rate'] ?? null;
            if ($requestedRefundRate !== null) {
                $this->assertBusExchangeRate((float) $requestedRefundRate, 'refund_exchange_rate');
            }

            $destination = $data['destination'] ?? 'agency_treasury';

            return BusRefundRequest::create([
                'bus_booking_id' => $booking->id,
                'company_id' => $booking->inventory?->company_id,
                'refund_type' => $data['refund_type'] ?? 'cash_to_agency',
                'original_currency' => self::BUS_CURRENCY,
                'original_amount' => $originalAmount,
                'cancellation_fee' => $cancellationFee,
                'refund_amount' => $refundAmount,
                'refund_currency' => self::BUS_CURRENCY,
                'refund_exchange_rate' => self::BUS_EXCHANGE_RATE_TO_EGP,
                'base_currency_refund' => round($refundAmount, 2),
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

            // EGP-only contract: every Bus booking and every BusRefundRequest row
            // must be EGP. Refuse to process a refund whose stored snapshot
            // disagrees with the canonical currency. Defends against legacy
            // rows that pre-date the EGP-only contract.
            $this->assertBusCurrency((string) ($booking->currency ?? self::BUS_CURRENCY), 'booking.currency');
            $this->assertBusCurrency((string) ($refundRequest->refund_currency ?? self::BUS_CURRENCY), 'refund_request.refund_currency');
            $this->assertBusCurrency((string) ($refundRequest->original_currency ?? self::BUS_CURRENCY), 'refund_request.original_currency');
            $this->assertBusExchangeRate((float) ($refundRequest->refund_exchange_rate ?? self::BUS_EXCHANGE_RATE_TO_EGP), 'refund_request.refund_exchange_rate');

            // 1. زيادة عدد التذاكر المتاحة في المخزون
            // ملاحظة: قد يكون الاسترجاع جزئياً في المبلغ ولكن كامل في المقاعد، أو جزئياً في المقاعد.
            // حالياً نفترض استرجاع كامل للمقاعد المرتبطة بهذا الحجز عند معالجة طلب الاسترجاع.
            $inventory->increment('available_tickets', $booking->quantity);

            // 2. معالجة الجانب المالي (المورد)
            //
            // EGP-only contract: the supplier ledger is always EGP. The cost
            // we reverse is always the same EGP figure that was originally
            // posted at booking time. No FX conversion is performed here.
            if ($company && $company->account_id) {
                $costPerTicket = (float) $inventory->cost_per_ticket;
                $totalCostToReverse = round($costPerTicket * $booking->quantity, 2);
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

            // 2.5 عكس قيد العميل (customer AR) — symmetric to Step 2 (supplier).
            //
            // EGP-only contract: the customer AR account is in EGP; the
            // income-clearing account is also in EGP. We post a same-currency
            // Refund-type journal entry (customer → income_clearing). No
            // `converted_amount` or `exchange_rate` is passed because both
            // sides of the transfer are EGP.
            if ($customer && $customer->account_id && (float) $refundRequest->refund_amount > 0) {
                $incomeClearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus);
                if ($incomeClearingAccountId && $incomeClearingAccountId !== $customer->account_id) {
                    $refundArgs = [
                        'amount' => round((float) $refundRequest->refund_amount, 2),
                        'from_account_id' => (int) $customer->account_id, // Debit customer → AR goes negative
                        'to_account_id' => (int) $incomeClearingAccountId, // Credit income clearing
                        'module' => TransactionModule::Bus->value,
                        'type' => TransactionType::Refund->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'عكس قيد عميل لاسترجاع حجز باص #'.$booking->id,
                        'allow_from_negative' => true, // customer AR can go negative (office owes customer)
                    ];
                    $this->transactionService->recordJournalTransfer($refundArgs);
                }
            }

            // 3. معالجة الجانب المالي (الخزينة - إذا كان الوجهة خزينة)
            //
            // EGP-only contract: the destination treasury must be EGP. We assert
            // the stored refund_currency is EGP (already done above) AND that the
            // treasury's stored currency is also EGP (defence in depth — a
            // non-EGP treasury is a configuration error after Phase 3).
            if ($refundRequest->destination === 'agency_treasury') {
                $treasury = Treasury::lockForUpdate()->find($refundRequest->treasury_id);

                if (! $treasury) {
                    throw new \RuntimeException('خزينة الوجهة المحددة غير موجودة.');
                }

                if (! $treasury->is_active) {
                    throw new \RuntimeException("الخزينة المحددة ({$treasury->name}) غير نشطة حالياً.");
                }

                $this->assertBusCurrency((string) ($treasury->currency ?? self::BUS_CURRENCY), 'treasury.currency');

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
                    'currency' => self::BUS_CURRENCY,
                    'balance_before' => $treasury->current_balance - $refundRequest->refund_amount,
                    'balance_after' => $treasury->current_balance,
                    'agent_name' => $booking?->customer?->full_name ?? 'System',
                    'reason' => 'استرجاع حجز باص',
                    'bus_booking_id' => $booking->id,
                    'type' => 'credit',
                    'exchange_rate' => self::BUS_EXCHANGE_RATE_TO_EGP,
                    'base_amount' => round((float) $refundRequest->refund_amount, 2),
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
