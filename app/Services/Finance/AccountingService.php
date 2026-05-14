<?php

namespace App\Services\Finance;

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نقطة تعاقد موحدة للقيد المحاسبي: كل الموديولات يُفضَّل المرور بهذه الخدمة
 * (قيود متوازنة أو income/expense/transfer) لتقليل تجاوز جداول المعاملات.
 */
class AccountingService
{
    public function __construct(
        protected LedgerClearingAccounts $clearingAccounts,
        protected TransactionService $transactions,
        protected TransactionAuditStamper $auditStamper,
    ) {}

    /**
     * @param  array<int, array{account_id: int, debit: float|string, credit: float|string}>  $lines
     */
    public function postBalancedJournal(array $lines, TransactionModule|string $module, ?string $relatedType, ?int $relatedId, ?string $notes = null, ?int $createdBy = null): Transaction
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('Journal requires at least one line.');
        }

        $normalized = [];
        foreach ($lines as $i => $line) {
            if (! isset($line['account_id'])) {
                throw new \InvalidArgumentException("Journal line {$i}: account_id مطلوب.");
            }
            $d = round((float) ($line['debit'] ?? 0), 2);
            $c = round((float) ($line['credit'] ?? 0), 2);
            if (($d <= 0 && $c <= 0) || ($d > 0 && $c > 0)) {
                throw new \InvalidArgumentException("Journal line {$i}: ضع إما مدين أو دائن (> 0)، لا الاثنين معاً.");
            }
            $normalized[] = [(int) $line['account_id'], $d, $c];
        }

        $sumDebit = round(array_sum(array_column($normalized, 1)), 2);
        $sumCredit = round(array_sum(array_column($normalized, 2)), 2);
        if (abs($sumDebit - $sumCredit) > 0.009) {
            throw new \InvalidArgumentException(
                'القيد غير متزن: مجموع المدين = '.$sumDebit.' ، مجموع الدائن = '.$sumCredit
            );
        }

        $moduleEnum = $module instanceof TransactionModule
            ? $module
            : (TransactionModule::tryFrom((string) $module) ?? TransactionModule::General);

        $principal = $this->extractPrincipalAccounts($normalized);
        $userId = $createdBy ?? Auth::id();

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($normalized, $moduleEnum, $relatedType, $relatedId, $notes, $userId, $sumDebit, $principal) {
            $transaction = Transaction::create([
                'type' => TransactionType::Transfer->value,
                'amount' => $sumDebit,
                'module' => $moduleEnum->value,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'from_account_id' => $principal['from'],
                'to_account_id' => $principal['to'],
                'created_by' => $userId !== null ? (int) $userId : throw new \RuntimeException('يجب تحديد مستخدم لتسجيل القيد المحاسبي.'),
                'notes' => $notes,
            ]);

            $this->auditStamper->stamp($transaction);

            $accountIds = array_unique(array_column($normalized, 0));
            sort($accountIds);

            /** @var array<int, Account> $accounts */
            $accounts = Account::query()
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($normalized as [$accountId, $debit, $credit]) {
                $account = $accounts->get($accountId);
                if (! $account) {
                    throw new \RuntimeException('لم يتم العثور على الحساب #'.$accountId);
                }

                // Same balance semantics as TransactionService::recordJournalTransfer:
                // debit column decreases balance when debit > 0; credit increases when credit > 0
                $delta = $credit > 0 ? $credit : -$debit;
                $account->balance = round((float) $account->balance + $delta, 2);
                $account->save();

                AccountEntry::create([
                    'account_id' => $account->id,
                    'transaction_id' => $transaction->id,
                    'debit' => $debit > 0 ? $debit : 0,
                    'credit' => $credit > 0 ? $credit : 0,
                    'balance_after' => $account->balance,
                ]);
            }

            Log::info('Balanced journal posted', [
                'transaction_id' => $transaction->id,
                'module' => $moduleEnum->value,
                'lines' => count($normalized),
                'created_by' => $userId,
            ]);

            return $transaction->fresh();
        }));
    }

    /** @param  array<int, array{0:int,1:float,2:float}>  $normalized */
    protected function extractPrincipalAccounts(array $normalized): array
    {
        $from = null;
        $to = null;
        foreach ($normalized as [$accountId, $debit, $credit]) {
            if ($debit > 0) {
                $from = $from ?? $accountId;
            }
            if ($credit > 0) {
                $to = $to ?? $accountId;
            }
        }

        return [
            'from' => $from ?? ($normalized[0][0] ?? null),
            'to' => $to ?? ($normalized[0][0] ?? null),
        ];
    }

    /**
     * Money into a treasury / cash ledger account from module clearing (Dr clearing, Cr cash).
     *
     * @throws \Throwable
     */
    public function postCashReceiptAgainstClearing(int $cashAccountId, TransactionModule|string $module, float $amount, ?string $relatedType, ?int $relatedId, ?string $notes = null, ?int $createdBy = null): Transaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ التحصيل يجب أن يكون أكبر من صفر.');
        }

        $contraId = $this->clearingAccounts->incomeContraIdForModule(
            $module instanceof TransactionModule ? $module : (TransactionModule::tryFrom((string) $module) ?? TransactionModule::General)
        );
        if ($contraId === null || $contraId === $cashAccountId) {
            throw new \RuntimeException('تعذر تحديد حساب إقفال الإيرادات للموديول المحدد.');
        }

        return $this->postBalancedJournal(
            lines: [
                ['account_id' => $contraId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $cashAccountId, 'debit' => 0, 'credit' => $amount],
            ],
            module: $module instanceof TransactionModule ? $module : (TransactionModule::tryFrom((string) $module) ?? TransactionModule::General),
            relatedType: $relatedType,
            relatedId: $relatedId,
            notes: $notes,
            createdBy: $createdBy
        );
    }

    /**
     * Money out of treasury into module cost clearing (Dr cash, Cr cost clearing).
     *
     * @throws \Throwable
     */
    public function postCashDisbursementAgainstClearing(int $cashAccountId, TransactionModule|string $module, float $amount, ?string $relatedType, ?int $relatedId, ?string $notes = null, ?int $createdBy = null): Transaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ الصرف يجب أن يكون أكبر من صفر.');
        }

        $contraId = $this->clearingAccounts->expenseContraIdForModule(
            $module instanceof TransactionModule ? $module : (TransactionModule::tryFrom((string) $module) ?? TransactionModule::General)
        );
        if ($contraId === null || $contraId === $cashAccountId) {
            throw new \RuntimeException('تعذر تحديد حساب إقفال التكاليف للموديول المحدد.');
        }

        return $this->postBalancedJournal(
            lines: [
                ['account_id' => $cashAccountId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $contraId, 'debit' => 0, 'credit' => $amount],
            ],
            module: $module instanceof TransactionModule ? $module : (TransactionModule::tryFrom((string) $module) ?? TransactionModule::General),
            relatedType: $relatedType,
            relatedId: $relatedId,
            notes: $notes,
            createdBy: $createdBy
        );
    }

    /**
     * For flight parity with config flight_accounting.ledger_clearing_account_*.
     */
    public function postFlightCashReceiptAgainstFlightClearing(
        int $cashAccountId,
        float $amount,
        ?string $relatedType,
        ?int $relatedId,
        ?string $notes = null,
        ?int $createdBy = null
    ): Transaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ التحصيل يجب أن يكون أكبر من صفر.');
        }

        $contraId = $this->clearingAccounts->incomeContraIdForFlightBooking();
        if ($contraId === null || $contraId === $cashAccountId) {
            throw new \RuntimeException('تعذر تحديد حساب إقفال مبيعات الطيران.');
        }

        return $this->postBalancedJournal(
            lines: [
                ['account_id' => $contraId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $cashAccountId, 'debit' => 0, 'credit' => $amount],
            ],
            module: TransactionModule::Flight,
            relatedType: $relatedType,
            relatedId: $relatedId,
            notes: $notes,
            createdBy: $createdBy
        );
    }

    public function clearingResolver(): LedgerClearingAccounts
    {
        return $this->clearingAccounts;
    }

    /**
     * تمرير إلى {@see TransactionService::recordIncome} (نفس سلسلة audit).
     */
    public function recordIncome(array $data): Transaction
    {
        return $this->transactions->recordIncome($data);
    }

    /** @see TransactionService::recordExpense */
    public function recordExpense(array $data): Transaction
    {
        return $this->transactions->recordExpense($data);
    }

    /** @see TransactionService::recordJournalTransfer */
    public function recordJournalTransfer(array $data): Transaction
    {
        return $this->transactions->recordJournalTransfer($data);
    }

    /** @see TransactionService::recordTransfer */
    public function recordTransfer(array $data): Transfer
    {
        return $this->transactions->recordTransfer($data);
    }

    /** @see TransactionService::reverseTransaction */
    public function reverseTransaction(Transaction $transaction): Transaction
    {
        return $this->transactions->reverseTransaction($transaction);
    }
}

