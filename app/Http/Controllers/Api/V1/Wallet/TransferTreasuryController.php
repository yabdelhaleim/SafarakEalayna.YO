<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\AccountResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransferTreasuryController extends Controller
{
    public function overview()
    {
        $accounts = Account::query()
            ->where('module_type', 'wallet_transfer')
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($acc) => $acc->type instanceof AccountType ? $acc->type->value : (string) $acc->type);

        $map = fn ($items) => AccountResource::collection(collect($items ?? []))->resolve();

        return ApiResponse::success('Wallet treasury overview retrieved successfully', [
            'wallets' => $map($accounts->get(AccountType::Wallet->value)),
            'banks' => $map($accounts->get(AccountType::Bank->value)),
            'cashboxes' => $map($accounts->get(AccountType::Cashbox->value)),
            'treasury' => $map($accounts->get(AccountType::Bank->value)),
            'accounts' => $map($accounts->flatten(1)),
        ]);
    }

    public function accountTransactions(Account $account)
    {
        $transactions = Transaction::where('from_account_id', $account->id)
            ->orWhere('to_account_id', $account->id)
            ->with(['createdBy'])
            ->latest()
            ->paginate(20);

        return ApiResponse::success('Account transactions retrieved successfully', $transactions);
    }
}
