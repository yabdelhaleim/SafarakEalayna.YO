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
        // 1. Module Stats (wallet_transfer is in the Office division).
        //
        // Bug fix (2026-07-27): the previous code used `where('module_type',
        // AccountType::Wallet->value)` for the balance SUM — but the seeded
        // wallet accounts have `module_type='office'` (division) with a
        // `module='wallet_transfer'` alias. As a result, the API returned
        // `balance=0` for every section even when hundreds of thousands of
        // EGP sat in the wallets. The matching count() filter used the
        // correct module_type='wallet_transfer' (which is also a valid
        // legacy value), so the two were inconsistent.
        //
        // We now use `whereIn('module_type', ['office', 'wallet_transfer'])`
        // — which matches:
        //   • Newly-seeded rows (module_type='office', module='wallet_transfer')
        //   • Legacy rows (module_type='wallet_transfer')
        // The Bank/Cashbox counts remain scoped to the same module filter so
        // a wallet_transfer bank doesn't bleed into bus/fawry/online counts.
        $moduleFilter = fn ($q) => $q->whereIn('module_type', ['office', 'wallet_transfer'])
            ->where(function ($q2) {
                $q2->where('module', 'wallet_transfer')
                    ->orWhere('module_type', 'wallet_transfer');
            });

        $stats = [
            'wallets' => [
                'count' => (clone $moduleFilter(Account::query()))->where('type', AccountType::Wallet->value)->count(),
                'balance' => (float) (clone $moduleFilter(Account::query()))->where('type', AccountType::Wallet->value)->sum('balance'),
            ],
            'banks' => [
                'count' => (clone $moduleFilter(Account::query()))->where('type', AccountType::Bank->value)->count(),
                'balance' => (float) (clone $moduleFilter(Account::query()))->where('type', AccountType::Bank->value)->sum('balance'),
            ],
            'cashboxes' => [
                'count' => (clone $moduleFilter(Account::query()))->where('type', AccountType::Cashbox->value)->count(),
                'balance' => (float) (clone $moduleFilter(Account::query()))->where('type', AccountType::Cashbox->value)->sum('balance'),
            ],
            'treasury' => [
                'count' => (clone $moduleFilter(Account::query()))->where('type', AccountType::Bank->value)->count(),
                'balance' => (float) (clone $moduleFilter(Account::query()))->where('type', AccountType::Bank->value)->sum('balance'),
            ],
        ];

        // 1.5 Total Liquidity
        $stats['total_liquidity'] = (float) $stats['wallets']['balance'] +
                                    (float) $stats['banks']['balance'] +
                                    (float) $stats['cashboxes']['balance'] +
                                    (float) $stats['treasury']['balance'];

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
