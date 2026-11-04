<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessLogicException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * خدمة دفتر الرصيد الدائن الموحد للعميل (CustomerCreditLedger).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * القاعدة المحاسبية الحاكمة (مصدر الحقيقة الوحيد):
 *
 *     -Account.balance  =  AvailableCredit (L)  −  OpenDebt (D)
 *
 * حيث:
 *   • L = مجموع حركات `customer_credit_ledger` غير المعكوسة (رصيد دائن متاح).
 *   • D = مجموع `remaining_amount` للحجوزات الحية (دين مفتوح).
 *   • Account.balance رصيد حساب العميل في دفتر الأستاذ (موجب = مدين لنا).
 *
 * كل حركة في هذا الدفتر يجب أن يقابلها أثر محاسبي (GL) — وإلا اختلف الدفتر
 * عن دفتر الأستاذ. لذلك:
 *   • سند القبض (receipt):   قيد على حساب العميل + (إن وُجد فائض) حركة موجبة.
 *   • سند الصرف  (payment):   قيد على حساب العميل + حركة سالبة.
 *   • إلغاء سند القبض:        إلغاء القيد + عكس حركة الفائض (إن وُجد).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Phase 6 (2026-11-03) — عكس أثر الفائض عند إلغاء سند القبض:
 *
 * لا يجوز إلغاء سند قبض أنتج فائضاً تم استخدامه في سداد حجوزات أخرى:
 * عندها يكون جزء من رصيد العميل قد انتقل فعلياً إلى تسوية دين حجز، وإلغاؤه
 * سيترك رصيداً دائناً سالباً ويكسر المعادلة أعلاه. لذلك نمنع الإلغاء (422)
 * ما لم يكن الفائض كاملاً وغير مستخدم.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Phase 8 (2026-11-03) — التزامن وأمان النقد (Concurrency & Cash Safety):
 *
 * القراءة العادية (`getAvailableCredit`) تجميع غير مقفل — قد ترى snapshot
 * قديماً. لذلك كل مسار يستهلك الرصيد الدائن يمرّ عبر
 * {@see self::lockCustomerScope()} التي:
 *   ① تقفل صف العميل، ② تقفل حسابه المالي، ③ تقرأ الرصيد بقراءة قفلية.
 *
 * النتيجة: طلبان متزامنان للصرف لا يستطيعان معاً تجاوز الرصيد المتاح —
 * فالأول يُخصم، والثاني ينتظر القفل ثم يقرأ الرصيد المحدَّث فيُرفض (422).
 */
