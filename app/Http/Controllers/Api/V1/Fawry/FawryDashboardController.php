<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
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
            AccountType::Bank
        );

        $stats['banks'] = LiquidityAccountGroups::countAndBalance($accounts, AccountType::Bank);

        $stats['wallets'] = LiquidityAccountGroups::countAndBalance($accounts, AccountType::Wallet);

        $stats['total_liquidity'] = $stats['cashboxes']['balance'] + $stats['banks']['balance'] + $stats['wallets']['balance'];

        // 3. Customers Debt (مديونية العملاء)
        //
        // Phase A fix: source from the GL ledger (account_entries), NOT from
        // fawry_transactions.selling_price/amount columns. The two diverge
        // the moment `updateTransaction()` reposts the ledger after a price
        // change — the model gets the new value but if we read from the
        // model we'd silently inflate / deflate the debt number.
        //
        // Logic: for each customer account that has at least one Fawry
        // transaction (TransactionModule::Fawry), sum (credit - debit) on
        // its account_entries where the underlying transactions have
        // module='fawry'. Positive = customer owes us (receivable).
        //
        // The `accounts.module_type = 'fawry'` filter excludes the unified
        // walk-in AR account ("ذمم عملاء فوري غير مسجلين") so it isn't
        // double-counted with `walkin_debt` below.
        $stats['customers_debt'] = (float) DB::table('account_entries')
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->join('accounts', 'account_entries.account_id', '=', 'accounts.id')
            ->where('accounts.type', AccountType::Customer->value)
            ->where('accounts.module_type', 'fawry')
            ->where('transactions.module', TransactionModule::Fawry->value)
            ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as debt')
            ->value('debt') ?? 0.0;

        // 3b. Walk-in clients debt (مديونيات غير المسجلين)
        //
        // Walk-in clients have no Customer record. Per-client balance is
        // sourced from `fawry_transactions.selling_price - amount` grouped
        // by client_name where client_id IS NULL. The unified AR account
        // holds the GL mirror but per-client breakdown is not enforceable
        // there (one account → many client_names), so the report still has
        // to read the columns for the split.
        $stats['walkin_debt'] = (float) DB::table('fawry_transactions')
            ->whereNull('client_id')
            ->selectRaw('COALESCE(SUM(selling_price - amount), 0) as debt')
            ->value('debt') ?? 0.0;

        $stats['walkin_clients_count'] = (int) DB::table('fawry_transactions')
            ->whereNull('client_id')
            ->whereRaw('selling_price > amount')
            ->distinct()
            ->count('client_name');

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
