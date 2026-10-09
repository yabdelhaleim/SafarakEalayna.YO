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
            ->get(['id', 'name', 'type', 'currency', 'balance']);

        $totalCashbox = 0.0;
        $totalWallet = 0.0;
        $totalBank = 0.0;
        $totalTreasury = 0.0;
        $totalPost = 0.0;
        $grandTotal = 0.0;

        $mappedAccounts = $accounts->map(function (Account $account) use (
            &$totalCashbox,
            &$totalWallet,
            &$totalBank,
            &$totalTreasury,
            &$totalPost,
            &$grandTotal
        ) {
            $balance = (float) $account->balance;
            $currency = strtoupper((string) ($account->currency ?: 'EGP'));
            
            $balanceEgp = $balance;
            if ($currency !== 'EGP' && $balance != 0.0) {
                try {
                    $converted = app(\App\Services\Finance\CurrencyService::class)->convert($balance, $currency, 'EGP');
                    $balanceEgp = (float) $converted['to_amount'];
                } catch (\Exception $e) {
                    $rate = \App\Models\ExchangeRate::where('from_currency', $currency)
                        ->where('to_currency', 'EGP')
                        ->where('is_active', true)
                        ->orderBy('effective_date', 'desc')
                        ->first();
                    if ($rate && $rate->rate > 0) {
                        $balanceEgp = $balance * (float) $rate->rate;
                    }
                }
            }

            $type = $account->type instanceof AccountType ? $account->type->value : (string) $account->type;
            
            $grandTotal += $balanceEgp;
            match ($type) {                'cashbox' => $totalCashbox += $balanceEgp,                'wallet' => $totalWallet += $balanceEgp,                'bank' => $totalBank += $balanceEgp,                'treasury' => $totalTreasury += $balanceEgp,                'post' => $totalPost += $balanceEgp,                default => null,            };            return [                'id' => $account->id,                'name' => $account->name,                'type' => $type,                'currency' => $account->currency,                'balance' => round($balance, 2),            ];
        });

        return [
            'accounts' => $mappedAccounts->values(),
            'total_cashbox' => round($totalCashbox, 2),
            'total_wallet' => round($totalWallet, 2),
            'total_bank' => round($totalBank, 2),
            'total_treasury' => round($totalTreasury, 2),
            'total_post' => round($totalPost, 2),
            'grand_total' => round($grandTotal, 2),
        ];
    }

    /**
     * Get income breakdown grouped by module.
     *
     * Powers the dashboard's "دخل حسب القسم" chart and the per-module
     * revenue cards. Uses {@see ProfitLossReportService::moduleBreakdown()}
     * as the source of truth — that engine classifies ALL flight/hajj/visa/
     * tourism revenue, which is recorded as `type='transfer'` touching the
     * `pending_sales_receivable` / `income_clearing` accounts (cash-basis
     * recognition). The previous raw `WHERE type='income'` query silently
     * dropped ~100% of tourism revenue.
     *
     * @param  array  $filters  Keys: from_date, to_date, category
     * @return array<string, float>
     */
    public function getIncomeByModule(array $filters): array
    {
        $breakdown = app(ProfitLossReportService::class)->moduleBreakdown($filters);

        $incomeByModule = [];
        foreach ($breakdown['by_module'] ?? [] as $row) {
            $key = $this->normalizeModuleKey((string) ($row['module'] ?? ''));
            $incomeByModule[$key] = (float) ($row['income'] ?? 0);
        }

        $result = $this->projectToCanonicalKeys($incomeByModule);

        $result['total'] = round(array_sum(array_intersect_key($result, array_flip([
            'flight', 'hajj_umra', 'visa', 'tourism', 'bus', 'fawry', 'online', 'wallet', 'service', 'general',
        ]))), 2);

        return $result;
    }

    /**
     * Get expense breakdown grouped by module.
     *
     * Same rationale as {@see getIncomeByModule()} — pivots through
     * {@see ProfitLossReportService::moduleBreakdown()} so COGS-classified
     * spend (transfer rows from prepaid → expense accounts) is visible.
     * Before this fix, only `type='expense'` rows were counted and the
     * entire airline prepaid-consumption side of the ledger was invisible
     * on the dashboard.
     *
     * COGS + operating_expense are combined into a single 'expense' bucket
     * per module — that matches the operator-visible meaning of "مصروفات
     * القسم" on the dashboard cards.
     *
     * @param  array  $filters  Keys: from_date, to_date, category
     * @return array<string, float>
     */
    public function getExpenseByModule(array $filters): array
    {
        $breakdown = app(ProfitLossReportService::class)->moduleBreakdown($filters);

        $rows = $breakdown['by_module'] ?? [];
        // Combine COGS + operating_expense per module. The breakdown
        // service exposes them as separate keys ('cogs', 'expense'); on
        // the dashboard the operator sees one "expenses" number, which
        // is the sum of both — that matches `getFinancialSummary`'
        // total_expense semantics.
        $combined = [];
        foreach ($rows as $row) {
            $key = $this->normalizeModuleKey((string) ($row['module'] ?? ''));
            $combined[$key] = (float) ($row['cogs'] ?? 0) + (float) ($row['expense'] ?? 0);
        }

        $result = $this->projectToCanonicalKeys($combined);

        $result['total'] = round(array_sum(array_intersect_key($result, array_flip([
            'flight', 'hajj_umra', 'visa', 'tourism', 'bus', 'fawry', 'online', 'wallet', 'service', 'general',
        ]))), 2);

        return $result;
    }

    /**
     * Get daily income and expense totals for a date range.
     *
     * Powers the monthly income-vs-expense line chart on the main
     * dashboard. Like {@see getIncomeByModule()}, this previously queried
     * `WHERE type IN ('income','expense')` and missed every
     * `type='transfer'` row that carries the cash-basis revenue or COGS
     * — i.e. the entire tourism book. Now routed through the P&L engine
     * with a date-bucketed scan so transfer revenue is included.
     *
     * @param  array  $filters  Keys: from_date (required), to_date (required), category (optional)
     */
    public function getDailyFinancialChart(array $filters): Collection
    {
        $maps = app(\App\Services\Finance\LedgerClearingAccounts::class)->moduleAccountMaps();
        $incomeClearing = $maps['income'] ?? [];
        $expenseClearing = $maps['expense'] ?? [];
        $prepaidAccounts = app(\App\Services\Finance\LedgerClearingAccounts::class)->prepaidAccountIdMap();
        $allClearingIds = array_values(array_unique(array_merge(
            array_keys($incomeClearing),
            array_keys($expenseClearing),
            array_keys($prepaidAccounts)
        )));

        $query = DB::table('transactions as t')
            ->leftJoin('accounts as to_acc', 't.to_account_id', '=', 'to_acc.id')
            ->leftJoin('accounts as from_acc', 't.from_account_id', '=', 'from_acc.id')
            ->leftJoin('transfers as tr', 't.id', '=', 'tr.transaction_id')
            ->select([
                't.id',
                't.type',
                't.module',
                't.amount',
                't.notes',
                't.from_account_id',
                't.to_account_id',
                't.created_at',
                'to_acc.type as to_account_type',
                'to_acc.module_type as to_account_module_type',
                'from_acc.module_type as from_account_module_type',
                'tr.converted_amount',
                'tr.from_currency',
                'tr.to_currency',
            ])
            ->whereDate('t.created_at', '>=', $filters['from_date'])
            ->whereDate('t.created_at', '<=', $filters['to_date']);

        // Mirror the relevance filter from ProfitLossReportService so
        // transfer-type revenue and prepaid/cogs flows are picked up.
        $query->where(function ($outer) use ($allClearingIds) {
            $outer->whereIn('t.type', ['income', 'expense', 'refund']);
            $outer->orWhere(function ($transfer) use ($allClearingIds) {
                $transfer->where('t.type', 'transfer')
                    ->where(function ($legs) use ($allClearingIds) {
                        if ($allClearingIds !== []) {
                            $legs->whereIn('t.from_account_id', $allClearingIds)
                                ->orWhereIn('t.to_account_id', $allClearingIds);
                        }
                        $legs->orWhere('to_acc.type', 'expense');
                    });
            });
        });

        // Skip reversed rows (mirror notes) so we don't double-count.
        $query->where(function ($q) {
            $q->whereNull('t.notes')
                ->orWhere(function ($q2) {
                    $q2->where('t.notes', 'not like', 'عكس:%')
                        ->where('t.notes', 'not like', 'عكس %');
                });
        });

        $daily = [];
        foreach ($query->orderBy('t.id')->cursor() as $tx) {
            $classification = $this->classifyTransactionForChart((object) $tx, $incomeClearing, $expenseClearing, $prepaidAccounts);
            if ($classification === null) {
                continue;
            }

            $amount = $this->chartAmountEGP((object) $tx);
            if ($amount <= 0) {
                continue;
            }

            $dateKey = substr((string) $tx->created_at, 0, 10);
            if (! isset($daily[$dateKey])) {
                $daily[$dateKey] = ['income' => 0.0, 'expense' => 0.0];
            }

            if (in_array($classification, ['revenue', 'cogs_reversal'], true)) {
                $daily[$dateKey]['income'] += $amount;
            } elseif (in_array($classification, ['cogs', 'operating_expense', 'revenue_reversal', 'refund'], true)) {
                $daily[$dateKey]['expense'] += $amount;
            }
        }

        ksort($daily);

        return collect($daily)->map(function (array $row, string $date): array {
            $income = round((float) $row['income'], 2);
            $expense = round((float) $row['expense'], 2);

            return [
                'date' => $date,
                'total_income' => $income,
                'total_expense' => $expense,
                'net' => round($income - $expense, 2),
            ];
        })->values();
    }

    /**
     * Project a per-module value map onto the canonical UI keys
     * (flight, hajj_umra, visa, tourism, bus, fawry, online, wallet,
     * service, office, general).
     *
     * Powers both {@see getIncomeByModule()} and {@see getExpenseByModule()}.
     *
     * @param  array<string, float>  $valueByModule  Module key → value
     * @return array<string, float>                  Canonical key → value (default 0)
     */
    private function projectToCanonicalKeys(array $valueByModule): array
    {
        $canonical = [
            'flight' => 'flight',
            'hajj_umra' => 'hajj_umra',
            'visa' => 'visa',
            'tourism' => 'tourism',
            'bus' => 'bus',
            'fawry' => 'fawry',
            'online' => 'online',
            'wallet_transfer' => 'wallet',
            'wallets' => 'wallet',
            'wallet' => 'wallet',
            'service' => 'service',
            'office' => 'office',
            'general' => 'general',
        ];

        $out = [];
        foreach ($canonical as $alias => $displayKey) {
            $out[$displayKey] = round((float) ($valueByModule[$alias] ?? 0), 2);
        }

        return $out;
    }

    /**
     * Compact classification that mirrors {@see ProfitLossReportService::classify()}
     * for the daily-chart bucketing pipeline. Returns one of:
     *  - 'revenue' / 'revenue_reversal' (refund path on a transfer)
     *  - 'cogs' / 'cogs_reversal'
     *  - 'operating_expense'
     *  - null (neutral / prepaid top-ups)
     *
     * Kept private to this service because the daily chart has its own
     * bucket semantics (income vs expense columns) that don't need the
     * full P&L report machinery.
     *
     * @param  array<int, string>  $incomeClearing
     * @param  array<int, string>  $expenseClearing
     * @param  array<int, string>  $prepaidAccounts
     */
    private function classifyTransactionForChart(object $tx, array $incomeClearing, array $expenseClearing, array $prepaidAccounts): ?string
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

        // Liquidity → prepaid asset: neutral for P&L purposes.
        if ($toPrepaid && ! $fromPrepaid && ! $fromExpense && ! $fromIncome) {
            return null;
        }

        // Prepaid → expense clearing = COGS recognition.
        if ($fromPrepaid && $toExpense && ! $toPrepaid) {
            return 'cogs';
        }

        // Expense clearing → prepaid = COGS reversal (refund path).
        if ($toPrepaid && $fromExpense && ! $fromPrepaid) {
            return 'cogs_reversal';
        }

        if ($fromIncome && ! $toIncome) {
            return 'revenue';
        }

        if ($toIncome && ! $fromIncome) {
            return 'revenue_reversal';
        }

        if ($toExpense && ! $fromExpense) {
            return 'operating_expense';
        }

        if ($toType === 'expense') {
            return 'operating_expense';
        }

        return null;
    }

    private function chartAmountEGP(object $tx): float
    {
        $amount = (float) ($tx->amount ?? 0);

        if (isset($tx->converted_amount) && (float) $tx->converted_amount > 0) {
            $toCurrency = strtoupper((string) ($tx->to_currency ?? ''));
            $fromCurrency = strtoupper((string) ($tx->from_currency ?? ''));
            if ($toCurrency === 'EGP') {
                return (float) $tx->converted_amount;
            }
            if ($fromCurrency === 'EGP') {
                return $amount;
            }
        }

        // If both currencies are the same and not EGP the raw amount is fine
        // for the per-day comparison (chart is in transaction currency-class).
        // For chart purposes we keep the raw transaction amount, which is
        // what the reporting accountant compares day-to-day in the source
        // ledger currency bucket, then a separate EGP normalisation could be
        // layered on top.
        return $amount;
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
            ->leftJoin('transfers as tr', 'transactions.id', '=', 'tr.transaction_id')
            ->select(
                'transactions.id',
                'transactions.type',
                'transactions.amount',
                'transactions.currency as transaction_currency',
                'transactions.module',
                'transactions.notes',
                'transactions.created_at',
                'transactions.from_account_id',
                'transactions.to_account_id',
                'users.name as created_by_name',
                'from_account.name as from_account_name',
                'from_account.type as from_account_type',
                'from_account.currency as from_account_currency',
                'to_account.name as to_account_name',
                'to_account.type as to_account_type',
                'to_account.currency as to_account_currency',
                'tr.from_currency as transfer_from_currency',
                'tr.to_currency as transfer_to_currency',
                'tr.exchange_rate as transfer_exchange_rate',
                'tr.converted_amount as transfer_converted_amount'
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

                $currency = $row->transaction_currency ?: ($row->from_account_currency ?: ($row->to_account_currency ?: 'EGP'));
                if ($currency !== 'EGP' && empty($row->transfer_from_currency)) {
                    try {
                        $converted = app(\App\Services\Finance\CurrencyService::class)->convert((float) $row->amount, $currency, 'EGP');
                        $row->transfer_from_currency = $currency;
                        $row->transfer_to_currency = 'EGP';
                        $row->transfer_exchange_rate = (float) $converted['rate'];
                        $row->transfer_converted_amount = (float) $converted['to_amount'];
                    } catch (\Exception $e) {
                        $rate = \App\Models\ExchangeRate::where('from_currency', $currency)
                            ->where('to_currency', 'EGP')
                            ->where('is_active', true)
                            ->orderBy('effective_date', 'desc')
                            ->first();
                        if ($rate && $rate->rate > 0) {
                            $row->transfer_from_currency = $currency;
                            $row->transfer_to_currency = 'EGP';
                            $row->transfer_exchange_rate = (float) $rate->rate;
                            $row->transfer_converted_amount = (float) $row->amount * (float) $rate->rate;
                        }
                    }
                }

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
                    // OnlineTransaction relations were renamed to *Row helpers
                    // (serviceTypeRow / providerRow) after migration
                    // 2026_08_28_000000_convert_online_service_type_and_provider_to_text
                    // converted the FK columns to free-text codes.
                    OnlineTransaction::class => ['customer', 'serviceTypeRow', 'providerRow'],
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
