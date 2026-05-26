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
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\Bus\BusCompany;
use App\Models\Flight\AirlineAccount;
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
                'customer_name' => $customer->full_name ?: $customer->name,
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

    /**
     * تقرير الديون والمديونيات الموحد
     */
    public function getDebtsReport(array $filters = []): array
    {
        $department = $filters['department'] ?? null; // tourism, office
        $module = $filters['module'] ?? null; // flight, bus, hajj_umra, visa, fawry, online
        $direction = $filters['direction'] ?? 'all'; // receivables, payables, all
        $search = $filters['search'] ?? null;
        $entityType = $filters['entity_type'] ?? 'all'; // all, customer, supplier

        $results = [];

        if ($entityType === 'all' || $entityType === 'customer') {
            // 1. QUERY CUSTOMERS (always part of receivables if balance > 0, payables if balance < 0)
            $customerQuery = Customer::query()->with('ledgerAccount');

        if ($search) {
            $customerQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Apply module-specific conditions for customers
        if ($module) {
            switch ($module) {
                case 'flight':
                    $customerQuery->whereHas('flightBookings');
                    break;
                case 'bus':
                    $customerQuery->whereHas('busBookings');
                    break;
                case 'hajj_umra':
                    $customerQuery->whereHas('hajjUmraBookings');
                    break;
                case 'visa':
                    $customerQuery->whereHas('visaBookings');
                    break;
                case 'fawry':
                    $customerQuery->whereHas('fawryTransactions');
                    break;
                case 'online':
                    $customerQuery->whereHas('onlineTransactions');
                    break;
                case 'general':
                    $customerQuery->whereDoesntHave('flightBookings')
                                  ->whereDoesntHave('busBookings')
                                  ->whereDoesntHave('hajjUmraBookings')
                                  ->whereDoesntHave('visaBookings')
                                  ->whereDoesntHave('fawryTransactions')
                                  ->whereDoesntHave('onlineTransactions');
                    break;
                case 'wallet':
                    $customerQuery->whereRaw('1=0');
                    break;
            }
        } elseif ($department) {
            if ($department === 'tourism') {
                $customerQuery->where(function ($q) {
                    $q->whereHas('hajjUmraBookings')
                      ->orWhereHas('visaBookings')
                      ->orWhereHas('flightBookings');
                });
            } elseif ($department === 'office') {
                $customerQuery->where(function ($q) {
                    $q->whereHas('busBookings')
                      ->orWhereHas('fawryTransactions')
                      ->orWhereHas('onlineTransactions')
                      ->orWhere(function ($sub) {
                          $sub->whereDoesntHave('hajjUmraBookings')
                              ->whereDoesntHave('visaBookings')
                              ->whereDoesntHave('flightBookings');
                      });
                });
            }
        }

        $customers = $customerQuery->get();

        foreach ($customers as $c) {
            $balance = $c->ledgerAccount ? (float) $c->ledgerAccount->balance : 0.0;
            if ($balance == 0.0) {
                continue;
            }

            // Customer balance > 0 is Receivable (customer owes company)
            // Customer balance < 0 is Payable (company owes customer)
            $dir = $balance > 0 ? 'receivables' : 'payables';

            if ($direction !== 'all' && $direction !== $dir) {
                continue;
            }

            // Deduce customer active department and module
            $custDept = 'office';
            $custMod = 'general';
            if ($c->flightBookings()->exists() || $c->hajjUmraBookings()->exists() || $c->visaBookings()->exists()) {
                $custDept = 'tourism';
                if ($c->flightBookings()->exists()) {
                    $custMod = 'flight';
                } elseif ($c->hajjUmraBookings()->exists()) {
                    $custMod = 'hajj_umra';
                } else {
                    $custMod = 'visa';
                }
            } else {
                if ($c->busBookings()->exists()) {
                    $custMod = 'bus';
                } elseif ($c->fawryTransactions()->exists()) {
                    $custMod = 'fawry';
                } elseif ($c->onlineTransactions()->exists()) {
                    $custMod = 'online';
                }
            }

            if ($department && $custDept !== $department) {
                continue;
            }
            if ($module && $custMod !== $module) {
                continue;
            }

            $results[] = [
                'id' => $c->id,
                'name' => $c->full_name ?: $c->name,
                'phone' => $c->phone,
                'entity_type' => 'customer',
                'entity_type_label' => 'عميل',
                'department' => $custDept,
                'department_label' => $custDept === 'tourism' ? 'قسم سياحه' : 'قسم مكتب',
                'module' => $custMod,
                'module_label' => $this->getModuleLabel($custMod),
                'balance' => $balance,
                'account_id' => $c->account_id,
                'statement_url' => $c->account_id ? "/finance/account-statement/{$c->account_id}" : null,
            ];
        }
        }

        if ($entityType === 'all' || $entityType === 'supplier') {
            // 2. QUERY SUPPLIERS
            $supplierQuery = Supplier::query()->with('account');
        if ($search) {
            $supplierQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $targetSupplierTypes = [];
        if ($module) {
            switch ($module) {
                case 'flight':
                    $targetSupplierTypes = ['airline'];
                    break;
                case 'bus':
                    $targetSupplierTypes = ['bus_company'];
                    break;
                case 'hajj_umra':
                    $targetSupplierTypes = ['hotel', 'service_provider'];
                    break;
                case 'visa':
                    $targetSupplierTypes = ['visa_provider'];
                    break;
                case 'fawry':
                case 'online':
                case 'wallet':
                    $targetSupplierTypes = ['service_provider'];
                    break;
                case 'general':
                    $targetSupplierTypes = ['service_provider', 'other'];
                    break;
            }
        } elseif ($department) {
            if ($department === 'tourism') {
                $targetSupplierTypes = ['airline', 'hotel', 'visa_provider', 'service_provider'];
            } elseif ($department === 'office') {
                $targetSupplierTypes = ['bus_company', 'service_provider', 'other'];
            }
        }

        if ($module || $department) {
            if (!empty($targetSupplierTypes)) {
                $supplierQuery->whereIn('type', $targetSupplierTypes);
            } else {
                $supplierQuery->whereRaw('1=0');
            }
        }

        $suppliers = $supplierQuery->get();

        foreach ($suppliers as $s) {
            $balance = $s->account ? (float) $s->account->balance : 0.0;
            if ($balance == 0.0) {
                continue;
            }

            // Supplier balance < 0 is Payable (company owes supplier)
            // Supplier balance > 0 is Receivable (supplier owes company)
            $dir = $balance < 0 ? 'payables' : 'receivables';

            if ($direction !== 'all' && $direction !== $dir) {
                continue;
            }

            $typeVal = $s->type instanceof \App\Enums\SupplierType ? $s->type->value : (string) $s->type;
            
            // Deduce supplier module and department
            $supMod = 'general';
            $supDept = 'office';
            
            if ($typeVal === 'airline') {
                $supMod = 'flight';
                $supDept = 'tourism';
            } elseif ($typeVal === 'bus_company') {
                $supMod = 'bus';
                $supDept = 'office';
            } elseif ($typeVal === 'hotel') {
                $supMod = 'hajj_umra';
                $supDept = 'tourism';
            } elseif ($typeVal === 'visa_provider') {
                $supMod = 'visa';
                $supDept = 'tourism';
            } else { // service_provider or other
                if ($module) {
                    $supMod = $module;
                    $supDept = in_array($module, ['flight', 'hajj_umra', 'visa']) ? 'tourism' : 'office';
                } elseif ($department) {
                    $supDept = $department;
                    $supMod = $department === 'tourism' ? 'hajj_umra' : 'online';
                } else {
                    $supMod = 'online';
                    $supDept = 'office';
                }
            }

            if ($department && $supDept !== $department) {
                continue;
            }
            if ($module && $supMod !== $module) {
                continue;
            }

            $results[] = [
                'id' => $s->id,
                'name' => $s->name,
                'phone' => $s->phone ?: $s->mobile,
                'entity_type' => 'supplier',
                'entity_type_label' => 'مورد / شركة',
                'department' => $supDept,
                'department_label' => $supDept === 'tourism' ? 'قسم سياحه' : 'قسم مكتب',
                'module' => $supMod,
                'module_label' => $this->getModuleLabel($supMod),
                'balance' => $balance,
                'account_id' => $s->account_id,
                'statement_url' => $s->account_id ? "/finance/account-statement/{$s->account_id}" : null,
            ];
        }

        // 3. QUERY VISA AGENTS (tourism -> visa)
        if ((!$department || $department === 'tourism') && (!$module || $module === 'visa')) {
            $agentQuery = VisaAgent::query()->with('account');
            if ($search) {
                $agentQuery->where(function ($q) use ($search) {
                    $q->where('company_name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            $agents = $agentQuery->get();
            foreach ($agents as $a) {
                $balance = $a->account ? (float) $a->account->balance : 0.0;
                if ($balance == 0.0) {
                    continue;
                }

                $dir = $balance < 0 ? 'payables' : 'receivables';
                if ($direction !== 'all' && $direction !== $dir) {
                    continue;
                }

                $results[] = [
                    'id' => $a->id,
                    'name' => $a->company_name,
                    'phone' => $a->phone,
                    'entity_type' => 'visa_agent',
                    'entity_type_label' => 'وكيل تأشيرات',
                    'department' => 'tourism',
                    'department_label' => 'قسم سياحه',
                    'module' => 'visa',
                    'module_label' => 'تأشيرات',
                    'balance' => $balance,
                    'account_id' => $a->account_id,
                    'statement_url' => $a->account_id ? "/finance/account-statement/{$a->account_id}" : null,
                ];
            }
        }

        // 4. QUERY EXECUTING COMPANIES (tourism -> hajj_umra)
        if ((!$department || $department === 'tourism') && (!$module || $module === 'hajj_umra')) {
            $execQuery = HajjUmraExecutingCompany::query()->with('account');
            if ($search) {
                $execQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            $execs = $execQuery->get();
            foreach ($execs as $e) {
                $balance = $e->account ? (float) $e->account->balance : 0.0;
                if ($balance == 0.0) {
                    continue;
                }

                $dir = $balance < 0 ? 'payables' : 'receivables';
                if ($direction !== 'all' && $direction !== $dir) {
                    continue;
                }

                $results[] = [
                    'id' => $e->id,
                    'name' => $e->name,
                    'phone' => $e->phone,
                    'entity_type' => 'executing_company',
                    'entity_type_label' => 'شركة منفذة (حج وعمرة)',
                    'department' => 'tourism',
                    'department_label' => 'قسم سياحه',
                    'module' => 'hajj_umra',
                    'module_label' => 'حج وعمرة',
                    'balance' => $balance,
                    'account_id' => $e->account_id,
                    'statement_url' => $e->account_id ? "/finance/account-statement/{$e->account_id}" : null,
                ];
            }
        }

        // 5. QUERY BUS COMPANIES (office -> bus)
        if ((!$department || $department === 'office') && (!$module || $module === 'bus')) {
            $busQuery = BusCompany::query()->with('account');
            if ($search) {
                $busQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            $buses = $busQuery->get();
            foreach ($buses as $b) {
                $balance = $b->account ? (float) $b->account->balance : 0.0;
                if ($balance == 0.0) {
                    continue;
                }

                $dir = $b->account->balance < 0 ? 'payables' : 'receivables';
                if ($direction !== 'all' && $direction !== $dir) {
                    continue;
                }

                $results[] = [
                    'id' => $b->id,
                    'name' => $b->name,
                    'phone' => $b->phone,
                    'entity_type' => 'bus_company',
                    'entity_type_label' => 'شركة باصات',
                    'department' => 'office',
                    'department_label' => 'قسم مكتب',
                    'module' => 'bus',
                    'module_label' => 'باص',
                    'balance' => $balance,
                    'account_id' => $b->account_id,
                    'statement_url' => $b->account_id ? "/finance/account-statement/{$b->account_id}" : null,
                ];
            }
        }

        // 6. QUERY AIRLINE ACCOUNTS (tourism -> flight)
        if ((!$department || $department === 'tourism') && (!$module || $module === 'flight')) {
            $airQuery = AirlineAccount::query();
            if ($search) {
                $airQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }
            $airlines = $airQuery->get();
            foreach ($airlines as $a) {
                $balance = (float) $a->balance;
                if ($balance == 0.0) {
                    continue;
                }

                $dir = $balance < 0 ? 'payables' : 'receivables';
                if ($direction !== 'all' && $direction !== $dir) {
                    continue;
                }

                $results[] = [
                    'id' => $a->id,
                    'name' => $a->name . " ({$a->code})",
                    'phone' => null,
                    'entity_type' => 'airline_account',
                    'entity_type_label' => 'حساب خط طيران',
                    'department' => 'tourism',
                    'department_label' => 'قسم سياحه',
                    'module' => 'flight',
                    'module_label' => 'طيران',
                    'balance' => $balance,
                    'account_id' => null,
                    'statement_url' => "/flights/airline-accounts/{$a->id}/transactions",
                ];
            }
        }

        // 7. QUERY FLIGHT GROUPS (tourism -> flight)
        if ((!$department || $department === 'tourism') && (!$module || $module === 'flight')) {
            $groupQuery = \App\Models\Flight\FlightGroup::query()->with('account');
            if ($search) {
                $groupQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }
            $groups = $groupQuery->get();
            foreach ($groups as $g) {
                $balance = $g->account ? (float) $g->account->balance : 0.0;
                if ($balance == 0.0) {
                    continue;
                }

                $dir = $balance < 0 ? 'payables' : 'receivables';
                if ($direction !== 'all' && $direction !== $dir) {
                    continue;
                }

                $results[] = [
                    'id' => $g->id,
                    'name' => $g->name,
                    'phone' => $g->contact_phone,
                    'entity_type' => 'flight_group',
                    'entity_type_label' => 'مجموعة طيران',
                    'department' => 'tourism',
                    'department_label' => 'قسم سياحه',
                    'module' => 'flight',
                    'module_label' => 'طيران',
                    'balance' => $balance,
                    'account_id' => $g->account_id,
                    'statement_url' => $g->account_id ? "/finance/account-statement/{$g->account_id}" : null,
                ];
            }
        }
        }

        // Sort items by absolute balance value descending
        usort($results, function ($a, $b) {
            return abs($b['balance']) <=> abs($a['balance']);
        });

        // Calculate totals
        $totalReceivables = 0.0;
        $totalPayables = 0.0;

        foreach ($results as $item) {
            if ($item['balance'] > 0) {
                $totalReceivables += $item['balance'];
            } else {
                $totalPayables += abs($item['balance']);
            }
        }

        return [
            'total_receivables' => $totalReceivables,
            'total_payables' => $totalPayables,
            'net_balance' => $totalReceivables - $totalPayables,
            'items' => $results,
        ];
    }

    private function getModuleLabel(string $module): string
    {
        return match ($module) {
            'flight' => 'طيران',
            'bus' => 'باص',
            'hajj_umra' => 'حج وعمرة',
            'visa' => 'تأشيرات',
            'fawry' => 'فوري',
            'online' => 'خدمات إلكترونية',
            'wallet' => 'محفظة',
            default => 'عام / كاش',
        };
    }
}
