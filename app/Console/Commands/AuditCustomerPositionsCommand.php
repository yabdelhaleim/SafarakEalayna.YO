<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Services\Finance\CustomerFinancialPositionService;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تدقيق المراكز المالية للعملاء (Phase 7 — 2026-11-03 · Phase 10 — 2026-11-04).
 *
 * يفحص المعادلة المحاسبية الحاكمة لكل عميل:
 *
 *     -Account.balance  =  AvailableCredit (L)  −  OpenDebt (D)
 *
 * ويطبع جدولاً يوضّح كل مركّب، ثم يسجّل `Log::critical` لكل عميل يتجاوز
 * الفرق فيه الحد المسموح (0.005) — أي عميل يخالف المعادلة.
 *
 * ملاحظة النطاق: يُفحص كل عميل في نطاقه الخاص (حركاته في دفتر الرصيد
 * الدائن + حجوزاته + حسابه في دفتر الأستاذ). وحركات شركة تُفحص ضمن نطاق
 * الشركة (COMPANY) لأن رصيدها مُشترك بين كل موظفيها.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Phase 10 (2026-11-04) — عرف وحدات المكتب (debit-first) + التسوية التاريخية:
 *
 * ① **توحيد العملات**: `L` يُقرأ موحَّداً بالجنيه المصري
 *    (`CustomerFinancialPositionService::getTotalCreditInEgp()`) لأن
 *    `Account.balance` بالجنيه دائماً. عميل رصيده 100 USD يُحسب رصيده
 *    الدائن 5,000 ج.م لا صفراً.
 *
 * ② **عرف وحدات المكتب (debit-first)**: حسابات عملاء وحدات المكتب
 *    (office / bus / fawry / online / wallet_transfer) تُدار بعرف إشارة
 *    **مدين أولاً** — رصيدها الموجب هو تعرض المكتب للعميل (ذمة)، خلافاً
 *    لحسابات وحدات السياحة (flights / hajj_umra / visas) التي تقرأ
 *    بـ `−Account.balance`. لذلك يُقارَن كل عميل بعرف وحدةه:
 *
 *        Position_GL = debit-first ? (+Account.balance) : (−Account.balance)
 *
 * ③ **التسوية التاريخية (`--reconcile`)**: حسابات أُنشئت قبل ترحيل دفتر
 *    الرصيد الموحد (`2026_11_02_140000`) ولها رصيد في دفتر الأستاذ بلا أي
 *    صف في `customer_credit_ledger` ⇒ الدفتر ناقص الطرف الآخر من المعادلة.
 *    عند تمرير `--reconcile` يُنشأ قيد افتتاحي في الدفتر بمقدار الفرق، فيتطابق
 *    الدفتر مع دفتر الأستاذ. والعملية **مُعرَّفة idempotent**: لا تُنشأ
 *    قيود لعميل له صفوف في الدفتر أصلاً.
 */
#[Signature(
    signature: 'audit:customer-positions
        {--customer_id= : فحص عميل واحد فقط (معرّفه)}
        {--reconcile : تسوية الفروق التاريخية بإضافة قيود افتتاحية إلى دفتر الرصيد الدائن}',
)]
class AuditCustomerPositionsCommand extends Command
{
    /**
     * الحد المسموح به في الفرق (نفس tolerance المستخدم في باقي النظام).
     */
    protected const TOLERANCE = 0.005;

    /**
     * وحدات المكتب التي تُدار بعرف الإشارة **مدين أولاً** (debit-first).
     *
     * رصيد حساب العميل فيها الموجب = تعرض المكتب للعميل (ذمة مدينة)،
     * فيُقرأ موضعه_GL على أنه `+Account.balance` لا `−Account.balance`.
     */
    protected const DEBIT_FIRST_MODULE_TYPES = [
        'OFFICE',
        'BUS',
        'FAWRY',
        'ONLINE',
        'WALLET_TRANSFER',
    ];

