<?php

namespace App\Services\Fawry;

use App\Enums\TransactionModule;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryTransaction;
use App\Services\Finance\TransactionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FawryTransactionService
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function getAllTransactions(array $filters): LengthAwarePaginator
    {
        $query = FawryTransaction::with([
            'client',
            'employee',
            'account',
            'currency',
            'expenseTransaction',
            'incomeTransaction',
            'operationTypeRow',
            'paymentMethodRow',
        ]);

        if (isset($filters['operation_type']) && $filters['operation_type']) {
            $query->where('operation_type', $filters['operation_type']);
        }

        if (isset($filters['payment_method']) && $filters['payment_method']) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (isset($filters['employee_id']) && $filters['employee_id']) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }

        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        if (isset($filters['search']) && $filters['search']) {
            $query->where('client_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('reference_number', 'like', '%'.$filters['search'].'%');
        }

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function createTransaction(array $data): FawryTransaction
    {
        try {
            return DB::transaction(function () use ($data) {
                $profit = ($data['selling_price'] - $data['fawry_price']);

                // Get client name if client_id is provided
                $clientName = $data['client_name'] ?? '';
                if (isset($data['client_id']) && $data['client_id']) {
                    $client = \App\Models\Customer::find($data['client_id']);
                    if ($client) {
                        $clientName = $client->full_name;
                    }
                }

                $fawryTransaction = FawryTransaction::create([
                    'client_id' => $data['client_id'] ?? null,
                    'client_name' => $clientName,
                    'operation_type' => $data['operation_type'],
                    'client_amount' => $data['client_amount'],
                    'fawry_price' => $data['fawry_price'],
                    'selling_price' => $data['selling_price'],
                    'profit' => $profit,
                    'employee_id' => $data['employee_id'],
                    'account_id' => $data['account_id'],
                    'payment_method' => $data['payment_method'],
                    'amount' => $data['amount'],
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'currency_id' => $data['currency_id'] ?? null,
                    'payment_details' => $data['payment_details'] ?? null,
                ]);

                // Get operation type label from database
                $operationType = FawryOperationType::where('code', $data['operation_type'])->first();
                $operationLabel = $operationType ? $operationType->name_ar : $data['operation_type'];

                $createdBy = Auth::id() ?: ($data['created_by'] ?? $data['employee_id'] ?? 1);

                $expenseTransaction = $this->transactionService->recordExpense([
                    'amount' => $data['fawry_price'],
                    'from_account_id' => $data['account_id'],
                    'module' => TransactionModule::Fawry->value,
                    'related_type' => FawryTransaction::class,
                    'related_id' => $fawryTransaction->id,
                    'notes' => "عملية فوري - {$operationLabel}: {$clientName}",
                    'created_by' => $createdBy,
                ]);

                $incomeTransaction = $this->transactionService->recordIncome([
                    'amount' => $data['selling_price'],
                    'to_account_id' => $data['account_id'],
                    'module' => TransactionModule::Fawry->value,
                    'related_type' => FawryTransaction::class,
                    'related_id' => $fawryTransaction->id,
                    'notes' => "تحصيل فوري - {$operationLabel}: {$clientName}",
                    'created_by' => $createdBy,
                ]);

                $fawryTransaction->update([
                    'expense_transaction_id' => $expenseTransaction->id,
                    'income_transaction_id' => $incomeTransaction->id,
                ]);

                Log::info('Fawry transaction created', [
                    'fawry_transaction_id' => $fawryTransaction->id,
                    'operation_type' => $data['operation_type'],
                    'client_name' => $clientName,
                    'amount' => $data['selling_price'],
                    'profit' => $profit,
                    'created_by' => Auth::id(),
                ]);

                return $fawryTransaction->fresh([
                    'client',
                    'employee',
                    'account',
                    'currency',
                    'expenseTransaction',
                    'incomeTransaction',
                    'operationTypeRow',
                    'paymentMethodRow',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('FawryTransactionService::createTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function updateTransaction(FawryTransaction $transaction, array $data): FawryTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $data) {
                if (isset($data['selling_price']) || isset($data['fawry_price'])) {
                    $fawryPrice = $data['fawry_price'] ?? $transaction->fawry_price;
                    $sellingPrice = $data['selling_price'] ?? $transaction->selling_price;
                    $data['profit'] = $sellingPrice - $fawryPrice;
                }

                $transaction->update($data);

                return $transaction->fresh([
                    'client',
                    'employee',
                    'account',
                    'currency',
                    'expenseTransaction',
                    'incomeTransaction',
                    'operationTypeRow',
                    'paymentMethodRow',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('FawryTransactionService::updateTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'fawry_transaction_id' => $transaction->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function deleteTransaction(FawryTransaction $transaction): bool
    {
        try {
            return DB::transaction(function () use ($transaction) {
                if ($transaction->expense_transaction_id) {
                    $this->transactionService->reverseTransaction($transaction->expenseTransaction);
                }

                if ($transaction->income_transaction_id) {
                    $this->transactionService->reverseTransaction($transaction->incomeTransaction);
                }

                $transaction->delete();

                Log::info('Fawry transaction deleted', [
                    'fawry_transaction_id' => $transaction->id,
                    'deleted_by' => Auth::id(),
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('FawryTransactionService::deleteTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'fawry_transaction_id' => $transaction->id,
            ]);
            throw $e;
        }
    }

    public function getTransactionById(int $id): FawryTransaction
    {
        return FawryTransaction::with([
            'client',
            'employee',
            'account',
            'currency',
            'expenseTransaction',
            'incomeTransaction',
            'operationTypeRow',
            'paymentMethodRow',
        ])->findOrFail($id);
    }

    public function getDailySummary(string $date): array
    {
        $startDate = $date.' 00:00:00';
        $endDate = $date.' 23:59:59';

        $results = FawryTransaction::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(client_amount) as total_client_amount,
                SUM(fawry_price) as total_fawry_price,
                SUM(selling_price) as total_selling_price,
                SUM(profit) as total_profit
            ')
            ->first();

        return [
            'total_transactions' => (int) ($results->total_transactions ?? 0),
            'total_client_amount' => (float) ($results->total_client_amount ?? 0.00),
            'total_fawry_price' => (float) ($results->total_fawry_price ?? 0.00),
            'total_selling_price' => (float) ($results->total_selling_price ?? 0.00),
            'total_profit' => (float) ($results->total_profit ?? 0.00),
        ];
    }
}
