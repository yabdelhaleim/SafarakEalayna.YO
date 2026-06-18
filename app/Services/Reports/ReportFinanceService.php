<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportFinanceService
{
    /**
     * Get overall financial summary across all accounts.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getFinancialSummary(array $filters): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $toDate = $filters['to_date'] ?? now()->toDateString();
        $moduleFilter = $filters['module'] ?? null;
        $category = $filters['category'] ?? null;

        if (is_array($moduleFilter) && count($moduleFilter) > 1) {
            $breakdown = app(ProfitLossReportService::class)->moduleBreakdown([
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'category' => $category,
            ]);

            $totalIncome = 0.0;
            $totalCogs = 0.0;
            $totalOperating = 0.0;
            foreach ($breakdown['by_module'] as $row) {
                if ($this->moduleMatchesFilter($row['module'], $moduleFilter)) {
                    $totalIncome += (float) $row['income'];
                    $totalCogs += (float) ($row['cogs'] ?? 0);
                    $totalOperating += (float) $row['expense'];
                }
            }

            $totalExpense = $totalCogs + $totalOperating;

            return [
                'total_income' => round($totalIncome, 2),
                'total_cogs' => round($totalCogs, 2),
                'total_operating_expenses' => round($totalOperating, 2),
                'total_expense' => round($totalExpense, 2),
                'total_refunds' => 0.0,
                'total_transfers' => 0.0,
                'net_profit' => round($totalIncome - $totalExpense, 2),
                'period' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                ],
            ];
        }

        $plFilters = [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'category' => $category,
        ];

        if (is_array($moduleFilter) && count($moduleFilter) === 1) {
            $plFilters['module'] = $moduleFilter[0];
        } elseif (is_string($moduleFilter) && $moduleFilter !== '') {
            $plFilters['module'] = $moduleFilter;
        }

        $report = app(ProfitLossReportService::class)->report($plFilters);

        if (($filters['expense_scope'] ?? null) === 'operating') {
            $operating = (float) $report['totalExpenses'];

            return [
                'total_income' => 0.0,
                'total_expense' => round($operating, 2),
                'total_refunds' => 0.0,
                'total_transfers' => 0.0,
                'net_profit' => round(-$operating, 2),
                'period' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                ],
            ];
        }

        $totalExpense = (float) $report['totalCogs'] + (float) $report['totalExpenses'];

        return [
            'total_income' => (float) $report['totalRevenues'],
            'total_cogs' => (float) $report['totalCogs'],
            'total_operating_expenses' => (float) $report['totalExpenses'],
            'total_expense' => round($totalExpense, 2),
            'total_refunds' => (float) $report['totalRefunds'],
            'total_transfers' => 0.0,
            'net_profit' => (float) $report['netProfit'],
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
        ];
    }

    /**
     * Liquidity balances for treasury/cashbox/bank/wallet accounts only.
     */
    public function getAccountsBalance(): array
    {
        $query = Account::query()
            ->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
            ->where('is_active', true);
        AccountModuleDivision::applyLiquidityTreasuryScope($query);

        $accounts = $query
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency', 'balance'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type instanceof AccountType ? $account->type->value : (string) $account->type,
                'currency' => $account->currency,
                'balance' => round((float) $account->balance, 2),
            ]);

        $totalCashbox = (float) $accounts->where('type', 'cashbox')->sum('balance');
        $totalWallet = (float) $accounts->where('type', 'wallet')->sum('balance');
        $totalBank = (float) $accounts->where('type', 'bank')->sum('balance');
        $totalTreasury = (float) $accounts->where('type', 'treasury')->sum('balance');
        $totalPost = (float) $accounts->where('type', 'post')->sum('balance');

        return [
            'accounts' => $accounts->values(),
            'total_cashbox' => round($totalCashbox, 2),
            'total_wallet' => round($totalWallet, 2),
            'total_bank' => round($totalBank, 2),
            'total_treasury' => round($totalTreasury, 2),
            'total_post' => round($totalPost, 2),
            'grand_total' => round((float) $accounts->sum('balance'), 2),
        ];
    }

    /**
     * Get income breakdown grouped by module.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getIncomeByModule(array $filters): array
    {
        $query = DB::table('transactions')->where('type', 'income');

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $modules = $query->selectRaw('
            module,
            SUM(amount) as total
        ')->groupBy('module')->pluck('total', 'module');

        $result = [
            'flight' => round((float) ($modules['flight'] ?? 0), 2),
            'bus' => round((float) ($modules['bus'] ?? 0), 2),
            'service' => round((float) ($modules['service'] ?? 0), 2),
            'online' => round((float) ($modules['online'] ?? 0), 2),
            'general' => round((float) ($modules['general'] ?? 0), 2),
        ];

        $result['total'] = round(array_sum($result), 2);

        return $result;
    }

    /**
     * Get expense breakdown grouped by module.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getExpenseByModule(array $filters): array
    {
        $query = DB::table('transactions')->where('type', 'expense');

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $modules = $query->selectRaw('
            module,
            SUM(amount) as total
        ')->groupBy('module')->pluck('total', 'module');

        $result = [
            'flight' => round((float) ($modules['flight'] ?? 0), 2),
            'bus' => round((float) ($modules['bus'] ?? 0), 2),
            'service' => round((float) ($modules['service'] ?? 0), 2),
            'online' => round((float) ($modules['online'] ?? 0), 2),
            'general' => round((float) ($modules['general'] ?? 0), 2),
        ];

        $result['total'] = round(array_sum($result), 2);

        return $result;
    }

    /**
     * Get daily income and expense totals for a date range.
     * Used for charts.
     *
     * @param  array  $filters  Keys: from_date (required), to_date (required)
     */
    public function getDailyFinancialChart(array $filters): Collection
    {
        $query = DB::table('transactions')
            ->selectRaw('
                DATE(created_at) as date,
                SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as total_income,
                SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as total_expense
            ')
            ->whereDate('created_at', '>=', $filters['from_date'])
            ->whereDate('created_at', '<=', $filters['to_date'])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)');

        return $query->get()->map(function ($row) {
            $income = (float) ($row->total_income ?? 0);
            $expense = (float) ($row->total_expense ?? 0);

            return [
                'date' => $row->date,
                'total_income' => round($income, 2),
                'total_expense' => round($expense, 2),
                'net' => round($income - $expense, 2),
            ];
        });
    }

    /**
     * Get transaction history with filters for finance report.
     *
     * @param  array  $filters  Keys: type, module, account_id, from_date, to_date, per_page
     */
    public function getTransactionReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('transactions')
            ->leftJoin('users', 'transactions.created_by', '=', 'users.id')
            ->leftJoin('accounts as from_account', 'transactions.from_account_id', '=', 'from_account.id')
            ->leftJoin('accounts as to_account', 'transactions.to_account_id', '=', 'to_account.id')
            ->select(
                'transactions.id',
                'transactions.type',
                'transactions.amount',
                'transactions.module',
                'transactions.notes',
                'transactions.created_at',
                'transactions.from_account_id',
                'transactions.to_account_id',
                'users.name as created_by_name',
                'from_account.name as from_account_name',
                'from_account.type as from_account_type',
                'to_account.name as to_account_name',
                'to_account.type as to_account_type'
            );

        if (! empty($filters['type'])) {
            $query->where('transactions.type', $filters['type']);
        }

        if (! empty($filters['expenses_only'])) {
            $query->where(function ($q): void {
                $q->where('transactions.type', 'expense')
                    ->orWhere(function ($sub): void {
                        $sub->where('transactions.type', 'transfer')
                            ->where('to_account.type', 'expense');
                    });
            });
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('transactions.notes', 'like', "%{$search}%")
                    ->orWhere('transactions.id', 'like', "%{$search}%")
                    ->orWhere('from_account.name', 'like', "%{$search}%")
                    ->orWhere('to_account.name', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['module'])) {
            $modules = $this->expandModuleFilter($filters['module']);
            if ($modules !== []) {
                $query->whereIn('transactions.module', $modules);
            }
        }

        if (! empty($filters['account_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('transactions.from_account_id', $filters['account_id'])
                    ->orWhere('transactions.to_account_id', $filters['account_id']);
            });
        }

        if (! empty($filters['account_type'])) {
            $t = $filters['account_type'];
            $query->where(function ($q) use ($t) {
                $q->where('from_account.type', $t)
                    ->orWhere('to_account.type', $t);
            });
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('transactions.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('transactions.created_at', '<=', $filters['to_date']);
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $query->orderBy('transactions.created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
        $maps = app(LedgerClearingAccounts::class)->moduleAccountMaps();

        $paginator->setCollection(
            collect($paginator->items())->map(function ($row) use ($maps) {
                $row->flow_kind = $this->classifyTransactionFlow($row, $maps);

                return $row;
            })
        );

        return $paginator;
    }

    /**
     * Get a single transaction with related details.
     */
    public function getTransactionDetail(int $id): ?\stdClass
    {
        $txModel = Transaction::with([
            'createdBy',
            'fromAccount',
            'toAccount',
            'entries.account',
            'related' => function ($morph) {
                $morph->morphWith([
                    FlightBooking::class => ['customer', 'passengers', 'airlineAccount', 'flightSystem', 'flightCarrier', 'flightGroup'],
                    VisaBooking::class => ['customer', 'visaDetail'],
                    HajjUmraBooking::class => ['customer', 'program'],
                    BusBooking::class => ['customer', 'inventory.company'],
                    OnlineTransaction::class => ['customer', 'serviceType', 'provider'],
                ]);
            },
        ])->find($id);

        if (! $txModel) {
            return null;
        }

        // Convert to standard object to match original signature and fields
        $transaction = new \stdClass;
        $transaction->id = $txModel->id;
        $transaction->type = $txModel->type instanceof TransactionType ? $txModel->type->value : (string) $txModel->type;
        $transaction->amount = (float) $txModel->amount;
        $transaction->currency = $txModel->currency ?: 'EGP';
        $transaction->module = $txModel->module instanceof TransactionModule ? $txModel->module->value : (string) $txModel->module;
        $transaction->notes = $txModel->notes;
        $transaction->created_at = $txModel->created_at ? $txModel->created_at->toDateTimeString() : null;
        $transaction->from_account_id = $txModel->from_account_id;
        $transaction->to_account_id = $txModel->to_account_id;
        $transaction->created_by_name = $txModel->createdBy?->name;
        $transaction->from_account_name = $txModel->fromAccount?->name;
        $transaction->from_account_type = $txModel->fromAccount?->type;
        $transaction->from_account_currency = $txModel->fromAccount?->currency;
        $transaction->to_account_name = $txModel->toAccount?->name;
        $transaction->to_account_type = $txModel->toAccount?->type;
        $transaction->to_account_currency = $txModel->toAccount?->currency;

        // Map entries
        $transaction->entries = $txModel->entries->map(function ($e) {
            $entry = new \stdClass;
            $entry->id = $e->id;
            $entry->account_id = $e->account_id;
            $entry->debit = (float) $e->debit;
            $entry->credit = (float) $e->credit;
            $entry->balance_after = (float) $e->balance_after;
            $entry->account_name = $e->account?->name ?? '—';
            $entry->account_type = $e->account?->type ?? '—';
            $entry->account_currency = $e->account?->currency ?? 'EGP';

            return $entry;
        })->toArray();

        // Build related_meta if related model exists
        $transaction->related_meta = null;
        if ($txModel->related) {
            $related = $txModel->related;
            $meta = new \stdClass;
            $meta->type = class_basename($related);

            // Extract person name and phone
            $meta->person_name = null;
            $meta->person_phone = null;

            if (isset($related->customer) && $related->customer) {
                $meta->person_name = $related->customer->full_name ?: $related->customer->name;
                $meta->person_phone = $related->customer->phone;
            } elseif (isset($related->customer_name)) {
                $meta->person_name = $related->customer_name;
                $meta->person_phone = $related->customer_phone ?? null;
            }

            // Extract financial data
            $meta->total_amount = 0.0;
            $meta->paid_amount = 0.0;

            if ($related instanceof FlightBooking) {
                $meta->total_amount = (float) $related->selling_price;
                $meta->paid_amount = (float) $related->payments()->sum('amount');
                $meta->details = 'حجز طيران - PNR: '.($related->pnr ?: '—').' - خط الطيران: '.($related->airline_name ?: '—').' ('.($related->origin ?: '—').' -> '.($related->destination ?: '—').')';
            } elseif ($related instanceof VisaBooking) {
                $meta->total_amount = (float) $related->selling_price + (float) ($related->service_fee ?? 0);
                $meta->paid_amount = (float) $related->payments()->sum('amount');
                $meta->details = 'حجز تأشيرة - النوع: '.($related->visaDetail?->title ?: '—').' - الوكيل: '.($related->agent_name ?: '—');
            } elseif ($related instanceof HajjUmraBooking) {
                $meta->total_amount = (float) $related->selling_price;
                $meta->paid_amount = (float) $related->payments()->sum('amount');
                $meta->details = 'حجز حج وعمرة - البرنامج: '.($related->program?->name ?: '—').' - الوكيل: '.($related->agent_name ?: '—');
            } elseif ($related instanceof BusBooking) {
                $meta->total_amount = (float) $related->total_price;
                $meta->paid_amount = (float) $related->paid_amount;
                $meta->details = 'حجز باص - الرحلة: '.($related->inventory?->origin ?: '—').' -> '.($related->inventory?->destination ?: '—').' - الشركة: '.($related->inventory?->company?->name ?: '—');
            } elseif ($related instanceof OnlineTransaction) {
                $meta->total_amount = (float) $related->selling_price;
                $meta->paid_amount = (float) $related->selling_price; // Online services are generally fully paid
                $meta->details = 'خدمة إلكترونية - النوع: '.($related->serviceType?->name ?: '—').' - المزود: '.($related->provider?->name ?: '—');
            }

            $meta->remaining_amount = max(0.0, $meta->total_amount - $meta->paid_amount);

            $transaction->related_meta = $meta;
        }

        return $transaction;
    }

    /**
     * Get bus company debt summary.
     * Shows which companies the office still owes money to.
     */
    public function getBusDebtSummary(): Collection
    {
        return DB::table('bus_inventories')
            ->join('bus_companies', 'bus_inventories.company_id', '=', 'bus_companies.id')
            ->where('bus_inventories.remaining_debt', '>', 0)
            ->selectRaw('
                bus_companies.id as company_id,
                bus_companies.name as company_name,
                SUM(bus_inventories.remaining_debt) as total_debt,
                COUNT(*) as inventory_count
            ')
            ->groupBy('bus_companies.id', 'bus_companies.name')
            ->orderBy('total_debt', 'desc')
            ->get()
            ->map(function ($row) {
                return [
                    'company_id' => $row->company_id,
                    'company_name' => $row->company_name,
                    'total_debt' => round((float) $row->total_debt, 2),
                    'inventory_count' => $row->inventory_count,
                ];
            });
    }

    public function getProfitLossReport(array $filters): array
    {
        return app(ProfitLossReportService::class)->report($filters);
    }

    /**
     * @param  array<int, string>|string|null  $filter
     */
    private function expandModuleFilter(array|string|null $filter): array
    {
        if ($filter === null || $filter === '') {
            return [];
        }

        $modules = is_array($filter) ? $filter : [$filter];
        $expanded = [];

        foreach ($modules as $module) {
            $expanded[] = $module;
            $normalized = $this->normalizeModuleKey((string) $module);

            if ($normalized === 'wallet') {
                $expanded[] = 'wallet_transfer';
                $expanded[] = 'wallets';
            }
            if ($normalized === 'flight') {
                $expanded[] = 'flights';
            }
            if ($normalized === 'visa') {
                $expanded[] = 'visas';
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param  array<int, string>|string|null  $filter
     */
    private function moduleMatchesFilter(string $module, array|string|null $filter): bool
    {
        if ($filter === null || $filter === '' || (is_array($filter) && $filter === [])) {
            return true;
        }

        $allowed = array_map(
            fn (string $m) => $this->normalizeModuleKey($m),
            $this->expandModuleFilter($filter)
        );

        return in_array($this->normalizeModuleKey($module), $allowed, true);
    }

    private function normalizeModuleKey(string $module): string
    {
        $module = strtolower(trim($module));

        return match ($module) {
            '', 'general' => 'general',
            'flights', 'flight' => 'flight',
            'visas', 'visa' => 'visa',
            'wallet_transfer', 'wallets', 'wallet' => 'wallet',
            default => $module,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function enrichLedgerRowArray(object $row): array
    {
        $maps = app(LedgerClearingAccounts::class)->moduleAccountMaps();

        return [
            'id' => $row->id ?? null,
            'type' => $row->type ?? null,
            'flow_kind' => $this->classifyTransactionFlow($row, $maps),
            'amount' => round((float) ($row->amount ?? 0), 2),
            'module' => $row->module ?? null,
            'notes' => $row->notes ?? null,
            'created_at' => $row->created_at ?? null,
            'created_by_name' => $row->created_by_name ?? null,
        ];
    }

    /**
     * @param  array{income: array<int, string>, expense: array<int, string>}  $maps
     */
    private function classifyTransactionFlow(object $tx, array $maps): string
    {
        $type = (string) ($tx->type ?? '');
        $fromId = (int) ($tx->from_account_id ?? 0);
        $toId = (int) ($tx->to_account_id ?? 0);
        $toType = (string) ($tx->to_account_type ?? '');
        $incomeClearing = $maps['income'];
        $expenseClearing = $maps['expense'];

        if ($type === 'income') {
            return 'inflow';
        }

        if ($type === 'expense' || $type === 'refund') {
            return 'outflow';
        }

        if ($type !== 'transfer') {
            return 'neutral';
        }

        $fromIncome = $fromId > 0 && isset($incomeClearing[$fromId]);
        $toIncome = $toId > 0 && isset($incomeClearing[$toId]);
        $fromExpense = $fromId > 0 && isset($expenseClearing[$fromId]);
        $toExpense = $toId > 0 && isset($expenseClearing[$toId]);

        if ($fromIncome && ! $toIncome) {
            return 'inflow';
        }

        if ($toIncome && ! $fromIncome) {
            return 'outflow';
        }

        if ($toExpense && ! $fromExpense) {
            return 'outflow';
        }

        if ($fromExpense && ! $toExpense) {
            return 'inflow';
        }

        if ($toType === 'expense') {
            return 'outflow';
        }

        return 'neutral';
    }
}
