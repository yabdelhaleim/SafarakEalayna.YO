<?php

namespace App\Services\Treasury;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\TreasuryTransaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TreasuryService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}

    /**
     * إضافة مبلغ لحساب الخزنة (credit) — يُسجّل قيداً مزدوجاً في الدفتر + صف خزينة للتتبع.
     */
    public function credit(int $accountId, float $amount, string $reason, ?int $flightBookingId = null, int $userId = 1)
    {
        return DB::transaction(function () use ($accountId, $amount, $reason, $flightBookingId, $userId) {
            $account = Account::query()->findOrFail($accountId);
            $balanceBefore = $account->balance;

            $suspenseId = $this->clearingAccounts->treasuryOperationsContraAccountId();

            $gl = $this->transactionService->recordJournalTransfer([
                'amount' => $amount,
                'from_account_id' => $suspenseId,
                'to_account_id' => $accountId,
                'allow_from_negative' => true,
                'module' => TransactionModule::General->value,
                'related_type' => TreasuryTransaction::class,
                'related_id' => null,
                'notes' => $reason.' [خزينة: إيداع]',
                'created_by' => $userId,
            ]);

            $account->refresh();

            return TreasuryTransaction::create([
                'transaction_type' => 'credit',
                'from_treasury' => null,
                'to_treasury' => $account->name,
                'amount' => $amount,
                'currency' => $account->currency,
                'reason' => $reason,
                'flight_booking_id' => $flightBookingId,
                'agent_name' => $userId ? \App\Models\User::find($userId)?->name ?? 'System' : 'System',
                'account_id' => $accountId,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->balance,
                'reference_number' => 'TRX-'.strtoupper(uniqid()),
                'ledger_transaction_id' => $gl->id,
            ]);
        });
    }

    /**
     * خصم مبلغ من حساب الخزنة (debit) — قيد مزدوج + صف خزينة.
     *
     * @throws \Exception
     */
    public function debit(int $accountId, float $amount, string $reason, ?int $flightBookingId = null, int $userId = 1)
    {
        return DB::transaction(function () use ($accountId, $amount, $reason, $flightBookingId, $userId) {
            $account = Account::query()->findOrFail($accountId);

            $suspenseId = $this->clearingAccounts->treasuryOperationsContraAccountId();

            $balanceBefore = $account->balance;

            $gl = $this->transactionService->recordJournalTransfer([
                'amount' => $amount,
                'from_account_id' => $accountId,
                'to_account_id' => $suspenseId,
                'allow_from_negative' => false,
                'module' => TransactionModule::General->value,
                'related_type' => TreasuryTransaction::class,
                'related_id' => null,
                'notes' => $reason.' [خزينة: سحب]',
                'created_by' => $userId,
            ]);

            $account->refresh();

            return TreasuryTransaction::create([
                'transaction_type' => 'debit',
                'from_treasury' => $account->name,
                'to_treasury' => null,
                'amount' => $amount,
                'currency' => $account->currency,
                'reason' => $reason,
                'flight_booking_id' => $flightBookingId,
                'agent_name' => $userId ? \App\Models\User::find($userId)?->name ?? 'System' : 'System',
                'account_id' => $accountId,
                'balance_before' => $balanceBefore,
                'balance_after' => $account->balance,
                'reference_number' => 'TRX-'.strtoupper(uniqid()),
                'ledger_transaction_id' => $gl->id,
            ]);
        });
    }

    /**
     * تحويل مبلغ بين حسابين (transfer) — قيدان منفصلان في الدفتر (سحب + إيداع).
     */
    public function transfer(int $fromAccountId, int $toAccountId, float $amount, string $reason, ?int $flightBookingId = null, int $userId = 1)
    {
        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $reason, $flightBookingId, $userId) {
            $referenceNumber = 'TRX-'.strtoupper(uniqid());

            $debitTransaction = $this->debit(
                $fromAccountId,
                $amount,
                $reason.' (تحويل خروج)',
                $flightBookingId,
                $userId
            );

            $creditTransaction = $this->credit(
                $toAccountId,
                $amount,
                $reason.' (تحويل وارد)',
                $flightBookingId,
                $userId
            );

            Log::info('Treasury transfer completed', [
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => $amount,
                'reference_number' => $referenceNumber,
                'user_id' => $userId,
            ]);

            return [
                'debit_transaction' => $debitTransaction,
                'credit_transaction' => $creditTransaction,
                'reference_number' => $referenceNumber,
            ];
        });
    }

    /**
     * الحصول على رصيد حساب محدد
     */
    public function getAccountBalance(int $accountId): array
    {
        $account = Account::query()->findOrFail($accountId);

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'currency' => $account->currency,
            'balance' => $account->balance,
            'type' => $account->type,
            'transactions_count' => $account->entries()->count(),
        ];
    }

    /**
     * الحصول على سجل معاملات حساب
     */
    public function getAccountTransactions(int $accountId, int $limit = 50)
    {
        return TreasuryTransaction::where('account_id', $accountId)
            ->with(['flightBooking', 'flightBooking.customer'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * الحصول على ملخص جميع الأرصدة حسب العملة
     */
    public function getBalancesByCurrency(): array
    {
        $accounts = Account::where('is_active', true)->get();

        $balances = [
            'EGP' => ['balance' => 0, 'accounts' => []],
            'KWD' => ['balance' => 0, 'accounts' => []],
            'SAR' => ['balance' => 0, 'accounts' => []],
            'USD' => ['balance' => 0, 'accounts' => []],
        ];

        foreach ($accounts as $account) {
            $currency = $account->currency;
            if (isset($balances[$currency])) {
                $balances[$currency]['balance'] += $account->balance;
                $balances[$currency]['accounts'][] = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'balance' => $account->balance,
                    'type' => $account->type,
                ];
            }
        }

        return $balances;
    }
}
