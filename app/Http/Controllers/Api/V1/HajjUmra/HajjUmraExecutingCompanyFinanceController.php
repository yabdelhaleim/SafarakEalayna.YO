<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HajjUmraExecutingCompanyFinanceController extends Controller
{
    public function dues(Request $request): JsonResponse
    {
        $companies = HajjUmraExecutingCompany::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rows = $companies->map(function (HajjUmraExecutingCompany $c) {
            if (! $c->account_id) {
                $account = Account::create([
                    'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($c->name ?: 'غير مسمى'),
                    'type' => \App\Enums\AccountType::Supplier->value,
                    'currency' => 'EGP',
                    'balance' => 0.00,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'hajj_umra',
                    'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام.',
                    'created_by' => auth()->id() ?? 1,
                ]);
                $c->account_id = $account->id;
                $c->save();
            }

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
        })->values();

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
        // ─────────────────────────────────────────────────────────────────
        // FIX (BUG #HJ-1, fixed 2026-07-16):
        //   Old check `$toAccount->module_type !== 'hajj_umra'` is wrong —
        //   it rejects every valid tourism-division cashbox/wallet/bank
        //   (Phase-3 contract forbids liquidity accounts from having
        //   module_type='hajj_umra'; they must be 'office' or 'tourism').
        //
        //   Use AccountModuleContract::isTourismModule() which checks the
        //   division AND the 'hajj_umra' alias — the canonical predicate.
        // ─────────────────────────────────────────────────────────────────
        if (! AccountModuleContract::isTourismModule($toAccount->module_type)
            && $toAccount->module !== 'hajj_umra') {
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
        // See BUG #HJ-1 fix above in withdraw() — same predicate needed here.
        if (! AccountModuleContract::isTourismModule($fromAccount->module_type)
            && $fromAccount->module !== 'hajj_umra') {
            return ApiResponse::error('يجب اختيار حساب تابع لقسم الحج والعمرة.', null, 422);
        }

        // ─────────────────────────────────────────────────────────────────
        // FIX (GAP #HJ-6, fixed 2026-07-16):
        //   The cashbox is the "from" account in repay() — it pays the
        //   executing company. Without this guard, the cashbox could go
        //   negative, breaking reconciliation. We allow the operation only
        //   if the source account has sufficient balance.
        // ─────────────────────────────────────────────────────────────────
        if ((float) $fromAccount->balance < (float) $data['amount']) {
            return ApiResponse::error(
                'رصيد الحساب المصدر غير كافٍ لإتمام السداد: '
                .'الرصيد الحالي ' . number_format((float) $fromAccount->balance, 2)
                .' والمطلوب ' . number_format((float) $data['amount'], 2)
                .' (' . $fromAccount->name . ').',
                null,
                422
            );
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

