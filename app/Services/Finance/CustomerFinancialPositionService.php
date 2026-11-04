<?php

namespace App\Services\Finance;

use App\Enums\FlightBookingStatus;
use App\Models\CustomerCreditLedger;
use App\Models\ExchangeRate;
use App\Models\Flight\FlightBooking;
use Illuminate\Support\Facades\Log;

/**
 * المركز المالي للعميل — مصدر الحقيقة الوحيد لرصيد حساب العميل.
 *
 * المعادلة المحاسبية:
 *
 *     رصيد العميل الصافي = الرصيد الدائن المتاح (L) − الديون المفتوحة (D)
 *     ≡ -Account.balance
 *
 * حيث:
 *   • L = مجموع حركات `customer_credit_ledger` غير المعكوسة (رصيد دائن متاح).
 *   • D = مجموع `remaining_amount` للحجوزات الحية غير الملغاة وغير المستردة.
 *
 * و`Account.balance` (رصيد الدفتر = مجموع الدائن − مجموع المدين):
 *   • موجب = دين العميل علينا (ذمم مدينة).
 *   • سالب = رصيد دائن مستحق للعميل عندنا.
 *
 * ملاحظة مهمة: تسوية حجز بالرصيد الدائن إعادة توزيع داخلية — تُنقص L وتُنقص D
 * بمقدار واحد، فلا يغيّر المركز المالي ولا يُنشأ لها قيد GL إضافي.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Phase 10 (2026-11-04) — توحيد العملات (Multi-Currency Consolidation):
 *
 * `FlightBooking.selling_price` / `paid_amount` / `remaining_amount` **دائماً**
 * بالجنيه المصري (EGP) — العملة الأساسية. كما أن `FlightPaymentAllocation.amount`
 * دائماً بالجنيه. لكن `customer_credit_ledger` قد يحرّك بعملات مختلفة (رصيد
 * عميل بالدولار مثلاً)، لأن القيد يُسجَّل بعملة الرصيد الذي استُهلك فعلياً.
 *
 * لذلك:
 *   • `D` (الديون المفتوحة) = دائماً بالجنيه.
 *   • `L` (الرصيد الدائن) = قد يكون موزّعاً على عدة عملات ⇒ يُوحَّد بالجنيه
 *     قبل المقارنة مع `Account.balance` (الذي بالجنيه أيضاً).
 *
 * بدون هذا التوحيد تختلف الدفاتر: عميل رصيده 100 USD (= 5,000 ج.م) يُقرأ
 * رصيده الدائن على سلة EGP فقط ⇒ صفر، بينما دفتر الأستاذ يقول 5,000 ج.م.
 */
class CustomerFinancialPositionService
{
    public function __construct(
        protected TreasuryService $treasuryService,
    ) {
    }

    /**
     * إجمالي الديون المفتوحة (غير المسددة) للعميل أو للشركة عبر حجوزات الطيران.
     *
     * المعايير مطابقة تماماً لمنطق تسوية الديون FIFO في `RefundService`
     * (الحجوزات الحية غير الملغاة وغير المستردة).
     *
     * ⚠️ `remaining_amount` مُشتق من `selling_price − paid_amount` — وكلاهما
     * بالجنيه المصري دائماً ⇒ لا حاجة لتحويل هنا.
     */
    public function getOpenDebt(int $customerId, ?int $companyId = null): float
    {
        $query = FlightBooking::query()
            ->whereNull('deleted_at')
            ->whereNotIn('status', [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED]);

        if ($companyId) {
            $query->where(function ($q) use ($customerId, $companyId) {
                $q->where('customer_id', $customerId)
                    ->orWhere('customer_id', $companyId)
                    ->orWhereHas('customer', fn ($cq) => $cq->where('company_id', $companyId));
            });
        } else {
            $query->where('customer_id', $customerId);
        }

        // remaining_amount مُشتق: max(0, selling_price - paid_amount)
        return round((float) $query->get()->sum(fn ($b) => (float) $b->remaining_amount), 2);
    }

    /**
     * الرصيد الدائن المتاح من دفتر رصيد العميل الموحد — بعملة واحدة.
     */
    public function getAvailableCredit(int $customerId, ?int $companyId = null, string $currency = 'EGP'): float
    {
        return CustomerCreditLedger::getAvailableCredit($customerId, $companyId, $currency);
    }

