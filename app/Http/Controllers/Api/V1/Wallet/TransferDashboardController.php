<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Wallet\WalletTransaction;
use App\Enums\AccountType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferDashboardController extends Controller
{
    public function index()
    {
        // 1. Module Stats (Isolated by module_type = wallet_transfer)
        $stats = [
            'wallets' => [
                'count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Wallet->value)->count(),
                'balance' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Wallet->value)->sum('balance'),
            ],
            'banks' => [
                'count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Bank->value)->count(),
                'balance' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Bank->value)->sum('balance'),
            ],
            'cashboxes' => [
                'count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Cashbox->value)->count(),
                'balance' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Cashbox->value)->sum('balance'),
            ],
            'treasury' => [
                'count' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Treasury->value)->count(),
                'balance' => Account::where('module_type', 'wallet_transfer')->where('type', AccountType::Treasury->value)->sum('balance'),
            ],
        ];

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

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'daily' => $daily_stats,
                'recent_transactions' => $recent,
            ]
        ]);
    }
}
