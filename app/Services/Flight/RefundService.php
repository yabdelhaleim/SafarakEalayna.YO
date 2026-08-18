<?php

namespace App\Services\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\RefundRequest;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Treasury;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Services\Finance\TransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use App\Services\Flight\FlightBookingService;
use App\Services\Finance\TreasuryLedgerMirror;
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
        protected PrepaidLedgerService $prepaidLedgerService,
        protected FlightBookingService $flightBookingService,
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
     * حل حساب الـ cashbox (Account) المطابق لـ Treasury model.
     * الـ Treasury model (current_balance منفصل) منفصل عن الـ Account.balance.
     * الـ Account.balance هو اللي بيتأثر بالـ GL transactions في عملية الإرجاع.
     *
     * Fallback chain:
     *   1) Account بنفس اسم الـ treasury
     *   2) Account نوعه cashbox بنفس العملة
     *   3) Module vault للطيران
     *   4) إنشاء cashbox جديد تلقائياً
     */
    protected function resolveCashboxAccount(Treasury $treasury, string $currency, int $userId): Account
    {
        $account = Account::where('name', $treasury->name)->first();
        if ($account) {
            return $account;
        }

        $account = Account::where('type', AccountType::Cashbox->value)
            ->where('currency', $currency)
            ->whereIn('module_type', ['flights', 'tourism'])
            ->first();
        if ($account) {
            return $account;
        }

        $account = Account::getModuleVault('flights');
        if ($account) {
            return $account;
        }

        return Account::create([
            'name' => $treasury->name,
            'type' => AccountType::Cashbox,
            'currency' => $currency,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $userId,
        ]);
    }

    /**
     * ضمان وجود حساب محاسبي للعميل — ينسخ من FlightBookingService::ensureCustomerAccount.
     */
    protected function ensureCustomerAccount(int $customerId): Account
    {
        $customer = Customer::findOrFail($customerId);
        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                return $account;
            }
        }

        $account = Account::where('name', 'Customer #'.$customer->id)->first();
        if ($account) {
            $customer->update(['account_id' => $account->id]);
            return $account;
        }

        $account = Account::where('type', AccountType::Customer->value)
            ->where('currency', 'EGP')
            ->first();
        if ($account) {
            $customer->update(['account_id' => $account->id]);
            return $account;
        }

        $account = Account::create([
            'name' => 'Customer #'.$customer->id,
            'type' => AccountType::Customer,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'customer',
            'module_type' => 'tourism',
            'created_by' => $customer->created_by ?? 1,
        ]);
        $customer->update(['account_id' => $account->id]);

        return $account;
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
                // Scenario B: إيداع في خزينة الوكالة (cash refund to agency treasury)
                //
                // الـ Pattern الصحيح (نفس cancelBooking / deleteBookingWithReversal):
                //
                //   Step A — عكس قيد البيع على دفتر العميل:
                //     recordJournalTransfer(customer → clearing, amount=refund_amount)
                //
                //   Step B — إرجاع تكلفة الشراء لرصيد الـ carrier/system/group:
                //     carrier: carrier->credit(purchaseNet) + PrepaidLedgerService::refundCogs('flight_carrier', purchaseNet)
                //     system:  نفس النمط مع 'flight_system'
                //     group:   recordJournalTransfer(expense_contra → group_account, purchaseNet)
                //
                //   Step C — صرف المبلغ من الخزينة النقدية للعميل:
                //     recordJournalTransfer(cashbox → customer, amount=refund_amount, allow_from_negative=true)
                //     + TreasuryLedgerMirror::mirrorFlightOutboundFromCash()
                //     + TreasuryTransaction بنوع debit (صرف من الخزينة)

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

                $bookingCurrency = strtoupper((string) $booking->currency);
                $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
                $refundAmount = (float) $refundRequest->refund_amount;
                $cancellationFee = (float) $refundRequest->cancellation_fee;
                $purchaseEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
                $purchaseNet = max(0.0, $purchaseEgp - $cancellationFee);

                $glTransaction = null;

                // ── Step A: عكس قيد البيع (customer → clearing, amount=refund_amount) ──
                //
                // نعكس الـ sale بـ refund_amount (مش selling_price بالكامل) عشان:
                //   - الـ clearing (income) يرجع لـ -(cancellation_fee) = رسوم الإلغاء المتبقية كإيراد
                //   - هذا pattern متطابق مع cancelBooking في FlightBookingService
                //
                // الـ cancellation_fee المحفوظة هي الفرق بين الـ sale والـ refund:
                //   customer دفع selling_price، استرد refund_amount = selling_price - cancellation_fee
                //   فالباقي (cancellation_fee) يبقى محفوظ في الـ clearing كإيراد (cancellation_fee_revenue)
                if ($booking->sale_gl_transaction_id && $refundAmount > 0) {
                    $orig = Transaction::query()->find($booking->sale_gl_transaction_id);
                    if ($orig && $orig->from_account_id && $orig->to_account_id) {
                        $reversalGl = $this->transactionService->recordJournalTransfer([
                            'amount' => $refundAmount,
                            'from_account_id' => (int) $orig->to_account_id,    // customer (DR — يرجع له رصيد البيع)
                            'to_account_id' => (int) $orig->from_account_id,    // clearing (CR — يمسح الإيراد)
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "عكس قيد مبيعات الحجز ضمن عملية الاسترداد (مخصوماً منه رسوم الإلغاء {$cancellationFee}) — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                        $glTransaction = $reversalGl;

                        Log::info('تم عكس قيد مبيعات الحجز ضمن عملية الاسترداد', [
                            'refund_request_id' => $refundRequest->id,
                            'original_sale_gl_id' => $booking->sale_gl_transaction_id,
                            'reversal_gl_id' => $reversalGl->id,
                            'amount' => $refundAmount,
                            'cancellation_fee_kept' => $cancellationFee,
                        ]);
                    }
                }

                // ── Step B: إرجاع تكلفة الشراء لرصيد الـ carrier/system/group ──
                if ($booking->purchase_balance_source === 'carrier' && $booking->flight_carrier_id) {
                    $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                    if ($carrier && $purchaseNet > 0) {
                        $creditSub = FlightBookingService::purchaseAmountInBalanceCurrency(
                            (string) $carrier->currency,
                            $bookingCurrency,
                            $purchaseNet,
                            null,
                            $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
                        );
                        if ($creditSub > 0) {
                            $carrier->credit(
                                amount: $creditSub,
                                description: 'استرداد حجز — إرجاع رصيد الناقل — حجز #'.$booking->booking_number,
                                userId: $userId,
                                bookingId: $booking->id
                            );
                        }
                    }
                    if ($purchaseNet > 0) {
                        $this->prepaidLedgerService->refundCogs(
                            'flight_carrier',
                            TransactionModule::Flight,
                            $purchaseNet,
                            "استرداد تكلفة حجز {$booking->booking_number} — ناقل",
                            FlightBooking::class,
                            $booking->id
                        );
                    }
                } elseif ($booking->purchase_balance_source === 'system' && $booking->flight_system_id) {
                    $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                    if ($system && $purchaseNet > 0) {
                        $creditSub = FlightBookingService::purchaseAmountInBalanceCurrency(
                            (string) $system->currency,
                            $bookingCurrency,
                            $purchaseNet,
                            null,
                            $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
                        );
                        if ($creditSub > 0) {
                            $system->credit(
                                amount: $creditSub,
                                description: 'استرداد حجز — إرجاع رصيد النظام — حجز #'.$booking->booking_number,
                                userId: $userId,
                                bookingId: $booking->id
                            );
                        }
                    }
                    if ($purchaseNet > 0) {
                        $this->prepaidLedgerService->refundCogs(
                            'flight_system',
                            TransactionModule::Flight,
                            $purchaseNet,
                            "استرداد تكلفة حجز {$booking->booking_number} — نظام",
                            FlightBooking::class,
                            $booking->id
                        );
                    }
                } elseif ($booking->purchase_balance_source === 'group' && $booking->flight_group_id) {
                    $group = FlightGroup::lockForUpdate()->find($booking->flight_group_id);
                    if ($group && $group->account_id && $purchaseNet > 0) {
                        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                        $groupGl = $this->transactionService->recordJournalTransfer([
                            'amount' => $purchaseNet,
                            'from_account_id' => $expenseContraId,
                            'to_account_id' => (int) $group->account_id,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "استرداد تكلفة مجموعة — حجز #{$booking->booking_number} — مجموعة: {$group->name}",
                            'created_by' => $userId,
                        ]);
                        $glTransaction = $groupGl;
                    }
                }

                // ── Step C: صرف المبلغ من الخزينة النقدية للعميل ──
                if ($refundAmount > 0) {
                    $cashboxAccount = $this->resolveCashboxAccount($treasury, $refundRequest->refund_currency, $userId);
                    $customerAccount = $this->ensureCustomerAccount((int) $booking->customer_id);

                    $cashoutGl = $this->transactionService->recordJournalTransfer([
                        'amount' => $refundAmount,
                        'from_account_id' => $cashboxAccount->id,         // cashbox (DR — صرف نقدي)
                        'to_account_id' => $customerAccount->id,           // customer (CR — تسوية دين العميل)
                        'allow_from_negative' => true,                      // الاسترداد تدفق مصرح حتى لو الرصيد بالسالب
                        'module' => TransactionModule::Flight->value,
                        'related_type' => RefundRequest::class,
                        'related_id' => $refundRequest->id,
                        'notes' => "صرف استرداد نقدي للعميل من الخزينة ({$treasury->name}) — طلب #{$refundRequest->id} — حجز #{$booking->booking_number}",
                        'created_by' => $userId,
                    ]);
                    $glTransaction = $cashoutGl;

                    // توثيق حركة الخزينة (audit trail) — debit من الخزينة النقدية
                    $treasuryTransaction = $treasury->transactions()->create([
                        'transaction_type' => 'payment',
                        'amount' => $refundAmount,
                        'currency' => $refundRequest->refund_currency,
                        'balance_before' => $treasury->current_balance + $refundAmount,
                        'balance_after' => $treasury->current_balance,
                        'reason' => 'صرف استرداد تذكرة طيران للعميل',
                        'flight_booking_id' => $booking->id,
                        'refund_request_id' => $refundRequest->id,
                        'type' => 'debit',
                        'exchange_rate' => $refundRequest->refund_exchange_rate,
                        'base_amount' => $refundRequest->base_currency_refund,
                        'description' => "صرف استرداد نقدي لتذكرة #{$booking->booking_number}",
                        'agent_name' => $booking->agent_name ?: 'System',
                    ]);

                    // اربط مع الـ GL transaction + الـ cashbox Account
                    $treasuryTransaction->linkToGl($cashoutGl, $cashboxAccount->id);

                    // Mirror audit في الـ treasury ledger (لا يغير الـ balances — للمراجعة فقط)
                    TreasuryLedgerMirror::mirrorFlightOutboundFromCash(
                        $cashoutGl,
                        $booking->id,
                        "صرف استرداد نقدي للعميل — حجز #{$booking->booking_number}",
                        User::find($userId)?->name ?? 'System'
                    );

                    Log::info('تم صرف مبلغ الاسترداد للعميل من الخزينة', [
                        'refund_request_id' => $refundRequest->id,
                        'treasury_id' => $treasury->id,
                        'amount' => $refundAmount,
                        'cashbox_account_id' => $cashboxAccount->id,
                        'gl_transaction_id' => $cashoutGl->id,
                    ]);
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
                // -- agency_treasury: reverse the 3 steps in inverse order --
                //
                //   Undo Step C (cash-out from cashbox): recordJournalTransfer(customer → cashbox) + treasury_transaction row
                //   Undo Step B (carrier credit-back): carrier->debit(purchaseNet) + consumeCogs('flight_carrier', purchaseNet)
                //                                            أو نفس النمط لـ system/group
                //   Undo Step A (sale GL reversal): recordJournalTransfer(clearing → customer, refund_amount)

                $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);
                $bookingCurrency = strtoupper((string) $booking->currency);
                $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
                $refundAmount = (float) $refundRequest->refund_amount;
                $cancellationFee = (float) $refundRequest->cancellation_fee;
                $purchaseEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
                $purchaseNet = max(0.0, $purchaseEgp - $cancellationFee);

                $treasury = $refundRequest->treasury_id ? Treasury::lockForUpdate()->find($refundRequest->treasury_id) : null;
                $glTransaction = null;

                // ── Undo Step C: عكس صرف الخزينة (customer → cashbox) ──
                if ($treasury && $refundAmount > 0) {
                    $cashboxAccount = $this->resolveCashboxAccount($treasury, $refundRequest->refund_currency, $userId);
                    $customerAccount = $this->ensureCustomerAccount((int) $booking->customer_id);

                    $reverseCashoutGl = $this->transactionService->recordJournalTransfer([
                        'amount' => $refundAmount,
                        'from_account_id' => $customerAccount->id,         // customer (DR)
                        'to_account_id' => $cashboxAccount->id,            // cashbox (CR) — يرجع له الرصيد اللي اتخصم منه الاسترداد
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => RefundRequest::class,
                        'related_id' => $refundRequest->id,
                        'notes' => "عكس صرف استرداد نقدي للعميل — حذف طلب #{$refundRequest->id} — حجز #{$booking->booking_number}",
                        'created_by' => $userId,
                    ]);
                    $glTransaction = $reverseCashoutGl;

                    // ⚠️ لا نعدّل $treasury->current_balance — الـ Treasury model منفصل عن الـ GL Account.
                    // الـ processRefundRequest ما عملش $treasury->credit/debit، فالـ reverse ما يعملش كمان.
                    // التوثيق بيكون عبر TreasuryTransaction (audit row) فقط.

                    $treasuryTransaction = $treasury->transactions()->create([
                        'transaction_type' => 'receipt',
                        'amount' => $refundAmount,
                        'currency' => $refundRequest->refund_currency,
                        'balance_before' => $treasury->current_balance,
                        'balance_after' => $treasury->current_balance,
                        'reason' => 'عكس صرف استرداد تذكرة طيران — حذف طلب #'.$refundRequest->id,
                        'flight_booking_id' => $booking->id,
                        'refund_request_id' => $refundRequest->id,
                        'type' => 'credit',
                        'exchange_rate' => $refundRequest->refund_exchange_rate,
                        'base_amount' => $refundRequest->base_currency_refund,
                        'description' => 'إيداع عكسي لاسترداد نقدي محذوف — طلب #'.$refundRequest->id,
                        'agent_name' => $booking->agent_name ?: 'System',
                    ]);
                    $treasuryTransaction->linkToGl($reverseCashoutGl, $cashboxAccount->id);

                    TreasuryLedgerMirror::mirrorFlightInboundReceipt(
                        $reverseCashoutGl,
                        $booking->id,
                        "عكس صرف استرداد نقدي للعميل — حذف طلب #{$refundRequest->id}",
                        User::find($userId)?->name ?? 'System'
                    );

                    Log::info('RefundService::reverseRefundRequest — GL treasury cashout reversal posted', [
                        'refund_request_id' => $refundRequestId,
                        'gl_transaction_id' => $reverseCashoutGl->id,
                        'amount' => $refundAmount,
                    ]);
                }

                // ── Undo Step B: عكس إرجاع التكلفة (carrier/system/group) ──
                if ($booking->purchase_balance_source === 'carrier' && $booking->flight_carrier_id) {
                    $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                    if ($carrier && $purchaseNet > 0) {
                        $debitSub = FlightBookingService::purchaseAmountInBalanceCurrency(
                            (string) $carrier->currency,
                            $bookingCurrency,
                            $purchaseNet,
                            null,
                            $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
                        );
                        if ($debitSub > 0) {
                            $carrier->debit($debitSub, $booking->id, $userId);
                        }
                    }
                    if ($purchaseNet > 0) {
                        $this->prepaidLedgerService->consumeCogs(
                            'flight_carrier',
                            TransactionModule::Flight,
                            $purchaseNet,
                            "عكس استرداد تكلفة حجز {$booking->booking_number} — ناقل",
                            FlightBooking::class,
                            $booking->id
                        );
                    }
                } elseif ($booking->purchase_balance_source === 'system' && $booking->flight_system_id) {
                    $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                    if ($system && $purchaseNet > 0) {
                        $debitSub = FlightBookingService::purchaseAmountInBalanceCurrency(
                            (string) $system->currency,
                            $bookingCurrency,
                            $purchaseNet,
                            null,
                            $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
                        );
                        if ($debitSub > 0) {
                            $system->debit($debitSub, $booking->id, $userId);
                        }
                    }
                    if ($purchaseNet > 0) {
                        $this->prepaidLedgerService->consumeCogs(
                            'flight_system',
                            TransactionModule::Flight,
                            $purchaseNet,
                            "عكس استرداد تكلفة حجز {$booking->booking_number} — نظام",
                            FlightBooking::class,
                            $booking->id
                        );
                    }
                } elseif ($booking->purchase_balance_source === 'group' && $booking->flight_group_id) {
                    $group = FlightGroup::lockForUpdate()->find($booking->flight_group_id);
                    if ($group && $group->account_id && $purchaseNet > 0) {
                        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                        $reverseGroupGl = $this->transactionService->recordJournalTransfer([
                            'amount' => $purchaseNet,
                            'from_account_id' => (int) $group->account_id,
                            'to_account_id' => $expenseContraId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "عكس استرداد تكلفة مجموعة — حذف طلب #{$refundRequest->id} — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                        $glTransaction = $reverseGroupGl;
                    }
                }

                // ── Undo Step A: إعادة قيد البيع (clearing → customer, amount=refund_amount) ──
                //
                // عكس الـ Step A (اللي عكس البيع بـ refund_amount).
                // فالـ reverse لازم يعيد البيع بـ refund_amount عشان:
                //   - clearing يرجع لقيمته الأصلية (-selling_price)
                //   - customer يرجع لقيمته الأصلية بعد الـ payment (0)
                $saleRestoreAmount = $refundAmount;
                if ($booking->sale_gl_transaction_id && $saleRestoreAmount > 0) {
                    $orig = Transaction::query()->find($booking->sale_gl_transaction_id);
                    if ($orig && $orig->from_account_id && $orig->to_account_id) {
                        $restoreSaleGl = $this->transactionService->recordJournalTransfer([
                            'amount' => $saleRestoreAmount,
                            'from_account_id' => (int) $orig->from_account_id,    // clearing (DR — يرجع لقيمته)
                            'to_account_id' => (int) $orig->to_account_id,        // customer (CR)
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "إعادة قيد مبيعات الحجز بعد حذف طلب الاسترداد #{$refundRequest->id} — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                        $glTransaction = $restoreSaleGl;

                        Log::info('RefundService::reverseRefundRequest — GL sale re-recorded', [
                            'refund_request_id' => $refundRequestId,
                            'flight_booking_id' => $booking->id,
                            'amount' => $saleRestoreAmount,
                        ]);
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
