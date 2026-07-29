<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Services\Finance\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * ExpenseController — dedicated endpoint for recording expenses.
 *
 * Replaces the previous "POST /finance/transfers with type=expense" pattern,
 * which had three defects:
 *  1. It produced an unbalanced ledger entry (cashbox debited + expense
 *     account credited instead of debited).
 *  2. It auto-created orphan expense accounts outside the DB transaction.
 *  3. It saved the uploaded attachment BEFORE the ledger commit, leaving
 *     orphaned files on failure.
 *
 * This endpoint delegates to {@see TransactionService::recordExpense()},
 * which handles the canonical double-entry posting against the module's
 * expense-clearing account (resolved via
 * {@see \App\Services\Finance\LedgerClearingAccounts::expenseContraIdForModule()}).
 */
class ExpenseController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        try {
            $data = [
                'amount' => (float) $request->validated('amount'),
                'from_account_id' => (int) $request->validated('from_account_id'),
                'module' => $request->validated('module') ?? TransactionModule::General->value,
                'notes' => $request->validated('notes'),
                'created_by' => Auth::id() ?? 1,
            ];

            $transaction = $this->transactionService->recordExpense($data);

            // Save attachment AFTER successful ledger commit to avoid orphan files.
            if ($request->hasFile('attachment')) {
                $path = $request->file('attachment')->store('transactions/attachments', 'public');
                $transaction->forceFill(['attachment_path' => $path])->save();
                $transaction = $transaction->fresh();
            }

            // Expense mutates one liquidity account's balance — flush the
            // accounts cache so the next listing reflects the new balance.
            CacheHelper::flushTags(['accounts']);

            $transaction->load(['fromAccount', 'toAccount', 'createdBy', 'entries']);

            return ApiResponse::success('تم تسجيل المصروف بنجاح.', $transaction, 201);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}