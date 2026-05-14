<?php

namespace App\Http\Controllers\Api\V1\Wallet;

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

        return response()->json([
            'success' => true,
            'data' => [
                'wallets' => $accounts->get(AccountType::Wallet->value, []),
                'banks' => $accounts->get(AccountType::Bank->value, []),
                'cashboxes' => $accounts->get(AccountType::Cashbox->value, []),
                'treasury' => $accounts->get(AccountType::Treasury->value, []),
            ]
        ]);
    }

    public function accountTransactions(Account $account)
    {
        $transactions = Transaction::where('account_id', $account->id)
            ->with(['employee'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }
}
