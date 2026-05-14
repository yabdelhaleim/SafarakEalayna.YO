<?php

namespace App\Services\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\RefundRequest;
use App\Models\Treasury;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    /**
     * إنشاء طلب استرجاع جديد للتذكرة.
     */
    public function createRefundRequest(array $data, int $userId): RefundRequest
    {
        $booking = FlightBooking::findOrFail($data['flight_booking_id']);

        // التحقق من أن الحجز ليس مسترداً بالكامل مسبقاً
        if ($booking->status === FlightBookingStatus::REFUNDED) {
            throw new \RuntimeException('هذا الحجز تم استرداده بالكامل مسبقاً ولا يمكن إصدار طلب استرجاع جديد له.');
        }

        $originalCurrency = $booking->original_currency ?: ($booking->currency ?: 'EGP');
        $originalAmount = (float) ($booking->original_amount ?: $booking->selling_price);
        $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));

        $cancellationFee = (float) ($data['cancellation_fee'] ?? 0);
        $refundAmount = $originalAmount - $cancellationFee;

        if ($refundAmount < 0) {
            throw new \InvalidArgumentException('رسوم الإلغاء لا يمكن أن تتجاوز المبلغ الأصلي للحجز.');
        }

        $refundCurrency = $data['refund_currency'] ?? $originalCurrency;
        $refundExchangeRate = (float) ($data['refund_exchange_rate'] ?? 1.0);
        $baseCurrencyRefund = $refundAmount * $refundExchangeRate;

        // حساب فرق العملة بناءً على المبلغ الصافي المسترد
        $baseAmountAfterFeeAtBookingRate = $refundAmount * $bookingExchangeRate;
        $currencyDifference = $baseCurrencyRefund - $baseAmountAfterFeeAtBookingRate;

        $destination = $data['destination'] ?? 'agency_treasury';
        $refundType = $data['refund_type'] ?? ($destination === 'airline_credit' ? 'airline_credit_only' : 'cash_to_agency');

        return RefundRequest::create([
            'flight_booking_id' => $booking->id,
            'refund_type' => $refundType,
            'original_currency' => $originalCurrency,
            'original_amount' => $originalAmount,
            'cancellation_fee' => $cancellationFee,
            'refund_amount' => $refundAmount,
            'refund_currency' => $refundCurrency,
            'refund_exchange_rate' => $refundExchangeRate,
            'base_currency_refund' => $baseCurrencyRefund,
            'currency_difference' => $currencyDifference,
            'destination' => $destination,
            'treasury_id' => $destination === 'agency_treasury' ? ($data['treasury_id'] ?? null) : null,
            'airline_credit_balance' => $destination === 'airline_credit' ? $refundAmount : null,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * معالجة واعتماد طلب الاسترجاع مع تطبيق العزل المالي التام وحسابات العملات.
     */
    public function processRefundRequest(int $refundRequestId, int $userId): RefundRequest
    {
        return DB::transaction(function () use ($refundRequestId, $userId) {
            $refundRequest = RefundRequest::lockForUpdate()->findOrFail($refundRequestId);

            // ضمان الـ Idempotency لمنع التكرار
            if ($refundRequest->status === 'processed') {
                return $refundRequest;
            }

            $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);

            if ($refundRequest->destination === 'airline_credit') {
                // Scenario A: رصيد طيران فقط
                if (! $booking->flight_carrier_id) {
                    throw new \RuntimeException('لا يمكن إصدار رصيد طيران لحجز لا يحتوي على شركة طيران (Carrier) محددة.');
                }

                // إنشاء أو تحديث رصيد الطيران
                AirlineCredit::create([
                    'flight_carrier_id' => $booking->flight_carrier_id,
                    'customer_id' => $booking->customer_id,
                    'currency' => $refundRequest->refund_currency,
                    'amount' => $refundRequest->refund_amount,
                    'expiry_date' => now()->addYear()->toDateString(), // افتراضي سنة واحدة
                    'flight_booking_id' => $booking->id,
                    'refund_request_id' => $refundRequest->id,
                    'status' => 'active',
                ]);

                Log::info('تم إصدار رصيد طيران بنجاح', [
                    'refund_request_id' => $refundRequest->id,
                    'amount' => $refundRequest->refund_amount,
                    'currency' => $refundRequest->refund_currency,
                ]);

            } else {
                // Scenario B: إيداع في خزينة الوكالة
                $treasury = Treasury::lockForUpdate()->find($refundRequest->treasury_id);

                if (! $treasury) {
                    throw new \RuntimeException('خزينة الوجهة المحددة غير موجودة.');
                }

                if (! $treasury->is_active) {
                    throw new \RuntimeException("الخزينة المحددة ({$treasury->name}) غير نشطة حالياً.");
                }

                // شرط التطابق الصارم للعملة (Currency Match)
                if (strtoupper($treasury->currency) !== strtoupper($refundRequest->refund_currency)) {
                    throw new \RuntimeException(
                        "تضارب في العملة: لا يمكن إيداع استرجاع بعملة ({$refundRequest->refund_currency}) " .
                        "في خزينة تعمل بعملة ({$treasury->currency}). يرجى اختيار أو إنشاء خزينة مطابقة."
                    );
                }

                // إيداع المبلغ في الخزينة
                $treasury->credit((float) $refundRequest->refund_amount);

                // توثيق الحركة المالية في حركات الخزينة
                $treasury->transactions()->create([
                    'transaction_type' => 'receipt',
                    'amount' => $refundRequest->refund_amount,
                    'currency' => $refundRequest->refund_currency,
                    'balance_before' => $treasury->current_balance - $refundRequest->refund_amount,
                    'balance_after' => $treasury->current_balance,
                    'reason' => 'استرجاع تذكرة طيران',
                    'flight_booking_id' => $booking->id,
                    'refund_request_id' => $refundRequest->id,
                    'type' => 'credit',
                    'exchange_rate' => $refundRequest->refund_exchange_rate,
                    'base_amount' => $refundRequest->base_currency_refund,
                    'description' => "إيداع استرجاع تذكرة #{$booking->booking_number}" .
                        ($refundRequest->currency_difference != 0 ? " (فروقات عملة: {$refundRequest->currency_difference})" : ''),
                    'agent_name' => $booking->agent_name ?: 'System',
                ]);

                Log::info('تم إيداع مبلغ الاسترجاع في الخزينة بنجاح', [
                    'refund_request_id' => $refundRequest->id,
                    'treasury_id' => $treasury->id,
                    'amount' => $refundRequest->refund_amount,
                ]);
            }

            // تحديث حالة الحجز إلى مسترد أو مسترد جزئياً
            $isPartial = $refundRequest->cancellation_fee > 0 || $refundRequest->refund_amount < $refundRequest->original_amount;
            $booking->status = $isPartial ? FlightBookingStatus::PARTIALLY_REFUNDED : FlightBookingStatus::REFUNDED;
            $booking->save();

            // تحديث حالة طلب الاسترجاع
            $refundRequest->status = 'processed';
            $refundRequest->processed_at = now();
            $refundRequest->save();

            return $refundRequest;
        });
    }
}
