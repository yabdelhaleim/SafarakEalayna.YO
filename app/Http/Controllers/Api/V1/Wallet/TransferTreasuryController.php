<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Enums\AccountType;
use Illuminate\Http\Request;

class TransferTreasuryController extends Controller
{
    public function overview()
    {
        $accounts = Account::where('module_type', 'wallet_transfer')
            ->orderBy('type')
            ->get()
            ->groupBy(function($acc) {
                return $acc->type->value;
            });

        return ApiResponse::success('Wallet treasury overview retrieved successfully', [
            'wallets' => $accounts->get(AccountType::Wallet->value, []),
            'banks' => $accounts->get(AccountType::Bank->value, []),
            'cashboxes' => $accounts->get(AccountType::Cashbox->value, []),
            'treasury' => $accounts->get(AccountType::Treasury->value, []),
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
