<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Enums\SupplierType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bank;
use App\Models\Bus\BusCompany;
use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightCarrier;
use App\Models\Fawry\FawryMachine;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
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

        if (! empty($filters['from_date'])) {
            $query->where('account_entries.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
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

        if (! empty($filters['search'])) {
            $query->where('full_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('phone', 'like', '%'.$filters['search'].'%');
        }

        if (! empty($filters['customer_type'])) {
            $query->where('type', $filters['customer_type']);
        }

        $customers = $query->with(['flightBookings' => function ($q) use ($filters) {
            $q->where('status', 'pending');
            if (! empty($filters['module'])) {
                // Since flight bookings are always 'flight' module,
                // we check if the requested module matches.
                if (is_array($filters['module'])) {
                    if (! in_array('flight', $filters['module'])) {
                        $q->whereRaw('1=0');
                    }
                } elseif ($filters['module'] !== 'flight') {
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

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['module_type'])) {
            $query->whereHas('account', function ($q) use ($filters) {
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
     * ملخص مالي شامل — يعتمد على القيد المزدوج عبر ProfitLossReportService
     * ليشمل إيرادات حجوزات الطيران المسجّلة كتحويلات (type=transfer).
     */
    public function getFinancialSummary(array $filters = []): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $toDate = $filters['to_date'] ?? now()->endOfDay()->format('Y-m-d');

        // Delegate to the double-entry P&L service so that transfer-type transactions
        // (e.g. flight bookings) are correctly classified as revenue.
        $report = resolve(ProfitLossReportService::class)->report([
            'from_date' => $fromDate,
            'to_date'   => $toDate,
        ]);

        $totalIncome  = (float) $report['totalRevenues'];
        $totalCogs    = (float) $report['totalCogs'];
        $totalOpex    = (float) $report['totalExpenses'];
        $totalExpense = round($totalCogs + $totalOpex, 2);
        $totalRefunds = (float) $report['totalRefunds'];
        $profit       = (float) $report['netProfit'];

        // Keep a raw transfer total for callers that still need it (informational only)
        $totalTransfers = Transaction::whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', TransactionType::Transfer->value)
            ->sum('amount');

        $tourismAccountsBalance = Account::tourism()->sum('balance');
        $officeAccountsBalance  = Account::office()->sum('balance');
        $totalAccountsBalance   = Account::sum('balance');

        return [
            'from_date'                => $fromDate,
            'to_date'                  => $toDate,
            'income'                   => $totalIncome,
            'expense'                  => $totalExpense,
            'transfers'                => (float) $totalTransfers,
            'refunds'                  => $totalRefunds,
            'profit'                   => $profit,
            // Extended keys aligned with ReportFinanceService
            'total_income'             => $totalIncome,
            'total_cogs'               => $totalCogs,
            'total_operating_expenses' => $totalOpex,
            'total_expense'            => $totalExpense,
            'total_refunds'            => $totalRefunds,
            'net_profit'               => $profit,
            'profit_margin'            => $totalIncome > 0 ? round(($profit / $totalIncome) * 100, 2) : 0,
            'tourism_accounts_balance' => $tourismAccountsBalance,
            'office_accounts_balance'  => $officeAccountsBalance,
            'total_accounts_balance'   => $totalAccountsBalance,
        ];
    }

    /**
     * ربح تقديري لكل الموديولات — يعتمد على منطق P&L المتوافق مع القيد المزدوج.
     */
    public function getProfitByModule(array $filters = []): array
    {
        if (isset($filters['department']) && ! isset($filters['category'])) {
            $filters['category'] = $filters['department'];
        }

        return resolve(ProfitLossReportService::class)->moduleBreakdown($filters);
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
            $customerQuery = Customer::query()
                ->with('ledgerAccount')
                ->withCount([
                    'flightBookings',
                    'busBookings',
                    'hajjUmraBookings',
                    'visaBookings',
                    'fawryTransactions',
                    'onlineTransactions',
                    'walletTransactions',
                ]);

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
                        $customerQuery->where(function ($q) {
                            $q->whereHas('flightBookings')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'flights');
                                });
                        });
                        break;
                    case 'bus':
                        $customerQuery->where(function ($q) {
                            $q->whereHas('busBookings')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'bus');
                                });
                        });
                        break;
                    case 'hajj_umra':
                        $customerQuery->where(function ($q) {
                            $q->whereHas('hajjUmraBookings')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'hajj_umra');
                                });
                        });
                        break;
                    case 'visa':
                        $customerQuery->where(function ($q) {
                            $q->whereHas('visaBookings')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'visas');
                                });
                        });
                        break;
                    case 'fawry':
                        $customerQuery->where(function ($q) {
                            $q->whereHas('fawryTransactions')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'fawry');
                                });
                        });
                        break;
                    case 'online':
                        $customerQuery->where(function ($q) {
                            $q->whereHas('onlineTransactions')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'online');
                                });
                        });
                        break;
                    case 'general':
                        $customerQuery->whereDoesntHave('flightBookings')
                            ->whereDoesntHave('busBookings')
                            ->whereDoesntHave('hajjUmraBookings')
                            ->whereDoesntHave('visaBookings')
                            ->whereDoesntHave('fawryTransactions')
                            ->whereDoesntHave('onlineTransactions')
                            ->whereDoesntHave('walletTransactions')
                            ->whereDoesntHave('ledgerAccount', function ($sub) {
                                $sub->whereIn('module_type', ['flights', 'bus', 'hajj_umra', 'visas', 'fawry', 'online', 'wallet']);
                            });
                        break;
                    case 'wallet':
                        $customerQuery->where(function ($q) {
                            $q->whereHas('walletTransactions')
                                ->orWhereHas('ledgerAccount', function ($sub) {
                                    $sub->where('module_type', 'wallet');
                                });
                        });
                        break;
                }
            } elseif ($department) {
                if ($department === 'tourism') {
                    $customerQuery->where(function ($q) {
                        $q->whereHas('hajjUmraBookings')
                            ->orWhereHas('visaBookings')
                            ->orWhereHas('flightBookings')
                            ->orWhereHas('ledgerAccount', function ($sub) {
                                $sub->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas']);
                            });
                    });
                } elseif ($department === 'office') {
                    $customerQuery->where(function ($q) {
                        $q->whereHas('busBookings')
                            ->orWhereHas('fawryTransactions')
                            ->orWhereHas('onlineTransactions')
                            ->orWhereHas('walletTransactions')
                            ->orWhereHas('ledgerAccount', function ($sub) {
                                $sub->whereIn('module_type', ['office', 'bus', 'fawry', 'online', 'wallet']);
                            })
                            ->orWhere(function ($sub) {
                                $sub->whereDoesntHave('hajjUmraBookings')
                                    ->whereDoesntHave('visaBookings')
                                    ->whereDoesntHave('flightBookings')
                                    ->whereDoesntHave('ledgerAccount', function ($sub2) {
                                        $sub2->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas']);
                                    });
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

                if ($c->ledgerAccount) {
                    $accModule = $c->ledgerAccount->module_type;
                    if (in_array($accModule, ['tourism', 'flights', 'hajj_umra', 'visas'])) {
                        $custDept = 'tourism';
                        $custMod = $accModule === 'tourism' ? 'general' : ($accModule === 'flights' ? 'flight' : $accModule);
                    } elseif (in_array($accModule, ['office', 'bus', 'fawry', 'online', 'wallet'])) {
                        $custDept = 'office';
                        $custMod = $accModule === 'office' ? 'general' : $accModule;
                    }
                }

                if ($c->flight_bookings_count > 0 || $c->hajj_umra_bookings_count > 0 || $c->visa_bookings_count > 0) {
                    $custDept = 'tourism';
                    if ($c->flight_bookings_count > 0) {
                        $custMod = 'flight';
                    } elseif ($c->hajj_umra_bookings_count > 0) {
                        $custMod = 'hajj_umra';
                    } else {
                        $custMod = 'visa';
                    }
                } else {
                    if ($c->bus_bookings_count > 0) {
                        $custDept = 'office';
                        $custMod = 'bus';
                    } elseif ($c->fawry_transactions_count > 0) {
                        $custDept = 'office';
                        $custMod = 'fawry';
                    } elseif ($c->online_transactions_count > 0) {
                        $custDept = 'office';
                        $custMod = 'online';
                    } elseif ($c->wallet_transactions_count > 0) {
                        $custDept = 'office';
                        $custMod = 'wallet';
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
                    'currency' => $c->ledgerAccount ? $c->ledgerAccount->currency : 'EGP',
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
                $supplierQuery->where(function ($q) use ($targetSupplierTypes, $department, $module) {
                    if (! empty($targetSupplierTypes)) {
                        $q->whereIn('type', $targetSupplierTypes);
                    }
                    if ($department) {
                        $q->orWhereHas('account', function ($sub) use ($department) {
                            if ($department === 'tourism') {
                                $sub->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas']);
                            } else {
                                $sub->whereIn('module_type', ['office', 'bus', 'fawry', 'online', 'wallet']);
                            }
                        });
                    } elseif ($module) {
                        $q->orWhereHas('account', function ($sub) use ($module) {
                            $mapped = $module === 'flight' ? 'flights' : ($module === 'visa' ? 'visas' : $module);
                            $sub->where('module_type', $mapped);
                        });
                    }
                });
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

                // Deduce supplier module and department
                $supMod = 'general';
                $supDept = 'office';

                if ($s->account && $s->account->module_type !== null) {
                    $accModule = $s->account->module_type;
                    if (in_array($accModule, ['tourism', 'flights', 'hajj_umra', 'visas'])) {
                        $supDept = 'tourism';
                        $supMod = $accModule === 'tourism' ? 'general' : ($accModule === 'flights' ? 'flight' : ($accModule === 'visas' ? 'visa' : $accModule));
                    } elseif (in_array($accModule, ['office', 'bus', 'fawry', 'online', 'wallet'])) {
                        $supDept = 'office';
                        $supMod = $accModule === 'office' ? 'general' : $accModule;
                    }
                } else {
                    $typeVal = $s->type instanceof SupplierType ? $s->type->value : (string) $s->type;
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
                }

                // Refine generic module if supplier has a specific type, without changing the department
                if ($supMod === 'general') {
                    $typeVal = $s->type instanceof SupplierType ? $s->type->value : (string) $s->type;
                    if ($typeVal === 'airline') {
                        $supMod = 'flight';
                    } elseif ($typeVal === 'bus_company') {
                        $supMod = 'bus';
                    } elseif ($typeVal === 'hotel') {
                        $supMod = 'hajj_umra';
                    } elseif ($typeVal === 'visa_provider') {
                        $supMod = 'visa';
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
                    'currency' => $s->account ? $s->account->currency : 'EGP',
                    'account_id' => $s->account_id,
                    'statement_url' => $s->account_id ? "/finance/account-statement/{$s->account_id}" : null,
                ];
            }

            // 3. QUERY VISA AGENTS (tourism -> visa)
            if ((! $department || $department === 'tourism') && (! $module || $module === 'visa')) {
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
                        'currency' => $a->account ? $a->account->currency : 'EGP',
                        'account_id' => $a->account_id,
                        'statement_url' => $a->account_id ? "/finance/account-statement/{$a->account_id}" : null,
                    ];
                }
            }

            // 4. QUERY EXECUTING COMPANIES (tourism -> hajj_umra)
            if ((! $department || $department === 'tourism') && (! $module || $module === 'hajj_umra')) {
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
                        'currency' => $e->account ? $e->account->currency : 'EGP',
                        'account_id' => $e->account_id,
                        'statement_url' => $e->account_id ? "/finance/account-statement/{$e->account_id}" : null,
                    ];
                }
            }

            // 5. QUERY BUS COMPANIES (office -> bus)
            if ((! $department || $department === 'office') && (! $module || $module === 'bus')) {
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
                        'currency' => $b->account ? $b->account->currency : 'EGP',
                        'account_id' => $b->account_id,
                        'statement_url' => $b->account_id ? "/finance/account-statement/{$b->account_id}" : null,
                    ];
                }
            }

            // 6. QUERY AIRLINE ACCOUNTS (tourism -> flight)
            if ((! $department || $department === 'tourism') && (! $module || $module === 'flight')) {
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
                        'name' => $a->name." ({$a->code})",
                        'phone' => null,
                        'entity_type' => 'airline_account',
                        'entity_type_label' => 'حساب خط طيران',
                        'department' => 'tourism',
                        'department_label' => 'قسم سياحه',
                        'module' => 'flight',
                        'module_label' => 'طيران',
                        'balance' => $balance,
                        'currency' => $a->currency ?: 'EGP',
                        'account_id' => null,
                        'statement_url' => "/flights/airline-accounts/{$a->id}/transactions",
                    ];
                }
            }



            // 8. QUERY HOTELS (tourism -> hajj_umra)
            if ((! $department || $department === 'tourism') && (! $module || $module === 'hajj_umra')) {
                $hotelQuery = Hotel::query()->with('account');
                if ($search) {
                    $hotelQuery->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }
                $hotels = $hotelQuery->get();
                foreach ($hotels as $h) {
                    $balance = $h->account ? (float) $h->account->balance : 0.0;
                    if ($balance == 0.0) {
                        continue;
                    }

                    $dir = $balance < 0 ? 'payables' : 'receivables';
                    if ($direction !== 'all' && $direction !== $dir) {
                        continue;
                    }

                    $results[] = [
                        'id' => $h->id,
                        'name' => $h->name,
                        'phone' => $h->phone,
                        'entity_type' => 'hotel',
                        'entity_type_label' => 'فندق',
                        'department' => 'tourism',
                        'department_label' => 'قسم سياحه',
                        'module' => 'hajj_umra',
                        'module_label' => 'حج وعمرة',
                        'balance' => $balance,
                        'currency' => $h->account ? $h->account->currency : 'EGP',
                        'account_id' => $h->account_id,
                        'statement_url' => $h->account_id ? "/finance/account-statement/{$h->account_id}" : null,
                    ];
                }
            }

            // 9. QUERY UMRAH SUPPLIERS (tourism -> hajj_umra)
            if ((! $department || $department === 'tourism') && (! $module || $module === 'hajj_umra')) {
                $umrahSupQuery = UmrahSupplier::query()->with('account');
                if ($search) {
                    $umrahSupQuery->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }
                $umrahSups = $umrahSupQuery->get();
                foreach ($umrahSups as $us) {
                    $balance = $us->account ? (float) $us->account->balance : 0.0;
                    if ($balance == 0.0) {
                        continue;
                    }

                    $dir = $balance < 0 ? 'payables' : 'receivables';
                    if ($direction !== 'all' && $direction !== $dir) {
                        continue;
                    }

                    $results[] = [
                        'id' => $us->id,
                        'name' => $us->name,
                        'phone' => $us->phone,
                        'entity_type' => 'umrah_supplier',
                        'entity_type_label' => 'مورد عمرة',
                        'department' => 'tourism',
                        'department_label' => 'قسم سياحه',
                        'module' => 'hajj_umra',
                        'module_label' => 'حج وعمرة',
                        'balance' => $balance,
                        'currency' => $us->account ? $us->account->currency : 'EGP',
                        'account_id' => $us->account_id,
                        'statement_url' => $us->account_id ? "/finance/account-statement/{$us->account_id}" : null,
                    ];
                }
            }
        }

        // 7. QUERY FLIGHT GROUPS (tourism -> flight) - Query for all entity filters (customers, suppliers, or all)
        if ($entityType === 'all' || $entityType === 'supplier' || $entityType === 'customer') {
            if ((! $department || $department === 'tourism') && (! $module || $module === 'flight')) {
                $groupQuery = FlightGroup::query()->with(['account', 'carrier']);
                if ($search) {
                    $groupQuery->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
                }
                $groups = $groupQuery->get();
                foreach ($groups as $g) {
                    $totalDebt = (float) $g->groupTransactions()->where('type', 'debt')->sum('amount');
                    $totalPayment = (float) $g->groupTransactions()->where('type', 'payment')->sum('amount');
                    
                    if ($totalDebt > 0 || $totalPayment > 0) {
                        // Standard debts report convention: positive balance = receivable (they owe us), negative balance = payable (we owe them)
                        $balance = $totalPayment - $totalDebt;
                    } else {
                        $balance = $g->account ? (float) $g->account->balance : 0.0;
                    }

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
                        'currency' => $g->carrier ? $g->carrier->currency : 'EGP',
                        'account_id' => $g->account_id,
                        'statement_url' => '/flights/customers',
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
            'wallet', 'wallet_transfer' => 'محفظة وتحويلات',
            default => 'عام / كاش',
        };
    }

    /**
     * تحليل رأس المال للقطاعات بالعملات المتعددة
     */
    public function getCapitalAnalysis(array $filters = []): array
    {
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;

        // 1. Get all active currencies in the system
        $currencies = collect()
            ->merge(Account::pluck('currency'))
            ->merge(FlightSystem::pluck('currency'))
            ->merge(FlightCarrier::pluck('currency'))
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        if (empty($currencies)) {
            $currencies = ['EGP'];
        }

        $tourismReport = [];
        $officeReport = [];

        $clearingAccounts = app(\App\Services\Finance\LedgerClearingAccounts::class);
        $maps = $clearingAccounts->moduleAccountMaps();
        $incomeClearing = $maps['income'];
        $expenseClearing = $maps['expense'];
        $prepaidAccounts = $clearingAccounts->prepaidAccountIdMap();

        foreach ($currencies as $currency) {
            $tourismCapital = $this->calculateTourismCapital($currency);
            $tourismPL = $this->calculatePL($currency, 'tourism', $fromDate, $toDate, $incomeClearing, $expenseClearing, $prepaidAccounts);

            $tourismReport[$currency] = [
                'capital' => $tourismCapital,
                'profit' => $tourismPL['profit'],
                'expense' => $tourismPL['expense'],
                'cogs' => $tourismPL['cogs'],
                'revenue' => $tourismPL['revenue'],
            ];

            $officeCapital = $this->calculateOfficeCapital($currency);
            $officePL = $this->calculatePL($currency, 'office', $fromDate, $toDate, $incomeClearing, $expenseClearing, $prepaidAccounts);

            $officeReport[$currency] = [
                'capital' => $officeCapital,
                'profit' => $officePL['profit'],
                'expense' => $officePL['expense'],
                'cogs' => $officePL['cogs'],
                'revenue' => $officePL['revenue'],
            ];
        }

        return [
            'tourism' => $tourismReport,
            'office' => $officeReport,
            'currencies' => $currencies,
        ];
    }

    protected function calculateTourismCapital(string $currency): array
    {
        $systems = FlightSystem::where('currency', $currency)->sum('balance');
        $carriers = FlightCarrier::where('currency', $currency)->sum('balance');
        
        $vaults = Account::tourism()
            ->tap(fn ($q) => \App\Support\Finance\AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->where('currency', $currency)
            ->where('is_active', true)
            ->sum('balance');

        $receivables = Customer::whereHas('ledgerAccount', function($q) use ($currency) {
            $q->where('currency', $currency)->where('balance', '>', 0);
        })->where(function($q) {
            $q->whereHas('flightBookings')
              ->orWhereHas('hajjUmraBookings')
              ->orWhereHas('visaBookings');
        })->with('ledgerAccount')->get()->sum(fn($c) => (float)$c->ledgerAccount->balance);

        $payables = 0.0;
        $groups = FlightGroup::with('carrier')->get();
        foreach ($groups as $g) {
            $gCurrency = $g->carrier?->currency ?: 'EGP';
            if (strtoupper($gCurrency) !== strtoupper($currency)) {
                continue;
            }
            $totalDebt = (float) $g->groupTransactions()->where('type', 'debt')->sum('amount');
            $totalPayment = (float) $g->groupTransactions()->where('type', 'payment')->sum('amount');
            $netBalance = $totalDebt - $totalPayment;
            if ($netBalance > 0) {
                $payables += $netBalance;
            }
        }

        $total = ($systems + $carriers + $vaults + $receivables) - $payables;

        return [
            'systems' => (float)$systems,
            'carriers' => (float)$carriers,
            'vaults' => (float)$vaults,
            'receivables' => (float)$receivables,
            'payables' => (float)$payables,
            'total' => (float)$total,
        ];
    }

    protected function calculateOfficeCapital(string $currency): array
    {
        $systems = 0.0;
        if (strtoupper($currency) === 'EGP') {
            $systems = (float)FawryMachine::sum('balance');
        }

        $carriers = 0.0;

        $vaults = Account::office()
            ->tap(fn ($q) => \App\Support\Finance\AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->where('currency', $currency)
            ->where('is_active', true)
            ->sum('balance');

        $receivables = Customer::whereHas('ledgerAccount', function($q) use ($currency) {
            $q->where('currency', $currency)->where('balance', '>', 0);
        })->where(function($q) {
            $q->whereHas('busBookings')
              ->orWhereHas('fawryTransactions')
              ->orWhereHas('onlineTransactions')
              ->orWhereHas('walletTransactions')
              ->orWhere(function($sub) {
                  $sub->whereDoesntHave('flightBookings')
                      ->whereDoesntHave('hajjUmraBookings')
                      ->whereDoesntHave('visaBookings');
              });
        })->with('ledgerAccount')->get()->sum(fn($c) => (float)$c->ledgerAccount->balance);

        $busPayables = BusCompany::whereHas('account', function($q) use ($currency) {
            $q->where('currency', $currency)->where('balance', '<', 0);
        })->with('account')->get()->sum(fn($b) => abs((float)$b->account->balance));

        $supplierPayables = Supplier::whereHas('account', function($q) use ($currency) {
            $q->where('currency', $currency)->where('balance', '<', 0);
        })->whereIn('type', [SupplierType::ServiceProvider->value, SupplierType::Other->value])
          ->with('account')->get()->sum(fn($s) => abs((float)$s->account->balance));

        $payables = $busPayables + $supplierPayables;

        $total = ($systems + $carriers + $vaults + $receivables) - $payables;

        return [
            'systems' => (float)$systems,
            'carriers' => (float)$carriers,
            'vaults' => (float)$vaults,
            'receivables' => (float)$receivables,
            'payables' => (float)$payables,
            'total' => (float)$total,
        ];
    }

    protected function calculatePL(
        string $currency,
        string $category,
        ?string $fromDate,
        ?string $toDate,
        array $incomeClearing,
        array $expenseClearing,
        array $prepaidAccounts = []
    ): array {
        $query = DB::table('transactions as t')
            ->leftJoin('accounts as to_acc', 't.to_account_id', '=', 'to_acc.id')
            ->leftJoin('accounts as from_acc', 't.from_account_id', '=', 'from_acc.id')
            ->select([
                't.id',
                't.type',
                't.module',
                't.amount',
                't.from_account_id',
                't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.name as to_account_name',
                'to_acc.currency as to_account_currency',
                'from_acc.currency as from_account_currency',
            ]);

        if (! empty($fromDate)) {
            $query->whereDate('t.created_at', '>=', $fromDate);
        }
        if (! empty($toDate)) {
            $query->whereDate('t.created_at', '<=', $toDate);
        }

        $allClearingIds = array_values(array_unique(array_merge(
            array_keys($incomeClearing),
            array_keys($expenseClearing),
            array_keys($prepaidAccounts)
        )));

        $query->where(function ($outer) use ($allClearingIds): void {
            $outer->whereIn('t.type', ['income', 'expense', 'refund']);
            $outer->orWhere(function ($transfer) use ($allClearingIds): void {
                $transfer->where('t.type', 'transfer')
                    ->where(function ($legs) use ($allClearingIds): void {
                        if ($allClearingIds !== []) {
                            $legs->whereIn('t.from_account_id', $allClearingIds)
                                ->orWhereIn('t.to_account_id', $allClearingIds);
                        }
                        $legs->orWhere('to_acc.type', 'expense');
                    });
            });
        });

        $modules = $category === 'tourism' 
            ? ['flight', 'hajj_umra', 'visa'] 
            : ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'wallets', 'general', 'service'];

        $clearingIds = [];
        foreach ([$incomeClearing, $expenseClearing] as $map) {
            foreach ($map as $accountId => $module) {
                if (in_array($module, $modules, true)) {
                    $clearingIds[] = (int) $accountId;
                }
            }
        }
        $clearingIds = array_values(array_unique($clearingIds));

        $query->where(function ($q) use ($modules, $clearingIds): void {
            $q->whereIn('t.module', $modules);
            if ($clearingIds !== []) {
                $q->orWhereIn('t.from_account_id', $clearingIds)
                  ->orWhereIn('t.to_account_id', $clearingIds);
            }
        });

        $totalRevenue = 0.0;
        $totalCogs = 0.0;
        $totalExpense = 0.0;

        foreach ($query->orderBy('t.id')->cursor() as $tx) {
            $txCurrency = $tx->to_account_currency ?: $tx->from_account_currency;
            if ($txCurrency !== $currency) {
                continue;
            }

            $classification = $this->classifyPL($tx, $incomeClearing, $expenseClearing, $prepaidAccounts);
            if ($classification === null) {
                continue;
            }

            $amount = (float) $tx->amount;
            if ($amount <= 0) {
                continue;
            }

            if ($classification === 'revenue') {
                $totalRevenue += $amount;
            } elseif ($classification === 'revenue_reversal' || $classification === 'refund') {
                $totalRevenue -= $amount;
            } elseif ($classification === 'cogs') {
                $totalCogs += $amount;
            } elseif ($classification === 'cogs_reversal') {
                $totalCogs -= $amount;
            } elseif ($classification === 'operating_expense') {
                $totalExpense += $amount;
            }
        }

        $totalRevenue = max(0, round($totalRevenue, 2));
        $totalCogs = max(0, round($totalCogs, 2));
        $totalExpense = max(0, round($totalExpense, 2));
        $profit = round($totalRevenue - $totalCogs - $totalExpense, 2);

        return [
            'revenue'  => $totalRevenue,
            // المصروفات والتكاليف = المصاريف التشغيلية الفعلية فقط (expense transactions)
            // COGS (تكلفة الحجوزات من الأرصدة المسبقة) لا تُعرض هنا لأنها ليست مصاريف تشغيلية
            // لكنها لا تزال تُطرح من الإيرادات في حسابة الربح أدناه
            'expense'  => $totalExpense,
            'cogs'     => $totalCogs,
            'profit'   => $profit,
        ];
    }

    private function classifyPL(object $tx, array $incomeClearing, array $expenseClearing, array $prepaidAccounts = []): ?string
    {
        $type = (string) $tx->type;
        $fromId = (int) ($tx->from_account_id ?? 0);
        $toId = (int) ($tx->to_account_id ?? 0);
        $toType = (string) ($tx->to_account_type ?? '');

        if ($type === 'income') {
            return 'revenue';
        }

        if ($type === 'refund') {
            return 'refund';
        }

        if ($type === 'expense') {
            return 'operating_expense';
        }

        if ($type !== 'transfer') {
            return null;
        }

        $fromIncome = $fromId > 0 && isset($incomeClearing[$fromId]);
        $toIncome = $toId > 0 && isset($incomeClearing[$toId]);
        $fromExpense = $fromId > 0 && isset($expenseClearing[$fromId]);
        $toExpense = $toId > 0 && isset($expenseClearing[$toId]);
        $fromPrepaid = $fromId > 0 && isset($prepaidAccounts[$fromId]);
        $toPrepaid = $toId > 0 && isset($prepaidAccounts[$toId]);

        if ($toPrepaid && ! $fromPrepaid && ! $fromExpense && ! $fromIncome) {
            return null;
        }

        if ($fromPrepaid && $toExpense && ! $toPrepaid) {
            return 'cogs';
        }

        if ($toPrepaid && $fromExpense && ! $fromPrepaid) {
            return 'cogs_reversal';
        }

        if ($fromIncome && ! $toIncome) {
            return 'revenue';
        }

        if ($toIncome && ! $fromIncome) {
            return 'revenue_reversal';
        }

        if ($toExpense && ! $fromExpense && ! $fromPrepaid) {
            return 'cogs';
        }

        if ($fromExpense && ! $toExpense) {
            return 'cogs_reversal';
        }

        if ($toType === 'expense') {
            return 'operating_expense';
        }

        return null;
    }

    /**
     * تقرير حركات الطيران التفصيلي الموحد
     */
    public function getDetailedFlightReport(array $filters = []): array
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 15;
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $bookingSystem = $filters['booking_system'] ?? null; // 'system_{id}' or 'carrier_{id}'
        $search = $filters['search'] ?? null;

        // 1. Build carrier/airline transaction query
        $airlineQuery = DB::table('airline_transactions')
            ->select(
                'airline_transactions.id',
                'airline_transactions.created_at',
                'airline_transactions.type',
                'airline_transactions.amount',
                'airline_transactions.balance_before',
                'airline_transactions.balance_after',
                'airline_transactions.description',
                'airline_transactions.created_by',
                'airline_transactions.flight_booking_id',
                'airline_transactions.flight_carrier_id',
                DB::raw('NULL as flight_system_id'),
                DB::raw("'carrier' as source_type")
            );

        // 2. Build GDS system transaction query
        $systemQuery = DB::table('flight_system_transactions')
            ->select(
                'flight_system_transactions.id',
                'flight_system_transactions.created_at',
                'flight_system_transactions.type',
                'flight_system_transactions.amount',
                'flight_system_transactions.balance_before',
                'flight_system_transactions.balance_after',
                'flight_system_transactions.description',
                'flight_system_transactions.created_by',
                'flight_system_transactions.flight_booking_id',
                DB::raw('NULL as flight_carrier_id'),
                'flight_system_transactions.flight_system_id',
                DB::raw("'system' as source_type")
            );

        // Apply filters & search to Airline Query
        if ($fromDate) {
            $airlineQuery->where('airline_transactions.created_at', '>=', $fromDate . ' 00:00:00');
        }
        if ($toDate) {
            $airlineQuery->where('airline_transactions.created_at', '<=', $toDate . ' 23:59:59');
        }

        if ($bookingSystem) {
            if (str_starts_with($bookingSystem, 'carrier_')) {
                $carrierId = (int) str_replace('carrier_', '', $bookingSystem);
                $airlineQuery->where('airline_transactions.flight_carrier_id', $carrierId);
            } elseif (str_starts_with($bookingSystem, 'system_')) {
                $systemId = (int) str_replace('system_', '', $bookingSystem);
                // Get all carriers belonging to this system
                $carrierIds = DB::table('flight_carriers')
                    ->where('flight_system_id', $systemId)
                    ->pluck('id')
                    ->toArray();
                if (empty($carrierIds)) {
                    $airlineQuery->whereRaw('1 = 0');
                } else {
                    $airlineQuery->whereIn('airline_transactions.flight_carrier_id', $carrierIds);
                }
            }
        }

        if ($search) {
            $matchingBookingIds = DB::table('flight_bookings')
                ->leftJoin('customers', 'flight_bookings.customer_id', '=', 'customers.id')
                ->where('flight_bookings.booking_number', 'like', "%{$search}%")
                ->orWhere('flight_bookings.pnr', 'like', "%{$search}%")
                ->orWhere('flight_bookings.origin', 'like', "%{$search}%")
                ->orWhere('flight_bookings.destination', 'like', "%{$search}%")
                ->orWhere('customers.full_name', 'like', "%{$search}%")
                ->orWhere('customers.affiliation', 'like', "%{$search}%")
                ->pluck('flight_bookings.id')
                ->toArray();

            $airlineQuery->where(function ($q) use ($matchingBookingIds, $search) {
                if (!empty($matchingBookingIds)) {
                    $q->whereIn('airline_transactions.flight_booking_id', $matchingBookingIds)
                      ->orWhere('airline_transactions.description', 'like', "%{$search}%");
                } else {
                    $q->where('airline_transactions.description', 'like', "%{$search}%");
                }
            });
        }

        // Apply filters & search to System Query
        if ($fromDate) {
            $systemQuery->where('flight_system_transactions.created_at', '>=', $fromDate . ' 00:00:00');
        }
        if ($toDate) {
            $systemQuery->where('flight_system_transactions.created_at', '<=', $toDate . ' 23:59:59');
        }

        if ($bookingSystem) {
            if (str_starts_with($bookingSystem, 'system_')) {
                $systemId = (int) str_replace('system_', '', $bookingSystem);
                $systemQuery->where('flight_system_transactions.flight_system_id', $systemId);
            } else {
                // If filtering by carrier, the GDS system query won't match any carrier directly, so force empty
                $systemQuery->whereRaw('1 = 0');
            }
        }

        if ($search) {
            $matchingBookingIds = DB::table('flight_bookings')
                ->leftJoin('customers', 'flight_bookings.customer_id', '=', 'customers.id')
                ->where('flight_bookings.booking_number', 'like', "%{$search}%")
                ->orWhere('flight_bookings.pnr', 'like', "%{$search}%")
                ->orWhere('flight_bookings.origin', 'like', "%{$search}%")
                ->orWhere('flight_bookings.destination', 'like', "%{$search}%")
                ->orWhere('customers.full_name', 'like', "%{$search}%")
                ->orWhere('customers.affiliation', 'like', "%{$search}%")
                ->pluck('flight_bookings.id')
                ->toArray();

            $systemQuery->where(function ($q) use ($matchingBookingIds, $search) {
                if (!empty($matchingBookingIds)) {
                    $q->whereIn('flight_system_transactions.flight_booking_id', $matchingBookingIds)
                      ->orWhere('flight_system_transactions.description', 'like', "%{$search}%");
                } else {
                    $q->where('flight_system_transactions.description', 'like', "%{$search}%");
                }
            });
        }

        // 3. Build flight group transaction query (bookings on credit via groups)
        $groupQuery = DB::table('flight_group_transactions')
            ->join('flight_groups', 'flight_group_transactions.flight_group_id', '=', 'flight_groups.id')
            ->select(
                'flight_group_transactions.id',
                'flight_group_transactions.created_at',
                DB::raw("CASE WHEN flight_group_transactions.type = 'debt' THEN 'debit' WHEN flight_group_transactions.type = 'payment' THEN 'credit' ELSE flight_group_transactions.type END as type"),
                'flight_group_transactions.amount',
                DB::raw('NULL as balance_before'),
                DB::raw('NULL as balance_after'),
                'flight_group_transactions.notes as description',
                'flight_group_transactions.created_by',
                'flight_group_transactions.flight_booking_id',
                'flight_groups.flight_carrier_id',
                DB::raw('NULL as flight_system_id'),
                DB::raw("'group' as source_type")
            );

        if ($fromDate) {
            $groupQuery->where('flight_group_transactions.created_at', '>=', $fromDate . ' 00:00:00');
        }
        if ($toDate) {
            $groupQuery->where('flight_group_transactions.created_at', '<=', $toDate . ' 23:59:59');
        }

        if ($bookingSystem) {
            if (str_starts_with($bookingSystem, 'carrier_')) {
                $carrierId = (int) str_replace('carrier_', '', $bookingSystem);
                $groupQuery->where('flight_groups.flight_carrier_id', $carrierId);
            } elseif (str_starts_with($bookingSystem, 'system_')) {
                $systemId = (int) str_replace('system_', '', $bookingSystem);
                $groupCarrierIds = DB::table('flight_carriers')
                    ->where('flight_system_id', $systemId)
                    ->pluck('id')
                    ->toArray();
                if (empty($groupCarrierIds)) {
                    $groupQuery->whereRaw('1 = 0');
                } else {
                    $groupQuery->whereIn('flight_groups.flight_carrier_id', $groupCarrierIds);
                }
            }
        }

        if ($search) {
            $matchingBookingIds = DB::table('flight_bookings')
                ->leftJoin('customers', 'flight_bookings.customer_id', '=', 'customers.id')
                ->where('flight_bookings.booking_number', 'like', "%{$search}%")
                ->orWhere('flight_bookings.pnr', 'like', "%{$search}%")
                ->orWhere('flight_bookings.origin', 'like', "%{$search}%")
                ->orWhere('flight_bookings.destination', 'like', "%{$search}%")
                ->orWhere('customers.full_name', 'like', "%{$search}%")
                ->orWhere('customers.affiliation', 'like', "%{$search}%")
                ->pluck('flight_bookings.id')
                ->toArray();

            $groupQuery->where(function ($q) use ($matchingBookingIds, $search) {
                if (! empty($matchingBookingIds)) {
                    $q->whereIn('flight_group_transactions.flight_booking_id', $matchingBookingIds)
                        ->orWhere('flight_group_transactions.notes', 'like', "%{$search}%");
                } else {
                    $q->where('flight_group_transactions.notes', 'like', "%{$search}%");
                }
            });
        }

        // 4. Union all sources and paginate
        $unionQuery = $airlineQuery->unionAll($systemQuery)->unionAll($groupQuery);

        $paginator = DB::table(DB::raw("({$unionQuery->toSql()}) as union_table"))
            ->mergeBindings($unionQuery)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // 5. Retrieve original models with relations for the current page
        $items = collect($paginator->items());
        $carrierTxIds = $items->where('source_type', 'carrier')->pluck('id')->toArray();
        $systemTxIds = $items->where('source_type', 'system')->pluck('id')->toArray();
        $groupTxIds = $items->where('source_type', 'group')->pluck('id')->toArray();

        $carrierTxs = \App\Models\Flight\AirlineTransaction::with([
            'flightCarrier',
            'flightBooking.customer',
            'flightBooking.employee',
            'createdBy'
        ])->whereIn('id', $carrierTxIds)->get()->keyBy('id');

        $systemTxs = \App\Models\Flight\FlightSystemTransaction::with([
            'system',
            'flightBooking.customer',
            'flightBooking.employee',
            'createdBy'
        ])->whereIn('id', $systemTxIds)->get()->keyBy('id');

        $groupTxs = FlightGroupTransaction::with([
            'group.carrier',
            'group.account',
            'booking.customer',
            'booking.employee',
            'createdBy',
        ])->whereIn('id', $groupTxIds)->get()->keyBy('id');

        // 6. Map back and format
        $formattedItems = $items->map(function ($item) use ($carrierTxs, $systemTxs, $groupTxs) {
            if ($item->source_type === 'carrier') {
                $tx = $carrierTxs->get($item->id);
                $booking = $tx ? $tx->flightBooking : null;
                $systemName = $tx && $tx->flightCarrier ? $tx->flightCarrier->name : 'N/A';
            } elseif ($item->source_type === 'system') {
                $tx = $systemTxs->get($item->id);
                $booking = $tx ? $tx->flightBooking : null;
                $systemName = $tx && $tx->system ? $tx->system->name : 'N/A';
            } else {
                $tx = $groupTxs->get($item->id);
                $booking = $tx ? $this->resolveGroupTransactionBooking($tx) : null;
                $group = $tx ? $tx->group : null;
                $carrierName = $group && $group->carrier ? $group->carrier->name : null;
                $systemName = $group
                    ? trim($group->name . ($carrierName ? " — {$carrierName}" : ''))
                    : 'N/A';
            }

            if (!$tx) {
                return null;
            }

            // Customer presentation logic
            $customerName = '-';
            $customerType = '-';
            if ($booking && $booking->customer) {
                if ($booking->customer->type->value === 'individual') {
                    $customerName = $booking->customer->full_name ?: $booking->customer->name;
                    $customerType = 'عميل كاونتر';
                } elseif ($booking->customer->type->value === 'company') {
                    $customerName = $booking->customer->affiliation ?: ($booking->customer->full_name ?: $booking->customer->name);
                    $customerType = 'شركات';
                }
            }

            // Route representation
            $route = '-';
            if ($booking) {
                $route = ($booking->origin && $booking->destination)
                    ? "{$booking->origin} - {$booking->destination}"
                    : '-';
            }

            // Debit/Credit (خصم / ايداع)
            if ($item->source_type === 'group') {
                [$txType, $statusAr] = $this->mapGroupTransactionPresentation($tx, (bool) $booking);
            } else {
                $txType = $tx->type;
                $statusAr = match ($txType) {
                    'debit' => 'حجز',
                    'refund' => 'استرجاع',
                    'credit' => 'شحن',
                    default => 'شحن',
                };
            }
            $debit = ($txType === 'debit') ? $tx->amount : 0;
            $credit = ($txType === 'credit' || $txType === 'refund') ? $tx->amount : 0;

            $balanceAfter = (float) ($tx->balance_after ?? 0);
            if ($item->source_type === 'group' && $tx->group?->account) {
                $balanceAfter = (float) $tx->group->account->balance;
            }

            $groupKey = $this->detailedFlightReportGroupKey(
                $booking ? ($booking->booking_number ?: $booking->booking_reference) : null,
                $booking ? $booking->pnr : null,
                $systemName,
                $item->source_type,
                $tx->id
            );

            return [
                'id' => $tx->id,
                'source_type' => $item->source_type,
                'group_key' => $groupKey,
                'created_at' => $tx->created_at->toDateTimeString(),
                'system_name' => $systemName,
                'type' => $txType,
                'status_ar' => $statusAr,
                'amount' => (float) $tx->amount,
                'debit' => (float) $debit,
                'credit' => (float) $credit,
                'balance_before' => (float) ($tx->balance_before ?? 0),
                'balance_after' => $balanceAfter,
                'description' => $item->source_type === 'group' ? ($tx->notes ?? '') : $tx->description,
                'booking_number' => $booking ? ($booking->booking_number ?: $booking->booking_reference) : null,
                'pnr' => $booking ? $booking->pnr : null,
                'route' => $route,
                'departure_date' => $booking ? ($booking->departure_date ? $booking->departure_date->toDateString() : '-') : '-',
                'customer_name' => $customerName,
                'customer_type' => $customerType,
                'employee_name' => $booking && $booking->employee ? ($booking->employee->full_name ?: $booking->employee->name) : ($tx->createdBy ? $tx->createdBy->name : '-'),
                'payment_system' => $booking
                    ? match ((string) ($booking->purchase_balance_source ?: '')) {
                        'group' => 'مجموعة طيران',
                        'system' => 'نظام حجز',
                        'carrier' => 'ناقل جوي',
                        '' => 'المحفظة',
                        default => (string) $booking->purchase_balance_source,
                    }
                    : ($item->source_type === 'group' ? 'مجموعة طيران' : 'شحن رصيد'),
            ];
        })->filter()->values();

        $formattedItems = $this->sortAndGroupDetailedFlightReportItems($formattedItems);

        return [
            'data' => $formattedItems,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * ترتيب حركات التقرير: تجميع الحجز مع سداده، والحجز قبل السداد زمنياً.
     */
    private function sortAndGroupDetailedFlightReportItems($items)
    {
        return $items
            ->groupBy(fn (array $item) => $item['group_key'])
            ->sortByDesc(fn ($group) => $group->max(fn (array $item) => strtotime($item['created_at'])))
            ->flatMap(function ($group) {
                return $group
                    ->sortBy([
                        fn (array $item) => $this->detailedFlightReportMovementPriority($item),
                        fn (array $item) => strtotime($item['created_at']),
                    ])
                    ->values();
            })
            ->values();
    }

    private function detailedFlightReportGroupKey(
        ?string $bookingNumber,
        ?string $pnr,
        string $systemName,
        string $sourceType,
        int $transactionId
    ): string {
        if ($bookingNumber) {
            return 'booking:' . $bookingNumber . '|' . $systemName;
        }

        if ($pnr) {
            return 'pnr:' . $pnr . '|' . $systemName;
        }

        return 'tx:' . $sourceType . ':' . $transactionId;
    }

    private function detailedFlightReportMovementPriority(array $item): int
    {
        if (($item['type'] ?? '') === 'debit') {
            return 1;
        }

        if (($item['type'] ?? '') === 'credit' && ($item['status_ar'] ?? '') === 'سداد') {
            return 2;
        }

        if (($item['type'] ?? '') === 'credit') {
            return 3;
        }

        if (($item['type'] ?? '') === 'refund') {
            return 4;
        }

        return 9;
    }

    /**
     * ربط حركة مجموعة طيران بالحجز — مباشرة أو عبر سداد دين مرتبط بنفس المبلغ.
     */
    private function resolveGroupTransactionBooking(FlightGroupTransaction $tx): ?FlightBooking
    {
        if ($tx->relationLoaded('booking') && $tx->booking) {
            return $tx->booking;
        }

        if ($tx->flight_booking_id) {
            return $tx->booking()->with(['customer', 'employee'])->first();
        }

        if ($tx->type !== 'payment') {
            return null;
        }

        $relatedDebt = FlightGroupTransaction::query()
            ->where('flight_group_id', $tx->flight_group_id)
            ->where('type', 'debt')
            ->whereNotNull('flight_booking_id')
            ->where('created_at', '<=', $tx->created_at)
            ->where('amount', $tx->amount)
            ->orderByDesc('created_at')
            ->with(['booking.customer', 'booking.employee'])
            ->first();

        return $relatedDebt?->booking;
    }

    /**
     * @return array{0: string, 1: string} [txType, statusAr]
     */
    private function mapGroupTransactionPresentation(FlightGroupTransaction $tx, bool $hasBooking): array
    {
        if ($tx->type === 'debt') {
            return $hasBooking || $tx->flight_booking_id
                ? ['debit', 'حجز']
                : ['debit', 'تحصيل'];
        }

        if ($tx->type === 'payment') {
            if (str_contains((string) ($tx->notes ?? ''), 'إلغاء')) {
                return ['refund', 'استرجاع'];
            }

            return ['credit', 'سداد'];
        }

        return [(string) $tx->type, 'شحن'];
    }
}