    /**
     * PHASE 10 — إجمالي الرصيد الدائن موحَّداً بالجنيه المصري (EGP).
     *
     * يجمع كل حركات الدفتر غير المعكوسة للعميل (أو للشركة) ويحوّلها بالجنيه
     * المصري، لأن `Account.balance` في دفتر الأستاذ بالجنيه دائماً.
     *
     * ترتيب تحويل السعر (الأولوية):
     *   ① `exchange_rates` — آخر سعر صرف مُفعَّل (المصدر الرسمي لسعر الصرف).
     *   ② `TreasuryService::getAveragePurchaseRate()` — متوسط سعر الشراء
     *      (ويشمل بدوره جدول `currencies` في إعدادات الإدمن كبديل أخير).
     *
     * @return float الرصيد الدائن الإجمالي بالجنيه المصري.
     */
    public function getTotalCreditInEgp(int $customerId, ?int $companyId = null): float
    {
        $rows = CustomerCreditLedger::query()
            ->whereNull('reversed_at')
            ->when(
                $companyId,
                fn ($query) => $query->where('company_id', $companyId),
                fn ($query) => $query->where('customer_id', $customerId)
            )
            ->get(['currency', 'amount']);

        // تجميع أولي بالعملة (لتقليل عدد تحويلات السعر).
        $byCurrency = [];
        foreach ($rows as $row) {
            $currency = strtoupper((string) ($row->currency ?: 'EGP'));
            $byCurrency[$currency] = round(($byCurrency[$currency] ?? 0.0) + (float) $row->amount, 2);
        }

        $total = 0.0;
        foreach ($byCurrency as $currency => $amount) {
            $total += $amount * $this->egpPerCreditUnit($currency);
        }

        return round($total, 2);
    }

    /**
     * عدد الجنيهات المصرية مقابل وحدة واحدة من عملة الرصيد.
     *
     * EGP → 1.0 دائماً. وأي عملة أخرى تُحوَّل بأحدث سعر صرف مُسجَّل، وإلا
     * بمتوسط سعر الشراء المعتمد في النظام.
     */
    public function egpPerCreditUnit(string $currency): float
    {
        $code = strtoupper(trim($currency));

        if ($code === '' || $code === 'EGP') {
            return 1.0;
        }

        // ① سعر الصرف المسجَّل (exchange_rates) — الأحدث الفعّال.
        $rate = ExchangeRate::query()
            ->where('from_currency', $code)
            ->where('to_currency', 'EGP')
            ->where('is_active', true)
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($rate !== null && (float) $rate > 0) {
            return (float) $rate;
        }

        // ② متوسط سعر الشراء (يتضمن جدول `currencies` كبديل أخير).
        $rate = (float) $this->treasuryService->getAveragePurchaseRate($code);

        if ($rate <= 0) {
            Log::warning('Customer financial position: no exchange rate for credit currency', [
                'currency' => $code,
            ]);

            return 1.0;
        }

        if (abs($rate - 1.0) < 0.000001) {
            Log::warning('Customer financial position: credit currency has no registered exchange rate — valued at 1.0', [
                'currency' => $code,
            ]);
        }

        return $rate;
    }

    /**
     * تفصيل المركز المالي: الرصيد الدائن المتاح، الديون المفتوحة، وصافي المركز.
     *
     * صافي المركز يطابق رصيد حساب العميل في دفتر الأستاذ: -(Account.balance).
     *
     * PHASE 10: `net_balance` يُحسب من **الرصيد الدائن الموحَّد بالجنيه**
     * (`total_credit_egp`) لا من سلة عملة واحدة — لأن `Account.balance`
     * بالجنيه دائماً. أما `available_credit` فيبقى بعملة `currency`
     * المطلوبة (رصيد تلك العملة وحدها).
     *
     * @return array{currency: string, available_credit: float, total_credit_egp: float, open_debt: float, net_balance: float}
     */
    public function getPosition(int $customerId, ?int $companyId = null, string $currency = 'EGP'): array
    {
        $availableCredit = $this->getAvailableCredit($customerId, $companyId, $currency);
        $totalCreditEgp = $this->getTotalCreditInEgp($customerId, $companyId);
        $openDebt = $this->getOpenDebt($customerId, $companyId);
        $netBalance = round($totalCreditEgp - $openDebt, 2);

        return [
            'currency'         => $currency,
            'available_credit' => $availableCredit,
            'total_credit_egp' => $totalCreditEgp,
            'open_debt'        => $openDebt,
            'net_balance'      => $netBalance,
        ];
    }
}
