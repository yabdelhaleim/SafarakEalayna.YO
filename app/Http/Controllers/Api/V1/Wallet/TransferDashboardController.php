<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;

class TransferDashboardController extends Controller
{
    public function index()
    {
        // 1. Module Stats (Isolated by module_type = wallet_transfer)
        $stats = [
            'wallets' => ['count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Wallet->value)->count(), 'balance' => (float) Account::where('module_type', AccountType::Wallet->value)->sum('balance')],
            'banks' => ['count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Bank->value)->count(), 'balance' => (float) Account::where('module_type', AccountType::Bank->value)->sum('balance')],
            'cashboxes' => ['count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Cashbox->value)->count(), 'balance' => (float) Account::where('module_type', AccountType::Cashbox->value)->sum('balance')],
            'treasury' => ['count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Bank->value)->count(), 'balance' => (float) Account::where('module_type', AccountType::Bank->value)->sum('balance')],
        ];

        // 1.5 Total Liquidity
        $stats['total_liquidity'] = (float) $stats['wallets']['balance'] +
                                    (float) $stats['banks']['balance'] +
                                    (float) $stats['cashboxes']['balance'] +
                                    (float) $stats['treasury']['balance'];

        // 1.6 Customers Debt
        $customerAccounts = Account::where('type', AccountType::Customer->value)->pluck('id');
        $stats['customers_debt'] = 0.0;
        if ($customerAccounts->isNotEmpty()) {
            $stats['customers_debt'] = (float) DB::table('account_entries')
                ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
                ->whereIn('account_entries.account_id', $customerAccounts)
                ->where('transactions.module', 'wallet')
                ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as total_debt')
                ->value('total_debt') ?? 0.0;
        }

        // 2. Transaction Summary (Today)
        $today = now()->startOfDay();
        $daily_stats = [
            'revenue' => WalletTransaction::where('created_at', '>=', $today)->sum('amount'),
            'profit' => WalletTransaction::where('created_at', '>=', $today)->sum('service_fee'),
            'count' => WalletTransaction::where('created_at', '>=', $today)->count(),
        ];

        // 3. Recent Transactions
        $recent = WalletTransaction::latest()
            ->with(['employee', 'walletType'])
            ->limit(10)
            ->get();

        return ApiResponse::success('Transfer dashboard statistics retrieved successfully', [
            'stats' => $stats,
            'daily' => $daily_stats,
            'recent_transactions' => $recent,
        ]);
    }
}
