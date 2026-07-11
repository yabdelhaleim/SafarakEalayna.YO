<?php

namespace App\Services\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\RefundRequest;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Treasury;
use App\Models\Account;
use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Services\Finance\TransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}
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
                // NOTE: الـ ledger_transaction_id + account_id يتم ربطهما بعد إنشاء
                // الـ GL Transaction (في الأسفل) عبر ->linkToGl() — هذه هي الـ GAP
                // اللي تم إغلاقها في هذا الـ commit.
                $treasuryTransaction = $treasury->transactions()->create([
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

                // 1. Resolve corresponding Account for the Treasury
                $account = Account::where('name', $treasury->name)->first();
                if (! $account) {
                    $account = Account::where('type', AccountType::Cashbox->value)
                        ->where('currency', $refundRequest->refund_currency)
                        ->whereIn('module_type', ['flights', 'tourism'])
                        ->first();
                }
                if (! $account) {
                    // Fallback to flights module vault
                    $account = Account::getModuleVault('flights');
                }
                if (! $account) {
                    // Create new Cashbox Account if none exists
                    $account = Account::create([
                        'name' => $treasury->name,
                        'type' => AccountType::Cashbox,
                        'currency' => $refundRequest->refund_currency,
                        'is_active' => true,
                        'owner_type' => 'office',
                        'module_type' => 'flights',
                        'created_by' => $userId,
                    ]);
                }

                // 2. Resolve source prepaid account and decrement balance in GDS/carrier sub-ledger
                $prepaidKey = 'flight_system';
                $debitSubLedgerAmount = $refundRequest->refund_amount;
                if ($booking->purchase_balance_source === 'carrier') {
                    $prepaidKey = 'flight_carrier';
                    if ($booking->flight_carrier_id) {
                        $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                        if ($carrier) {
                            if (strtoupper($carrier->currency) === 'EGP') {
                                $debitSubLedgerAmount = $refundRequest->base_currency_refund;
                            }
                            $carrier->debit($debitSubLedgerAmount, $booking->id, $userId);
                        }
                    }
                } else {
                    if ($booking->flight_system_id) {
                        $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                        if ($system) {
                            if (strtoupper($system->currency) === 'EGP') {
                                $debitSubLedgerAmount = $refundRequest->base_currency_refund;
                            }
                            $system->debit($debitSubLedgerAmount, $booking->id, $userId);
                        }
                    }
                }
                $fromAccountId = $this->clearingAccounts->prepaidAccountId($prepaidKey);

                // 3. Record GL journal entry transfer
                if ($fromAccountId && $account && $fromAccountId !== $account->id) {
                    $transferAmount = $refundRequest->refund_amount;
                    $fromAccount = Account::find($fromAccountId);
                    if (($fromAccount && $fromAccount->currency === 'EGP') || ($account->currency === 'EGP')) {
                        $transferAmount = $refundRequest->base_currency_refund ?? $refundRequest->refund_amount;
                    }
                    $convertedAmount = null;
                    $exchangeRate = null;
                    if ($fromAccount && $fromAccount->currency !== $account->currency) {
                        if ($fromAccount->currency === 'EGP') {
                            $convertedAmount = $refundRequest->refund_amount;
                            $exchangeRate = $refundRequest->refund_exchange_rate;
                        } else {
                            $convertedAmount = $refundRequest->base_currency_refund;
                            $exchangeRate = $refundRequest->refund_exchange_rate;
                        }
                    }

                    $glTransaction = $this->transactionService->recordJournalTransfer([
                        'amount' => $transferAmount,
                        'from_account_id' => $fromAccountId,
                        'to_account_id' => $account->id,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightBooking::class,
                        'related_id' => $booking->id,
                        'notes' => "إيداع استرجاع تذكرة حجز طيران — حجز #{$booking->booking_number}",
                        'created_by' => $userId,
                        'converted_amount' => $convertedAmount,
                        'exchange_rate' => $exchangeRate,
                    ]);

                    Log::info('تم تسجيل القيد المحاسبي المزدوج للاسترداد بنجاح', [
                        'refund_request_id' => $refundRequest->id,
                        'from_account_id' => $fromAccountId,
                        'to_account_id' => $account->id,
                        'amount' => $transferAmount,
                        'gl_transaction_id' => $glTransaction->id,
                    ]);

                    // NEW (2026-07-11): اربط الـ TreasuryTransaction بالـ GL Transaction
                    // عشان نقفل الـ desync (rows بدون ledger_transaction_id كانت orphan).
                    // الـ Treasury::credit() عمل debit لـ Treasury.balance،
                    // والـ recordJournalTransfer عمل credit للـ Account.balance (cashbox).
                    $treasuryTransaction->linkToGl($glTransaction, $account->id);
                }
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

    /**
     * Reverse (delete with full financial reversal) a refund request.
     *
     * Project rule: deleting any financial entity is a combination of:
     *  1) a Soft Delete (preserves the row, hides it from views/reports), and
     *  2) a Full Reversal of every accounting impact (creates new reversal rows
     *     on `transactions` / `account_entries` / `treasury_transactions` — the
     *     ORIGINAL rows are NEVER deleted or modified).
     *
     * Branches:
     *  - `airline_credit`: cancel the linked AirlineCredit voucher (no GL was ever posted).
     *  - `agency_treasury`: reverse (a) the GL transfer prepaid→cashbox, (b) the carrier/system
     *    balance debit, (c) the treasury receipt.
     *
     * Idempotency: throws RuntimeException if already soft-deleted (prevents double-reversal).
     *
     * Note on booking status: the original refund set $booking->status to REFUNDED /
     * PARTIALLY_REFUNDED. This method does NOT revert that — the original refund still
     * happened. Manual status management is recommended if there was only one refund.
     *
     * @throws \RuntimeException if already deleted, or if booking/carrier is missing
     */
    public function reverseRefundRequest(int $refundRequestId, int $userId): RefundRequest
    {
        return DB::transaction(function () use ($refundRequestId, $userId) {
            // Use withTrashed() so an already-soft-deleted refund can be located —
            // we want a clean idempotency error, not "No query results".
            $refundRequest = RefundRequest::withTrashed()
                ->lockForUpdate()
                ->findOrFail($refundRequestId);

            if ($refundRequest->trashed()) {
                throw new \RuntimeException(
                    'هذا الطلب محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                );
            }

            Log::info('RefundService::reverseRefundRequest — starting', [
                'refund_request_id' => $refundRequestId,
                'destination' => $refundRequest->destination,
                'status' => $refundRequest->status,
                'user_id' => $userId,
            ]);

            // If not yet processed — just soft-delete (no GL impact to reverse)
            if ($refundRequest->status !== 'processed') {
                $refundRequest->delete();
                Log::info('RefundService::reverseRefundRequest — was unprocessed, soft-deleted only', [
                    'refund_request_id' => $refundRequestId,
                    'user_id' => $userId,
                ]);
                return $refundRequest;
            }

            if ($refundRequest->destination === 'airline_credit') {
                // -- Cancel the AirlineCredit voucher (no GL reversal needed) --
                $credit = $refundRequest->airlineCredit()->first();
                if ($credit && ! $credit->trashed() && $credit->status !== 'cancelled') {
                    $credit->cancelCredit();
                    Log::info('RefundService::reverseRefundRequest — AirlineCredit cancelled', [
                        'airline_credit_id' => $credit->id,
                    ]);
                }
            } else {
                // -- agency_treasury: reverse GL + carrier/system debit + treasury receipt --

                $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);
                $prepaidKey = 'flight_system';
                $debitSubLedgerAmount = (float) $refundRequest->refund_amount;

                // (a) Reverse the FlightCarrier/System debit (credit back)
                if ($booking->purchase_balance_source === 'carrier' && $booking->flight_carrier_id) {
                    $prepaidKey = 'flight_carrier';
                    $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                    if ($carrier) {
                        if (strtoupper($carrier->currency) === 'EGP') {
                            $debitSubLedgerAmount = (float) $refundRequest->base_currency_refund;
                        }
                        $carrier->credit(
                            amount: $debitSubLedgerAmount,
                            description: 'عكس خصم ناقل — حذف طلب استرداد #'.$refundRequest->id,
                            userId: $userId,
                            bookingId: $booking->id,
                        );
                    }
                } elseif ($booking->flight_system_id) {
                    $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                    if ($system) {
                        if (strtoupper($system->currency) === 'EGP') {
                            $debitSubLedgerAmount = (float) $refundRequest->base_currency_refund;
                        }
                        $system->credit(
                            amount: $debitSubLedgerAmount,
                            description: 'عكس خصم نظام — حذف طلب استرداد #'.$refundRequest->id,
                            userId: $userId,
                            bookingId: $booking->id,
                        );
                    }
                }

                // (b) Reverse the GL journal transfer (cashbox → prepaid, opposite direction)
                //     We re-resolve the destination Account the same way processRefundRequest does.
                $treasury = $refundRequest->treasury_id ? Treasury::lockForUpdate()->find($refundRequest->treasury_id) : null;
                $account = $treasury ? Account::where('name', $treasury->name)->first() : null;
                if (! $account) {
                    $account = Account::where('type', AccountType::Cashbox->value)
                        ->where('currency', $refundRequest->refund_currency)
                        ->whereIn('module_type', ['flights', 'tourism'])
                        ->first();
                }
                if (! $account) {
                    $account = Account::getModuleVault('flights');
                }

                $fromAccountId = $this->clearingAccounts->prepaidAccountId($prepaidKey);

                if ($fromAccountId && $account && $fromAccountId !== $account->id) {
                    // Mirror the conversion logic in processRefundRequest
                    $transferAmount = (float) $refundRequest->refund_amount;
                    $fromAccount = Account::find($fromAccountId);
                    if (($fromAccount && $fromAccount->currency === 'EGP') || ($account->currency === 'EGP')) {
                        $transferAmount = (float) ($refundRequest->base_currency_refund ?? $refundRequest->refund_amount);
                    }
                    $convertedAmount = null;
                    $exchangeRate = null;
                    if ($fromAccount && $fromAccount->currency !== $account->currency) {
                        if ($fromAccount->currency === 'EGP') {
                            $convertedAmount = (float) $refundRequest->refund_amount;
                            $exchangeRate = (float) $refundRequest->refund_exchange_rate;
                        } else {
                            $convertedAmount = (float) $refundRequest->base_currency_refund;
                            $exchangeRate = (float) $refundRequest->refund_exchange_rate;
                        }
                    }

                    // REVERSE: original was prepaid → cashbox; here cashbox → prepaid
                    $glTransaction = $this->transactionService->recordJournalTransfer([
                        'amount' => $transferAmount,
                        'from_account_id' => $account->id,
                        'to_account_id' => $fromAccountId,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => RefundRequest::class,
                        'related_id' => $refundRequest->id,
                        'notes' => 'عكس قيد استرداد — حذف طلب #'.$refundRequest->id.
                                   ' — حجز #'.$refundRequest->flight_booking_id,
                        'created_by' => $userId,
                        'converted_amount' => $convertedAmount,
                        'exchange_rate' => $exchangeRate,
                    ]);

                    Log::info('RefundService::reverseRefundRequest — GL reversal posted', [
                        'refund_request_id' => $refundRequestId,
                        'gl_transaction_id' => $glTransaction->id,
                    ]);
                }

                // (c) Reverse the Treasury receipt (debit the treasury + create compensating tx)
                if ($treasury) {
                    $amount = (float) $refundRequest->refund_amount;
                    $treasury->debit($amount);

                    // توثيق الحركة المالية العكسية
                    // NOTE: الـ ledger_transaction_id + account_id يتم ربطهما عبر ->linkToGl()
                    // بعد ما الـ GL Transaction يتعمل في الأعلى.
                    $treasuryTransaction = $treasury->transactions()->create([
                        'transaction_type' => 'debit',
                        'amount' => $amount,
                        'currency' => $refundRequest->refund_currency,
                        'balance_before' => $treasury->current_balance + $amount,
                        'balance_after' => $treasury->current_balance,
                        'reason' => 'عكس استرجاع تذكرة طيران — طلب #'.$refundRequest->id,
                        'flight_booking_id' => $refundRequest->flight_booking_id,
                        'refund_request_id' => $refundRequest->id,
                        'type' => 'debit',
                        'exchange_rate' => $refundRequest->refund_exchange_rate,
                        'base_amount' => $refundRequest->base_currency_refund,
                        'description' => 'عكس قيد طلب استرداد #'.$refundRequest->id.' (مرتجع)',
                        'agent_name' => $booking->agent_name ?: 'System',
                    ]);

                    // NEW (2026-07-11): اربط الـ TreasuryTransaction بالـ GL Transaction
                    if (isset($glTransaction)) {
                        $treasuryTransaction->linkToGl($glTransaction, $account->id ?? null);
                    }
                }
            }

            // 4) Soft delete the refund request itself
            $refundRequest->delete();

            Log::info('RefundService::reverseRefundRequest — complete', [
                'refund_request_id' => $refundRequestId,
                'destination' => $refundRequest->destination,
                'user_id' => $userId,
            ]);

            return $refundRequest;
        });
    }
}
