<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Enums\TransactionType;
use App\Enums\TransactionModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierAccountService
{
    public function __construct(
        protected AccountService $accountService,
        protected TransactionService $transactionService,
        protected TransactionAuditStamper $auditStamper,
    ) {}

    /**
     * شحن رصيد لحساب مورد (خط طيران)
     */
    public function rechargeSupplierAccount(Supplier $supplier, array $data): \App\Models\Transfer
    {
        return DB::transaction(function () use ($supplier, $data) {
            if (!$supplier->account_id) {
                throw new \Exception('Supplier does not have an account linked.');
            }

            $supplierAccount = Account::findOrFail($supplier->account_id);

            if (!$supplierAccount->is_active) {
                throw new \Exception('Supplier account is not active.');
            }

            $amount = (float) $data['amount'];
            $fromTreasuryId = $data['from_treasury_id'];
            $notes = $data['notes'] ?? "Recharge supplier account: {$supplier->name}";

            $fromTreasury = Account::findOrFail($fromTreasuryId);

            if ($fromTreasury->balance < $amount) {
                throw new \Exception('Insufficient balance in treasury account.');
            }

            $transfer = $this->transactionService->recordTransfer([
                'from_account_id' => $fromTreasuryId,
                'to_account_id' => $supplierAccount->id,
                'amount' => $amount,
                'from_currency' => $fromTreasury->currency,
                'to_currency' => $supplierAccount->currency,
                'exchange_rate' => 1.0,
                'converted_amount' => $amount,
                'type' => TransactionType::Transfer->value,
                'module' => TransactionModule::Flight->value,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            Log::info('Supplier account recharged', [
                'supplier_id' => $supplier->id,
                'amount' => $amount,
                'transfer_id' => $transfer->id,
            ]);

            return $transfer;
        });
    }

    /**
     * خصم من حساب مورد عند الحجز
     */
    public function debitSupplierAccount(Supplier $supplier, float $amount, int $bookingId, string $notes = null): AccountEntry
    {
        return DB::transaction(function () use ($supplier, $amount, $bookingId, $notes) {
            if (!$supplier->account_id) {
                throw new \Exception('Supplier does not have an account linked.');
            }

            $supplierAccount = Account::lockForUpdate()->findOrFail($supplier->account_id);

            if (!$supplierAccount->is_active) {
                throw new \Exception('Supplier account is not active.');
            }

            if ($supplierAccount->balance < $amount) {
                throw new \Exception("Insufficient balance in supplier account: {$supplierAccount->name}");
            }

            $transaction = Transaction::create([
                'type' => TransactionType::Expense->value,
                'module' => TransactionModule::Flight->value,
                'amount' => $amount,
                'currency' => $supplierAccount->currency,
                'notes' => $notes ?? "Flight booking #{$bookingId}",
                'from_account_id' => $supplierAccount->id,
                'created_by' => auth()->id(),
            ]);
            $this->auditStamper->stamp($transaction);

            $entry = $this->accountService->debit($supplierAccount, $amount, $transaction->id);

            Log::info('Supplier account debited for booking', [
                'supplier_id' => $supplier->id,
                'amount' => $amount,
                'booking_id' => $bookingId,
                'transaction_id' => $transaction->id,
            ]);

            return $entry;
        });
    }

    /**
     * إضافة رصيد لحساب مورد (إلغاء/استرداد)
     */
    public function creditSupplierAccount(Supplier $supplier, float $amount, int $refundId, string $notes = null): AccountEntry
    {
        return DB::transaction(function () use ($supplier, $amount, $refundId, $notes) {
            if (!$supplier->account_id) {
                throw new \Exception('Supplier does not have an account linked.');
            }

            $supplierAccount = Account::lockForUpdate()->findOrFail($supplier->account_id);

            if (!$supplierAccount->is_active) {
                throw new \Exception('Supplier account is not active.');
            }

            $transaction = Transaction::create([
                'type' => TransactionType::Income->value,
                'module' => TransactionModule::Flight->value,
                'amount' => $amount,
                'currency' => $supplierAccount->currency,
                'notes' => $notes ?? "Refund #{$refundId}",
                'to_account_id' => $supplierAccount->id,
                'created_by' => auth()->id(),
            ]);
            $this->auditStamper->stamp($transaction);

            $entry = $this->accountService->credit($supplierAccount, $amount, $transaction->id);

            Log::info('Supplier account credited for refund', [
                'supplier_id' => $supplier->id,
                'amount' => $amount,
                'refund_id' => $refundId,
                'transaction_id' => $transaction->id,
            ]);

            return $entry;
        });
    }

    /**
     * كشف حساب مورد
     */
    public function getSupplierStatement(Supplier $supplier, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        if (!$supplier->account_id) {
            throw new \Exception('Supplier does not have an account linked.');
        }

        $supplierAccount = Account::findOrFail($supplier->account_id);

        return $this->accountService->getAccountStatement($supplierAccount, $filters);
    }

    /**
     * الحصول على رصيد مورد
     */
    public function getSupplierBalance(Supplier $supplier): float
    {
        if (!$supplier->account_id) {
            return 0.0;
        }

        $account = Account::find($supplier->account_id);
        return $account ? $account->balance : 0.0;
    }
}