    /**
     * تاريخ ترحيل دفتر الرصيد الدائن الموحد. الحسابات المنشأة قبله لم
     * يُسجَّل لها أي صف في الدفتر — وهي وحدها مرشَّحة للتسوية التاريخية.
     */
    protected const CREDIT_LEDGER_MIGRATION_AT = '2026-11-02 14:00:00';

    /**
     * نوع الحركة الافتتاحية التي تُنشئها التسوية التاريخية.
     */
    protected const SOURCE_OPENING_BALANCE = 'opening_balance';

    protected $description = 'تدقيق معادلة رصيد العميل: -Account.balance = الرصيد الدائن المتاح − الديون المفتوحة';

    public function handle(CustomerFinancialPositionService $positionService): int
    {
        $customerId = $this->option('customer_id');
        $reconcile = (bool) $this->option('reconcile');

        $query = Customer::query()->with('ledgerAccount');

        if ($customerId !== null && $customerId !== '') {
            $customerId = (int) $customerId;

            if (! Customer::query()->whereKey($customerId)->exists()) {
                $this->error("العميل #{$customerId} غير موجود.");

                return self::FAILURE;
            }

            $query->whereKey($customerId);
        }

        $rows = [];
        $mismatches = [];
        $reconciled = 0;

        foreach ($query->orderBy('id')->get() as $customer) {
            $account = $customer->ledgerAccount;

            [$gl, $isDebitFirst] = $this->glPosition($account);

            // الرصيد الدائن موحَّد بالجنيه + الديون المفتوحة (بالجنيه).
            $position = $positionService->getPosition((int) $customer->id, null, 'EGP');

            $credit = (float) $position['total_credit_egp'];
            $debt = (float) $position['open_debt'];
            $net = (float) $position['net_balance'];
            $diff = round($gl - $net, 2);

            // ─────────────────────────────────────────────────────────────────
            // التسوية التاريخية: قيد افتتاحي في الدفتر يطابق رصيد دفتر
            // الأستاذ للأرشيف الذي سبق دفتر الرصيد الموحد.
            // ─────────────────────────────────────────────────────────────────
            if ($reconcile && abs($diff) > self::TOLERANCE) {
                $entry = $this->reconcileLegacyPosition($customer, $account, $diff);

                if ($entry !== null) {
                    $reconciled++;

                    // إعادة الحساب بعد التسوية.
                    $position = $positionService->getPosition((int) $customer->id, null, 'EGP');
                    $credit = (float) $position['total_credit_egp'];
                    $debt = (float) $position['open_debt'];
                    $net = (float) $position['net_balance'];
                    $diff = round($gl - $net, 2);
                }
            }

            $rows[] = [
                (int) $customer->id,
                $customer->full_name,
                $isDebitFirst ? 'مدين أولاً' : 'دائن',
                number_format($gl, 2),
                number_format($credit, 2),
                number_format($debt, 2),
                number_format($net, 2),
                number_format($diff, 2),
                abs($diff) > self::TOLERANCE ? '✗' : '✓',
            ];

            if (abs($diff) > self::TOLERANCE) {
                $mismatches[] = [
                    'customer_id' => (int) $customer->id,
                    'customer_name' => $customer->full_name,
                    'gl' => $gl,
                    'available_credit' => $credit,
                    'open_debt' => $debt,
                    'expected_gl' => $net,
                    'diff' => $diff,
                    'currency' => 'EGP',
                    'sign_convention' => $isDebitFirst ? 'debit_first' : 'credit_first',
                ];
            }
        }

        $this->table(
            [
                'العميل #',
                'الاسم',
                'عرف الإشارة',
                'GL (موضع العميل)',
                'الرصيد الدائن (L)',
                'الديون المفتوحة (D)',
                'L − D',
                'الفرق',
                'متوافق',
            ],
            $rows
        );

        if ($reconcile) {
            $this->line(sprintf('تمت تسوية %d عميل بقيود افتتاحية.', $reconciled));
        }

        $this->line(sprintf(
            'فحص %d عميل — المخالفات: %d',
            count($rows),
            count($mismatches)
        ));

        foreach ($mismatches as $mismatch) {
            Log::critical('Customer financial position mismatch — the accounting equation is broken', $mismatch);

            $this->error(sprintf(
                'العميل #%d (%s): الفرق %s — GL %s ≠ (L %s − D %s) = %s',
                $mismatch['customer_id'],
                $mismatch['customer_name'],
                number_format($mismatch['diff'], 2),
                number_format($mismatch['gl'], 2),
                number_format($mismatch['available_credit'], 2),
                number_format($mismatch['open_debt'], 2),
                number_format($mismatch['expected_gl'], 2)
            ));
        }

        return $mismatches === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * موضع العميل في دفتر الأستاذ، مع احترام عرف وحدة الحساب.
     *
     * @return array{0: float, 1: bool} [الموضع، هل الوحدة debit-first؟]
     */
    protected function glPosition(?Account $account): array
    {
        if (! $account) {
            return [0.0, false];
        }

        $moduleType = strtoupper((string) ($account->module_type ?: ''));
        $isDebitFirst = in_array($moduleType, self::DEBIT_FIRST_MODULE_TYPES, true);

        $balance = round((float) $account->balance, 2);

        return [$isDebitFirst ? $balance : round(-1 * $balance, 2), $isDebitFirst];
    }

    /**
     * تسوية تاريخية: ينشئ قيد افتتاحي في دفتر الرصيد الدائن يطابق رصيد
     * حساب العميل في دفتر الأستاذ (للحسابات التي سبقت دفتر الرصيد الموحد).
     *
     * الشروط (كلها مطلوبة):
     *   • الحساب أُنشئ/العميل سُجّل قبل ترحيل دفتر الرصيد الموحد.
     *   • لا يوجد أي صف في `customer_credit_ledger` للعميل (حالة الأرشيف).
     *   • الفرق يتجاوز حد التسامح.
     *
     * @return CustomerCreditLedger|null الحركة الافتتاحية (أو null إن لم تُطبَّق التسوية)
     */
    protected function reconcileLegacyPosition(Customer $customer, ?Account $account, float $discrepancy): ?CustomerCreditLedger
    {
        if (! $account) {
            return null;
        }

        $createdAt = $customer->created_at ? $customer->created_at->format('Y-m-d H:i:s') : null;

        if ($createdAt === null || $createdAt >= self::CREDIT_LEDGER_MIGRATION_AT) {
            return null;
        }

        $hasLedgerRows = CustomerCreditLedger::query()
            ->where('customer_id', (int) $customer->id)
            ->exists();

        if ($hasLedgerRows) {
            return null;
        }

        // المبلغ الافتتاحي = قيمة الفرق. الحالة المستهدفة (رصيد دائن
        // تاريخي بلا سطر دفتر) فرقها موجب؛ ويُبقى الإشارة لو كان الفرق
        // سالباً حتى تُغلق الفجوة تماماً بدل مضاعفتها.
        $amount = $discrepancy > 0
            ? round($discrepancy, 2)
            : round(-abs($discrepancy), 2);

        if (abs($amount) < 0.005) {
            return null;
        }

        $currency = strtoupper((string) ($account->currency ?: 'EGP'));

        return DB::transaction(fn () => CustomerCreditLedger::create([
            'customer_id'   => $customer->id,
            'company_id'    => $customer->company_id,
            'source_type'   => self::SOURCE_OPENING_BALANCE,
            'source_id'     => null,
            'amount'        => $amount,
            'currency'      => $currency,
            'notes'         => 'تسوية رصيد افتتاحي تاريخي ما قبل دفتر الائتمان الموحد',
            'created_by'    => 1,
            'balance_after' => $amount,
        ]));
    }
}
