<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\Flight\FlightPaymentAllocationService;
use App\Support\Finance\SplitReceiptMarker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * إلغاء سند قبض لعميل (Phase 6 — 2026-11-03).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * لماذا هذه الخدمة؟
 *
 * لم يكن في النظام مسار مستقل لإلغاء سند قبض عميل: `TransactionController`
 * يرفض أي معاملة مرتبطة بعميل (`related_type !== null`)، والمسارات القائمة
 * كانت تلغي السند كأثر جانبي لإلغاء حجز (وهي وسم محاسبي فقط — `عكس:` —
 * بلا أي أثر على أرصدة Accounts ولا على دفتر الرصيد الدائن).
 *
 * إلغاء السند يجب أن يغيّر ثلاثة أشوار معاً وإلا اختلف الدفتر عن دفتر الأستاذ:
 *
 *   ① القيد المحاسبي (GL):  استعادة رصيد حساب العميل ورصيد الخزينة.
 *   ② التخصيصات:            عكس تخصيصات السند ⇒ عودة ديون الحجوزات (D ↑).
 *   ③ دفتر الرصيد الدائن:  عكس فائض السند (L ↓) — ما لم يكن مستهلَكاً.
 *
 * الترتيب مهم: حارس الاستخدام (③) يُنفَّذ أولاً، فإذا رُفض الإلغاء لم يُمس
 * أي قيد أو رصيد (العملية كلها داخل transaction واحدة ⇒ rollback كامل).
 *
 * المعادلة المحاسبية بعد الإلغاء:
 *
 *     -Account.balance  =  AvailableCredit (L)  −  OpenDebt (D)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PHASE 9 (2026-11-03) — السند المجزّأ (شطر إيراد + شطر أمانات):
 *
 * سند قبض الطيران الذي يحتوي فائضاً صار له **قيدان**:
 *
 *   ① شق الإيراد  (type='income')   → يحمل تخصيصات الحجوزات.
 *   ② شق الأمانات (type='transfer') → يحمل حركة دفتر الرصيد (الفائض).
 *
 * إلغاء السند يجب أن **يلغي القيدين معاً**، وإلا بقي أحدهما في الدفتر:
 *   • إلغاء شق الإيراد وحده ⇒ بقيت الأمانات محتفظ بها باسم العميل بعد
 *     إرجاع سنده (رصيد دائن وهمي + نقد مفقود من الخزينة).
 *   • إلغاء شق الأمانات وحده ⇒ بقي الإيراد معكوساً عن الحجوزات التي لم
 *     تعد مُسدَّدة (إيراد محقق بلا مقابل).
 *
 * وربط الشقين يتم بعلامة `SplitReceiptMarker` في ملاحظات شق الأمانات.
 */
