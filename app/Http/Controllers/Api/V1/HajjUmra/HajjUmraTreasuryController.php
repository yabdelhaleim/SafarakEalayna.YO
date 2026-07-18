<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HajjUmraTreasuryController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $accountTypes = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', $accountTypes)
            ->whereIn('module_type', ['hajj_umra', 'tourism'])
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

        $executingCompanies = HajjUmraExecutingCompany::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'account_id', 'phone']);

        $recentTransactions = Transaction::query()
            ->where('module', TransactionModule::HajjUmra)
            ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->limit(40)
            ->get(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'notes', 'related_type', 'related_id', 'created_at']);

        return ApiResponse::success('Hajj & Umra treasury overview', [
            'settlement_accounts' => $accounts,
            'executing_companies' => $executingCompanies,
            'recent_hajj_umra_transactions' => $recentTransactions,
        ]);
    }

    public function accountHajjUmraTransactions(Request $request, Account $account): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = Transaction::query()
            ->where('module', TransactionModule::HajjUmra)
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Account hajj/umra transactions', $paginator);
    }
}

