<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\TransactionType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'module' => 'nullable|string|max:50',
            'description' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'reference' => 'nullable|string|max:255',
            'date' => 'nullable|date',
        ]);

        $account = Account::query()->findOrFail((int) $validated['account_id']);
        if (! in_array($account->type->value ?? $account->type, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
            return ApiResponse::error('يجب اختيار حساب سيولة (خزينة / بنك / محفظة).', null, 422);
        }

        try {
            $type = $validated['type'];
            $amount = (float) $validated['amount'];
            $accountId = (int) $validated['account_id'];
            $module = $validated['module'] ?? 'general';

            $notes = trim(collect([
                $validated['description'],
                $validated['notes'] ?? null,
                isset($validated['reference']) && $validated['reference'] !== ''
                    ? 'مرجع: '.$validated['reference']
                    : null,
            ])->filter()->implode(' — '));

            $data = [
                'amount' => $amount,
                'module' => $module,
                'notes' => $notes,
                'created_by' => Auth::id() ?? 1,
            ];

            if ($type === 'income') {
                $data['to_account_id'] = $accountId;
                $transaction = $this->transactionService->recordIncome($data);
            } else {
                $data['from_account_id'] = $accountId;
                $transaction = $this->transactionService->recordExpense($data);
            }

            if (! empty($validated['date'])) {
                $at = $validated['date'].' 12:00:00';
                $transaction->timestamps = false;
                $transaction->forceFill([
                    'created_at' => $at,
                    'updated_at' => $at,
                ])->save();
                $transaction->timestamps = true;
            }

            $transaction->load(['fromAccount', 'toAccount', 'createdBy', 'entries']);

            return ApiResponse::success('تم تسجيل المعاملة بنجاح.', $transaction, 201);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($id);

        if (! $this->isEditableManualTransaction($transaction)) {
            return ApiResponse::error('لا يمكن تعديل هذا النوع من المعاملات من هذه الشاشة.', null, 422);
        }

        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['income', 'expense'])],
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:500',
            'module' => 'nullable|string|max:50',
            'date' => 'nullable|date',
        ]);

        $account = Account::query()->findOrFail((int) $validated['account_id']);
        if (! in_array($account->type->value ?? $account->type, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
            return ApiResponse::error('يجب اختيار حساب سيولة (خزينة / بنك / محفظة).', null, 422);
        }

        try {
            $newTransaction = DB::transaction(function () use ($transaction, $validated) {
                $this->transactionService->voidTransactionJournal($transaction);
                $transaction->delete();

                $type = $validated['type'] ?? ($transaction->type === TransactionType::Income ? 'income' : 'expense');
                $module = $validated['module']
                    ?? ($transaction->module instanceof \BackedEnum ? $transaction->module->value : (string) $transaction->module);

                $notes = trim((string) ($validated['notes'] ?? $validated['description'] ?? $transaction->notes ?? ''));

                $data = [
                    'amount' => (float) $validated['amount'],
                    'module' => $module ?: 'general',
                    'notes' => $notes,
                    'created_by' => Auth::id() ?? $transaction->created_by ?? 1,
                ];

                if ($type === 'income') {
                    $data['to_account_id'] = (int) $validated['account_id'];
                    $created = $this->transactionService->recordIncome($data);
                } else {
                    $data['from_account_id'] = (int) $validated['account_id'];
                    $created = $this->transactionService->recordExpense($data);
                }

                if (! empty($validated['date'])) {
                    $at = $validated['date'].' 12:00:00';
                    $created->timestamps = false;
                    $created->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
                    $created->timestamps = true;
                }

                return $created;
            });

            $newTransaction->load(['fromAccount', 'toAccount', 'createdBy', 'entries']);

            return ApiResponse::success('تم تحديث المعاملة بنجاح.', $newTransaction, 200);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy($id): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($id);

        if (! $this->isEditableManualTransaction($transaction)) {
            return ApiResponse::error('لا يمكن حذف هذا النوع من المعاملات من هذه الشاشة.', null, 422);
        }

        try {
            DB::transaction(function () use ($transaction): void {
                $this->transactionService->voidTransactionJournal($transaction);
                $transaction->delete();
            });

            return ApiResponse::success('تم حذف المعاملة بنجاح.', null, 200);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Manual income/expense from Vue may be stored as Transfer when strict double-entry applies.
     */
    private function isEditableManualTransaction(Transaction $transaction): bool
    {
        $type = $transaction->type instanceof TransactionType
            ? $transaction->type
            : TransactionType::tryFrom((string) $transaction->type);

        if ($type === null) {
            return false;
        }

        if (in_array($type, [TransactionType::Income, TransactionType::Expense, TransactionType::Transfer], true)) {
            return AccountEntry::query()->where('transaction_id', $transaction->id)->exists();
        }

        return false;
    }
}