class CustomerReceiptCancellationService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected CustomerCreditLedgerService $creditLedgerService,
        protected FlightPaymentAllocationService $allocationService,
    ) {}

    /**
     * @return array{
     *     reversed_allocation_total: float,
     *     reversed_surplus: float,
     *     reversal_entry_id: int|null
     * }
     *
     * @throws \App\Exceptions\BusinessLogicException إذا كان فائض السند مستهلَكاً
     */
    public function cancel(Transaction $transaction, int $userId): array
    {
        return DB::transaction(function () use ($transaction, $userId) {
            // ─────────────────────────────────────────────────────────────────
            // PHASE 8 (2026-11-03) — الأقفال التشاؤمية (Pessimistic Locking)
            // ─────────────────────────────────────────────────────────────────
            //
            // الإلغاء يقرأ الرصيد الدائن (فحص `consumedSurplusAmount`) ثم
            // يعكسه — فبدون قفل يمكن أن يتزامن معه صرف يستهلك الفائض في
            // نفس اللحظة: فيُعكس الفائض بعد استهلاكه فيبقى رصيد العميل
            // الدائن سالباً ويكسر المعادلة المحاسبية.
            //
            // ترتيب القفل (ثابت عبر كل مسارات الرصيد الدائن):
            //   ① صف المعاملة (transactions)
            //   ② صف العميل (customers)
            //   ③ حساب العميل (accounts)
            //   ④ حركات دفتر الرصيد (customer_credit_ledger) — داخل
            //      `reverseReceiptOverpayment`.
            // ─────────────────────────────────────────────────────────────────
            $lockedTransaction = Transaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            // PHASE 9: حلّ مجموعة السند (شق الإيراد + شق الأمانات).
            [$revenueTx, $surplusTx] = $this->resolveReceiptGroup($lockedTransaction);

            if ($surplusTx instanceof Transaction && $surplusTx->id !== $lockedTransaction->id) {
                Transaction::where('id', $surplusTx->id)->lockForUpdate()->first();
            }

            if ($lockedTransaction->related_type === Customer::class && $lockedTransaction->related_id) {
                $customer = Customer::where('id', $lockedTransaction->related_id)
                    ->lockForUpdate()
                    ->first();

                if ($customer && $customer->account_id) {
                    Account::where('id', $customer->account_id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            // ① حارس استخدام الرصيد الفائض + عكسه في دفتر الرصيد الدائن.
            //    حركة الفائض مرتبطة بقيد الأمانات (Phase 9) — وهو الذي يحمل
            //    أصل النقد المحتفظ به للعميل.
            $reversalEntry = $this->creditLedgerService->reverseReceiptOverpayment(
                $surplusTx ?? $lockedTransaction,
                $userId
            );

            // ② عكس تخصيصات الحجوزات — عودة ديونها (D ↑).
            //    التخصيصات مرتبطة بشق الإيراد (Phase 9).
            $reversedAllocations = $this->allocationService->reverseAllocationsForTransaction(
                $revenueTx ?? $lockedTransaction,
                $userId
            );

            // ③ عكس القيود المحاسبية (استعادة رصيد الطرفين) + حذف ناعم.
            //    يجب إلغاء **كل** شقّي السند معاً (Phase 9).
            foreach ($this->uniqueGroupTransactions($lockedTransaction, $revenueTx, $surplusTx) as $groupTx) {
                $this->transactionService->voidTransactionJournal($groupTx);
                $groupTx->delete();
            }

            $reversedSurplus = $reversalEntry
                ? round(abs((float) $reversalEntry->amount), 2)
                : 0.0;

            Log::info('Customer receipt cancelled', [
                'transaction_id' => (int) $lockedTransaction->id,
                'amount' => (float) $lockedTransaction->amount,
                'revenue_transaction_id' => $revenueTx?->id,
                'surplus_transaction_id' => $surplusTx?->id,
                'reversed_allocation_total' => $reversedAllocations,
                'reversed_surplus' => $reversedSurplus,
                'reversal_entry_id' => $reversalEntry?->id,
                'user_id' => $userId,
            ]);

            return [
                'reversed_allocation_total' => $reversedAllocations,
                'reversed_surplus' => $reversedSurplus,
                'reversal_entry_id' => $reversalEntry?->id,
            ];
        });
    }

    /**
     * PHASE 9: يحلّ مجموعة السند — [شق الإيراد, شق الأمانات].
     *
     * • المعامل يحمل علامة `[سند مجزأ #N]` ⇒ هو شق الأمانات، والمرساة N
     *   هي شق الإيراد.
     * • غير ذلك ⇒ المعامل هو شق الإيراد (أو سند غير مجزّأ)، ونبحث عن شق
     *   الأمانات المرتبط به بالعلامة نفسها.
     *
     * @return array{0: Transaction|null, 1: Transaction|null}
     */
    protected function resolveReceiptGroup(Transaction $transaction): array
    {
        $anchorId = SplitReceiptMarker::extract($transaction->notes);

        if ($anchorId !== null && $anchorId !== (int) $transaction->id) {
            $anchor = Transaction::query()->find($anchorId);

            return [$anchor instanceof Transaction ? $anchor : null, $transaction];
        }

        $deposit = Transaction::query()
            ->where('notes', 'like', SplitReceiptMarker::likePattern((int) $transaction->id))
            ->first();

        return [$transaction, $deposit instanceof Transaction ? $deposit : null];
    }

    /**
     * @return list<Transaction> شقوط السند بدون تكرار
     */
    protected function uniqueGroupTransactions(mixed ...$transactions): array
    {
        $unique = [];

        foreach ($transactions as $transaction) {
            if (! $transaction instanceof Transaction) {
                continue;
            }

            $unique[(int) $transaction->id] = $transaction;
        }

        return array_values($unique);
    }
}
