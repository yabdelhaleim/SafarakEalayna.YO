<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Support\Finance\LiquidityAccountGroups;
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

        $stats['cashboxes'] = LiquidityAccountGroups::countAndBalance(
            $accounts,
            AccountType::Cashbox,
            AccountType::Treasury
        );

        $stats['banks'] = LiquidityAccountGroups::countAndBalance($accounts, AccountType::Bank);

        $stats['wallets'] = LiquidityAccountGroups::countAndBalance($accounts, AccountType::Wallet);

        $stats['total_liquidity'] = $stats['cashboxes']['balance'] + $stats['banks']['balance'] + $stats['wallets']['balance'];

        // 3. Customers Debt (مديونية العملاء)
        $stats['customers_debt'] = (float) FawryTransaction::sum(DB::raw('selling_price - amount'));

        // 4. Machines Info (ماكينات الشحن)
        $stats['machines'] = [
            'count' => FawryMachine::where('is_active', true)->count(),
            'balance' => (float) FawryMachine::where('is_active', true)->sum('balance'),
        ];

        // 5. Recent Transactions
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
