<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmra\VisaAgent;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisaAgentFinanceController extends Controller
{
    public function dues(Request $request): JsonResponse
    {
        $rows = VisaAgent::query()
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_person', 'account_id', 'phone', 'country'])
            ->map(function (VisaAgent $v) {
                $totals = AccountEntry::query()
                    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
                    ->where('account_entries.account_id', $v->account_id)
                    ->where('transactions.module', TransactionModule::Visa->value)
                    ->selectRaw('COALESCE(SUM(account_entries.debit), 0) as total_debit, COALESCE(SUM(account_entries.credit), 0) as total_credit')
                    ->first();

                $debit = (float) ($totals?->total_debit ?? 0);
                $credit = (float) ($totals?->total_credit ?? 0);
                $netDue = $debit - $credit;

                return [
                    'id' => $v->id,
                    'name' => $v->company_name ?: $v->contact_person,
                    'company_name' => $v->company_name,
                    'contact_person' => $v->contact_person,
                    'phone' => $v->phone,
                    'country' => $v->country,
                    'account_id' => (int) $v->account_id,
                    'total_withdrawn' => $debit,
                    'total_repaid' => $credit,
                    'net_due' => $netDue,
                ];
            })
            ->values();

        return ApiResponse::success('Visa agents dues fetched', [
            'items' => $rows,
        ]);
    }

    public function withdraw(Request $request, VisaAgent $agent): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $agent->account_id) {
            return ApiResponse::error('هذا الوكيل لا يحتوي على حساب مالي مرتبط.', null, 422);
        }

        $toAccount = Account::query()->findOrFail((int) $data['to_account_id']);
        // Finding #2 fix: accept any account in the tourism division (visas, flights, hajj_umra,
        // tourism). Previously this was strict `module_type === 'visas'`, which blocked
        // legitimate withdrawals into the unified tourism cashbox/bank (which has
        // module_type='tourism' per AccountModuleContract).
        if (! AccountModuleContract::isTourismModule($toAccount->module_type)) {
            return ApiResponse::error('يجب اختيار حساب تابع لقسم السياحة أو التأشيرات.', null, 422);
        }

        $tx = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => (float) $data['amount'],
            'from_account_id' => (int) $agent->account_id,
            'to_account_id' => (int) $toAccount->id,
            'module' => TransactionModule::Visa->value,
            'notes' => 'سحب من وكيل التأشيرات ['.($agent->company_name ?: $agent->contact_person).']: '.($data['notes'] ?? ''),
            'created_by' => auth()->id(),
        ]);

        return ApiResponse::success('تم تسجيل السحب.', [
            'transaction_id' => $tx->id,
        ]);
    }

    public function repay(Request $request, VisaAgent $agent): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $agent->account_id) {
            return ApiResponse::error('هذا الوكيل لا يحتوي على حساب مالي مرتبط.', null, 422);
        }

        $fromAccount = Account::query()->findOrFail((int) $data['from_account_id']);
        // Finding #2 fix: accept any account in the tourism division (see withdraw() comment).
        if (! AccountModuleContract::isTourismModule($fromAccount->module_type)) {
            return ApiResponse::error('يجب اختيار حساب تابع لقسم السياحة أو التأشيرات.', null, 422);
        }

        $tx = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => (float) $data['amount'],
            'from_account_id' => (int) $fromAccount->id,
            'to_account_id' => (int) $agent->account_id,
            'module' => TransactionModule::Visa->value,
            'notes' => 'سداد لوكيل التأشيرات ['.($agent->company_name ?: $agent->contact_person).']: '.($data['notes'] ?? ''),
            'created_by' => auth()->id(),
        ]);

        return ApiResponse::success('تم تسجيل السداد.', [
            'transaction_id' => $tx->id,
        ]);
    }
}
