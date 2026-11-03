<?php

namespace App\Services\Flight;

use App\Enums\AccountType;
use App\Enums\CustomerType;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Exceptions\BusinessLogicException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\CustomerCreditDisbursement;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightPaymentAllocation;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use App\Services\Finance\RefundAuditLogger;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryLedgerMirror;
use App\Support\Finance\DeadlockRetry;
use App\Support\Finance\LedgerBalanceMutationGuard;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\Auth;
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
        protected FlightPaymentAllocationService $paymentAllocationService,
    ) {}

    /**
     * حساب مبلغ الاسترجاع بعملة رصيد الكيان (carrier / system) باستخدام سعر صرف الحجز.
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
     * حساب مبالغ الـ GL transfer بين حسابين بعملاتهم الصحيحة.
     *
     * @return array{amount: float, converted_amount: ?float, exchange_rate: ?float}
     */
    private function glTransferAmounts(
        string $fromCurrency,
        string $toCurrency,
        float $amount,
        float $baseCurrencyAmount,
        ?float $exchangeRate
    ): array {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return ['amount' => round($amount, 4), 'converted_amount' => null, 'exchange_rate' => null];
        }

        $rate = ($exchangeRate !== null && $exchangeRate > 0)
            ? $exchangeRate
            : 1.0;

        if ($from === 'EGP') {
            return [
                'amount' => round($baseCurrencyAmount, 2),
                'converted_amount' => round($amount, 4),
                'exchange_rate' => round($rate, 6),
            ];
        }

        if ($to === 'EGP') {
            return [
                'amount' => round($amount, 4),
                'converted_amount' => round($baseCurrencyAmount, 2),
                'exchange_rate' => round($rate, 6),
            ];
        }

        throw new \RuntimeException(
            "لا يمكن التحويل بين عملتين مختلفتين غير EGP ({$from} → {$to}). ".
            "يجب أن يكون أحد الحسابات بـ EGP."
        );
    }

    /**
     * مبلغ استرداد المورد بعملة رصيد الناقل/النظام.
     *
     * - رصيد الناقل بنفس عملة الحجز → المبلغ كما هو (عملة الحجز).
     * - رصيد الناقل بالجنيه المصري → المكافئ بالجنيه.
     * - رصيد الناقل بعملة أخرى → تحويل من الجنيه إلى عملة الناقل.
     *
     * @param float $amountInRefundCurrency المبلغ بعملة الاسترجاع (عملة الحجز)
     * @param float $amountInEgp المكافئ بالجنيه المصري
     */
    protected function supplierAmountInBalanceCurrency(
        string $balanceCurrency,
        string $refundCurrency,
        float $amountInRefundCurrency,
        float $amountInEgp,
        ?float $egpPerBalanceUnit
    ): float {
        $bal = strtoupper(trim($balanceCurrency));
        $ref = strtoupper(trim($refundCurrency));

        if ($bal === 'EGP') {
            return round($amountInEgp, 2);
        }

        if ($bal === $ref) {
            return round($amountInRefundCurrency, 4);
        }

        if ($egpPerBalanceUnit !== null && $egpPerBalanceUnit > 0) {
            return round($amountInEgp / $egpPerBalanceUnit, 4);
        }

        return 0.0;
    }

    /**
     * سعر صرف عملة الاسترجاع: جنيه مصري لكل وحدة من عملة الاسترجاع.
     * عملة EGP تعني السعر = 1.
     */
    protected function egpPerRefundUnit(RefundRequest $refundRequest): float
    {
        $currency = strtoupper(trim((string) ($refundRequest->refund_currency ?: 'EGP')));
        if ($currency === 'EGP') {
            return 1.0;
        }

        $rate = (float) ($refundRequest->refund_exchange_rate ?: 0.0);

        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * حل حساب الـ cashbox (Account) المطابق لـ Treasury model.
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
     * ضمان وجود حساب محاسبي للعميل.
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
            'module_type' => 'flights',
            'created_by' => $customer->created_by ?? 1,
        ]);
        $customer->update(['account_id' => $account->id]);

        return $account;
    }

    /**
     * تحديد معرف الشركة للعميل (إذا كان العميل شركة أو تابع لشركة).
     */
    public function resolveCompanyId(FlightBooking $booking): ?int
    {
        $customer = $booking->customer;
        if (! $customer) {
            return null;
        }
        if (isset($customer->type) && ($customer->type === CustomerType::Company || $customer->type?->value === 'company')) {
            return $customer->id;
        }
        if (! empty($customer->company_id)) {
            return (int) $customer->company_id;
        }
        return null;
    }

    /**
     * حساب وتحديد بارامترات الاسترداد وفق الحالات الثلاث:
     * - الحالة الأولى: إلغاء اختياري من العميل (غرامة طيران + عمولة وكالة).
     * - الحالة الثانية: إلغاء الرحلة من الطيران (استرداد كامل/ناقص ضرائب + عمولة اختيارية + ضرائب على العميل أو الوكالة).
     * - الحالة الافتراضية / التوافق القديم.
     *
     * @return array<string, mixed>
     */
    public function calculateRefundParameters(FlightBooking $booking, array $data): array
    {
        $originalCurrency = strtoupper($booking->original_currency ?: ($booking->currency ?: 'EGP'));

        if ($originalCurrency === 'EGP') {
            $sellingPrice = (float) $booking->selling_price;
            $supplierCost = (float) ($booking->purchase_price_egp ?: $booking->purchase_price);
        } else {
            $sellingPrice = (float) ($booking->original_amount ?: (
                $booking->selling_price_foreign > 0
                    ? $booking->selling_price_foreign
                    : $booking->selling_price
            ));
            $supplierCost = (float) ($booking->purchase_price_foreign ?: $booking->purchase_price);
        }

        $refundCase = $data['refund_case'] ?? null;
        if (! $refundCase) {
            if (isset($data['airline_penalty']) && (float) $data['airline_penalty'] > 0) {
                $refundCase = 'customer_voluntary';
            } elseif (isset($data['non_refundable_taxes_on_customer']) || isset($data['airline_refund_amount'])) {
                $refundCase = 'airline_cancelled';
            } else {
                $refundCase = 'default';
            }
        }

        $airlinePenalty = 0.0;
        $agencyCommission = 0.0;
        $applyCommission = false;
        $nonRefundableTaxesOnCustomer = false;
        $nonRefundableTaxesAmount = 0.0;
        $supplierRefundAmount = $supplierCost;
        $supplierRefundLoss = 0.0;

        if ($refundCase === 'customer_voluntary') {
            // الحالة الأولى: العميل هو الذي يلغي (غرامة الطيران يتحملها العميل بالكامل)
            $airlinePenalty = isset($data['airline_penalty']) ? (float) $data['airline_penalty'] : 0.0;
            $agencyCommission = isset($data['agency_commission'])
                ? (float) $data['agency_commission']
                : (isset($data['cancellation_fee']) ? (float) $data['cancellation_fee'] : 0.0);

            if ($airlinePenalty < 0) {
                throw new \InvalidArgumentException('غرامة شركة الطيران لا يمكن أن تكون سالبة.');
            }
            if ($agencyCommission < 0) {
                throw new \InvalidArgumentException('عمولة الوكالة لا يمكن أن تكون سالبة.');
            }
            if ($airlinePenalty + $agencyCommission > $sellingPrice + 0.001) {
                throw new \InvalidArgumentException(
                    "مجموع غرامة الطيران والعمولة (".($airlinePenalty + $agencyCommission).") لا يمكن أن يتجاوز سعر البيع ({$sellingPrice})."
                );
            }

            // المسترد المتوقع من الطيران للمورد = تكلفة التذكرة - airline_penalty
            $supplierRefundAmount = max(0.0, round($supplierCost - $airlinePenalty, 2));
            $supplierRefundLoss = 0.0; // الغرامة على العميل بالكامل، وليست خسارة علينا

            // المسترد للعميل = سعر البيع - airline_penalty - agency_commission
            $netCustomerRefund = max(0.0, round($sellingPrice - $airlinePenalty - $agencyCommission, 2));
        } elseif ($refundCase === 'airline_cancelled') {
            // الحالة الثانية: الطيران هو الذي ألغى الرحلة
            $airlineRefundAmount = isset($data['airline_refund_amount'])
                ? (float) $data['airline_refund_amount']
                : (isset($data['supplier_refund_amount']) ? (float) $data['supplier_refund_amount'] : $supplierCost);

            // البند 7 (2026-11-03): لا يمكن أن يسترد المورد أكثر من تكلفة التذكرة.
            if ($airlineRefundAmount > $supplierCost + 0.001) {
                throw new \InvalidArgumentException(
                    "المبلغ المسترد من شركة الطيران ({$airlineRefundAmount}) لا يمكن أن يتجاوز تكلفة التذكرة الأصلية ({$supplierCost})."
                );
            }

            $applyCommission = (bool) ($data['apply_commission'] ?? false);
            $agencyCommission = $applyCommission
                ? (isset($data['agency_commission']) ? (float) $data['agency_commission'] : (isset($data['cancellation_fee']) ? (float) $data['cancellation_fee'] : 0.0))
                : 0.0;

            $nonRefundableTaxesOnCustomer = (bool) ($data['non_refundable_taxes_on_customer'] ?? false);
            $nonRefundableTaxesAmount = isset($data['non_refundable_taxes_amount'])
                ? (float) $data['non_refundable_taxes_amount']
                : max(0.0, round($supplierCost - $airlineRefundAmount, 2));

            $supplierRefundAmount = max(0.0, round($airlineRefundAmount, 2));

            if ($nonRefundableTaxesOnCustomer) {
                // الضرائب غير المستردة يتحملها العميل
                $netCustomerRefund = max(0.0, round($sellingPrice - $agencyCommission - $nonRefundableTaxesAmount, 2));
                $supplierRefundLoss = 0.0;
            } else {
                // الضرائب غير المستردة تتحملها الوكالة وتسجل في خسائر استرداد
                $netCustomerRefund = max(0.0, round($sellingPrice - $agencyCommission, 2));
                $supplierRefundLoss = round($nonRefundableTaxesAmount, 2);
            }
        } else {
            // الوضع الافتراضي / التوافق السابق
            $cancellationFee = isset($data['cancellation_fee']) ? (float) $data['cancellation_fee'] : 0.0;
            if ($cancellationFee < 0) {
                throw new \InvalidArgumentException('عمولة الإلغاء لا يمكن أن تكون سالبة.');
            }
            if ($cancellationFee > $sellingPrice + 0.001) {
                throw new \InvalidArgumentException(
                    "عمولة الإلغاء ({$cancellationFee}) لا يمكن أن تتجاوز سعر البيع الأصلي ({$sellingPrice})."
                );
            }
            $agencyCommission = $cancellationFee;
            $supplierRefundAmount = isset($data['supplier_refund_amount'])
                ? (float) $data['supplier_refund_amount']
                : $supplierCost;
            if ($supplierRefundAmount < 0) {
                throw new \InvalidArgumentException('المبلغ المسترد من المورد لا يمكن أن يكون سالباً.');
            }
            if ($supplierRefundAmount > $supplierCost + 0.001) {
                throw new \InvalidArgumentException(
                    "المبلغ المسترد من المورد ({$supplierRefundAmount}) لا يمكن أن يتجاوز تكلفة التذكرة الأصلية ({$supplierCost})."
                );
            }
            $supplierRefundLoss = max(0.0, round($supplierCost - $supplierRefundAmount, 2));
            $netCustomerRefund = max(0.0, round($sellingPrice - $cancellationFee, 2));
        }

        $cancellationFeeTotal = round($sellingPrice - $netCustomerRefund, 2);
        $companyId = $this->resolveCompanyId($booking);

        return [
            'refund_case' => $refundCase,
            'original_currency' => $originalCurrency,
            'selling_price' => $sellingPrice,
            'supplier_cost' => $supplierCost,
            'airline_penalty' => $airlinePenalty,
            'agency_commission' => $agencyCommission,
            'apply_commission' => $applyCommission,
            'non_refundable_taxes_on_customer' => $nonRefundableTaxesOnCustomer,
            'non_refundable_taxes_amount' => $nonRefundableTaxesAmount,
            'supplier_refund_amount' => $supplierRefundAmount,
            'supplier_refund_loss' => $supplierRefundLoss,
            'cancellation_fee' => $cancellationFeeTotal,
            'net_customer_refund' => $netCustomerRefund,
            'company_id' => $companyId,
        ];
    }

    /**
     * خطوة عرض المسترد المقترح (Preview) قبل التنفيذ النهائي.
     *
     * @return array<string, mixed>
     */
    public function previewRefund(array $data): array
    {
        $bookingId = (int) ($data['flight_booking_id'] ?? 0);
        $booking = FlightBooking::with('customer')->findOrFail($bookingId);

        $params = $this->calculateRefundParameters($booking, $data);

        // محاكاة تسوية ديون العميل / الشركة FIFO
        $companyId = $params['company_id'];
        $customerId = $booking->customer_id;

        $unpaidQuery = FlightBooking::query()
            ->where('id', '!=', $booking->id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED]);

        if ($companyId) {
            $unpaidQuery->where(function ($q) use ($customerId, $companyId) {
                $q->where('customer_id', $customerId)
                    ->orWhere('customer_id', $companyId)
                    ->orWhereHas('customer', fn ($cq) => $cq->where('company_id', $companyId));
            });
        } else {
            $unpaidQuery->where('customer_id', $customerId);
        }

        $unpaidBookings = $unpaidQuery->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get();

        $remainingAlloc = $params['net_customer_refund'];
        $simulatedFifoSettled = 0.0;
        $settlementBreakdown = [];

        foreach ($unpaidBookings as $unpaid) {
            if ($remainingAlloc <= 0.005) {
                break;
            }
            $rem = (float) $unpaid->remaining_amount;
            if ($rem <= 0.005) {
                continue;
            }
            $alloc = min($remainingAlloc, $rem);
            $alloc = round($alloc, 2);
            $simulatedFifoSettled += $alloc;
            $remainingAlloc -= $alloc;

            $settlementBreakdown[] = [
                'booking_id' => $unpaid->id,
                'booking_number' => $unpaid->booking_number,
                'total_debt' => $rem,
                'amount_to_settle' => $alloc,
            ];
        }

        $expectedCredit = max(0.0, round($params['net_customer_refund'] - $simulatedFifoSettled, 2));

        return [
            'flight_booking_id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'customer_id' => $customerId,
            'customer_name' => $booking->customer?->full_name,
            'company_id' => $companyId,
            'is_corporate' => ! empty($companyId),
            'currency' => $params['original_currency'],
            'refund_case' => $params['refund_case'],
            'selling_price' => $params['selling_price'],
            'supplier_cost' => $params['supplier_cost'],
            'airline_penalty' => $params['airline_penalty'],
            'agency_commission' => $params['agency_commission'],
            'apply_commission' => $params['apply_commission'],
            'non_refundable_taxes_amount' => $params['non_refundable_taxes_amount'],
            'non_refundable_taxes_on_customer' => $params['non_refundable_taxes_on_customer'],
            'expected_supplier_refund' => $params['supplier_refund_amount'],
            'supplier_refund_loss' => $params['supplier_refund_loss'],
            'net_customer_refund' => $params['net_customer_refund'],
            'fifo_debt_to_settle' => $simulatedFifoSettled,
            'settlement_breakdown' => $settlementBreakdown,
            'expected_customer_credit' => $expectedCredit,
        ];
    }

    /**
     * إنشاء طلب استرجاع جديد للتذكرة مع التحقق الصارم وحساب تكلفة المورد.
     */
    public function createRefundRequest(array $data, int $userId): RefundRequest
    {
        return $this->withDeadlockRetry(fn () => DB::transaction(function () use ($data, $userId) {
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

            if (! in_array($booking->status, [
                FlightBookingStatus::CONFIRMED,
                FlightBookingStatus::PARTIALLY_REFUNDED,
            ], true)) {
                throw new \RuntimeException(
                    "لا يمكن إصدار طلب استرجاع لحجز بحالة '{$booking->status->value}'. ".
                    "يجب أن يكون الحجز مؤكداً على الأقل."
                );
            }

            $params = $this->calculateRefundParameters($booking, $data);
            $originalCurrency = $params['original_currency'];
            $originalAmount = $params['selling_price'];
            $supplierCost = $params['supplier_cost'];
            $supplierRefundAmount = $params['supplier_refund_amount'];
            $supplierRefundLoss = $params['supplier_refund_loss'];
            $cancellationFee = $params['cancellation_fee'];
            $refundAmount = $params['net_customer_refund'];
            $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));

            // تذكرة غير مباعة: لا يوجد ما يُسترد للعميل لأنه لم يُحصَّل منه شيء.
            //
            // تُقبل التذكرة في حالتين فقط:
            //   1) لها دفعة مباشرة على الحجز (flight_payments / تخصيصات الدفع).
            //   2) العميل سدّد دفعة عامة (pay-debt) لم تُخصَّص كلها لحجز آخر
            //      — وهي الدفعة التي تغطي هذه التذكرة (وإلا فلا يوجد رصيد يُسترد).
            // استثناء: وجهة airline_credit — فهي عكس تكلفة للمورد وليس رصيداً للعميل.
            $requestedDestination = strtolower((string) ($data['destination'] ?? 'customer_credit'));
            if ($requestedDestination !== 'airline_credit' && (float) $booking->paid_amount <= 0.0) {
                $generalReceipts = Transaction::query()
                    ->where('related_type', Customer::class)
                    ->where('related_id', (int) $booking->customer_id)
                    ->where('module', TransactionModule::Flight->value)
                    ->where('type', 'income')
                    ->get();

                $hasUnallocatedReceipt = false;
                foreach ($generalReceipts as $receipt) {
                    $allocated = (float) FlightPaymentAllocation::query()
                        ->where('transaction_id', $receipt->id)
                        ->where('status', 'active')
                        ->sum('amount');
                    if ((float) $receipt->amount - $allocated > 0.0) {
                        $hasUnallocatedReceipt = true;
                        break;
                    }
                }

                if (! $hasUnallocatedReceipt) {
                    throw new \RuntimeException(
                        'لا يمكن إصدار طلب استرجاع لتذكرة لم يتم تحصيل أي مبلغ منها (تذكرة غير مباعة).'
                    );
                }
            }

            // التحقق من السقف التراكمي للاسترداد
            //
            // البند 5 (2026-11-03): الطلب الذي لا يحمل رسوماً/غرامات جديدة
            // ("استرداد الباقي") يُقصّ على المتبقي من الحجز بدل رفضه، أما الطلب
            // الذي يحمل غرامات/رسوم جديدة فوق السقف (مثل تكرار نفس الطلب)
            // فيبقى مرفوضاً — لأن احتساب غرامة جديدة على مبلغ سبقه استرداد
            // جزئي لا معنى له محاسبياً.
            $alreadyRefunded = (float) RefundRequest::query()
                ->where('flight_booking_id', $booking->id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'rejected')
                ->sum('refund_amount');

            $remainingRefundable = max(0.0, round($originalAmount - $alreadyRefunded, 2));
            // الرسوم/الغرامات التي يحملها هذا الطلب على العميل (بيع − صافي المسترد)
            $newChargesOnCustomer = round($originalAmount - $refundAmount, 2);

            if ($alreadyRefunded + $refundAmount > $originalAmount + 0.0001) {
                if ($newChargesOnCustomer <= 0.001 && $remainingRefundable > 0) {
                    // طلب "استرداد الباقي": نُقصّ المبلغ على المتبقي فقط.
                    $refundAmount = $remainingRefundable;
                } else {
                    throw new \RuntimeException(
                        "إجمالي مبالغ الاسترجاع النشطة ({$alreadyRefunded} {$originalCurrency}) ".
                        "مع هذا الطلب ({$refundAmount} {$originalCurrency}) ".
                        "سيتجاوز المبلغ الأصلي للحجز ({$originalAmount} {$originalCurrency})."
                    );
                }
            }

            // البند 5: قصّ المسترد من المورد على التكلفة التي لم تُسترد ولم تُحمَّل
            // على العميل في طلب سابق — حتى لا يُسترد من الناقل أكثر من تكلفته الفعلية
            // ولا يُسترد نفس الثمن مرتين.
            $previousRefunds = RefundRequest::query()
                ->where('flight_booking_id', $booking->id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'rejected');

            $alreadySupplierRefunded = (float) (clone $previousRefunds)->sum('supplier_refund_amount');
            $alreadyChargedToCustomer = (float) (clone $previousRefunds)->sum(DB::raw(
                'COALESCE(airline_penalty, 0) + COALESCE(non_refundable_taxes_amount, 0)'
            ));

            $remainingSupplierCost = max(0.0, round($supplierCost - $alreadySupplierRefunded - $alreadyChargedToCustomer, 2));
            if ($supplierRefundAmount > $remainingSupplierCost + 0.0001) {
                $supplierRefundAmount = $remainingSupplierCost;
                // الجزء غير المسترد من التكلفة يُحمَّل مرة واحدة فقط —
                // وهي في طلب سابق — لذلك خسارة هذا الطلب = صفر.
                $supplierRefundLoss = 0.0;
            }

            $refundCurrency = strtoupper((string) ($data['refund_currency'] ?? $originalCurrency));
            if ($refundCurrency !== $originalCurrency) {
                throw new \InvalidArgumentException(
                    "عملة الاسترجاع ({$refundCurrency}) لا تطابق عملة الحجز الأصلية ({$originalCurrency}). ".
                    "يجب أن يكون الاسترجاع بنفس عملة الحجز."
                );
            }

            $refundExchangeRate = isset($data['refund_exchange_rate'])
                ? (float) $data['refund_exchange_rate']
                : ($refundCurrency === 'EGP' ? 1.0 : $bookingExchangeRate);
            if ($refundExchangeRate <= 0) {
                throw new \InvalidArgumentException('سعر صرف الاسترجاع يجب أن يكون أكبر من صفر.');
            }

            $baseCurrencyRefund = round($refundAmount * $refundExchangeRate, 2);
            $baseAmountAfterFeeAtBookingRate = round($refundAmount * $bookingExchangeRate, 2);
            $currencyDifference = round($baseCurrencyRefund - $baseAmountAfterFeeAtBookingRate, 2);

            $destination = $data['destination'] ?? 'customer_credit';
            $refundType = $data['refund_type'] ?? ($destination === 'airline_credit' ? 'airline_credit_only' : 'customer_credit');

            $idempotencyKey = $data['idempotency_key'] ?? null;
            if ($idempotencyKey) {
                $exists = RefundRequest::query()
                    ->where('flight_booking_id', $booking->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();
                if ($exists) {
                    throw new \RuntimeException('طلب الاسترجاع مكرر بنفس مفتاح العملية (Idempotency Key).');
                }
            }

            $refundRequest = RefundRequest::create([
                'flight_booking_id' => $booking->id,
                'refund_case' => $params['refund_case'],
                'airline_penalty' => $params['airline_penalty'],
                'agency_commission' => $params['agency_commission'],
                'apply_commission' => $params['apply_commission'],
                'non_refundable_taxes_on_customer' => $params['non_refundable_taxes_on_customer'],
                'non_refundable_taxes_amount' => $params['non_refundable_taxes_amount'],
                'company_id' => $params['company_id'],
                'refund_type' => $refundType,
                'original_currency' => $originalCurrency,
                'original_amount' => $originalAmount,
                'supplier_cost' => $supplierCost,
                'supplier_refund_amount' => $supplierRefundAmount,
                'supplier_refund_loss' => $supplierRefundLoss,
                'cancellation_fee' => $cancellationFee,
                'refund_amount' => $refundAmount,
                'customer_credit_amount' => $refundAmount, // افتراضياً قبل تسوية FIFO
                'customer_debt_settled_amount' => 0,
                'disbursed_amount' => 0,
                'disbursement_status' => 'pending',
                'refund_currency' => $refundCurrency,
                'refund_exchange_rate' => $refundExchangeRate,
                'base_currency_refund' => $baseCurrencyRefund,
                'currency_difference' => $currencyDifference,
                'destination' => $destination,
                'treasury_id' => $data['treasury_id'] ?? null,
                'airline_credit_balance' => $destination === 'airline_credit' ? $refundAmount : null,
                'status' => 'pending',
                'supplier_refund_confirmed' => (bool) ($data['supplier_refund_confirmed'] ?? false),
                'supplier_refund_confirmed_at' => ! empty($data['supplier_refund_confirmed']) ? now() : null,
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Audit Log
            try {
                RefundAuditLogger::logRefund([
                    'module' => 'flight',
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_number,
                    'customer_id' => $booking->customer_id,
                    'customer_name' => $booking->customer?->full_name,
                    'refund_amount' => $refundAmount,
                    'currency' => $refundCurrency,
                    'paid_amount_before' => (float) $booking->paid_amount,
                    'previously_refunded' => $alreadyRefunded,
                    'remaining_refundable' => max(0.0, $originalAmount - ($alreadyRefunded + $refundAmount)),
                    'reason' => $data['notes'] ?? 'طلب استرداد تذكرة طيران',
                    'idempotency_key' => $idempotencyKey,
                ], 'refund.requested');
            } catch (\Throwable $e) {
                Log::warning('Refund request audit log failed: '.$e->getMessage());
            }

            return $refundRequest;
        }));
    }

    /**
     * معالجة واعتماد طلب الاسترجاع مع تطبيق العزل المالي التام:
     *   الطرف (أ): المورد (تسوية مديونية المورد + رصيد دائن + عكس التكلفة).
     *   الطرف (ب): عميل الشركة (عكس البيع + عمولة الإلغاء + تسوية ديون العميل FIFO + رصيد دائن للعميل).
     *
     * @param bool $autoDisburse في حال تمريرها true، يتم صرف الرصيد الدائن فوراً للعميل (للتوافق القديم عند الطلب الصريح).
     */
    public function processRefundRequest(int $refundRequestId, int $userId, bool $autoDisburse = false): RefundRequest
    {
        return $this->withDeadlockRetry(
            fn () => LedgerBalanceMutationGuard::run(
                fn () => DB::transaction(function () use ($refundRequestId, $userId, $autoDisburse) {
                    $refundRequest = RefundRequest::lockForUpdate()->findOrFail($refundRequestId);

                    // ضمان الـ Idempotency لمنع التكرار والضغط المزدوج
                    if ($refundRequest->status === 'processed') {
                        return $refundRequest;
                    }

                    $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);

                    // التحقق من الحجز
                    if ($booking->status === FlightBookingStatus::REFUNDED) {
                        throw new \RuntimeException('الحجز مسترد بالكامل مسبقاً.');
                    }

                    $customerAccount = $this->ensureCustomerAccount((int) $booking->customer_id);

                    $sellingPriceEgp = (float) $booking->selling_price;
                    $supplierCost = (float) ($refundRequest->supplier_cost ?: ($booking->purchase_price_egp ?: $booking->purchase_price));
                    $supplierRefundAmount = (float) ($refundRequest->supplier_refund_amount ?? $supplierCost);
                    $supplierRefundLoss = (float) ($refundRequest->supplier_refund_loss ?? max(0.0, $supplierCost - $supplierRefundAmount));
                    $cancellationFee = (float) ($refundRequest->cancellation_fee ?? 0.0);
                    $netCustomerRefund = (float) $refundRequest->refund_amount;

                    if ($netCustomerRefund < 0) {
                        throw new \InvalidArgumentException('المبلغ المسترد للعميل لا يمكن أن يكون سالباً.');
                    }

                    // ─────────────────────────────────────────────────────────────
                    // عملة الاسترجاع ومكافئها بالجنيه المصري
                    //
                    // مبالغ طلب الاسترجاع (غرامة/عمولة/ضرائب/تكلفة مستردة)
                    // محفوظة بعملة الحجز الأصلية — وهي عملة أجنبية إذا كان
                    // الحجز بالدولار/الريال/الدينار. لكن حساب العميل (ذمم)
                    // وحسابات الإقفال (تكاليف/مبيعات معلّقة/عمولات/خسائر
                    // استرداد) كلها بالجنيه المصري.
                    //
                    // لذلك تُحوَّل هذه المبالغ إلى الجنيه قبل ترحيلها على
                    // تلك الحسابات، وإلا خُصمت التكلفة من الدفتر بقيمة
                    // أجنبية بدل قيمتها الحقيقية بالجنيه — فيبقى الـ P&L
                    // مقلوباً بمقدار فرق الصرف.
                    // ─────────────────────────────────────────────────────────────
                    $refundCurrency = strtoupper((string) ($refundRequest->refund_currency ?: 'EGP'));
                    $egpPerRefundUnit = $this->egpPerRefundUnit($refundRequest);
                    $netCustomerRefundEgp = (float) ($refundRequest->base_currency_refund ?: 0.0) > 0
                        ? round((float) $refundRequest->base_currency_refund, 2)
                        : round($netCustomerRefund * $egpPerRefundUnit, 2);
                    $supplierRefundAmountEgp = round($supplierRefundAmount * $egpPerRefundUnit, 2);
                    $supplierRefundLossEgp = round($supplierRefundLoss * $egpPerRefundUnit, 2);

                    // ═══════════════════════════════════════════════════════════════
                    // الطرف (أ): المورد (Supplier Leg)
                    // ═══════════════════════════════════════════════════════════════
                    // 1. لو للمورد مديونية/رصيد مستحق له علينا: يُخصم منه بقدر المبلغ (الأقدم أولًا).
                    // 2. الباقي، أو المبلغ كله لو مفيش مديونية: يُسجَّل كرصيد دائن لنا عند المورد (Supplier Credit).
                    // 3. القيد: مدين حساب المورد / دائن تكلفة التذاكر (عكس التكلفة).
                    if ($booking->purchase_balance_source === 'carrier' || (! $booking->purchase_balance_source && $booking->flight_carrier_id)) {
                        $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                        if ($carrier && $supplierRefundAmount > 0) {
                            // رصيد الناقل بعملة رصيده: إن كانت عملة الحجز فالمبلغ
                            // كما هو، وإلا فنحوّل من المكافئ بالجنيه.
                            $creditSub = $this->supplierAmountInBalanceCurrency(
                                (string) $carrier->currency,
                                $refundCurrency,
                                $supplierRefundAmount,
                                $supplierRefundAmountEgp,
                                $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
                            );
                            if ($creditSub > 0) {
                                $carrier->credit(
                                    amount: $creditSub,
                                    description: 'استرداد حجز — إرجاع رصيد الناقل/تسوية مديونية — حجز #'.$booking->booking_number,
                                    userId: $userId,
                                    bookingId: $booking->id
                                );
                            }

                            // عكس التكلفة في الدفتر العام COGS Reversal
                            // (حسابات الجنيه: prepaid ↔ إقفال تكاليف الطيران)
                            $this->prepaidLedgerService->refundCogs(
                                'flight_carrier',
                                TransactionModule::Flight,
                                $supplierRefundAmountEgp,
                                "استرداد تكلفة حجز {$booking->booking_number} — ناقل",
                                FlightBooking::class,
                                $booking->id
                            );
                        }
                    } elseif ($booking->purchase_balance_source === 'system' && $booking->flight_system_id) {
                        $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                        if ($system && $supplierRefundAmount > 0) {
                            $creditSub = $this->supplierAmountInBalanceCurrency(
                                (string) $system->currency,
                                $refundCurrency,
                                $supplierRefundAmount,
                                $supplierRefundAmountEgp,
                                $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
                            );
                            if ($creditSub > 0) {
                                $system->credit(
                                    amount: $creditSub,
                                    description: 'استرداد حجز — إرجاع رصيد النظام/تسوية مديونية — حجز #'.$booking->booking_number,
                                    userId: $userId,
                                    bookingId: $booking->id
                                );
                            }

                            $this->prepaidLedgerService->refundCogs(
                                'flight_system',
                                TransactionModule::Flight,
                                $supplierRefundAmountEgp,
                                "استرداد تكلفة حجز {$booking->booking_number} — نظام",
                                FlightBooking::class,
                                $booking->id
                            );
                        }
                    } elseif ($booking->purchase_balance_source === 'group' && $booking->flight_group_id) {
                        $group = FlightGroup::lockForUpdate()->find($booking->flight_group_id);
                        if ($group && $group->account_id && $supplierRefundAmount > 0) {
                            $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                            $groupAccount = Account::query()->find((int) $group->account_id);
                            $groupCurrency = $groupAccount ? strtoupper((string) $groupAccount->currency) : 'EGP';
                            // حساب الإقفال بالجنيه دائماً — فالمبلغ المرحَّل هو
                            // المكافئ بالجنيه، وتحويله لعملة حساب المجموعة
                            // يتم بسعر العملة نفسها.
                            $groupEgpPerUnit = match ($groupCurrency) {
                                'EGP' => 1.0,
                                $refundCurrency => $egpPerRefundUnit,
                                default => FlightBookingService::egpPerUnitOfCurrency($groupCurrency),
                            };
                            $groupAmounts = $this->glTransferAmounts(
                                'EGP',
                                $groupCurrency,
                                $groupEgpPerUnit > 0 ? round($supplierRefundAmountEgp / $groupEgpPerUnit, 4) : $supplierRefundAmountEgp,
                                $supplierRefundAmountEgp,
                                $groupEgpPerUnit
                            );
                            $this->transactionService->recordJournalTransfer([
                                'amount' => $groupAmounts['amount'],
                                'converted_amount' => $groupAmounts['converted_amount'],
                                'exchange_rate' => $groupAmounts['exchange_rate'],
                                'from_account_id' => $expenseContraId,
                                'to_account_id' => (int) $group->account_id,
                                'allow_from_negative' => true,
                                'module' => TransactionModule::Flight->value,
                                'related_type' => FlightBooking::class,
                                'related_id' => $booking->id,
                                'notes' => "استرداد تكلفة مجموعة — حجز #{$booking->booking_number} — مجموعة: {$group->name}",
                                'created_by' => $userId,
                            ]);
                        }
                    }

                    // إثبات خسائر/غرامات الاسترداد إن وجدت (استرد المورد أقل من التكلفة الفعلية)
                    if ($supplierRefundLoss > 0) {
                        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                        $refundLossAccountId = $this->clearingAccounts->refundLossAccountId();

                        $this->transactionService->recordJournalTransfer([
                            'amount' => $supplierRefundLossEgp,
                            'from_account_id' => $expenseContraId,
                            'to_account_id' => $refundLossAccountId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "إثبات خسارة/غرامة استرداد من شركة الطيران — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                    }

                    // حالة استرداد المورد: تبقى كما أدخلها المستخدم إلا لو تم تأكيدها مسبقاً أو لاحقاً
                    if ($refundRequest->supplier_refund_confirmed && ! $refundRequest->supplier_refund_confirmed_at) {
                        $refundRequest->supplier_refund_confirmed_at = now();
                    }

                    // ═══════════════════════════════════════════════════════════════
                    // الطرف (ب): عميل الشركة (Customer Leg)
                    // ═══════════════════════════════════════════════════════════════
                    // القيد: مدين إيراد المبيعات (عكس البيع) 7500 / دائن حساب العميل 7300 / دائن إيراد عمولة إلغاء 200.
                    //
                    // في نظام القيود المزدوجة المتوازنة:
                    // Leg 1: عكس قيد المبيعات كاملاً: from Customer (DR) to Sales Clearing (CR) بمبلغ 7500.
                    // Leg 2: إثبات عمولة الإلغاء: from Sales Clearing (DR) to Cancellation Fee Revenue (CR) بمبلغ 200.
                    // الصافي: إيراد المبيعات معكوس بـ 7500، حساب العميل دائن بـ 7300، وإيراد العمولة دائن بـ 200.
                    // المجموع متوازن تماماً: مدين 7500 = دائن 7500.

                    $salesClearingId = null;
                    if ($booking->sale_gl_transaction_id) {
                        $origSaleTx = Transaction::query()->find($booking->sale_gl_transaction_id);
                        if ($origSaleTx) {
                            $salesClearingId = (int) $origSaleTx->from_account_id;
                        }
                    }
                    if (! $salesClearingId) {
                        $salesClearingId = $this->clearingAccounts->pendingSalesReceivableIdForFlight();
                    }

                    // Leg 1: عكس قيد المبيعات بمقدار صافي المسترد للعميل فقط
                    // (لا نعكس كامل سعر البيع — الفرق يُعالَج في Leg 2 و Leg 3)
                    // هذا يضمن: رصيد Acc4 (العميل) = -netCustomerRefund = رصيد CustomerCreditLedger بالظبط
                    $reversalGl = null;
                    if ($netCustomerRefund > 0 && $salesClearingId) {
                        $reversalGl = $this->transactionService->recordJournalTransfer([
                            'amount' => $netCustomerRefundEgp,
                            'from_account_id' => $customerAccount->id,
                            'to_account_id' => $salesClearingId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "عكس قيد مبيعات الحجز (صافي المسترد) ضمن الاسترجاع — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                    } elseif ($sellingPriceEgp > 0 && $netCustomerRefund <= 0 && $salesClearingId) {
                        // لو صافي المسترد صفر (غرامة تساوي سعر البيع) — لا نحتاج قيد العميل
                        $reversalGl = null;
                    }

                    // Leg 2: قيد إيراد عمولة الإلغاء
                    $agencyCommissionRevenue = (float) ($refundRequest->agency_commission ?: 0.0);
                    if ($agencyCommissionRevenue <= 0 && $cancellationFee > 0 && ! $refundRequest->airline_penalty) {
                        $agencyCommissionRevenue = $cancellationFee;
                    }

                    if ($agencyCommissionRevenue > 0 && $salesClearingId) {
                        $cancellationFeeAccountId = $this->clearingAccounts->cancellationFeeRevenueAccountId();

                        // البند 10 (2026-11-03): اتجاه القيد معكوس —
                        // مدين حساب عمولات الإلغاء / دائن حساب المبيعات المعلّقة.
                        //
                        // السبب: حساب المبيعات المعلّقة (Acc6) يجب أن يعود إلى
                        // رصيده قبل البيع (صفر) بعد الاسترداد. وهو يستقبل:
                        //   - Leg 1: صافي المسترد للعميل
                        //   - Leg 3: الغرامة/الضرائب التي لم تردها شركة الطيران
                        //   - Leg 2: عمولة الإلغاء التي احتفظت بها الوكالة
                        // ومجموعها = سعر البيع كاملاً، فيصفر الحساب.
                        // بالاتجاه القديم (مدين Acc6) كانت العمولة تُحتسب مرتين
                        // في Acc6 (مرة كخصم من المبلغ المسترد ومرة كقيد عمولة)
                        // فبقي الرصيد -400 بدل صفر.
                        //
                        // ملاحظة: إثبات إيراد العمولة في الـ P&L يتم عبر قيد
                        // عكس الإيراد (memo) الذي يخفض الإيراد المعترف به
                        // بقيمة صافي المسترد — لذلك يبقى حساب عمولات الإلغاء
                        // محايداً تجاه الـ P&L ولا يؤثر على رصيد الإيراد الظاهر.
                        $this->transactionService->recordJournalTransfer([
                            'amount' => round($agencyCommissionRevenue * $egpPerRefundUnit, 2),
                            'from_account_id' => $cancellationFeeAccountId,
                            'to_account_id' => $salesClearingId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "تحرير عمولة إلغاء تذكرة طيران من حساب المبيعات المعلّقة — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                    }

                    // Leg 3: غرامة الطيران أو الضرائب غير المستردة المحملة على العميل
                    $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                    $penaltyOrTaxes = (float) ($refundRequest->airline_penalty ?? 0.0);
                    if ($refundRequest->refund_case === 'airline_cancelled' && $refundRequest->non_refundable_taxes_on_customer) {
                        $penaltyOrTaxes += (float) ($refundRequest->non_refundable_taxes_amount ?? 0.0);
                    }

                    if ($penaltyOrTaxes > 0 && $salesClearingId) {
                        // Leg 3: نقل الغرامة/الضرائب من Acc5 (إقفال تكاليف الطيران) إلى Acc6 (ذمم عملاء معلق)
                        // الاتجاه الصحيح: مدين Acc5 / دائن Acc6
                        // هذا يُصفّر Acc5 (كان +penaltyOrTaxes بعد استرداد المورد الجزئي)
                        // ويُصفّر Acc6 (بعد Leg 1 بـ -netCustomerRefund و Leg 2 بـ -commission)
                        $this->transactionService->recordJournalTransfer([
                            'amount' => round($penaltyOrTaxes * $egpPerRefundUnit, 2),
                            'from_account_id' => $expenseContraId,
                            'to_account_id' => $salesClearingId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "إقفال غرامة طيران / ضرائب غير مستردة على العميل (تصفير الحسابات الوسيطة) — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                    }

                    // عكس إيراد المدفوعات المسجلة في P&L للحجز إن وُجدت
                    // (نحفظ حالة العكس في الطلب ليعود الـ P&L لما كان عليه عند الإلغاء)
                    $reversalState = $this->reverseRevenueImpact($booking, $refundRequest, $userId);

                    // ─────────────────────────────────────────────────────────────
                    // تسوية مديونية العميل FIFO (حجوزات/فواتير الطيران غير المدفوعة)
                    // ─────────────────────────────────────────────────────────────
                    // 1. لو العميل أو الشركة عليه مديونية لنا: تُخصم منها (فواتير/حجوزات الأقدم أولًا FIFO).
                    // 2. الباقي، أو المبلغ كله لو مفيش مديونية: يُسجَّل في سجل رصيد العميل الموحد (Customer Credit Ledger).
                    $companyId = $refundRequest->company_id ?: $this->resolveCompanyId($booking);
                    // ديون الحجزات الأخرى محفوظة بالجنيه المصري (remaining_amount)
                    // — لذلك يُوزَّع صافي المسترد بالمكافئ بالجنيه لا بمبلغ
                    // العملة الأجنبية.
                    $remainingRefundToAllocate = $netCustomerRefundEgp;
                    $settledDebtTotal = 0.0;

                    if ($remainingRefundToAllocate > 0.005) {
                        $unpaidQuery = FlightBooking::query()
                            ->where('id', '!=', $booking->id)
                            ->whereNull('deleted_at')
                            ->whereNotIn('status', [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED]);

                        if ($companyId) {
                            $unpaidQuery->where(function ($q) use ($booking, $companyId) {
                                $q->where('customer_id', $booking->customer_id)
                                    ->orWhere('customer_id', $companyId)
                                    ->orWhereHas('customer', fn ($cq) => $cq->where('company_id', $companyId));
                            });
                        } else {
                            $unpaidQuery->where('customer_id', $booking->customer_id);
                        }

                        $unpaidBookings = $unpaidQuery->orderBy('created_at', 'asc')->orderBy('id', 'asc')->lockForUpdate()->get();

                        foreach ($unpaidBookings as $unpaidBooking) {
                            if ($remainingRefundToAllocate <= 0.005) {
                                break;
                            }
                            $remainingDebtOnBooking = (float) $unpaidBooking->remaining_amount;
                            if ($remainingDebtOnBooking <= 0.005) {
                                continue;
                            }

                            $allocAmount = min($remainingRefundToAllocate, $remainingDebtOnBooking);
                            $allocAmount = round($allocAmount, 2);

                            // تخصيص الدفعة للحجز الأقدم
                            FlightPaymentAllocation::create([
                                'transaction_id' => $reversalGl?->id ?? $booking->sale_gl_transaction_id ?? 1,
                                'flight_booking_id' => $unpaidBooking->id,
                                'customer_id' => $booking->customer_id,
                                'amount' => $allocAmount,
                                'status' => 'active',
                                'notes' => "تسوية مديونية FIFO من رصيد استرداد حجز #{$booking->booking_number}".($companyId ? " (شركة #{$companyId})" : ''),
                                'created_by' => $userId,
                                'allocated_at' => now(),
                            ]);

                            // ترقية حالة الحجز لو تم السداد بالكامل ومعه PNR
                            $unpaidBooking->unsetRelation('paymentAllocations');
                            if ((float) $unpaidBooking->paid_amount >= (float) $unpaidBooking->selling_price - 0.01
                                && $unpaidBooking->status === FlightBookingStatus::PENDING
                                && ! empty($unpaidBooking->pnr)) {
                                $unpaidBooking->update(['status' => FlightBookingStatus::CONFIRMED]);
                            }

                            $settledDebtTotal += $allocAmount;
                            $remainingRefundToAllocate -= $allocAmount;
                        }
                    }

                    // الرصيد الدائن المتبقي للعميل (Customer Credit)
                    //
                    // دفتر رصيد العميل يُحفظ بعملة الاسترجاع (نفس عملة الحجز)،
                    // بينما مبالغ ديون الحجزات الأخرى بالجنيه — لذلك يُحوَّل
                    // المتبقي إلى عملة الاسترجاع قبل تسجيله في الدفتر.
                    $customerCreditEgp = max(0.0, round($netCustomerRefundEgp - $settledDebtTotal, 2));
                    $customerCreditAmount = $refundCurrency === 'EGP'
                        ? $customerCreditEgp
                        : round($egpPerRefundUnit > 0 ? $customerCreditEgp / $egpPerRefundUnit : $customerCreditEgp, 4);

                    // تسجيل الرصيد الدائن المتبقي في سجل رصيد العميل الموحد (CustomerCreditLedger)
                    $ledgerEntry = null;
                    if ($customerCreditAmount > 0) {
                        $ledgerEntry = CustomerCreditLedger::recordEntry([
                            'customer_id' => $booking->customer_id,
                            'company_id' => $companyId,
                            'source_type' => 'refund',
                            'source_id' => $refundRequest->id,
                            'amount' => $customerCreditAmount,
                            'currency' => $refundRequest->refund_currency,
                            'notes' => "رصيد دائن متبقي من استرداد حجز #{$booking->booking_number} (طلب #{$refundRequest->id})",
                            'created_by' => $userId,
                        ]);
                    }

                    // لو الوجهة airline_credit ننشئ سجل رصيد الطيران
                    if ($refundRequest->destination === 'airline_credit' && $booking->flight_carrier_id) {
                        $carrier = FlightCarrier::query()->find($booking->flight_carrier_id);
                        $carrierCurrency = $carrier ? strtoupper((string) $carrier->currency) : 'EGP';

                        $existingActiveVoucher = AirlineCredit::query()
                            ->where('flight_booking_id', $booking->id)
                            ->where('status', 'active')
                            ->lockForUpdate()
                            ->exists();

                        if (! $existingActiveVoucher) {
                            AirlineCredit::create([
                                'flight_carrier_id' => $booking->flight_carrier_id,
                                'customer_id' => $booking->customer_id,
                                'currency' => $refundRequest->refund_currency,
                                'amount' => $customerCreditAmount > 0 ? $customerCreditAmount : $refundRequest->refund_amount,
                                'expiry_date' => now()->addYear()->toDateString(),
                                'flight_booking_id' => $booking->id,
                                'refund_request_id' => $refundRequest->id,
                                'status' => 'active',
                            ]);
                        }
                    }

                    // تحديث بيانات طلب الاسترداد
                    $refundRequest->company_id = $companyId;
                    $refundRequest->credit_ledger_entry_id = $ledgerEntry?->id;
                    $refundRequest->reversal_state = $reversalState;
                    $refundRequest->customer_debt_settled_amount = $settledDebtTotal;
                    $refundRequest->customer_credit_amount = $customerCreditAmount;
                    $refundRequest->disbursed_amount = 0.0;
                    $refundRequest->disbursement_status = ($customerCreditAmount <= 0.005 && $settledDebtTotal > 0)
                        ? 'applied_to_booking'
                        : 'pending';
                    $refundRequest->status = 'processed';
                    $refundRequest->processed_at = now();
                    $refundRequest->save();

                    // تحديث حالة الحجز (لو استرداد لكامل مبلغ التذكرة يعتبر REFUNDED حتى مع وجود رسوم إلغاء)
                    //
                    // البند 5 (2026-11-03): المقارنة تتم بين إجمالي المبلغ
                    // المسترد فعلياً (بEGP) وسعر البيع (بEGP دائماً) — وليس
                    // بين `original_amount` (العملة الأصلية للحجز) وسعر البيع،
                    // فكان ينتج دائماً REFUNDED حتى بعد استرجاع جزئي.
                    //
                    // التوزيع الفعلي لمبلغ البيع:
                    //   سعر البيع = (المسترد للعميل) + (عمولة الوكالة)
                    //                + (الغرامة/الضرائب التي لم تردها شركة الطيران).
                    // فالحجز يُعتبر "مسترداً بالكامل" فقط إذا لم تحتفظ شركة
                    // الطيران بأي جزء — أي إذا كان الفرق بين (المسترد + عمولة
                    // الوكالة) وسعر البيع صفراً. وكل المبالغ بالـ EGP.
                    $processedRefunds = RefundRequest::query()
                        ->where('flight_booking_id', $booking->id)
                        ->whereNull('deleted_at')
                        ->where('status', 'processed')
                        ->orderBy('id')
                        ->get(['refund_amount', 'base_currency_refund', 'agency_commission', 'refund_exchange_rate', 'refund_type']);

                    $totalRefundedEgp = 0.0;
                    $totalAgencyFeesEgp = 0.0;
                    $lastRefundType = null;
                    foreach ($processedRefunds as $processed) {
                        $refundedEgp = (float) $processed->base_currency_refund > 0
                            ? (float) $processed->base_currency_refund
                            : (float) $processed->refund_amount;
                        $totalRefundedEgp += $refundedEgp;

                        $feeRate = (float) ($processed->refund_exchange_rate ?: 1.0);
                        $totalAgencyFeesEgp += (float) $processed->agency_commission * $feeRate;
                        $lastRefundType = $processed->refund_type;
                    }

                    $sellingPriceEgp = (float) $booking->selling_price;
                    $unsettledAmount = $sellingPriceEgp - $totalRefundedEgp - $totalAgencyFeesEgp;

                    // طلب معلَّم صراحةً كاسترجاع جزئي (refund_type = 'partial')
                    // يبقى الحجز جزئياً حتى لو توفّق المبلغ في وقت لاحق.
                    $declaredPartial = $lastRefundType === 'partial';

                    $isPartial = $declaredPartial || abs($unsettledAmount) > 0.01;
                    $booking->status = $isPartial ? FlightBookingStatus::PARTIALLY_REFUNDED : FlightBookingStatus::REFUNDED;
                    $booking->save();

                    // Audit Log إلزامي
                    try {
                        RefundAuditLogger::logRefund([
                            'module' => 'flight',
                            'booking_id' => $booking->id,
                            'booking_reference' => $booking->booking_number,
                            'customer_id' => $booking->customer_id,
                            'customer_name' => $booking->customer?->full_name,
                            'refund_amount' => $netCustomerRefund,
                            'currency' => $refundRequest->refund_currency,
                            'paid_amount_before' => (float) $booking->paid_amount,
                            'previously_refunded' => 0,
                            'remaining_refundable' => 0,
                            'transaction_id' => $reversalGl?->id,
                            'reason' => "معالجة استرداد حجز #{$booking->booking_number} — رصيد دائن: {$customerCreditAmount}، تسوية ديون: {$settledDebtTotal}",
                        ], 'refund.processed');
                    } catch (\Throwable $e) {
                        Log::warning('Refund processing audit log error: '.$e->getMessage());
                    }

                    // خيار الصرف التلقائي الفوري (إذا طلبه المستخدم صراحة أو للـ backward-compatibility مع وجهة agency_treasury)
                    if (($autoDisburse || $refundRequest->destination === 'agency_treasury') && $customerCreditAmount > 0 && $refundRequest->treasury_id) {
                        $this->disburseCustomerCredit(
                            $refundRequest->id,
                            [
                                'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
                                'amount' => $customerCreditAmount,
                                'treasury_id' => $refundRequest->treasury_id,
                                'notes' => 'صرف استرداد نقدي فوري بعد الاعتماد',
                            ],
                            $userId,
                            true // صرف تلقائي داخل اعتماد الاسترجاع — محكوم بصلاحية manage_refunds
                        );
                    }

                    Log::info('Refund processed successfully with dual independent legs', [
                        'refund_request_id' => $refundRequest->id,
                        'supplier_refund' => $supplierRefundAmount,
                        'supplier_loss' => $supplierRefundLoss,
                        'customer_refund' => $netCustomerRefund,
                        'cancellation_fee' => $cancellationFee,
                        'debt_settled' => $settledDebtTotal,
                        'credit_remaining' => $customerCreditAmount,
                    ]);

                    return $refundRequest->fresh(['booking', 'treasury', 'airlineCredit', 'disbursements']);
                })
            )
        );
    }

    /**
     * صرف رصيد العميل الدائن (Customer Credit Disbursement)
     * قرار يدوي من المستخدم، ولا يتجاوز الرصيد المتاح، مع تطبيق قاعدة الأمان.
     *
     * البند 6 (2026-11-03): الصرف الصريح (endpoint الصرف / إجراء Filament) يتطلب
     * صلاحية `disburse_refund`. أما الصرف التلقائي الذي يتم داخل اعتماد طلب
     * الاسترجاع نفسه (`$isAutomatic = true`) فيُحكم بصلاحية `manage_refunds`
     * الخاصة باعتماد الاسترجاع، ولا يُرفض خطأً لأنه يتم في نفس المعاملة.
     *
     * @param array{
     *     disbursement_type: 'cash'|'bank'|'booking_settlement',
     *     amount: float,
     *     treasury_id?: int|null,
     *     bank_account_id?: int|null,
     *     target_flight_booking_id?: int|null,
     *     notes?: string|null,
     * } $data
     */
    public function disburseCustomerCredit(int $refundRequestId, array $data, int|User $userOrId, bool $isAutomatic = false): CustomerCreditDisbursement
    {
        $userId = $userOrId instanceof User ? (int) $userOrId->id : (int) $userOrId;
        $user = $userOrId instanceof User ? $userOrId : User::find($userId);

        return $this->withDeadlockRetry(
            fn () => LedgerBalanceMutationGuard::run(
                fn () => DB::transaction(function () use ($refundRequestId, $data, $userId, $user, $isAutomatic) {
                    $refundRequest = RefundRequest::lockForUpdate()->findOrFail($refundRequestId);

                    if ($refundRequest->status !== 'processed') {
                        throw new BusinessLogicException('طلب الاسترداد يجب أن يكون معالجاً ومرحلاً (Processed) قبل صرف الرصيد الدائن.');
                    }

                    $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);
                    $customer = Customer::lockForUpdate()->findOrFail($booking->customer_id);
                    $customerAccount = $this->ensureCustomerAccount((int) $customer->id);

                    // ─────────────────────────────────────────────────────────────
                    // قاعدة الأمان: التحقق من تأكيد استرداد المورد أو الصلاحية الخاصة
                    // ─────────────────────────────────────────────────────────────
                    $rawType = $data['type'] ?? $data['disbursement_type'] ?? CustomerCreditDisbursement::TYPE_CASH;
                    $disbursementType = match ($rawType) {
                        'cash_treasury', CustomerCreditDisbursement::TYPE_CASH => CustomerCreditDisbursement::TYPE_CASH,
                        'bank_transfer', CustomerCreditDisbursement::TYPE_BANK => CustomerCreditDisbursement::TYPE_BANK,
                        'booking_settlement', CustomerCreditDisbursement::TYPE_BOOKING_SETTLEMENT => CustomerCreditDisbursement::TYPE_BOOKING_SETTLEMENT,
                        default => $rawType,
                    };

                    if (in_array($disbursementType, [CustomerCreditDisbursement::TYPE_CASH, CustomerCreditDisbursement::TYPE_BANK], true)) {
                        $userPermissions = $user ? UserPermissions::effectiveFor($user) : [];
                        $canForcePayout = in_array(UserPermissions::FORCE_REFUND_PAYOUT, $userPermissions, true);

                        if (! $refundRequest->supplier_refund_confirmed && ! $canForcePayout) {
                            throw new BusinessLogicException(
                                'قاعدة أمان: لا يُسمح بالصرف النقدي أو البنكي للعميل قبل تأكيد استرداد المورد، إلا بإذن صريح بصلاحية خاصة (force_refund_payout).'
                            );
                        }
                    }

                    // ─────────────────────────────────────────────────────────────
                    // البند 6 (2026-11-03): فرض صلاحية `disburse_refund` على الصرف
                    // الصريح. الترتيب مهم: فحص "تأكيد استرداد المورد" يسبق هذا
                    // الفحص حتى تبقى رسالة قاعدة الأمان كما هي.
                    // ─────────────────────────────────────────────────────────────
                    if (! $isAutomatic) {
                        $permissions = $user ? UserPermissions::effectiveFor($user) : [];
                        if (! in_array(UserPermissions::DISBURSE_REFUND, $permissions, true)) {
                            throw new BusinessLogicException(
                                'لا تملك صلاحية صرف رصيد الاسترداد (disburse_refund) — لا يمكن تنفيذ الصرف.'
                            );
                        }
                    }

                    // التحقق من المبلغ والرصيد المتاح في الطلب وفي سجل الأرصدة الموحد
                    $amount = round((float) ($data['amount'] ?? 0), 2);
                    if ($amount <= 0.001) {
                        throw new \InvalidArgumentException('مبلغ الصرف يجب أن يكون أكبر من صفر.');
                    }

                    $availableCreditInRequest = $refundRequest->available_customer_credit;
                    $ledgerAvailable = CustomerCreditLedger::getAvailableCredit(
                        $customer->id,
                        $refundRequest->company_id,
                        $refundRequest->refund_currency
                    );
                    $effectiveAvailable = min(
                        $availableCreditInRequest,
                        $ledgerAvailable > 0 ? $ledgerAvailable : $availableCreditInRequest
                    );

                    if ($amount > $effectiveAvailable + 0.005) {
                        throw new BusinessLogicException(
                            sprintf(
                                'مبلغ الصرف المطلوب (%.2f) يتجاوز الرصيد الدائن المتاح للعميل (%.2f).',
                                $amount,
                                $effectiveAvailable
                            )
                        );
                    }

                    $glTransaction = null;
                    $treasuryId = null;
                    $bankAccountId = null;
                    $targetBookingId = null;

                    // تنفيذ الصرف حسب النوع المحدد
                    if ($disbursementType === CustomerCreditDisbursement::TYPE_CASH) {
                        // رد نقدي من الخزنة
                        $treasuryId = (int) ($data['treasury_id'] ?? $refundRequest->treasury_id);
                        $treasury = Treasury::lockForUpdate()->findOrFail($treasuryId);

                        if (! $treasury->is_active) {
                            throw new BusinessLogicException("الخزينة المحددة ({$treasury->name}) غير نشطة.");
                        }

                        if (strtoupper((string) $treasury->currency) !== strtoupper((string) $refundRequest->refund_currency)) {
                            throw new \RuntimeException(
                                "تضارب في العملة: لا يمكن إيداع استرجاع بعملة ({$refundRequest->refund_currency}) في خزينة تعمل بعملة ({$treasury->currency}). يرجى اختيار أو إنشاء خزينة مطابقة."
                            );
                        }

                        $cashboxAccount = $this->resolveCashboxAccount($treasury, $refundRequest->refund_currency, $userId);

                        $glAmounts = $this->glTransferAmounts(
                            $cashboxAccount->currency,
                            $customerAccount->currency,
                            $amount,
                            $amount * (float) $refundRequest->refund_exchange_rate,
                            (float) $refundRequest->refund_exchange_rate
                        );

                        // البند 8 (2026-11-03): لا يجوز أن يقل رصيد الخزينة عن الصفر
                        // بالصرف النقدي — يُفحص رصيد حساب الخزينة في الدفتر
                        // (قيد الأرصدة: balance = مجموع الدائن − مجموع المدين)
                        // قبل تنفيذ الصرف.
                        $treasuryAvailable = (float) $cashboxAccount->balance;
                        $cashOutAmount = (float) $glAmounts['amount'];
                        if ($treasuryAvailable < $cashOutAmount - 0.005) {
                            throw new BusinessLogicException(
                                sprintf(
                                    'رصيد الخزينة (%s %.2f) لا يكفي لصرف %.2f — لا يمكن أن يقل رصيد الخزينة عن الصفر.',
                                    strtoupper((string) $cashboxAccount->currency),
                                    $treasuryAvailable,
                                    $cashOutAmount
                                )
                            );
                        }

                        // القيد: مدين الخزينة (صرف نقدية) / دائن حساب العميل (تخفيض الرصيد الدائن للعميل)
                        $glTransaction = $this->transactionService->recordJournalTransfer([
                            'amount' => $glAmounts['amount'],
                            'converted_amount' => $glAmounts['converted_amount'],
                            'exchange_rate' => $glAmounts['exchange_rate'],
                            'from_account_id' => $cashboxAccount->id,
                            'to_account_id' => $customerAccount->id,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => CustomerCreditDisbursement::class,
                            'related_id' => $refundRequest->id,
                            'notes' => $data['notes'] ?? "صرف رصيد استرداد نقدي للعميل — طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                        ]);

                        $balanceBefore = (float) $treasury->current_balance;
                        $treasury->decrement('current_balance', $amount);
                        $balanceAfter = (float) $treasury->fresh()->current_balance;

                        $treasuryTransaction = $treasury->transactions()->create([
                            'transaction_type' => 'payment',
                            'amount' => $amount,
                            'currency' => $refundRequest->refund_currency,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $balanceAfter,
                            'reason' => 'صرف رصيد استرداد تذكرة طيران نقداً — طلب #'.$refundRequest->id,
                            'flight_booking_id' => $booking->id,
                            'refund_request_id' => $refundRequest->id,
                            'type' => 'debit',
                            'exchange_rate' => $refundRequest->refund_exchange_rate,
                            'base_amount' => $glAmounts['converted_amount'] ?? $amount,
                            'description' => 'صرف نقدي لرصيد العميل المسترد — طلب #'.$refundRequest->id,
                            'agent_name' => Auth::user()?->name ?? 'System',
                        ]);
                        $treasuryTransaction->linkToGl($glTransaction, $cashboxAccount->id);

                        TreasuryLedgerMirror::mirrorFlightOutboundFromCash(
                            $glTransaction,
                            $booking->id,
                            "صرف استرداد نقدي للعميل — طلب #{$refundRequest->id}",
                            User::find($userId)?->name ?? 'System'
                        );

                    } elseif ($disbursementType === CustomerCreditDisbursement::TYPE_BANK) {
                        // رد بنكي / تحويل
                        $bankAccountId = (int) ($data['bank_account_id'] ?? null);
                        if (! $bankAccountId) {
                            throw new \InvalidArgumentException('يجب تحديد الحساب البنكي للصرف.');
                        }
                        $bankAccount = Account::lockForUpdate()->findOrFail($bankAccountId);

                        $glAmounts = $this->glTransferAmounts(
                            $bankAccount->currency,
                            $customerAccount->currency,
                            $amount,
                            $amount * (float) $refundRequest->refund_exchange_rate,
                            (float) $refundRequest->refund_exchange_rate
                        );

                        $glTransaction = $this->transactionService->recordJournalTransfer([
                            'amount' => $glAmounts['amount'],
                            'converted_amount' => $glAmounts['converted_amount'],
                            'exchange_rate' => $glAmounts['exchange_rate'],
                            'from_account_id' => $bankAccount->id,
                            'to_account_id' => $customerAccount->id,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => CustomerCreditDisbursement::class,
                            'related_id' => $refundRequest->id,
                            'notes' => $data['notes'] ?? "صرف رصيد استرداد بتحويل بنكي للعميل — طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                        ]);

                    } elseif ($disbursementType === CustomerCreditDisbursement::TYPE_BOOKING_SETTLEMENT) {
                        // استخدام الرصيد الدائن في سداد حجز لاحق
                        $targetBookingId = (int) ($data['target_flight_booking_id'] ?? null);
                        if (! $targetBookingId) {
                            throw new \InvalidArgumentException('يجب تحديد الحجز المستهدف للتسوية.');
                        }
                        $targetBooking = FlightBooking::lockForUpdate()->findOrFail($targetBookingId);

                        if ((int) $targetBooking->customer_id !== (int) $customer->id) {
                            throw new BusinessLogicException('الحجز المستهدف لا ينتمي لنفس العميل.');
                        }

                        // رصيد الحجز المستهدف وديون الحجزات محفوظة بالجنيه
                        // المصري، بينما مبلغ الصرف بعملة الاسترجاع — فنحوّل
                        // المبلغ إلى الجنيه قبل المقارنة والتخصيص والترحيل.
                        $refundCurrency = strtoupper((string) ($refundRequest->refund_currency ?: 'EGP'));
                        $egpPerRefundUnit = $this->egpPerRefundUnit($refundRequest);
                        $settlementEgp = $refundCurrency === 'EGP'
                            ? round($amount, 2)
                            : round($amount * $egpPerRefundUnit, 2);

                        $remainingOnTarget = (float) $targetBooking->remaining_amount;
                        if ($settlementEgp > $remainingOnTarget + 0.005) {
                            throw new BusinessLogicException(
                                sprintf('مبلغ التسوية (%.2f) يتجاوز المتبقي على الحجز المستهدف (%.2f).', $settlementEgp, $remainingOnTarget)
                            );
                        }

                        // تخصيص التسوية يشير إلى قيد بيع الحجز المستهدف نفسه
                        // (لا إلى الحجز المُستَرَد ولا إلى قيمة احتياطية).
                        // القيمة الاحتياطية القديمة (?? 1) كانت تشير إلى أول
                        // قيد في الجدول — وهو في الواقع قيد شحن رصيد الناقل،
                        // أي مرجع خاطئ تماماً.
                        $targetSaleTransactionId = $targetBooking->sale_gl_transaction_id;
                        if (! $targetSaleTransactionId) {
                            throw new BusinessLogicException(
                                sprintf(
                                    'تعذّر تسوية رصيد العميل على الحجز #%s: لا يوجد قيد بيع مسجّل لهذا الحجز.',
                                    $targetBooking->booking_number
                                )
                            );
                        }

                        FlightPaymentAllocation::create([
                            'transaction_id' => $targetSaleTransactionId,
                            'flight_booking_id' => $targetBooking->id,
                            'customer_id' => $customer->id,
                            'amount' => $settlementEgp,
                            'status' => 'active',
                            'notes' => "تسوية من رصيد استرداد طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                            'allocated_at' => now(),
                        ]);

                        // البند 9 (2026-11-03): تسوية الحجز تُنشئ قيد GL مقابل خصم
                        // الـ ledger — مدين حساب العميل (استهلاك الرصيد الدائن)
                        // / دائن حساب المبيعات المعلّقة للحجز المستهدف (تسوية بيعه).
                        // بدون هذا القيد يبقى رصيد العميل في الدفتر غير مطابق
                        // لدفتر الرصيد الموحد.
                        $targetSalesClearingId = null;
                        if ($targetBooking->sale_gl_transaction_id) {
                            $origTargetSaleTx = Transaction::query()->find($targetBooking->sale_gl_transaction_id);
                            if ($origTargetSaleTx) {
                                $targetSalesClearingId = (int) $origTargetSaleTx->from_account_id;
                            }
                        }
                        if (! $targetSalesClearingId) {
                            $targetSalesClearingId = $this->clearingAccounts->pendingSalesReceivableIdForFlight();
                        }

                        $targetClearingAccount = Account::query()->find($targetSalesClearingId);
                        $targetCurrency = $targetClearingAccount
                            ? strtoupper((string) $targetClearingAccount->currency)
                            : strtoupper((string) $customerAccount->currency);
                        $targetEgpPerUnit = $targetCurrency === 'EGP'
                            ? 1.0
                            : FlightBookingService::egpPerUnitOfCurrency($targetCurrency);
                        $settlementGlAmounts = $this->glTransferAmounts(
                            $customerAccount->currency,
                            $targetCurrency,
                            $targetEgpPerUnit > 0 ? round($settlementEgp / $targetEgpPerUnit, 4) : $settlementEgp,
                            $settlementEgp,
                            $targetEgpPerUnit
                        );

                        $glTransaction = $this->transactionService->recordJournalTransfer([
                            'amount' => $settlementGlAmounts['amount'],
                            'converted_amount' => $settlementGlAmounts['converted_amount'],
                            'exchange_rate' => $settlementGlAmounts['exchange_rate'],
                            'from_account_id' => $customerAccount->id,
                            'to_account_id' => $targetSalesClearingId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => CustomerCreditDisbursement::class,
                            'related_id' => $refundRequest->id,
                            'notes' => $data['notes'] ?? "تسوية رصيد استرداد على حجز #{$targetBooking->booking_number} — طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                        ]);

                        $targetBooking->unsetRelation('paymentAllocations');
                        if ((float) $targetBooking->paid_amount >= (float) $targetBooking->selling_price - 0.01
                            && $targetBooking->status === FlightBookingStatus::PENDING
                            && ! empty($targetBooking->pnr)) {
                            $targetBooking->update(['status' => FlightBookingStatus::CONFIRMED]);
                        }
                    } else {
                        throw new \InvalidArgumentException("نوع الصرف '{$disbursementType}' غير مدعوم.");
                    }

                    // إنشاء سجل الصرف
                    $disbursement = CustomerCreditDisbursement::create([
                        'refund_request_id' => $refundRequest->id,
                        'customer_id' => $customer->id,
                        'disbursement_type' => $disbursementType,
                        'amount' => $amount,
                        'treasury_id' => $treasuryId,
                        'bank_account_id' => $bankAccountId,
                        'target_flight_booking_id' => $targetBookingId,
                        'transaction_id' => $glTransaction?->id,
                        'status' => CustomerCreditDisbursement::STATUS_COMPLETED,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $userId,
                    ]);

                    // خصم الرصيد المنصرف من سجل رصيد العميل الموحد (CustomerCreditLedger)
                    CustomerCreditLedger::recordEntry([
                        'customer_id' => $customer->id,
                        'company_id' => $refundRequest->company_id,
                        'source_type' => 'disbursement',
                        'source_id' => $disbursement->id,
                        'amount' => -$amount,
                        'currency' => $refundRequest->refund_currency,
                        'notes' => $data['notes'] ?? "صرف رصيد استرداد ({$disbursementType}) — طلب #{$refundRequest->id}",
                        'created_by' => $userId,
                    ]);

                    // تحديث مبالغ وحالة الصرف على طلب الاسترداد
                    $newDisbursedTotal = round((float) $refundRequest->disbursed_amount + $amount, 2);
                    $refundRequest->disbursed_amount = $newDisbursedTotal;
                    $refundRequest->disbursement_status = ($newDisbursedTotal >= (float) $refundRequest->customer_credit_amount - 0.005)
                        ? 'fully_disbursed'
                        : 'partially_disbursed';
                    $refundRequest->save();

                    Log::info('Customer credit disbursed successfully', [
                        'refund_request_id' => $refundRequest->id,
                        'disbursement_id' => $disbursement->id,
                        'type' => $disbursementType,
                        'amount' => $amount,
                        'new_disbursed_total' => $newDisbursedTotal,
                    ]);

                    return $disbursement;
                })
            )
        );
    }

    /**
     * تأكيد استرداد المورد (Supplier Refund Confirmation)
     * مع فحص الفروق عن المتوقع وتسجيلها في حساب فروق استرداد مستقل دون تحميل العميل.
     */
    public function confirmSupplierRefund(int $refundRequestId, int $userId, ?float $actualAirlineRefundAmount = null): RefundRequest
    {
        return $this->withDeadlockRetry(fn () => DB::transaction(function () use ($refundRequestId, $userId, $actualAirlineRefundAmount) {
            $refundRequest = RefundRequest::lockForUpdate()->findOrFail($refundRequestId);
            $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);

            if ($actualAirlineRefundAmount !== null) {
                $actual = round($actualAirlineRefundAmount, 2);
                $expected = (float) $refundRequest->supplier_refund_amount;
                $variance = max(0.0, round($expected - $actual, 2));

                if ($variance > 0) {
                    // تعديل رصيد المورد بنقص المسترد فعلياً
                    if ($booking->purchase_balance_source === 'carrier' || (! $booking->purchase_balance_source && $booking->flight_carrier_id)) {
                        $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                        if ($carrier) {
                            $debitSub = $this->flightBookingService->purchaseAmountInBalanceCurrency(
                                (string) $carrier->currency,
                                'EGP',
                                $variance,
                                null,
                                $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
                            );
                            if ($debitSub > 0) {
                                $carrier->debit($debitSub, $booking->id, $userId);
                            }
                        }
                    } elseif ($booking->purchase_balance_source === 'system' && $booking->flight_system_id) {
                        $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                        if ($system) {
                            $debitSub = $this->flightBookingService->purchaseAmountInBalanceCurrency(
                                (string) $system->currency,
                                'EGP',
                                $variance,
                                null,
                                $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
                            );
                            if ($debitSub > 0) {
                                $system->debit($debitSub, $booking->id, $userId);
                            }
                        }
                    }

                    // قيد فروق استرداد مستقل ولا يُحمَّل على العميل
                    $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                    $refundVarianceAccountId = $this->clearingAccounts->refundVarianceAccountId();

                    $this->transactionService->recordJournalTransfer([
                        'amount' => $variance,
                        'from_account_id' => $expenseContraId,
                        'to_account_id' => $refundVarianceAccountId,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => RefundRequest::class,
                        'related_id' => $refundRequest->id,
                        'notes' => "إثبات فروق استرداد تذكرة طيران (المسترد الفعلي أقل من المتوقع) — طلب #{$refundRequest->id}",
                        'created_by' => $userId,
                    ]);

                    $refundRequest->actual_airline_refund_amount = $actual;
                    $refundRequest->refund_variance_amount = $variance;
                } else {
                    $refundRequest->actual_airline_refund_amount = $actual;
                    $refundRequest->refund_variance_amount = 0.0;
                }
            }

            $refundRequest->supplier_refund_confirmed = true;
            $refundRequest->supplier_refund_confirmed_at = now();
            $refundRequest->save();

            Log::info('Supplier refund manually confirmed with variance check', [
                'refund_request_id' => $refundRequestId,
                'actual_airline_refund_amount' => $refundRequest->actual_airline_refund_amount,
                'refund_variance_amount' => $refundRequest->refund_variance_amount,
                'user_id' => $userId,
            ]);

            return $refundRequest;
        }));
    }

    /**
     * عكس إيراد المدفوعات المسجلة في P&L للحجز
     *
     * Two P&L-only effects are produced here — BOTH of them must be
     * reversible, otherwise cancelling the refund request (reverseRefundRequest)
     * leaves the P&L stuck in the "refunded" state forever:
     *
     *   • full refund  → the payment income rows are marked "عكس:" (skipped by
     *     the P&L engine) — their ids are returned so the caller can restore
     *     the notes when the refund request is cancelled;
     *   • partial refund → a MEMO transaction (no account_entries) that
     *     reverses the recognised revenue. It is a memo on purpose: it must
     *     not touch the balance sheet (the money never left the cashbox, the
     *     customer got a credit note). Its id is returned for the same reason.
     *
     * @return array{memo_transaction_id: int|null, reversed_income_transaction_ids: list<int>}
     */
    protected function reverseRevenueImpact(FlightBooking $booking, RefundRequest $refundRequest, int $userId): array
    {
        $state = [
            'memo_transaction_id' => null,
            'reversed_income_transaction_ids' => [],
        ];

        $refundAmount = (float) $refundRequest->refund_amount;
        $totalRecognisedRevenue = (float) $booking->payments()
            ->whereNotNull('transaction_id')
            ->sum('amount');

        $isFullRefund = $totalRecognisedRevenue > 0
            && abs($totalRecognisedRevenue - $refundAmount) < 0.01;

        if ($isFullRefund) {
            $state['reversed_income_transaction_ids'] = $this->flightBookingService->reverseFlightBookingRevenue(
                $booking,
                $userId
            );
        } else {
            $firstPayment = $booking->payments()
                ->whereNotNull('transaction_id')
                ->with('transaction')
                ->first();

            if ($firstPayment && $firstPayment->transaction) {
                $origIncome = $firstPayment->transaction;
                $incomeClearingId = $this->clearingAccounts->incomeContraIdForFlightBooking();

                if ($incomeClearingId) {
                    $reversalAmountEgp = (float) ($refundRequest->base_currency_refund ?? 0);
                    if ($reversalAmountEgp <= 0) {
                        $refundExchangeRate = (float) ($refundRequest->refund_exchange_rate ?? 1.0);
                        $reversalAmountEgp = $refundAmount * $refundExchangeRate;
                    }

                    $cashboxAccountId = (int) $origIncome->to_account_id;

                    $memo = Transaction::create([
                        'type' => TransactionType::Transfer->value,
                        'amount' => $reversalAmountEgp,
                        'currency' => $refundRequest->refund_currency,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightBooking::class,
                        'related_id' => $booking->id,
                        'from_account_id' => $cashboxAccountId,
                        'to_account_id' => $incomeClearingId,
                        'created_by' => $userId,
                        'notes' => "عكس إيراد مدفوعات جزئي ضمن الاسترداد (مخصوماً منه رسوم الإلغاء {$refundRequest->cancellation_fee}) — حجز #{$booking->booking_number}",
                    ]);

                    // The memo is the ONLY holder of the "partial revenue
                    // reversal" — keep its id so reverseRefundRequest() can
                    // flip it (see the notes-prefix contract of the P&L
                    // engine: rows starting with "عكس:" are skipped).
                    $state['memo_transaction_id'] = (int) $memo->id;
                }
            }
        }

        return $state;
    }

    /**
     * إلغاء وعكس طلب استرجاع (Full Reversal with Soft Delete)
     */
    public function reverseRefundRequest(int $refundRequestId, int $userId): RefundRequest
    {
        return $this->withDeadlockRetry(
            fn () => LedgerBalanceMutationGuard::run(
                fn () => DB::transaction(function () use ($refundRequestId, $userId) {
                    $refundRequest = RefundRequest::withTrashed()
                        ->lockForUpdate()
                        ->findOrFail($refundRequestId);

                    if ($refundRequest->trashed()) {
                        throw new \RuntimeException('هذا الطلب محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.');
                    }

                    if ($refundRequest->status !== 'processed') {
                        $refundRequest->delete();
                        return $refundRequest;
                    }

                    // التحقق من وجود حركات صرف مسبقة
                    $activeDisbursements = $refundRequest->disbursements()
                        ->where('status', CustomerCreditDisbursement::STATUS_COMPLETED)
                        ->count();

                    if ($activeDisbursements > 0) {
                        throw new BusinessLogicException(
                            'لا يمكن إلغاء طلب الاسترجاع لوجود حركات صرف نقدية/بنكية منفذة على رصيده. يرجى عكس حركات الصرف أولاً.'
                        );
                    }

                    $booking = FlightBooking::lockForUpdate()->findOrFail($refundRequest->flight_booking_id);
                    $customerAccount = $this->ensureCustomerAccount((int) $booking->customer_id);

                    $sellingPriceEgp = (float) $booking->selling_price;
                    $supplierCost = (float) ($refundRequest->supplier_cost ?: ($booking->purchase_price_egp ?: $booking->purchase_price));
                    $supplierRefundAmount = (float) ($refundRequest->supplier_refund_amount ?? $supplierCost);
                    $supplierRefundLoss = (float) ($refundRequest->supplier_refund_loss ?? max(0.0, $supplierCost - $supplierRefundAmount));
                    $cancellationFee = (float) ($refundRequest->cancellation_fee ?? 0.0);

                    // نفس قاعدة التحويل المستخدمة في processRefundRequest:
                    // مبالغ الطلب بعملة الحجز، وحسابات الدفتر بالجنيه.
                    $refundCurrency = strtoupper((string) ($refundRequest->refund_currency ?: 'EGP'));
                    $egpPerRefundUnit = $this->egpPerRefundUnit($refundRequest);
                    $storedNetRefund = (float) ($refundRequest->refund_amount ?? 0.0);
                    $storedNetRefundEgp = (float) ($refundRequest->base_currency_refund ?: 0.0) > 0
                        ? round((float) $refundRequest->base_currency_refund, 2)
                        : round($storedNetRefund * $egpPerRefundUnit, 2);
                    $supplierRefundAmountEgp = round($supplierRefundAmount * $egpPerRefundUnit, 2);
                    $supplierRefundLossEgp = round($supplierRefundLoss * $egpPerRefundUnit, 2);

                    // 1. عكس الطرف (أ): المورد
                    if ($booking->purchase_balance_source === 'carrier' || (! $booking->purchase_balance_source && $booking->flight_carrier_id)) {
                        $carrier = FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                        if ($carrier && $supplierRefundAmount > 0) {
                            $debitSub = $this->supplierAmountInBalanceCurrency(
                                (string) $carrier->currency,
                                $refundCurrency,
                                $supplierRefundAmount,
                                $supplierRefundAmountEgp,
                                $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
                            );
                            if ($debitSub > 0) {
                                $carrier->debit($debitSub, $booking->id, $userId);
                            }
                            $this->prepaidLedgerService->consumeCogs(
                                'flight_carrier',
                                TransactionModule::Flight,
                                $supplierRefundAmountEgp,
                                "عكس استرداد تكلفة حجز {$booking->booking_number} — ناقل",
                                FlightBooking::class,
                                $booking->id
                            );
                        }
                    } elseif ($booking->purchase_balance_source === 'system' && $booking->flight_system_id) {
                        $system = FlightSystem::lockForUpdate()->find($booking->flight_system_id);
                        if ($system && $supplierRefundAmount > 0) {
                            $debitSub = $this->supplierAmountInBalanceCurrency(
                                (string) $system->currency,
                                $refundCurrency,
                                $supplierRefundAmount,
                                $supplierRefundAmountEgp,
                                $this->flightBookingService->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
                            );
                            if ($debitSub > 0) {
                                $system->debit($debitSub, $booking->id, $userId);
                            }
                            $this->prepaidLedgerService->consumeCogs(
                                'flight_system',
                                TransactionModule::Flight,
                                $supplierRefundAmountEgp,
                                "عكس استرداد تكلفة حجز {$booking->booking_number} — نظام",
                                FlightBooking::class,
                                $booking->id
                            );
                        }
                    }

                    // عكس قيد خسارة الاسترداد إن كان مسجلاً
                    if ($supplierRefundLoss > 0) {
                        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                        $refundLossAccountId = $this->clearingAccounts->refundLossAccountId();

                        $this->transactionService->recordJournalTransfer([
                            'amount' => $supplierRefundLossEgp,
                            'from_account_id' => $refundLossAccountId,
                            'to_account_id' => $expenseContraId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "عكس إثبات خسارة/غرامة استرداد — حذف طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                        ]);
                    }

                    // 2. عكس الطرف (ب): عميل الشركة
                    $salesClearingId = null;
                    if ($booking->sale_gl_transaction_id) {
                        $origSaleTx = Transaction::query()->find($booking->sale_gl_transaction_id);
                        if ($origSaleTx) {
                            $salesClearingId = (int) $origSaleTx->from_account_id;
                        }
                    }
                    if (! $salesClearingId) {
                        $salesClearingId = $this->clearingAccounts->pendingSalesReceivableIdForFlight();
                    }

                    // عكس قيد إيراد عمولة الإلغاء
                    $agencyFee = (float) ($refundRequest->agency_commission ?: 0.0);
                    if ($agencyFee <= 0 && $cancellationFee > 0 && ! $refundRequest->airline_penalty) {
                        $agencyFee = $cancellationFee;
                    }
                    if ($agencyFee > 0 && $salesClearingId) {
                        $cancellationFeeAccountId = $this->clearingAccounts->cancellationFeeRevenueAccountId();
                        // عكس Leg 2 بعد تغيير اتجاهه (البند 10):
                        //   forward  Leg 2: from=cancellationFee → to=salesClearing
                        //   reversal Leg 2: from=salesClearing → to=cancellationFee
                        $this->transactionService->recordJournalTransfer([
                            'amount' => round($agencyFee * $egpPerRefundUnit, 2),
                            'from_account_id' => $salesClearingId,
                            'to_account_id' => $cancellationFeeAccountId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "عكس تحرير عمولة إلغاء تذكرة من حساب المبيعات المعلّقة — حذف طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                        ]);
                    }

                    // عكس غرامة الطيران / الضرائب غير المستردة
                    // الاتجاه المعكوس لـ Leg 3 (بعد الإصلاح):
                    //   forward  Leg 3: from=expenseContra → to=salesClearing (مدين Acc5 / دائن Acc6)
                    //   reversal Leg 3: from=salesClearing → to=expenseContra  (مدين Acc6 / دائن Acc5)
                    $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
                    $penaltyOrTaxes = (float) ($refundRequest->airline_penalty ?? 0.0);
                    if ($refundRequest->refund_case === 'airline_cancelled' && $refundRequest->non_refundable_taxes_on_customer) {
                        $penaltyOrTaxes += (float) ($refundRequest->non_refundable_taxes_amount ?? 0.0);
                    }
                    if ($penaltyOrTaxes > 0 && $salesClearingId) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => round($penaltyOrTaxes * $egpPerRefundUnit, 2),
                            'from_account_id' => $salesClearingId,
                            'to_account_id' => $expenseContraId,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "عكس إقفال غرامة/ضرائب الاسترداد — حذف طلب #{$refundRequest->id}",
                            'created_by' => $userId,
                        ]);
                    }

                    // عكس حركة رصيد العميل الموحد (CustomerCreditLedger) إن وُجدت
                    if ($refundRequest->credit_ledger_entry_id) {
                        $entry = CustomerCreditLedger::find($refundRequest->credit_ledger_entry_id);
                        if ($entry && ! $entry->reversed_at) {
                            $entry->reverse($userId, "إلغاء وعكس طلب الاسترداد #{$refundRequest->id}");
                        }
                    }

                    // إعادة قيد مبيعات الحجز للعميل
                    // الاتجاه المعكوس لـ Leg 1 (بعد الإصلاح):
                    //   forward  Leg 1: from=customerAccount → to=salesClearing, amount=netCustomerRefund
                    //   reversal Leg 1: from=salesClearing → to=customerAccount, amount=netCustomerRefund
                    // نستخدم refundRequest->refund_amount كمصدر موثوق لـ netCustomerRefund
                    if ($storedNetRefund > 0 && $salesClearingId) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => $storedNetRefundEgp,
                            'from_account_id' => $salesClearingId,
                            'to_account_id' => $customerAccount->id,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => "إعادة قيد مبيعات الحجز (عكس صافي المسترد) بعد حذف طلب الاسترداد #{$refundRequest->id} — حجز #{$booking->booking_number}",
                            'created_by' => $userId,
                        ]);
                    }

                    // ─────────────────────────────────────────────────────────────
                    // استرجاع أثر الـ P&L الذي سبّبه الاسترداد
                    //
                    // الاسترداد يعمل على الـ P&L بطريقتين (كلتاهما بلا أثر على
                    // أرصدة الحسابات — لذلك يجب التراجع عنها صراحةً):
                    //   1) الاسترداد الجزئي: قيد memo بلا account_entries يعكس
                    //      الإيراد المعترف به → يُوسم بـ "عكس:" فيتجاهله محرك
                    //      الـ P&L، فيعود الإيراد كما كان.
                    //   2) الاسترداد الكامل: قيود الإيراد (type=income) تُوسم
                    //      بـ "عكس:" بواسطة reverseFlightBookingRevenue → نُعيد
                    //      ملاحظاتها الأصلية فيعود الإيراد للـ P&L.
                    // ─────────────────────────────────────────────────────────────
                    $reversalState = $refundRequest->reversal_state;
                    if (is_array($reversalState)) {
                        foreach ((array) ($reversalState['reversed_income_transaction_ids'] ?? []) as $incomeTxId) {
                            $incomeTx = Transaction::query()->find((int) $incomeTxId);
                            if (! $incomeTx) {
                                continue;
                            }
                            $incomeNotes = (string) ($incomeTx->notes ?? '');
                            if (str_starts_with($incomeNotes, 'عكس: ')) {
                                $incomeTx->notes = mb_substr($incomeNotes, mb_strlen('عكس: '));
                                $incomeTx->save();
                            }
                        }

                        $memoId = (int) ($reversalState['memo_transaction_id'] ?? 0);
                        if ($memoId > 0) {
                            $memoTx = Transaction::query()->find($memoId);
                            if ($memoTx) {
                                $memoNotes = (string) ($memoTx->notes ?? '');
                                // NOTE: markTransactionReversed() refuses rows whose
                                // notes already start with "عكس " (this memo does),
                                // so the prefix is set here directly.
                                if (! str_starts_with($memoNotes, 'عكس:')) {
                                    $memoTx->notes = 'عكس: '.$memoNotes;
                                    $memoTx->save();
                                }
                            }
                        }
                    }

                    // إلغاء قسيمة AirlineCredit إن كانت موجودة
                    $credit = $refundRequest->airlineCredit()->first();
                    if ($credit && ! $credit->trashed() && $credit->status !== 'cancelled') {
                        $credit->cancelCredit();
                    }

                    // عكس تخصيصات الديون على الحجوزات الأخرى FIFO
                    FlightPaymentAllocation::query()
                        ->where('notes', 'like', "%استرداد حجز #{$booking->booking_number}%")
                        ->where('status', 'active')
                        ->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $userId]);

                    // استعادة حالة الحجز إلى مؤكد إذا لم تكن هناك طلبات استرداد نشطة أخرى
                    $hasOtherActiveRefunds = RefundRequest::query()
                        ->where('flight_booking_id', $booking->id)
                        ->where('id', '!=', $refundRequest->id)
                        ->where('status', 'processed')
                        ->exists();

                    if (! $hasOtherActiveRefunds) {
                        $booking->status = FlightBookingStatus::CONFIRMED;
                        $booking->save();
                    }

                    // حذف طلب الاسترداد soft delete
                    $refundRequest->delete();

                    return $refundRequest;
                })
            )
        );
    }
}
