<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisaTreasuryController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $accountTypes = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', $accountTypes)
            ->where('module_type', 'visas') // Note: the resource uses 'visas' plural
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

        $agents = VisaAgent::query()
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_person', 'account_id', 'phone']);

        $recentTransactions = Transaction::query()
            ->where('module', TransactionModule::Visa)
            ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->limit(40)
            ->get(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'notes', 'related_type', 'related_id', 'created_at']);

        return ApiResponse::success('Visa treasury overview', [
            'settlement_accounts' => $accounts,
            'agents' => $agents,
            'recent_visa_transactions' => $recentTransactions,
        ]);
    }

    public function accountVisaTransactions(Request $request, Account $account): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = Transaction::query()
            ->where('module', TransactionModule::Visa)
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Account visa transactions', $paginator);
    }
}