class CustomerCreditLedgerService
{
    /**
     * يقفل نطاق العميل المالي تسلسلياً ويقرأ رصيده الدائن المتاح بقراءة قفلية.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     * Phase 8 (2026-11-03) — Concurrency & Cash Safety:
     *
     * أي مسار يستهلك رصيد العميل الدائن يجب أن يمرّ من هنا أولاً. الترتيب
     * مقصود ومثبّت (Lock Order) لتفادي الجمود (deadlock):
     *
     *     ① صف العميل (customers)      — قفل نطاق العميل كله
     *     ② صف حسابه المالي (accounts) — قفل الأرصدة المرتبطة
     *     ③ قراءة الدفتر بـ FOR UPDATE — قفل نطاق حركات العميل
     *
     * القفل على صف العميل وحده لا يكفي (الرصيد الدائن تجميع على عدة
     * صفوف)، لذلك فالقراءة القفلية (`lockedAvailableCredit`) هي التي
     * تحسم الأمر: ترى آخر حالة مُلتزَمة وتمنع أي إدراج حركة جديدة لنفس
     * العميل حتى انتهاء المعاملة.
     *
     * يجب استدعاؤها داخل معاملة قائمة — وإلا فُكّت الأقفال فور انتهاء
     * الاستعلامات.
     *
     * @return array{
     *     customer: Customer,
     *     account: Account|null,
     *     available_credit: float
     * }
     */
    public function lockCustomerScope(
        int $customerId,
        ?int $companyId = null,
        string $currency = 'EGP'
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'lockCustomerScope() يجب أن تُستدعى داخل معاملة قاعدة بيانات — وإلا فُكّت الأقفال فور انتهاء الاستعلامات.'
            );
        }

        // ① قفل صف العميل — تسلسل كل العمليات المتزامنة لنفس العميل.
        $customer = Customer::query()
            ->whereKey($customerId)
            ->lockForUpdate()
            ->firstOrFail();

        // ② قفل حسابه المالي — حماية أرصدة دفتر الأستاذ المرتبطة.
        $account = null;
        if ($customer->account_id) {
            $account = Account::query()
                ->whereKey($customer->account_id)
                ->lockForUpdate()
                ->first();
        }

        // ③ قراءة قفلية للرصيد الدائن المتاح (SELECT ... FOR UPDATE).
        $availableCredit = CustomerCreditLedger::lockedAvailableCredit(
            $customerId,
            $companyId,
            $currency
        );

        return [
            'customer' => $customer,
            'account' => $account,
            'available_credit' => $availableCredit,
        ];
    }

    /**
     * يسجّل خصماً (حركة سالبة) من رصيد العميل الدائن بقيمة رصيد مُقفل.
     *
     * يُستدعى بعد {@see self::lockCustomerScope()} مباشرةً، فيكون الرصيد
     * اللاحق (`balance_after`) محسوباً من نفس القراءة القفلية — فلا يختلف
     * الدفتر عن الرصيد المقروء.
     *
     * @param  array{
     *     source_type: string,
     *     source_id?: int|null,
     *     notes?: string|null,
     *     created_by?: int|null
     * }  $entry
     */
    public function recordGuardedDebit(
        ?int $customerId,
        ?int $companyId,
        string $currency,
        float $amount,
        float $lockedAvailableCredit,
        array $entry
    ): CustomerCreditLedger {
        $amount = round($amount, 2);

        if ($amount <= 0.001) {
            throw new \InvalidArgumentException('مبلغ الخصم من الرصيد الدائن يجب أن يكون أكبر من صفر.');
        }

        return CustomerCreditLedger::recordEntry([
            'customer_id' => $customerId,
            'company_id' => $companyId,
            'source_type' => $entry['source_type'],
            'source_id' => $entry['source_id'] ?? null,
            'amount' => -$amount,
            'currency' => $currency,
            'notes' => $entry['notes'] ?? null,
            'created_by' => $entry['created_by'] ?? null,
            // الرصيد اللاحق من القراءة القفلية نفسها (لا من تجميع غير مقفل).
            'balance_after' => round($lockedAvailableCredit - $amount, 2),
        ]);
    }

    /**
     * يسجّل قيداً دائناً (حركة موجبة) في رصيد العميل بقيمة رصيد مُقفل.
     *
     * نظير {@see self::recordGuardedDebit()} — يُستخدم في المسارات التي
     * **تعيد** رصيداً للعميل (مثل استرجاع رصيده الدائن عند إلغاء حجز
     * سوّاه من رصيده)، فيكون `balance_after` محسوباً من القراءة القفلية
     * نفسها لا من تجميع غير مقفل.
     *
     * @param  array{
     *     source_type: string,
     *     source_id?: int|null,
     *     notes?: string|null,
     *     created_by?: int|null
     * }  $entry
     */
    public function recordGuardedCredit(
        ?int $customerId,
        ?int $companyId,
        string $currency,
        float $amount,
        float $lockedAvailableCredit,
        array $entry
    ): CustomerCreditLedger {
        $amount = round($amount, 2);

        if ($amount <= 0.001) {
            throw new \InvalidArgumentException('مبلغ الإضافة إلى الرصيد الدائن يجب أن يكون أكبر من صفر.');
        }

        return CustomerCreditLedger::recordEntry([
            'customer_id' => $customerId,
            'company_id' => $companyId,
            'source_type' => $entry['source_type'],
            'source_id' => $entry['source_id'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'notes' => $entry['notes'] ?? null,
            'created_by' => $entry['created_by'] ?? null,
            // الرصيد اللاحق من القراءة القفلية نفسها (لا من تجميع غير مقفل).
            'balance_after' => round($lockedAvailableCredit + $amount, 2),
        ]);
    }

    /**
     * عكس أثر الفائض الناتج عن سند قبض عند إلغائه.
     *
     * @return CustomerCreditLedger|null حركة العكس (أو null إن لم يكن للسند فائض)
     *
     * @throws BusinessLogicException إذا كان الفائض قد استُخدم في تسويات أخرى
     */
    public function reverseReceiptOverpayment(Transaction $transaction, int $userId): ?CustomerCreditLedger
    {
        return DB::transaction(function () use ($transaction, $userId) {
            $entry = CustomerCreditLedger::query()
                ->where('source_type', CustomerCreditLedger::SOURCE_OVERPAYMENT)
                ->where('source_id', $transaction->id)
                ->whereNull('reversed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // لا فائض مرتبط بهذا السند — لا يوجد أثر في الدفتر يُعكس.
            if (! $entry) {
                return null;
            }

            $consumed = $this->consumedSurplusAmount($entry);

            if ($consumed > 0.005) {
                throw new BusinessLogicException(
                    'لا يمكن إلغاء سند القبض لأنه تم استخدام الرصيد الفائض الناتج عنه في سداد حجوزات أخرى.',
                    [
                        'transaction_id' => (int) $transaction->id,
                        'ledger_entry_id' => (int) $entry->id,
                        'surplus_amount' => (float) $entry->amount,
                        'consumed_amount' => $consumed,
                        'currency' => $entry->currency,
                    ]
                );
            }

            // `CustomerCreditLedger::reverse()` ينشئ حركة عكسية
            // (source_type = reversal) ويوسم الحركتين كمعكوستين — وبذلك
            // يخرج الفائض كلياً من الرصيد الدائن المتاح.
            $reversal = $entry->reverse($userId, "إلغاء سند قبض #{$transaction->id}");

            Log::info('Customer credit ledger: receipt overpayment reversed', [
                'transaction_id' => (int) $transaction->id,
                'ledger_entry_id' => (int) $entry->id,
                'reversal_entry_id' => (int) $reversal->id,
                'amount' => (float) $entry->amount,
                'user_id' => $userId,
            ]);

            return $reversal;
        });
    }

    /**
     * هل تم استخدام الفائض الناتج عن هذه الحركة في تسويات أخرى؟
     */
    public function isSurplusConsumed(CustomerCreditLedger $entry): bool
    {
        return $this->consumedSurplusAmount($entry) > 0.005;
    }

    /**
     * المبلغ المستهلَك من فائض حركة معيّنة.
     *
     * الإسناد بأسلوب FIFO: كل حركة موجبة تُعد "دفعة" (lot) مستقلة، وكل حركة
     * سالبة (تسوية حجز / صرف) تستهلك من الدفعات الأقدم أولاً. فإذا استُهلك
     * جزء من الدفعة الخاصة بالفائض فالفائض "مستخدم".
     *
     * هذا يضمن أن إلغاء سند قبض فائضه مستهلَك يُرفض — لأن عكسه سيترك
     * رصيداً دائناً سالباً ويكسر المعادلة المحاسبية.
     */
    public function consumedSurplusAmount(CustomerCreditLedger $entry): float
    {
        $entries = CustomerCreditLedger::query()
            ->whereNull('reversed_at')
            ->where('currency', $entry->currency)
            ->when(
                $entry->company_id,
                fn ($query) => $query->where('company_id', $entry->company_id),
                fn ($query) => $query->where('customer_id', $entry->customer_id)
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var array<int, array{id:int, remaining:float}> $lots */
        $lots = [];
        $targetIndex = null;

        foreach ($entries as $row) {
            $amount = round((float) $row->amount, 2);

            if ($amount >= 0) {
                $lots[] = ['id' => (int) $row->id, 'remaining' => $amount];

                if ((int) $row->id === (int) $entry->id) {
                    $targetIndex = count($lots) - 1;
                }

                continue;
            }

            // حركة سالبة: تستهلك من الدفعات الأقدم أولاً (FIFO).
            $need = round(abs($amount), 2);

            foreach ($lots as $index => $lot) {
                if ($need <= 0.005) {
                    break;
                }

                $take = min($need, $lot['remaining']);
                $lots[$index]['remaining'] = round($lot['remaining'] - $take, 2);
                $need = round($need - $take, 2);
            }
        }

        if ($targetIndex === null) {
            return 0.0;
        }

        $original = round((float) $entry->amount, 2);
        $remaining = round((float) $lots[$targetIndex]['remaining'], 2);

        return round(max(0.0, $original - $remaining), 2);
    }
}
