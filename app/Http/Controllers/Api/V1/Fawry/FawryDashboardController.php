<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\AccountModuleDivision;
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

        // ============================================================
        // Section 1 — Legacy Transaction Stats (for the dashboard cards)
        // ============================================================
        //
        // Every filter MUST include `deleted_at IS NULL` so soft-deleted
        // rows (cancelled walk-in transactions, etc.) do NOT inflate any
        // KPI. Soft-deleting reverses the GL entries, so they would
        // otherwise double-count or stay as ghosts.
        //
        // Every subquery is wrapped as a single SELECT per stat instead
        // of an aggregated CROSS JOIN so a stale derived index can't
        // silently multiply the result.
        $stats = [];

        // (a) Counts
        $stats['total_transactions'] = (int) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->count();

        $stats['pending_transactions'] = (int) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->whereRaw('amount < selling_price')
            ->count();

        // `due_transactions` is an alias for `pending_transactions` in the
        // current model: there is no separate "due" lifecycle — a row is
        // "due" iff the client still owes money (amount < selling_price).
        // Kept as a separate key for forward-compatibility and to keep
        // the dashboard's KPI cards (which have distinct Arabic labels)
        // decoupled from the calculation logic.
        $stats['due_transactions'] = $stats['pending_transactions'];

        $stats['incomplete_transactions'] = (int) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->where('amount', 0)
            ->count();

        // (b) Money totals — all derived from the row columns. These
        // intentionally mirror the GL because the dashboard needs
        // per-transaction granularity (the GL is consolidated).
        $stats['total_bills'] = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->sum('selling_price');

        // `total_payments` = Σ amount WHERE amount > 0 (not deleted).
        //
        // Bug fix: prior versions filtered this through
        // `whereNotNull('paid_at')` or `income_transaction_id IS NOT NULL`
        // which silently excluded:
        //   - walk-in pay-debt settlements (FawryWalkInPaymentController
        //     only mutates the `amount` column, it does NOT create a new
        //     fawry_transaction row and does NOT update `paid_at`),
        //   - cash sales paid at creation when the post-creation
        //     `update()` of `income_transaction_id` failed for any reason.
        //
        // Reading `amount` directly is the correct invariant:
        // any non-zero `amount` represents money the client has paid in
        // toward this transaction. The walk-in FIFO allocation already
        // bumped `amount` on the relevant rows before this query runs.
        $stats['total_payments'] = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->where('amount', '>', 0)
            ->sum('amount');

        // `total_dues` = Σ (selling_price − amount) WHERE amount < selling_price
        // This is the per-transaction outstanding receivable. Note: this
        // can diverge from the GL-based `walkin_debt` below because the
        // GL also includes late adjustments from the unified walk-in AR
        // account (ذمم عملاء فوري غير مسجلين). The two views are
        // intentionally complementary:
        //   - `total_dues` — what each individual row says is owed
        //   - `walkin_debt` — what the consolidated ledger says is owed
        $stats['total_dues'] = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->whereRaw('amount < selling_price')
            ->selectRaw('COALESCE(SUM(selling_price - amount), 0) AS debt')
            ->value('debt') ?? 0.0;

        // Consistency assertion (defensive): if total_dues > 0 then
        // there must be at least one due row. We DON'T enforce this via
        // a CHECK constraint because it's a derived invariant and can
        // legitimately flip on partial-piaster rounding, but we DO
        // log+normalize if violated so the dashboard never displays
        // a self-contradicting set of KPIs.
        if ($stats['total_dues'] > 0.005 && $stats['due_transactions'] === 0) {
            \Illuminate\Support\Facades\Log::warning('FawryDashboard: total_dues>0 but due_transactions=0 — investigating', [
                'total_dues' => $stats['total_dues'],
                'due_transactions' => $stats['due_transactions'],
            ]);
            // Normalize: trust the row-level COUNT over the SUM in this
            // edge case. This keeps the UI from showing a non-zero
            // "إجمالي المستحقات" with a zero "عمليات مستحقة".
            $stats['total_dues'] = 0.0;
        }
        if ($stats['due_transactions'] > 0 && $stats['total_dues'] <= 0.005) {
            \Illuminate\Support\Facades\Log::warning('FawryDashboard: due_transactions>0 but total_dues=0 — investigating', [
                'total_dues' => $stats['total_dues'],
                'due_transactions' => $stats['due_transactions'],
            ]);
        }

        // ============================================================
        // Section 2 — Period Revenue & Profit (this month / today)
        // ============================================================
        $stats['monthly_revenue'] = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $month)
            ->sum('selling_price');

        $stats['monthly_profit'] = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $month)
            ->sum('profit');

        $stats['today_transactions'] = (int) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $today)
            ->count();

        $stats['today_revenue'] = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $today)
            ->sum('selling_price');

        // ============================================================
        // Section 3 — Liquidity Account Balances (Fawry module only)
        // ============================================================
        //
        // Phase-6 / Phase-7 fix: switch from the manual `module/module_type`
        // equality to {@see AccountModuleDivision::applyModuleFilter} so the
        // query ALSO picks up division-unified vault accounts (i.e. accounts
        // with `module_type='office'` rather than `module_type='fawry'`).
        //
        // Background — this mirrors the same bug fixed in
        // {@see \App\Http\Controllers\Api\V1\Bus\BusDashboardController}
        // (Bug #B-02) and confirmed in
        // {@see \App\Http\Controllers\Api\V1\Fawry\FawryMachineApiController}
        // line ~129:
        //
        //     "liquidity accounts cannot carry module_type='fawry' — they
        //      must use the division marker 'office' — so a strict equality
        //      would block every legitimate Fawry cashbox/wallet/bank in
        //      production."
        //
        // The previous `where('module','fawry')->orWhere('module_type','fawry')`
        // filter therefore returned an EMPTY account set on production
        // databases (FawryModuleProductionTestSeeder seeds the cashboxes
        // with `module_type='office'` per AccountModuleContract), which
        // surfaced as a phantom `total_liquidity=0` on the dashboard while
        // `cashboxes['balance']` showed non-zero numbers from another
        // module's accounts. Using `applyModuleFilter('fawry')` expands
        // the WHERE clause to include the office-division vault without
        // affecting other modules' dashboards.
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES);

        AccountModuleDivision::applyModuleFilter($accounts, 'fawry');

        $accounts = $accounts->get();

        $stats['cashboxes'] = LiquidityAccountGroups::countAndBalance(
            $accounts,
            AccountType::Cashbox,
            AccountType::Bank
        );

        $stats['banks'] = LiquidityAccountGroups::countAndBalance($accounts, AccountType::Bank);

        $stats['wallets'] = LiquidityAccountGroups::countAndBalance($accounts, AccountType::Wallet);

        $stats['total_liquidity'] = $stats['cashboxes']['balance'] + $stats['banks']['balance'] + $stats['wallets']['balance'];

        // ============================================================
        // Section 4 — Customer Debts (GL-sourced, authoritative)
        // ============================================================
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

        // ============================================================
        // Section 5 — Walk-in Clients Debt (per-client split)
        // ============================================================
        //
        // Walk-in clients have no Customer record. Per-client balance is
        // sourced from `fawry_transactions.selling_price - amount` grouped
        // by client_name where client_id IS NULL. The unified AR account
        // holds the GL mirror but per-client breakdown is not enforceable
        // there (one account → many client_names), so the report still has
        // to read the columns for the split.
        //
        // Filter `deleted_at IS NULL` because FawryTransactionService
        // soft-deletes cancelled walk-in transactions; their residual
        // `selling_price - amount` would otherwise inflate the dashboard's
        // walk-in debt even though the GL mirror (account 37) is already
        // zero — the ledger is the source of truth, the row columns are
        // just a per-client split helper.
        $stats['walkin_debt'] = (float) DB::table('fawry_transactions')
            ->whereNull('client_id')
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(selling_price - amount), 0) as debt')
            ->value('debt') ?? 0.0;

        $stats['walkin_clients_count'] = (int) DB::table('fawry_transactions')
            ->whereNull('client_id')
            ->whereNull('deleted_at')
            ->whereRaw('selling_price > amount')
            ->distinct()
            ->count('client_name');

        // ============================================================
        // Section 6 — Fawry Recharge Machines
        // ============================================================
        $stats['machines'] = [
            'count' => (int) FawryMachine::where('is_active', true)->count(),
            'balance' => (float) FawryMachine::where('is_active', true)->sum('balance'),
        ];

        // ============================================================
        // Section 7 — Recent Transactions (latest 10)
        // ============================================================
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
