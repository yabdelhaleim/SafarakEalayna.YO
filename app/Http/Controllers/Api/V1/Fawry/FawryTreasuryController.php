<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Finance\LiquidityAccountGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FawryTreasuryController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('module_type', ['fawry', 'office'])
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

        // Recent fawry transactions (from main transaction table)
        $recentTransactions = Transaction::query()
            ->where('module', TransactionModule::Fawry)
            ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->limit(30)
            ->get();

        $groups = LiquidityAccountGroups::group($accounts);

        return ApiResponse::success('Fawry treasury overview', [
            'accounts' => $accounts,
            'recent_transactions' => $recentTransactions,
            'wallets' => $groups['wallets'],
            'banks' => $groups['banks'],
            'cashboxes' => $groups['cashboxes'],
            'treasuries' => $groups['treasuries'],
        ]);
    }

    public function accountTransactions(Request $request, Account $account): JsonResponse
    {
        if ($account->module_type !== 'fawry') {
            return ApiResponse::error('هذا الحساب لا يتبع موديول فوري.', null, 403);
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = Transaction::query()
            ->where('module', TransactionModule::Fawry)
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Account fawry transactions', $paginator);
    }
}
