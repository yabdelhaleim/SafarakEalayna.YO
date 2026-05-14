<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HajjUmraExecutingCompanyFinanceController extends Controller
{
    public function dues(Request $request): JsonResponse
    {
        $rows = HajjUmraExecutingCompany::query()
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->orderBy('name')
            ->get(['id', 'name', 'account_id', 'phone'])
            ->map(function (HajjUmraExecutingCompany $c) {
                $totals = AccountEntry::query()
                    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
                    ->where('account_entries.account_id', $c->account_id)
                    ->where('transactions.module', TransactionModule::HajjUmra->value)
                    ->selectRaw('COALESCE(SUM(account_entries.debit), 0) as total_debit, COALESCE(SUM(account_entries.credit), 0) as total_credit')
                    ->first();

                $debit = (float) ($totals?->total_debit ?? 0);
                $credit = (float) ($totals?->total_credit ?? 0);
                $netDue = $debit - $credit;

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'account_id' => (int) $c->account_id,
                    'total_withdrawn' => $debit,
                    'total_repaid' => $credit,
                    'net_due' => $netDue,
                ];
            })
            ->values();

        return ApiResponse::success('Executing companies dues fetched', [
            'items' => $rows,
        ]);
    }

    public function withdraw(Request $request, HajjUmraExecutingCompany $company): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $company->account_id) {
            return ApiResponse::error('هذه الشركة لا تحتوي على حساب مالي مرتبط.', null, 422);
        }

        $toAccount = Account::query()->findOrFail((int) $data['to_account_id']);
        if ($toAccount->module_type !== 'hajj_umra') {
            return ApiResponse::error('يجب اختيار حساب تابع لقسم الحج والعمرة.', null, 422);
        }

        $tx = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => (float) $data['amount'],
            'from_account_id' => (int) $company->account_id,
            'to_account_id' => (int) $toAccount->id,
            'module' => TransactionModule::HajjUmra->value,
            'notes' => 'سحب من الشركة المنفذة ['.$company->name.']: '.($data['notes'] ?? ''),
            'created_by' => auth()->id(),
        ]);

        return ApiResponse::success('تم تسجيل السحب.', [
            'transaction_id' => $tx->id,
        ]);
    }

    public function repay(Request $request, HajjUmraExecutingCompany $company): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $company->account_id) {
            return ApiResponse::error('هذه الشركة لا تحتوي على حساب مالي مرتبط.', null, 422);
        }

        $fromAccount = Account::query()->findOrFail((int) $data['from_account_id']);
        if ($fromAccount->module_type !== 'hajj_umra') {
            return ApiResponse::error('يجب اختيار حساب تابع لقسم الحج والعمرة.', null, 422);
        }

        $tx = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => (float) $data['amount'],
            'from_account_id' => (int) $fromAccount->id,
            'to_account_id' => (int) $company->account_id,
            'module' => TransactionModule::HajjUmra->value,
            'notes' => 'سداد للشركة المنفذة ['.$company->name.']: '.($data['notes'] ?? ''),
            'created_by' => auth()->id(),
        ]);

        return ApiResponse::success('تم تسجيل السداد.', [
            'transaction_id' => $tx->id,
        ]);
    }
}

