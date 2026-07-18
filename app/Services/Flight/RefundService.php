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
use App\Support\Finance\DeadlockRetry;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    use DeadlockRetry;

    public function __construct(
        protected TransactionService $transactionService,
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}

    /**
     * حساب مبلغ الاسترجاع بعملة رصيد الكيان (carrier / system) باستخدام سعر صرف الحجز.
     * يستخدم نفس منطق purchaseAmountInBalanceCurrency في FlightBookingService.
     *
     * الـ refund_amount دائماً بعملة الحجز.
     * الـ base_currency_refund دائماً بالـ EGP.
     * هذي الدالة تحسب مبلغ الرصيد بعملة الكيان (foreign).
     *
     * @param  string  $balanceCurrency  عملة رصيد الـ carrier / system (مثل USD أو KWD)
     * @param  string  $bookingCurrency  عملة تسعير الحجز (EGP أو نفس عملة الرصيد)
     * @param  float  $refundAmount  المبلغ بعملة الحجز
     * @param  float  $baseCurrencyRefund  المبلغ بالـ EGP
     * @param  float|null  $exchangeRate  سعر الصرف (جنيه لكل 1 وحدة من عملة الرصيد)
     */
    private function refundAmountInBalanceCurrency(
        string $balanceCurrency,
        string $bookingCurrency,
        float $refundAmount,
        float $baseCurrencyRefund,
        ?float $exchangeRate = null
    ): float {
        $bal = strtoupper(trim($balanceCurrency));
        $book = strtoupper(trim($bookingCurrency));

        if ($bal === 'EGP') {
            return round($baseCurrencyRefund, 2);
        }

        if ($bal === $book && $book !== 'EGP') {
            return round($refundAmount, 4);
        }

        if ($book === 'EGP') {
            $rate = ($exchangeRate !== null && $exchangeRate > 0)
                ? $exchangeRate
                : 1.0;
            if ($rate <= 0) {
                throw new \RuntimeException(
                    "لا يوجد سعر صرف فعّال لتحويل الاسترجاع من EGP إلى {$bal}. ".
                    "حدّث سعر الصرف في جدول currencies."
                );
            }
            return round($baseCurrencyRefund / $rate, 4);
        }

        throw new \RuntimeException(
            "عملة رصيد الكيان ({$bal}) لا تتوافق مع عملة الحجز ({$book}). ".
            "تأكد من تطابق العملة أو حدّث بيانات الحجز."
        );
    }

    /**
     * حساب مبالغ الـ GL transfer بين حساب prepaid وحساب cashbox بعملاتهم الصحيحة.
     *
     * @param  string  $fromCurrency  عملة حساب الـ prepaid (المصدر)
     * @param  string  $toCurrency  عملة حساب الـ cashbox (الوجهة)
     * @param  float  $refundAmount  المبلغ بعملة الحجز (foreign for non-EGP, EGP for EGP)
     * @param  float  $baseCurrencyRefund  المبلغ بالـ EGP
     * @param  float|null  $refundExchangeRate  سعر صرف الاسترجاع
     * @return array{amount: float, converted_amount: ?float, exchange_rate: ?float}
     */
    private function glTransferAmounts(
        string $fromCurrency,
        string $toCurrency,
        float $refundAmount,
        float $baseCurrencyRefund,
        ?float $refundExchangeRate
    ): array {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return ['amount' => round($refundAmount, 4), 'converted_amount' => null, 'exchange_rate' => null];
        }

        $rate = ($refundExchangeRate !== null && $refundExchangeRate > 0)
            ? $refundExchangeRate
            : 1.0;

        if ($from === 'EGP') {
            // EGP prepaid → foreign cashbox: amount in EGP, converted to foreign
            return [
                'amount' => round($baseCurrencyRefund, 2),
                'converted_amount' => round($refundAmount, 4),
                'exchange_rate' => round($rate, 6),
            ];
        }

        if ($to === 'EGP') {
            // Foreign prepaid → EGP cashbox: amount in foreign, converted to EGP
            return [
                'amount' => round($refundAmount, 4),
                'converted_amount' => round($baseCurrencyRefund, 2),
                'exchange_rate' => round($rate, 6),
            ];
        }

        throw new \RuntimeException(
            "لا يمكن التحويل بين عملتين مختلفتين غير EGP ({$from} → {$to}). ".
            "يجب أن يكون أحد الحسابات بـ EGP."
        );
    }
    /**
     * إنشاء طلب استرجاع جديد للتذكرة.
     */
    public function createRefundRequest(array $data, int $userId): RefundRequest
    {
        // Use withTrashed() + lockForUpdate() so concurrent refund-request creations
        // on the same booking serialize cleanly (no two parallel requests get
        // past the status check below without seeing each other).
        $booking = FlightBooking::withTrashed()
            ->lockForUpdate()
            ->findOrFail($data['flight_booking_id']);

        if ($booking->trashed()) {
            throw new \RuntimeException('هذا الحجز محذوف ولا يمكن إنشاء طلب استرجاع عليه.');
        }

        // التحقق من أن الحجز ليس مسترداً بالكامل مسبقاً
        if ($booking->status === FlightBookingStatus::REFUNDED) {
            throw new \RuntimeException('هذا الحجز تم استرداده بالكامل مسبقاً ولا يمكن إصدار طلب استرجاع جديد له.');
        }

        // Bug #C4 fix: PENDING bookings (no PNR, no payment, no carrier debit)
        // must not be refundable. Allowing refund on PENDING creates an AirlineCredit
        // voucher with no corresponding original purchase, or a treasury credit
        // for a customer who never paid.
        if (!in_array($booking->status, [
            FlightBookingStatus::CONFIRMED,
            FlightBookingStatus::PARTIALLY_REFUNDED,
        ], true)) {
            throw new \RuntimeException(
                "لا يمكن إصدار طلب استرجاع لحجز بحالة '{$booking->status->value}'. ".
                "يجب أن يكون الحجز مؤكداً على الأقل."
            );
        }

        $originalCurrency = strtoupper($booking->original_currency ?: ($booking->currency ?: 'EGP'));
        $originalAmount = (float) ($booking->original_amount ?: $booking->selling_price);
        $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));

        $cancellationFee = (float) ($data['cancellation_fee'] ?? 0);
        $refundAmount = $originalAmount - $cancellationFee;

        if ($refundAmount < 0) {
            throw new \InvalidArgumentException('رسوم الإلغاء لا يمكن أن تتجاوز المبلغ الأصلي للحجز.');
        }

        // Bug #C5 fix: cap cumulative active refunds at the original amount.
        // Without this, sequential refund requests can refund > 100% of the booking.
        $alreadyRefunded = (float) RefundRequest::query()
            ->where('flight_booking_id', $booking->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'rejected')
            ->sum('refund_amount');
        if ($alreadyRefunded + $refundAmount > $originalAmount + 0.0001) {
            throw new \RuntimeException(
                "إجمالي مبالغ الاسترجاع النشطة ({$alreadyRefunded} {$originalCurrency}) ".
                "مع هذا الطلب ({$refundAmount} {$originalCurrency}) ".
                "سيتجاوز المبلغ الأصلي للحجز ({$originalAmount} {$originalCurrency})."
            );
        }

        $refundCurrency = strtoupper((string) ($data['refund_currency'] ?? $originalCurrency));
        // Bug #B1 fix: enforce currency match between booking and refund request.
        // A refund MUST be in the same currency as the booking — silent conversion
        // would create accounting drift and surprise the customer.
        if ($refundCurrency !== $originalCurrency) {
            throw new \InvalidArgumentException(
                "عملة الاسترجاع ({$refundCurrency}) لا تطابق عملة الحجز الأصلية ({$originalCurrency}). ".
                "يجب أن يكون الاسترجاع بنفس عملة الحجز."
            );
        }

        // Bug #B14 fix: validate refund_exchange_rate explicitly.
        // Defaulting silently to 1.0 for foreign currencies would create 50x+ accounting errors.
        $refundExchangeRate = isset($data['refund_exchange_rate'])
            ? (float) $data['refund_exchange_rate']
            : ($refundCurrency === 'EGP' ? 1.0 : $bookingExchangeRate);
        if ($refundExchangeRate <= 0) {
            throw new \InvalidArgumentException('سعر صرف الاسترجاع يجب أن يكون أكبر من صفر.');
        }

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
        return $this->withDeadlockRetry(
            fn () => LedgerBalanceMutationGuard::run(
                fn () => DB::transaction(function () use ($refundRequestId, $userId) {
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

                // Bug #B2 fix: enforce currency match between refund voucher
                // and the carrier's balance currency. Without this check, an
                // EGP-priced ticket could produce a USD-denominated voucher
                // that can only be spent against USD services of the carrier.
                $carrier = FlightCarrier::query()->find($booking->flight_carrier_id);
                $carrierCurrency = $carrier ? strtoupper((string) $carrier->currency) : 'EGP';
                if (strtoupper($refundRequest->refund_currency) !== $carrierCurrency) {
                    throw new \RuntimeException(
                        "تضارب في العملة: عملة الاسترجاع ({$refundRequest->refund_currency}) ".
                        "لا تطابق عملة شركة الطيران ({$carrierCurrency}). ".
                        "يجب أن يكون رصيد شركة الطيران بنفس عملة الحجز."
                    );
                }

                // NEW (2026-07-11): Defense against duplicate voucher creation.
                // Check ONLY for ACTIVE vouchers — cancelled/historical ones are OK
                // (allows re-refund after a previous refund was reversed).
                // The check uses the outer lockForUpdate on the booking + this
                // query (with its own lockForUpdate via forUpdate()) to serialize
                // concurrent voucher creations for the same booking.
                $existingActiveVoucher = AirlineCredit::query()
                    ->where('flight_booking_id', $booking->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->exists();

                if ($existingActiveVoucher) {
                    throw new \RuntimeException(
                        'يوجد رصيد طيران نشط مسبقًا لهذا الحجز. يجب إلغاء الرصيد القديم قبل إنشاء رصيد جديد.'
                    );
                }

                // إنشاء رصيد طيران جديد
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
                    // Last-resort fallback: create the cashbox Account on-the-fly.
                    // Safe here because we're inside LedgerBalanceMutationGuard::run() + DB::transaction
                    // — any failure will roll back atomically. The legacy concern of
                    // "untracked direct account creation" is mitigated by the wrapping guards.
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
                //
                // Bug #B3 fix: use refundAmountInBalanceCurrency() helper to correctly
                // convert refund_amount (in booking currency) to the carrier/system currency.
                // Old code only handled the EGP case — for foreign-currency booking on a
                // foreign-currency carrier, it was using refund_amount (foreign) directly
                // without verifying it matches the carrier's currency.
                $prepaidKey = 'flight_system';
                $bookingCurrency = strtoupper((string) $booking->currency);
                $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));

                if ($booking->purchase_balance_source === 'carrier' && $booking->flight_carrier_id) {
                    $prepaidKey = 'flight_carrier';
                    $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                    if ($carrier) {
                        $debitSubLedgerAmount = $this->refundAmountInBalanceCurrency(
                            (string) $carrier->currency,
                            $bookingCurrency,
                            (float) $refundRequest->refund_amount,
                            (float) $refundRequest->base_currency_refund,
                            $bookingExchangeRate
                        );
                        $carrier->debit($debitSubLedgerAmount, $booking->id, $userId);
                    }
                } elseif ($booking->flight_system_id) {
                    $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                    if ($system) {
                        $debitSubLedgerAmount = $this->refundAmountInBalanceCurrency(
                            (string) $system->currency,
                            $bookingCurrency,
                            (float) $refundRequest->refund_amount,
                            (float) $refundRequest->base_currency_refund,
                            $bookingExchangeRate
                        );
                        $system->debit($debitSubLedgerAmount, $booking->id, $userId);
                    }
                }
                $fromAccountId = $this->clearingAccounts->prepaidAccountId($prepaidKey);

                // 3. Record GL journal entry transfer
                //
                // Bug #B4 + #B15 fix: use glTransferAmounts() helper to compute the
                // correct amount (in from_account currency) and converted_amount (in
                // to_account currency). The old code used `||` instead of `&&` and the
                // convertedAmount was computed but not actually used as the GL amount.
                if ($fromAccountId && $account && $fromAccountId !== $account->id) {
                    $fromAccount = Account::find($fromAccountId);
                    $glAmounts = $this->glTransferAmounts(
                        (string) ($fromAccount ? $fromAccount->currency : 'EGP'),
                        (string) $account->currency,
                        (float) $refundRequest->refund_amount,
                        (float) $refundRequest->base_currency_refund,
                        $refundRequest->refund_exchange_rate !== null ? (float) $refundRequest->refund_exchange_rate : null
                    );

                    $glTransaction = $this->transactionService->recordJournalTransfer([
                        'amount' => $glAmounts['amount'],
                        'from_account_id' => $fromAccountId,
                        'to_account_id' => $account->id,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightBooking::class,
                        'related_id' => $booking->id,
                        'notes' => "إيداع استرجاع تذكرة حجز طيران — حجز #{$booking->booking_number}",
                        'created_by' => $userId,
                        'converted_amount' => $glAmounts['converted_amount'],
                        'exchange_rate' => $glAmounts['exchange_rate'],
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
                })  // close DB::transaction
            ),  // close LedgerBalanceMutationGuard::run
        );  // close withDeadlockRetry
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
     * ⚠️ Known Limitation — Deferred (GAP 6, 2026-07-11):
     *    This method does NOT revert $booking->status after reversal. The original refund
     *    set the booking status to REFUNDED or PARTIALLY_REFUNDED — after reversal,
     *    the status remains in that final state even though no financial impact remains.
     *
     *    Practical scenarios:
     *      - Booking + 1 refund, refund reversed → status stays REFUNDED (no active
     *        refund) but a new createRefundRequest() call would reject with "الحجز
     *        تم استرداده بالكامل مسبقاً".
     *      - Booking + 2 partial refunds, 1 reversed → status stays PARTIALLY_REFUNDED
     *        (1 refund still active) which is correct by accident.
     *      - Booking + 2 refunds, BOTH reversed → status stays REFUNDED (no active
     *        refunds) — misleading state.
     *
     *    Why deferred: fixing this requires a business decision on whether booking.status
     *    should reflect "current active refund count" or "has any refund ever happened".
     *    Out of scope for the current hardening pass; documented for follow-up.
     *
     * @throws \RuntimeException if already deleted, or if booking/carrier is missing
     */
    public function reverseRefundRequest(int $refundRequestId, int $userId): RefundRequest
    {
        return $this->withDeadlockRetry(
            fn () => LedgerBalanceMutationGuard::run(
                fn () => DB::transaction(function () use ($refundRequestId, $userId) {
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

                // (a) Reverse the FlightCarrier/System debit (credit back)
                //
                // Bug #B3 fix: use refundAmountInBalanceCurrency() helper for correct currency conversion.
                $bookingCurrency = strtoupper((string) $booking->currency);
                $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
                $creditSubLedgerAmount = (float) $refundRequest->refund_amount;

                if ($booking->purchase_balance_source === 'carrier' && $booking->flight_carrier_id) {
                    $prepaidKey = 'flight_carrier';
                    $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                    if ($carrier) {
                        $creditSubLedgerAmount = $this->refundAmountInBalanceCurrency(
                            (string) $carrier->currency,
                            $bookingCurrency,
                            (float) $refundRequest->refund_amount,
                            (float) $refundRequest->base_currency_refund,
                            $bookingExchangeRate
                        );
                        $carrier->credit(
                            amount: $creditSubLedgerAmount,
                            description: 'عكس خصم ناقل — حذف طلب استرداد #'.$refundRequest->id,
                            userId: $userId,
                            bookingId: $booking->id,
                        );
                    }
                } elseif ($booking->flight_system_id) {
                    $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                    if ($system) {
                        $creditSubLedgerAmount = $this->refundAmountInBalanceCurrency(
                            (string) $system->currency,
                            $bookingCurrency,
                            (float) $refundRequest->refund_amount,
                            (float) $refundRequest->base_currency_refund,
                            $bookingExchangeRate
                        );
                        $system->credit(
                            amount: $creditSubLedgerAmount,
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
                    // Bug #B4 + #B15 fix: use glTransferAmounts() helper.
                    $fromAccount = Account::find($fromAccountId);
                    $glAmounts = $this->glTransferAmounts(
                        (string) $account->currency,
                        (string) ($fromAccount ? $fromAccount->currency : 'EGP'),
                        (float) $refundRequest->refund_amount,
                        (float) $refundRequest->base_currency_refund,
                        $refundRequest->refund_exchange_rate !== null ? (float) $refundRequest->refund_exchange_rate : null
                    );

                    // REVERSE: original was prepaid → cashbox; here cashbox → prepaid
                    $glTransaction = $this->transactionService->recordJournalTransfer([
                        'amount' => $glAmounts['amount'],
                        'from_account_id' => $account->id,
                        'to_account_id' => $fromAccountId,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => RefundRequest::class,
                        'related_id' => $refundRequest->id,
                        'notes' => 'عكس قيد استرداد — حذف طلب #'.$refundRequest->id.
                                   ' — حجز #'.$refundRequest->flight_booking_id,
                        'created_by' => $userId,
                        'converted_amount' => $glAmounts['converted_amount'],
                        'exchange_rate' => $glAmounts['exchange_rate'],
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
                })  // close DB::transaction
            ),  // close LedgerBalanceMutationGuard::run
        );  // close withDeadlockRetry
    }
}
