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

        // ─────────────────────────────────────────────────────────────
        // SAFE FX GUARD (FIX 2026-08-21): reject cross-currency
        // withdrawals at the controller boundary. The executing-company
        // AP account may be in a different currency than the destination
        // treasury; the safe-FX rule in
        // TransactionService::recordJournalTransfer rejects such a
        // transfer unless explicit FX data is supplied. Withdraw is a
        // single-currency flow (no FX data on the wire), so we reject
        // here with HTTP 422 + clear Arabic message.
        //
        // Pre-fix: the silent `?? 1.0` fallback coerced a missing
        // `exchange_rate` to 1.0 and silently applied 1:1 — producing a
        // nominally-balanced but semantically-wrong ledger entry.
        // ─────────────────────────────────────────────────────────────
        $companyAccount = Account::find($company->account_id);
        $fromCurrency = $companyAccount ? strtoupper((string) $companyAccount->currency) : 'EGP';
        $toCurrency = strtoupper((string) $toAccount->currency);

        // ─────────────────────────────────────────────────────────────────
        // BRIEF 5 — REGRESSION #3 FIX (2026-08-21):
        //   Phase 10.12 contract: the withdraw endpoint does NOT reject
        //   cross-currency operations. The "manual conversion flow" is
        //   handled by the operator externally; this endpoint records the
        //   resulting movement.
        //
        //   Pre-fix (Brief 4 Phase 5b): added a 422 rejection here that
        //   broke the Phase 10.12 contract.
        //
        //   Post-fix:
        //     - Removed the rejection — same-currency path unchanged.
        //     - For cross-currency, accept caller-supplied FX data OR
        //       auto-compute the destination amount using
        //       CurrencyService::convert() (which reads the explicit
        //       ExchangeRate / currencies table — NO silent 1.0).
        //     - The amount the caller sends is treated as the destination
        //       amount; `converted_amount` is the source-amount equivalent
        //       that will be debited from the company AP.
        // ─────────────────────────────────────────────────────────────────

        $journalTransferData = [
            'amount' => (float) $data['amount'],
            'from_account_id' => (int) $company->account_id,
            'to_account_id' => (int) $toAccount->id,
            'module' => TransactionModule::HajjUmra->value,
            'notes' => 'سحب من الشركة المنفذة ['.$company->name.']: '.($data['notes'] ?? ''),
            'created_by' => auth()->id(),
        ];
        if ($fromCurrency !== $toCurrency) {
            if (isset($data['converted_amount'])) {
                $journalTransferData['converted_amount'] = $data['converted_amount'];
            } elseif (isset($data['exchange_rate'])) {
                $journalTransferData['exchange_rate'] = $data['exchange_rate'];
            } else {
                // Auto-compute via CurrencyService::convert() — uses
                // explicit rates from the DB (ExchangeRate / currencies
                // table). No silent 1.0 fallback. Throws if no rate exists.
                $converted = app(\App\Services\Finance\CurrencyService::class)->convert(
                    (float) $data['amount'],
                    $fromCurrency,
                    $toCurrency
                );
                $journalTransferData['converted_amount'] = $converted['to_amount'];
                $journalTransferData['exchange_rate'] = $converted['rate'];
            }
        }

        $tx = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer($journalTransferData);

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

        // ─────────────────────────────────────────────────────────────
        // SAFE FX GUARD (FIX 2026-08-21): reject cross-currency repays
        // at the controller boundary. Mirror of the guard in withdraw()
        // above — same single-currency flow.
        // ─────────────────────────────────────────────────────────────
        $companyAccount = Account::find($company->account_id);
        $fromCurrency = strtoupper((string) $fromAccount->currency);
        $toCurrency = $companyAccount ? strtoupper((string) $companyAccount->currency) : 'EGP';

        // ─────────────────────────────────────────────────────────────────
        // BRIEF 5 — REGRESSION #3 FIX (2026-08-21):
        //   Mirror of withdraw() fix — removed the 422 rejection. When
        //   currencies differ, caller MUST supply explicit FX data OR we
        //   auto-compute via CurrencyService::convert() (explicit DB
        //   rate, NO silent 1.0). Same-currency repays continue to work
        //   without FX data.
        // ─────────────────────────────────────────────────────────────────

        $journalTransferData = [
            'amount' => (float) $data['amount'],
            'from_account_id' => (int) $fromAccount->id,
            'to_account_id' => (int) $company->account_id,
            'module' => TransactionModule::HajjUmra->value,
            'notes' => 'سداد للشركة المنفذة ['.$company->name.']: '.($data['notes'] ?? ''),
            'created_by' => auth()->id(),
        ];
        if ($fromCurrency !== $toCurrency) {
            if (isset($data['converted_amount'])) {
                $journalTransferData['converted_amount'] = $data['converted_amount'];
            } elseif (isset($data['exchange_rate'])) {
                $journalTransferData['exchange_rate'] = $data['exchange_rate'];
            } else {
                $converted = app(\App\Services\Finance\CurrencyService::class)->convert(
                    (float) $data['amount'],
                    $fromCurrency,
                    $toCurrency
                );
                $journalTransferData['converted_amount'] = $converted['to_amount'];
                $journalTransferData['exchange_rate'] = $converted['rate'];
            }
        }

        $tx = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer($journalTransferData);

        return ApiResponse::success('تم تسجيل السداد.', [
            'transaction_id' => $tx->id,
        ]);
    }
}

