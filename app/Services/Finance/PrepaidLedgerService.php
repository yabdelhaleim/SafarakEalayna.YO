<?php

namespace App\Services\Finance;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * شحن رصيد مسبق (أصل) + استهلاك COGS عند الحجز/الاستخدام.
 */
class PrepaidLedgerService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}

    /**
     * شحن: سيولة → حساب رصيد مسبق (بدون تأثير على P&L).
     */
    public function recharge(
        string $prepaidKey,
        Account $source,
        float $amount,
        TransactionModule $module,
        ?string $notes = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): Transaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ الشحن يجب أن يكون أكبر من صفر.');
        }

        $prepaidId = $this->clearingAccounts->prepaidAccountId($prepaidKey);
        if ($prepaidId === $source->id) {
            throw new \InvalidArgumentException('حساب المصدر يطابق حساب الرصيد المسبق.');
        }

        $transaction = $this->transactionService->recordJournalTransfer([
            'amount' => $amount,
            'from_account_id' => $source->id,
            'to_account_id' => $prepaidId,
            'module' => $module->value,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'notes' => ($notes ?? 'شحن رصيد مسبق').' [رصيد مسبق]',
            'created_by' => Auth::id() ?? 1,
        ]);

        Log::info('Prepaid recharge recorded', [
            'prepaid_key' => $prepaidKey,
            'from_account_id' => $source->id,
            'amount' => $amount,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * استهلاك: رصيد مسبق → إقفال تكاليف (COGS — يدخل P&L).
     */
    public function consumeCogs(
        string $prepaidKey,
        TransactionModule $module,
        float $amount,
        ?string $notes = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): ?Transaction {
        if ($amount <= 0) {
            return null;
        }

        $prepaidId = $this->clearingAccounts->prepaidAccountId($prepaidKey);
        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule($module);

        if ($expenseContraId === null || $prepaidId === $expenseContraId) {
            throw new \RuntimeException('تعذر تحديد حسابات استهلاك الرصيد المسبق للموديول «'.$module->value.'».');
        }

        $transaction = $this->transactionService->recordJournalTransfer([
            'amount' => $amount,
            'from_account_id' => $prepaidId,
            'to_account_id' => $expenseContraId,
            'allow_from_negative' => true,
            'module' => $module->value,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'notes' => ($notes ?? 'استهلاك رصيد مسبق').' [COGS]',
            'created_by' => Auth::id() ?? 1,
        ]);

        Log::info('Prepaid COGS consumption recorded', [
            'prepaid_key' => $prepaidKey,
            'amount' => $amount,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }
}
