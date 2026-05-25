<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Services\Finance\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Enums\TransactionType;
use App\Enums\TransactionModule;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:income,expense',
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'module' => 'nullable|string',
            'description' => 'required|string',
            'notes' => 'nullable|string',
            'date' => 'nullable|date',
        ]);

        try {
            $type = $validated['type'];
            $amount = (float) $validated['amount'];
            $accountId = (int) $validated['account_id'];
            $module = $validated['module'] ?? 'general';
            $notes = $validated['notes'] ?? $validated['description'];

            $data = [
                'amount' => $amount,
                'module' => $module,
                'notes' => $notes,
                'created_by' => Auth::id() ?? 1,
            ];

            if ($validated['date'] ?? null) {
                $data['created_at'] = $validated['date'] . ' ' . date('H:i:s');
            }

            if ($type === 'income') {
                $data['to_account_id'] = $accountId;
                $transaction = $this->transactionService->recordIncome($data);
            } else {
                $data['from_account_id'] = $accountId;
                $transaction = $this->transactionService->recordExpense($data);
            }

            $transaction->load(['fromAccount', 'toAccount', 'createdBy']);

            return ApiResponse::success('Transaction recorded successfully.', $transaction, 201);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string',
            'module' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($transaction, $validated) {
                $newAmount = (float) $validated['amount'];
                $newAccountId = (int) $validated['account_id'];
                $newModule = $validated['module'] ?? $transaction->module->value;
                $newNotes = $validated['notes'] ?? $transaction->notes;

                // 1. Reverse the old transaction's financial impact on the accounts
                if ($transaction->type === TransactionType::Income || $transaction->type->value === 'income') {
                    $oldAccount = Account::lockForUpdate()->findOrFail($transaction->to_account_id);
                    $oldAccount->balance -= $transaction->amount;
                    $oldAccount->save();
                } else if ($transaction->type === TransactionType::Expense || $transaction->type->value === 'expense') {
                    $oldAccount = Account::lockForUpdate()->findOrFail($transaction->from_account_id);
                    $oldAccount->balance += $transaction->amount;
                    $oldAccount->save();
                }

                // Delete old account entries for this transaction to rewrite them
                AccountEntry::where('transaction_id', $transaction->id)->delete();

                // 2. Update transaction model fields
                $transaction->amount = $newAmount;
                $transaction->module = $newModule;
                $transaction->notes = $newNotes;

                if ($transaction->type === TransactionType::Income || $transaction->type->value === 'income') {
                    $transaction->to_account_id = $newAccountId;
                    $transaction->from_account_id = null;
                } else {
                    $transaction->from_account_id = $newAccountId;
                    $transaction->to_account_id = null;
                }
                $transaction->save();

                // 3. Apply new transaction's financial impact on the accounts
                if ($transaction->type === TransactionType::Income || $transaction->type->value === 'income') {
                    $newAccount = Account::lockForUpdate()->findOrFail($newAccountId);
                    $newAccount->balance += $newAmount;
                    $newAccount->save();

                    AccountEntry::create([
                        'account_id' => $newAccount->id,
                        'transaction_id' => $transaction->id,
                        'debit' => 0.00,
                        'credit' => $newAmount,
                        'balance_after' => $newAccount->balance,
                    ]);
                } else {
                    $newAccount = Account::lockForUpdate()->findOrFail($newAccountId);
                    
                    if ($newAccount->balance < $newAmount) {
                        throw new \Exception('Insufficient balance in account: ' . $newAccount->name);
                    }

                    $newAccount->balance -= $newAmount;
                    $newAccount->save();

                    AccountEntry::create([
                        'account_id' => $newAccount->id,
                        'transaction_id' => $transaction->id,
                        'debit' => $newAmount,
                        'credit' => 0.00,
                        'balance_after' => $newAccount->balance,
                    ]);
                }
            });

            $transaction->refresh()->load(['fromAccount', 'toAccount', 'createdBy']);

            return ApiResponse::success('Transaction updated successfully.', $transaction, 200);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy($id): JsonResponse
    {
        $transaction = Transaction::findOrFail($id);

        try {
            DB::transaction(function () use ($transaction) {
                if ($transaction->type === TransactionType::Income || $transaction->type->value === 'income') {
                    $account = Account::lockForUpdate()->findOrFail($transaction->to_account_id);
                    $account->balance -= $transaction->amount;
                    $account->save();
                } else if ($transaction->type === TransactionType::Expense || $transaction->type->value === 'expense') {
                    $account = Account::lockForUpdate()->findOrFail($transaction->from_account_id);
                    $account->balance += $transaction->amount;
                    $account->save();
                }

                AccountEntry::where('transaction_id', $transaction->id)->delete();
                $transaction->delete();
            });

            return ApiResponse::success('Transaction deleted successfully.', null, 200);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
