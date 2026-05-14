<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /**
     * تقرير كشف خزينة (إيرادات + مصروفات + رصيد)
     */
    public function getTreasuryReport(Account $treasury, array $filters = []): array
    {
        $query = $treasury->entries()
            ->with('transaction');

        if (!empty($filters['from_date'])) {
            $query->where('account_entries.created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('account_entries.created_at', '<=', $filters['to_date']);
        }

        $entries = $query->orderBy('account_entries.created_at', 'desc')->get();

        $totalIncome = $entries->sum('credit');
        $totalExpense = $entries->sum('debit');
        $netChange = $totalIncome - $totalExpense;

        return [
            'treasury' => $treasury,
            'from_date' => $filters['from_date'] ?? null,
            'to_date' => $filters['to_date'] ?? null,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_change' => $netChange,
            'opening_balance' => $treasury->balance - $netChange,
            'closing_balance' => $treasury->balance,
            'entries' => $entries,
        ];
    }

    /**
     * تقرير أرباح يومية / شهرية / سنوية
     */
    public function getProfitReport(array $filters = []): array
    {
        $period = $filters['period'] ?? 'daily'; // daily, monthly, yearly
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $toDate = $filters['to_date'] ?? now()->endOfDay()->format('Y-m-d');

        $transactions = Transaction::whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('
                DATE(created_at) as date,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as expense,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) - SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as profit
            ', [TransactionType::Income->value, TransactionType::Expense->value, TransactionType::Income->value, TransactionType::Expense->value])
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $totalIncome = $transactions->sum('income');
        $totalExpense = $transactions->sum('expense');
        $totalProfit = $transactions->sum('profit');

        return [
            'period' => $period,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'total_profit' => $totalProfit,
            'profit_margin' => $totalIncome > 0 ? ($totalProfit / $totalIncome) * 100 : 0,
            'transactions' => $transactions,
        ];
    }

    /**
     * تقرير مديونيات العملاء
     */
    public function getCustomerDebtsReport(array $filters = []): array
    {
        $query = Customer::query();

        if (!empty($filters['search'])) {
            $query->where('full_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('phone', 'like', '%'.$filters['search'].'%');
        }

        if (!empty($filters['customer_type'])) {
            $query->where('type', $filters['customer_type']);
        }

        $customers = $query->with(['flightBookings' => function ($q) use ($filters) {
            $q->where('status', 'pending');
            if (!empty($filters['module'])) {
                // Since flight bookings are always 'flight' module, 
                // we check if the requested module matches.
                if (is_array($filters['module'])) {
                    if (!in_array('flight', $filters['module'])) $q->whereRaw('1=0');
                } else if ($filters['module'] !== 'flight') {
                    $q->whereRaw('1=0');
                }
            }
        }])->get();

        $debts = $customers->map(function ($customer) {
            $pendingBookings = $customer->flightBookings;
            $totalDebt = $pendingBookings->sum('selling_price') - $pendingBookings->sum(function ($booking) {
                return $booking->payments->sum('amount');
            });

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'phone' => $customer->phone,
                'total_debt' => $totalDebt,
                'pending_bookings_count' => $pendingBookings->count(),
                'pending_bookings' => $pendingBookings,
            ];
        })->filter(function ($debt) {
            return $debt['total_debt'] > 0;
        })->sortByDesc('total_debt')->values();

        $totalDebts = $debts->sum('total_debt');

        return [
            'total_debts' => $totalDebts,
            'customers_with_debts' => $debts->count(),
            'debts' => $debts,
        ];
    }

    /**
     * تقرير مديونيات الموردين
     */
    public function getSupplierDebtsReport(array $filters = []): array
    {
        $query = Supplier::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['module_type'])) {
            $query->whereHas('account', function($q) use ($filters) {
                $q->where('module_type', $filters['module_type']);
            });
        }

        $suppliers = $query->with('account')->get();

        $debts = $suppliers->map(function ($supplier) {
            $balance = $supplier->account ? $supplier->account->balance : 0;
            $currentDebt = $supplier->current_debt;
            $remainingCredit = $supplier->credit_limit - $currentDebt;

            return [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'type' => $supplier->type,
                'account_balance' => $balance,
                'credit_limit' => $supplier->credit_limit,
                'current_debt' => $currentDebt,
                'remaining_credit' => $remainingCredit,
                'is_over_limit' => $currentDebt > $supplier->credit_limit,
            ];
        })->filter(function ($debt) {
            return $debt['current_debt'] > 0 || $debt['account_balance'] < 0;
        })->sortByDesc('current_debt')->values();

        $totalDebts = $debts->sum('current_debt');
        $totalNegativeBalances = $debts->where('account_balance', '<', 0)->sum('account_balance');

        return [
            'total_debts' => $totalDebts,
            'total_negative_balances' => abs($totalNegativeBalances),
            'suppliers_with_debts' => $debts->count(),
            'debts' => $debts,
        ];
    }

    /**
     * ملخص مالي شامل
     */
    public function getFinancialSummary(array $filters = []): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $toDate = $filters['to_date'] ?? now()->endOfDay()->format('Y-m-d');

        $totalIncome = Transaction::whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', TransactionType::Income->value)
            ->sum('amount');

        $totalExpense = Transaction::whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', TransactionType::Expense->value)
            ->sum('amount');

        $totalTransfers = Transaction::whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', TransactionType::Transfer->value)
            ->sum('amount');

        $totalRefunds = Transaction::whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', TransactionType::Refund->value)
            ->sum('amount');

        $tourismAccountsBalance = Account::tourism()->sum('balance');
        $officeAccountsBalance = Account::office()->sum('balance');
        $totalAccountsBalance = Account::sum('balance');

        $profit = $totalIncome - $totalExpense;

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'income' => $totalIncome,
            'expense' => $totalExpense,
            'transfers' => $totalTransfers,
            'refunds' => $totalRefunds,
            'profit' => $profit,
            'profit_margin' => $totalIncome > 0 ? ($profit / $totalIncome) * 100 : 0,
            'tourism_accounts_balance' => $tourismAccountsBalance,
            'office_accounts_balance' => $officeAccountsBalance,
            'total_accounts_balance' => $totalAccountsBalance,
        ];
    }

    /**
     * ربح تقديري لكل الموديولات من مجموع حقول المعاملة حسب نوع income/expense.
     */
    public function getProfitByModule(array $filters = []): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $toDate = $filters['to_date'] ?? now()->endOfDay()->format('Y-m-d');
        $income = TransactionType::Income->value;
        $expense = TransactionType::Expense->value;

        $rows = DB::table('transactions')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->whereNotNull('module')
            ->selectRaw('module as module_key')
            ->selectRaw("SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as income_total", [$income])
            ->selectRaw("SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as expense_total", [$expense])
            ->groupBy(DB::raw('module'))
            ->get()
            ->sortByDesc(fn ($r) => (float) $r->income_total)
            ->values();

        $breakdown = $rows->map(function ($r) {
            $inc = round((float) $r->income_total, 2);
            $exp = round((float) $r->expense_total, 2);

            return [
                'module' => $r->module_key,
                'income' => $inc,
                'expense' => $exp,
                'profit' => round($inc - $exp, 2),
            ];
        })->values()->all();

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'by_module' => $breakdown,
        ];
    }

    /**
     * لقطة سيولة وتدفق نقدي سريعة (مجموع أرصدة السيولة + صافي حركة القيود في نافذة زمنية).
     */
    public function getCashFlowRealtime(array $filters = []): array
    {
        $hours = (int) ($filters['recent_hours'] ?? 24);
        if ($hours < 1) {
            $hours = 24;
        }
        $since = now()->subHours($hours);

        $liquidTypes = [
            AccountType::Treasury,
            AccountType::Cashbox,
            AccountType::Bank,
            AccountType::Wallet,
        ];

        $accounts = Account::query()->whereIn('type', $liquidTypes)->get(['id', 'name', 'type', 'balance', 'currency']);
        $totalLiquidBalance = round((float) $accounts->sum('balance'), 2);
        $ids = $accounts->pluck('id')->all();

        $netLiquidityFlows = null;
        if ($ids !== []) {
            $netLiquidityFlows = AccountEntry::query()
                ->whereIn('account_id', $ids)
                ->where('account_entries.created_at', '>=', $since)
                ->selectRaw('SUM(credit - debit) as net_change')
                ->value('net_change');
        }

        return [
            'as_of' => now()->toIso8601String(),
            'recent_hours' => $hours,
            'since' => $since->toIso8601String(),
            'liquid_balance_total' => $totalLiquidBalance,
            'liquid_net_flow' => round((float) ($netLiquidityFlows ?? 0), 2),
            'liquid_accounts' => $accounts,
        ];
    }

    /**
     * أرصدة دفتر العملاء المرتبطة بـ customers.account_id.
     */
    public function getCustomerLedgerBalances(array $filters = []): array
    {
        $q = Customer::query()->with('ledgerAccount')->whereNotNull('account_id');

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(function ($qq) use ($s) {
                $qq->where('full_name', 'like', '%'.$s.'%')
                    ->orWhere('phone', 'like', '%'.$s.'%');
            });
        }

        $rows = $q->orderBy('full_name')->get()->map(function (Customer $c) {
            $a = $c->ledgerAccount;

            return [
                'customer_id' => $c->id,
                'full_name' => $c->full_name,
                'phone' => $c->phone,
                'ledger_account_id' => $a?->id,
                'ledger_account_name' => $a?->name,
                'balance' => $a ? round((float) $a->balance, 2) : null,
                'currency' => $a?->currency,
            ];
        })->values()->all();

        return [
            'count' => count($rows),
            'customers' => $rows,
        ];
    }

    /**
     * مطابقة سجل بنك تجاري مع حساب الدفتر المرتبط (bank.balance vs accounts.balance).
     */
    public function getBankLedgerReconciliation(array $filters = []): array
    {
        $tolerance = (float) ($filters['tolerance'] ?? config('accounting.reconciliation.tolerance', 0.02));

        $banksQuery = Bank::query()->with('ledgerAccount')->where('is_active', true);
        if (! empty($filters['bank_id'])) {
            $banksQuery->where('id', (int) $filters['bank_id']);
        }

        $items = $banksQuery->get()->map(function (Bank $bank) use ($tolerance) {
            $ledger = $bank->ledgerAccount;
            $stored = round((float) $bank->balance, 2);
            $gl = $ledger ? round((float) $ledger->balance, 2) : null;
            $delta = $gl === null ? null : round($stored - $gl, 2);

            return [
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
                'stored_balance' => $stored,
                'ledger_account_id' => $ledger?->id,
                'ledger_balance' => $gl,
                'difference' => $delta,
                'in_sync' => $delta !== null && abs($delta) <= $tolerance,
            ];
        })->values()->all();

        return [
            'tolerance' => $tolerance,
            'banks' => $items,
        ];
    }
}
