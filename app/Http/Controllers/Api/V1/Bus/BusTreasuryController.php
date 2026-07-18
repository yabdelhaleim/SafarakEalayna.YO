<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Rules\BusLiquidityAccount;
use App\Models\Bus\BusCompany;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusTreasuryController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $accountTypes = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', $accountTypes)
            ->whereIn('module_type', ['bus', 'office'])
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'balance',
                'currency',
                'module_type',
                'is_active',
                'wallet_provider',
                'wallet_number',
            ]);

        $companies = BusCompany::query()
            ->with('account:id,balance')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'account_id', 'is_active'])
            ->map(function($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'balance' => $company->account?->balance ?? 0,
                    'is_active' => $company->is_active
                ];
            });

        $recentTransactions = Transaction::query()
            ->where('module', TransactionModule::Bus)
            ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->limit(40)
            ->get(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'notes', 'related_type', 'related_id', 'created_at']);

        return ApiResponse::success('نظرة عامة على خزينة الباصات.', [
            'settlement_accounts' => $accounts,
            'companies' => $companies,
            'recent_bus_transactions' => $recentTransactions,
        ]);
    }

    public function accountBusTransactions(Request $request, Account $account): JsonResponse
    {
        if (! BusLiquidityAccount::belongsToBusModule($account)) {
            return ApiResponse::error('الحساب المحدد ليس تابعاً لموديول الباصات.', null, 422);
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = Transaction::query()
            ->where('module', TransactionModule::Bus)
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('معاملات الباصات للحساب.', $paginator);
    }
}
