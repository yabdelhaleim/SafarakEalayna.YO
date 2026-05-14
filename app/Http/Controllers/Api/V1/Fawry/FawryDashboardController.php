<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Fawry\FawryTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FawryDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        // 1. Transactions Stats
        $stats = [
            'total_transactions' => FawryTransaction::count(),
            'monthly_revenue' => (float) FawryTransaction::where('created_at', '>=', $month)->sum('selling_price'),
            'monthly_profit' => (float) FawryTransaction::where('created_at', '>=', $month)->sum('profit'),
            'today_transactions' => FawryTransaction::where('created_at', '>=', $today)->count(),
            'today_revenue' => (float) FawryTransaction::where('created_at', '>=', $today)->sum('selling_price'),
        ];

        // 2. Account Balances (Fawry module only)
        $accounts = Account::where('module_type', 'fawry')->where('is_active', true)->get();
        
        $stats['cashboxes'] = [
            'count' => $accounts->whereIn('type', [AccountType::Cashbox->value, AccountType::Treasury->value])->count(),
            'balance' => (float) $accounts->whereIn('type', [AccountType::Cashbox->value, AccountType::Treasury->value])->sum('balance'),
        ];
        
        $stats['banks'] = [
            'count' => $accounts->where('type', AccountType::Bank->value)->count(),
            'balance' => (float) $accounts->where('type', AccountType::Bank->value)->sum('balance'),
        ];
        
        $stats['wallets'] = [
            'count' => $accounts->where('type', AccountType::Wallet->value)->count(),
            'balance' => (float) $accounts->where('type', AccountType::Wallet->value)->sum('balance'),
        ];

        $stats['total_liquidity'] = $stats['cashboxes']['balance'] + $stats['banks']['balance'] + $stats['wallets']['balance'];

        // 3. Recent Transactions
        $recentTransactions = FawryTransaction::with(['employee:id,name', 'currency:id,name_ar'])
            ->latest()
            ->limit(10)
            ->get();

        return ApiResponse::success('Fawry dashboard data', [
            'stats' => $stats,
            'recent_transactions' => $recentTransactions,
        ]);
    }
}
