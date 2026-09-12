<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Wallet\WalletTransaction;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LiquidityAccountGroups;
use Illuminate\Support\Facades\DB;

class TransferDashboardController extends Controller
{
    public function index()
    {
        // 1. Module Stats (wallet_transfer is in the Office division).
        //
        // Bug fix (2026-09-05): the previous code used a double-AND filter:
        //   whereIn('module_type', ['office', 'wallet_transfer'])
        //     ->where('module', 'wallet_transfer') OR module_type='wallet_transfer'
        // This correctly matches the outer whereIn but then ALSO requires the
        // inner condition, which excludes real liquidity accounts that have
        // module_type='office' and module='' (empty). The result was all
        // wallet/bank/cashbox balances returning 0 on the dashboard.
        //
        // Fix: use AccountModuleDivision::applyModuleFilter('wallet_transfer')
        // — the same helper used by FawryDashboardController and every other
        // office-module dashboard. It correctly expands the filter to include:
        //   • module_type='wallet_transfer' (legacy rows)
        //   • module_type='office'           (division-tagged rows)
        //   • module='wallet_transfer'        (module column alias)
        // The Bank/Cashbox/Wallet accounts in the office division are now
        // correctly included in the balance SUM.
        //
        // Also add `is_active=true` filter to match the treasury controller's
        // behaviour (inactive/closed accounts should not count toward liquidity).
        $baseQuery = Account::query()->where('is_active', true);
        AccountModuleDivision::applyModuleFilter($baseQuery, 'wallet_transfer');

        $accounts = (clone $baseQuery)
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
            ->get(['type', 'balance']);

        $stats = [
            'wallets'   => LiquidityAccountGroups::countAndBalance($accounts, AccountType::Wallet),
            'banks'     => LiquidityAccountGroups::countAndBalance($accounts, AccountType::Bank),
            'cashboxes' => LiquidityAccountGroups::countAndBalance($accounts, AccountType::Cashbox),
            // 'treasury' kept for response-shape backward compatibility.
            // AccountType::Treasury was retired in Phase 3.5b; no accounts
            // carry this type anymore. The value is always 0.
            'treasury'  => ['count' => 0, 'balance' => 0.0],
        ];

        // 1.5 Total Liquidity
        $stats['total_liquidity'] = (float) $stats['wallets']['balance'] +
                                    (float) $stats['banks']['balance'] +
                                    (float) $stats['cashboxes']['balance'];

        // 1.6 Customers Debt — scoped to wallet_transfer customer accounts only.
        //
        // Bug fix (2026-07-27): the previous query fetched every customer
        // account across the whole system (bus, fawry, online, hajj_umra,
        // visas, flights…), then summed every ledger entry they ever
        // touched — even those belonging to foreign modules. The sum
        // `SUM(credit) - SUM(debit)` mixed debts across modules and
        // surfaces wrong numbers in the wallet dashboard.
        //
        // The fix restricts the account scope to customer accounts whose
        // `module_type='wallet_transfer'` (the canonical tag the
        // CustomerLedgerObserver + WalletTransactionService set on
        // a customer's first wallet touch).
        $customerAccounts = Account::where('type', AccountType::Customer->value)
            ->where('module_type', 'wallet_transfer')
            ->pluck('id');
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
