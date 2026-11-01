<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\TransactionType;
use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::user();
        if (! $user) {
            return ApiResponse::error('غير مصرح لك بالوصول.', null, 401);
        }

        if ($user->cannot('create', Transaction::class)) {
            return ApiResponse::error('ليس لديك الصلاحية لتسجيل المعاملات المالية.', null, 403);
        }

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

    protected function isDrawerClosed(Transaction $transaction): bool
    {
        if (! $transaction->from_account_id) {
            return false;
        }

        return AuditLog::query()
            ->where('action', 'close_drawer')
            ->where('model_type', Account::class)
            ->where('model_id', $transaction->from_account_id)
            ->where('created_at', '>=', $transaction->created_at)
            ->exists();
    }

    public function update(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($id);

        if (! $this->isEditableManualTransaction($transaction)) {
            return ApiResponse::error('لا يمكن تعديل هذا النوع من المعاملات من هذه الشاشة.', null, 422);
        }

        $user = $request->user() ?? Auth::user();
        if (! $user) {
            return ApiResponse::error('غير مصرح لك بالوصول.', null, 401);
        }

        if ($user->cannot('update', $transaction)) {
            return ApiResponse::error('ليس لديك الصلاحية لتعديل هذه المعاملة.', null, 403);
        }

        $isAdmin = $user->isAdmin() || in_array($user->role, ['admin', 'owner'], true);

        if ($this->isDrawerClosed($transaction) && ! $isAdmin) {
            return ApiResponse::error('لا يمكن تعديل المعاملة لأن الخزينة مغلقة لتاريخ المعاملة. يلزم تدخل المدير.', null, 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'to_account_id' => 'nullable|exists:accounts,id',
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
            $newTransaction = DB::transaction(function () use ($transaction, $validated, $user, $request, $isAdmin) {
                $oldSnapshot = [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'from_account_id' => $transaction->from_account_id,
                    'from_account_name' => $transaction->fromAccount?->name,
                    'to_account_id' => $transaction->to_account_id,
                    'to_account_name' => $transaction->toAccount?->name,
                    'notes' => $transaction->notes,
                    'module' => $transaction->module instanceof \BackedEnum ? $transaction->module->value : (string) $transaction->module,
                ];

                $hadTransfer = $transaction->transfer !== null;
                $originalToAccountId = $transaction->to_account_id;

                // 1. Void old journal entries
                $this->transactionService->voidTransactionJournal($transaction);

                // 2. Soft-delete old transfer if present
                if ($transaction->transfer) {
                    $transaction->transfer->delete();
                }

                // 3. Soft-delete old transaction
                $transaction->delete();

                // 4. Create new transaction with updated values
                $module = $validated['module']
                    ?? ($transaction->module instanceof \BackedEnum ? $transaction->module->value : (string) $transaction->module);

                $notes = trim((string) ($validated['notes'] ?? $validated['description'] ?? $transaction->notes ?? ''));
                $targetToAccountId = ! empty($validated['to_account_id']) ? (int) $validated['to_account_id'] : $originalToAccountId;

                if ($hadTransfer && $targetToAccountId) {
                    $transferData = [
                        'from_account_id' => (int) $validated['account_id'],
                        'to_account_id' => (int) $targetToAccountId,
                        'amount' => (float) $validated['amount'],
                        'module' => $module ?: 'general',
                        'notes' => $notes,
                        'type' => 'expense',
                        'created_by' => $user->id ?? $transaction->created_by ?? 1,
                    ];
                    $newTransfer = $this->transactionService->recordTransfer($transferData);
                    $created = Transaction::query()->findOrFail($newTransfer->transaction_id);
                } else {
                    $expenseData = [
                        'amount' => (float) $validated['amount'],
                        'from_account_id' => (int) $validated['account_id'],
                        'contra_account_id' => $targetToAccountId,
                        'module' => $module ?: 'general',
                        'notes' => $notes,
                        'created_by' => $user->id ?? $transaction->created_by ?? 1,
                    ];
                    $created = $this->transactionService->recordExpense($expenseData);
                }

                if (! empty($validated['date'])) {
                    $at = $validated['date'].' 12:00:00';
                    $created->timestamps = false;
                    $created->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
                    $created->timestamps = true;
                }

                // Audit Log
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'expense.updated',
                    'model_type' => Transaction::class,
                    'model_id' => $created->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $oldSnapshot,
                    'new_values' => [
                        'id' => $created->id,
                        'amount' => $created->amount,
                        'from_account_id' => $created->from_account_id,
                        'to_account_id' => $created->to_account_id,
                        'notes' => $created->notes,
                        'is_closed_drawer_override' => ($this->isDrawerClosed($transaction) && $isAdmin),
                    ],
                    'notes' => 'تم تعديل المصروف وإعادة احتساب القيود',
                ]);

                return $created;
            });

            CacheHelper::flushTags(['accounts', 'dashboard']);
            CacheHelper::flushNamespace();

            $newTransaction->load(['fromAccount', 'toAccount', 'createdBy', 'entries']);

            return ApiResponse::success('تم تحديث المعاملة بنجاح.', $newTransaction, 200);
        } catch (\Exception $e) {
            Log::error('Expense update error', ['error' => $e->getMessage(), 'id' => $id]);
            $msg = $e->getMessage();
            if (str_contains($msg, 'Insufficient balance in account:')) {
                $accountName = trim(str_ireplace('Insufficient balance in account:', '', $msg));
                $msg = "رصيد غير كافٍ في ({$accountName}). لا يمكن تعديل المصروف بمبلغ يتجاوز الرصيد المتاح في الخزينة.";
            }
            return ApiResponse::error('حدث خطأ أثناء تحديث المعاملة: '.$msg, null, 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($id);

        if (! $this->isEditableManualTransaction($transaction)) {
            return ApiResponse::error('لا يمكن حذف هذا النوع من المعاملات من هذه الشاشة.', null, 422);
        }

        $user = $request->user() ?? Auth::user();
        if (! $user) {
            return ApiResponse::error('غير مصرح لك بالوصول.', null, 401);
        }

        if ($user->cannot('delete', $transaction)) {
            return ApiResponse::error('ليس لديك الصلاحية لحذف هذه المعاملة.', null, 403);
        }

        $isAdmin = $user->isAdmin() || in_array($user->role, ['admin', 'owner'], true);

        if ($this->isDrawerClosed($transaction) && ! $isAdmin) {
            return ApiResponse::error('لا يمكن حذف المعاملة لأن الخزينة مغلقة لتاريخ المعاملة. يلزم تدخل المدير.', null, 422);
        }

        try {
            DB::transaction(function () use ($transaction, $user, $request, $isAdmin): void {
                $oldSnapshot = [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'type' => $transaction->type instanceof \BackedEnum ? $transaction->type->value : (string) $transaction->type,
                    'from_account_id' => $transaction->from_account_id,
                    'from_account_name' => $transaction->fromAccount?->name,
                    'to_account_id' => $transaction->to_account_id,
                    'to_account_name' => $transaction->toAccount?->name,
                    'notes' => $transaction->notes,
                    'created_by' => $transaction->created_by,
                ];

                // 1. Void ledger entries (reverses balances on both legs safely)
                $this->transactionService->voidTransactionJournal($transaction);

                // 2. Soft-delete linked transfer if present
                if ($transaction->transfer) {
                    $transaction->transfer->delete();
                }

                // 3. Soft-delete the transaction
                $transaction->delete();

                // 4. Record Audit Log
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'expense.deleted',
                    'model_type' => Transaction::class,
                    'model_id' => $transaction->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $oldSnapshot,
                    'new_values' => [
                        'status' => 'deleted',
                        'is_closed_drawer_override' => ($this->isDrawerClosed($transaction) && $isAdmin),
                    ],
                    'notes' => 'تم إلغاء المصروف وإعادة المبلغ للخزينة',
                ]);
            });

            CacheHelper::flushTags(['accounts', 'dashboard']);
            CacheHelper::flushNamespace();

            return ApiResponse::success('تم حذف المصروف وإعادة المبلغ للخزينة بنجاح.', null, 200);
        } catch (\Exception $e) {
            Log::error('Expense deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            return ApiResponse::error('حدث خطأ أثناء حذف المعاملة: '.$e->getMessage(), null, 422);
        }
    }

    /**
     * Manual income/expense from Vue may be stored as Transfer when strict double-entry applies.
     */
    private function isEditableManualTransaction(Transaction $transaction): bool
    {
        // 1. Must NOT be linked to external entity
        if ($transaction->related_type !== null || $transaction->related_id !== null) {
            return false;
        }

        // 2. Must not be already reversed
        if (str_starts_with((string) $transaction->notes, 'عكس:') || str_starts_with((string) $transaction->notes, 'عكس ')) {
            return false;
        }

        // 3. Liquidity account check
        $fromAccount = $transaction->fromAccount;
        if (! $fromAccount) {
            return false;
        }
        $fromType = $fromAccount->type instanceof \BackedEnum ? $fromAccount->type->value : (string) $fromAccount->type;
        if (! in_array($fromType, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
            return false;
        }

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
