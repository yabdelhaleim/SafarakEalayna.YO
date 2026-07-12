<?php

namespace App\Services\Reports;

use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Income statement (P&L) aligned with strict double-entry.
 * Reads live ledger data only — no HTTP/response caching.
 */
class ProfitLossReportService
{
    /** @var array<string, list<string>> */
    private const TOURISM_MODULES = ['flight', 'hajj_umra', 'visa', 'tourism'];

    /** @var array<string, list<string>> */
    private const OFFICE_MODULES = ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'wallets', 'general', 'service', 'office'];

    public function __construct(
        protected LedgerClearingAccounts $clearingAccounts
    ) {}

    /**
     * @param  array{from_date?: string, to_date?: string, category?: string, module?: string}  $filters
     */
    public function report(array $filters): array
    {
        $maps = $this->clearingAccounts->moduleAccountMaps();
        $incomeClearing = $maps['income'];
        $expenseClearing = $maps['expense'];
        $prepaidAccounts = $this->clearingAccounts->prepaidAccountIdMap();
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
                't.from_account_id',
                't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.name as to_account_name',
                'to_acc.module_type as to_account_module_type',
                'from_acc.module_type as from_account_module_type',
                'tr.converted_amount',
                'tr.from_currency',
                'tr.to_currency',
            ]);

        $this->applyDateFilters($query, $filters);
        $this->applyRelevanceFilter($query, $allClearingIds);
        $this->applyCategorySqlFilter($query, $filters, $incomeClearing, $expenseClearing);

        $revenuesByModule = [];
        $cogsByModule = [];
        $expensesByName = [];
        $refundsByModule = [];

        $totalRevenues = 0.0;
        $totalCogs = 0.0;
        $totalExpenses = 0.0;
        $totalRefunds = 0.0;
        $scanned = 0;
        $included = 0;

        foreach ($query->orderBy('t.id')->cursor() as $tx) {
            $scanned++;

            $classification = $this->classify($tx, $incomeClearing, $expenseClearing, $prepaidAccounts);
            if ($classification === null) {
                continue;
            }

            $module = $this->resolveModule($tx, $incomeClearing, $expenseClearing);
            if (! $this->matchesFilters($module, $filters)) {
                continue;
            }

            $amount = $this->resolveAmountEGP($tx);
            if ($amount <= 0) {
                continue;
            }

            $included++;

            if ($classification === 'revenue') {
                $this->addBucket($revenuesByModule, $module, $amount, $totalRevenues);
            } elseif ($classification === 'revenue_reversal' || $classification === 'refund') {
                $this->subtractBucket($revenuesByModule, $module, $amount, $totalRevenues);
                $this->addBucket($refundsByModule, $module, $amount, $totalRefunds);
            } elseif ($classification === 'cogs') {
                $this->addBucket($cogsByModule, $module, $amount, $totalCogs);
            } elseif ($classification === 'cogs_reversal') {
                $this->subtractBucket($cogsByModule, $module, $amount, $totalCogs);
            } elseif ($classification === 'operating_expense') {
                $this->addNamedBucket(
                    $expensesByName,
                    $tx->to_account_name ?: ('مصروفات '.$this->moduleLabel($module)),
                    $amount,
                    $totalExpenses
                );
            }
        }

        $totalRevenues = max(0, round($totalRevenues, 2));
        $totalCogs = max(0, round($totalCogs, 2));
        $totalExpenses = max(0, round($totalExpenses, 2));
        $grossProfit = round($totalRevenues - $totalCogs, 2);
        $netProfit = round($totalRevenues - $totalCogs - $totalExpenses, 2);

        return [
            'totalRevenues' => $totalRevenues,
            'totalCogs' => $totalCogs,
            'totalExpenses' => $totalExpenses,
            'totalRefunds' => round($totalRefunds, 2),
            'grossProfit' => $grossProfit,
            'netProfit' => $netProfit,
            'revenuesList' => $this->formatModuleList($revenuesByModule, 'إيرادات'),
            'cogsList' => $this->formatModuleList($cogsByModule, 'تكاليف'),
            'expensesList' => $this->formatNamedList($expensesByName),
            'refundsList' => $this->formatModuleList($refundsByModule, 'مردودات'),
            'period' => [
                'from' => $filters['from_date'] ?? null,
                'to' => $filters['to_date'] ?? null,
            ],
            'meta' => [
                'transactions_scanned' => $scanned,
                'transactions_included' => $included,
                'generated_at' => now()->toIso8601String(),
                'live' => true,
            ],
        ];
    }

    /**
     * Per-module income/expense breakdown for department dashboards.
     *
     * @param  array{from_date?: string, to_date?: string, category?: string, module?: string}  $filters
     */
    public function moduleBreakdown(array $filters = []): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $toDate = $filters['to_date'] ?? now()->toDateString();
        $filters['from_date'] = $fromDate;
        $filters['to_date'] = $toDate;

        $maps = $this->clearingAccounts->moduleAccountMaps();
        $incomeClearing = $maps['income'];
        $expenseClearing = $maps['expense'];
        $prepaidAccounts = $this->clearingAccounts->prepaidAccountIdMap();
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
                't.from_account_id',
                't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.name as to_account_name',
                'to_acc.module_type as to_account_module_type',
                'from_acc.module_type as from_account_module_type',
                'tr.converted_amount',
                'tr.from_currency',
                'tr.to_currency',
            ]);

        $this->applyDateFilters($query, $filters);
        $this->applyRelevanceFilter($query, $allClearingIds);
        $this->applyCategorySqlFilter($query, $filters, $incomeClearing, $expenseClearing);

        $incomeByModule = [];
        $cogsByModule = [];
        $expenseByModule = [];
        $scanned = 0;
        $included = 0;

        foreach ($query->orderBy('t.id')->cursor() as $tx) {
            $scanned++;

            $classification = $this->classify($tx, $incomeClearing, $expenseClearing, $prepaidAccounts);
            if ($classification === null) {
                continue;
            }

            $module = $this->resolveModule($tx, $incomeClearing, $expenseClearing);
            if (! $this->matchesFilters($module, $filters)) {
                continue;
            }

            $amount = $this->resolveAmountEGP($tx);
            if ($amount <= 0) {
                continue;
            }

            $included++;

            $ignoredTotal = 0.0;

            if ($classification === 'revenue') {
                $this->addBucket($incomeByModule, $module, $amount, $ignoredTotal);
            } elseif ($classification === 'revenue_reversal' || $classification === 'refund') {
                $this->subtractBucket($incomeByModule, $module, $amount, $ignoredTotal);
            } elseif ($classification === 'cogs') {
                $this->addBucket($cogsByModule, $module, $amount, $ignoredTotal);
            } elseif ($classification === 'cogs_reversal') {
                $this->subtractBucket($cogsByModule, $module, $amount, $ignoredTotal);
            } elseif ($classification === 'operating_expense') {
                $this->addBucket($expenseByModule, $module, $amount, $ignoredTotal);
            }
        }

        $allModules = array_unique(array_merge(
            array_keys($incomeByModule),
            array_keys($cogsByModule),
            array_keys($expenseByModule),
            ['flight', 'bus', 'hajj_umra', 'visa', 'fawry', 'online', 'wallet', 'general']
        ));

        $breakdown = [];
        foreach ($allModules as $mod) {
            $income = round(max(0, $incomeByModule[$mod] ?? 0.0), 2);
            $cogs = round(max(0, $cogsByModule[$mod] ?? 0.0), 2);
            $expense = round(max(0, $expenseByModule[$mod] ?? 0.0), 2);
            if ($income <= 0 && $cogs <= 0 && $expense <= 0) {
                continue;
            }
            $breakdown[] = [
                'module' => $mod,
                'income' => $income,
                'cogs' => $cogs,
                'expense' => $expense,
                'profit' => round($income - $cogs - $expense, 2),
            ];
        }

        usort($breakdown, fn (array $a, array $b) => $b['income'] <=> $a['income']);

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'by_module' => $breakdown,
            'meta' => [
                'transactions_scanned' => $scanned,
                'transactions_included' => $included,
                'generated_at' => now()->toIso8601String(),
                'live' => true,
            ],
        ];
    }

    /**
     * @param  array{from_date?: string, to_date?: string}  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['from_date'])) {
            $query->whereDate('t.created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('t.created_at', '<=', $filters['to_date']);
        }
    }

    /**
     * @param  list<int>  $clearingIds
     */
    private function applyRelevanceFilter(Builder $query, array $clearingIds): void
    {
        $query->where(function (Builder $outer) use ($clearingIds): void {
            $outer->whereIn('t.type', ['income', 'expense', 'refund']);

            $outer->orWhere(function (Builder $transfer) use ($clearingIds): void {
                $transfer->where('t.type', 'transfer')
                    ->where(function (Builder $legs) use ($clearingIds): void {
                        if ($clearingIds !== []) {
                            $legs->whereIn('t.from_account_id', $clearingIds)
                                ->orWhereIn('t.to_account_id', $clearingIds);
                        }
                        $legs->orWhere('to_acc.type', 'expense');
                    });
            });
        });
    }

    /**
     * @param  array<int, string>  $incomeClearing
     * @param  array<int, string>  $expenseClearing
     * @param  array{category?: string, module?: string}  $filters
     */
    private function applyCategorySqlFilter(
        Builder $query,
        array $filters,
        array $incomeClearing,
        array $expenseClearing
    ): void {
        $moduleFilter = $filters['module'] ?? 'all';
        if ($moduleFilter !== null && $moduleFilter !== '' && $moduleFilter !== 'all') {
            return;
        }

        $category = $filters['category'] ?? 'all';
        if ($category !== 'tourism' && $category !== 'office') {
            return;
        }

        $modules = $category === 'tourism' ? self::TOURISM_MODULES : self::OFFICE_MODULES;
        $divisionModules = $category === 'tourism'
            ? AccountModuleDivision::TOURISM
            : AccountModuleDivision::OFFICE;

        // Exclude 'general' and empty from direct module matching to enforce account-based resolution for general/unassigned transactions
        $filteredModules = array_values(array_diff($modules, ['general', '']));

        // Exclude 'general' from clearing account ID lookup as well so that general clearing accounts are resolved by source/destination account module
        $clearingIds = $this->clearingIdsForModules(
            $incomeClearing,
            $expenseClearing,
            $filteredModules
        );

        $incomeClearingIds = array_keys($incomeClearing);
        $expenseClearingIds = array_keys($expenseClearing);

        $query->where(function (Builder $q) use ($filteredModules, $clearingIds, $divisionModules, $incomeClearingIds, $expenseClearingIds): void {
            if ($filteredModules !== []) {
                $q->whereIn('t.module', $filteredModules);
            }
            if ($clearingIds !== []) {
                $q->orWhereIn('t.from_account_id', $clearingIds)
                    ->orWhereIn('t.to_account_id', $clearingIds);
            }
            // General transactions are resolved strictly by their source/destination account's module_type
            $q->orWhere(function (Builder $sub) use ($divisionModules, $incomeClearingIds, $expenseClearingIds): void {
                $sub->whereIn('t.module', ['general', ''])
                    ->where(function (Builder $sub2) use ($divisionModules, $incomeClearingIds, $expenseClearingIds): void {
                        // Expense path: paid from a liquidity account belonging to the division
                        $sub2->where(function (Builder $exp) use ($divisionModules, $expenseClearingIds): void {
                            $exp->where(function (Builder $eCond) use ($expenseClearingIds): void {
                                $eCond->where('t.type', 'expense')
                                    ->orWhere('to_acc.type', 'expense')
                                    ->orWhereIn('t.to_account_id', $expenseClearingIds);
                            })
                                ->whereIn('from_acc.module_type', $divisionModules);
                        })
                        // Income path: received into a liquidity account belonging to the division
                            ->orWhere(function (Builder $inc) use ($divisionModules, $incomeClearingIds): void {
                                $inc->where(function (Builder $iCond) use ($incomeClearingIds): void {
                                    $iCond->where('t.type', 'income')
                                        ->orWhere('to_acc.type', 'income')
                                        ->orWhereIn('t.from_account_id', $incomeClearingIds);
                                })
                                    ->whereIn('to_acc.module_type', $divisionModules);
                            });
                    });
            });
        });
    }

    /**
     * @param  array<int, string>  $incomeClearing
     * @param  array<int, string>  $expenseClearing
     * @param  list<string>  $modules
     * @return list<int>
     */
    private function clearingIdsForModules(array $incomeClearing, array $expenseClearing, array $modules): array
    {
        $ids = [];
        foreach ([$incomeClearing, $expenseClearing] as $map) {
            foreach ($map as $accountId => $module) {
                if (in_array($module, $modules, true)) {
                    $ids[] = (int) $accountId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, string>  $incomeClearing
     * @param  array<int, string>  $expenseClearing
     * @param  array<int, string>  $prepaidAccounts
     */
    private function classify(object $tx, array $incomeClearing, array $expenseClearing, array $prepaidAccounts = []): ?string
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

        // شحن رصيد مسبق: سيولة → أصل (محايد في P&L)
        if ($toPrepaid && ! $fromPrepaid && ! $fromExpense && ! $fromIncome) {
            return null;
        }

        // استهلاك COGS: رصيد مسبق → إقفال تكاليف
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
     * @param  array<int, string>  $incomeClearing
     * @param  array<int, string>  $expenseClearing
     */
    private function resolveModule(object $tx, array $incomeClearing, array $expenseClearing): string
    {
        $fromId = (int) ($tx->from_account_id ?? 0);
        $toId = (int) ($tx->to_account_id ?? 0);
        $module = $this->normalizeModuleKey((string) ($tx->module ?? ''));

        if ($module !== '' && $module !== 'general') {
            return $module;
        }

        // Try resolving by the liquidity/source account's module_type (highest precision for manual expenses/incomes)
        if ($tx->type === 'expense' && ! empty($tx->from_account_module_type)) {
            $resolved = $this->normalizeModuleKey($tx->from_account_module_type);
            if ($resolved !== 'general' && $resolved !== '') {
                return $resolved;
            }
        }

        if ($tx->type === 'income' && ! empty($tx->to_account_module_type)) {
            $resolved = $this->normalizeModuleKey($tx->to_account_module_type);
            if ($resolved !== 'general' && $resolved !== '') {
                return $resolved;
            }
        }

        if ($fromId > 0 && isset($incomeClearing[$fromId])) {
            return $incomeClearing[$fromId];
        }

        if ($toId > 0 && isset($incomeClearing[$toId])) {
            return $incomeClearing[$toId];
        }

        if ($fromId > 0 && isset($expenseClearing[$fromId])) {
            return $expenseClearing[$fromId];
        }

        if ($toId > 0 && isset($expenseClearing[$toId])) {
            return $expenseClearing[$toId];
        }

        return $module !== '' ? $module : 'general';
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

    private function resolveAmountEGP(object $tx): float
    {
        $amount = (float) $tx->amount;
        if (isset($tx->converted_amount) && (float) $tx->converted_amount > 0) {
            $fromCurrency = strtoupper((string) ($tx->from_currency ?? ''));
            $toCurrency = strtoupper((string) ($tx->to_currency ?? ''));
            if ($toCurrency === 'EGP') {
                $amount = (float) $tx->converted_amount;
            } elseif ($fromCurrency === 'EGP') {
                $amount = (float) $tx->amount;
            }
        }

        return $amount;
    }

    /**
     * @param  array{category?: string, module?: string}  $filters
     */
    private function matchesFilters(string $module, array $filters): bool
    {
        $category = $filters['category'] ?? 'all';
        $moduleFilter = $filters['module'] ?? 'all';

        if ($moduleFilter !== null && $moduleFilter !== '' && $moduleFilter !== 'all') {
            $wanted = $this->normalizeModuleKey((string) $moduleFilter);

            return $module === $wanted;
        }

        if ($category === 'tourism') {
            return in_array($module, self::TOURISM_MODULES, true);
        }

        if ($category === 'office') {
            return in_array($module, self::OFFICE_MODULES, true);
        }

        return true;
    }

    /** @param array<string, float> $bucket */
    private function addBucket(array &$bucket, string $key, float $amount, float &$total): void
    {
        $bucket[$key] = ($bucket[$key] ?? 0.0) + $amount;
        $total += $amount;
    }

    /** @param array<string, float> $bucket */
    private function subtractBucket(array &$bucket, string $key, float $amount, float &$total): void
    {
        $bucket[$key] = ($bucket[$key] ?? 0.0) - $amount;
        $total -= $amount;
    }

    /** @param array<string, float> $bucket */
    private function addNamedBucket(array &$bucket, string $name, float $amount, float &$total): void
    {
        $bucket[$name] = ($bucket[$name] ?? 0.0) + $amount;
        $total += $amount;
    }

    /** @param array<string, float> $byModule */
    private function formatModuleList(array $byModule, string $prefix): array
    {
        $list = [];
        foreach ($byModule as $module => $sum) {
            if (abs($sum) < 0.00001) {
                continue;
            }
            $list[] = [
                'name' => $prefix.' '.$this->moduleLabel($module),
                'amount' => round(max(0, $sum), 2),
                'module' => $module,
            ];
        }

        usort($list, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        return $list;
    }

    /** @param array<string, float> $byName */
    private function formatNamedList(array $byName): array
    {
        $list = [];
        foreach ($byName as $name => $sum) {
            if ($sum <= 0) {
                continue;
            }
            $list[] = [
                'name' => $name,
                'amount' => round($sum, 2),
            ];
        }

        usort($list, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        return $list;
    }

    private function moduleLabel(string $module): string
    {
        return match ($module) {
            'flight' => 'وحدة الطيران',
            'hajj_umra' => 'الحج والعمرة',
            'visa' => 'التأشيرات',
            'tourism' => 'السياحة عامة',
            'bus' => 'وحدة الباص',
            'fawry' => 'فوري',
            'online' => 'الخدمات الإلكترونية',
            'wallet', 'wallet_transfer' => 'المحافظ والتحويلات',
            'office' => 'المكتب عامة',
            'service' => 'الخدمات',
            'general' => 'عام',
            default => 'أخرى ('.$module.')',
        };
    }

    /**
     * Per-day profit/cogs/expense breakdown filtered by a single module.
     *
     * Same classification engine as `report()` / `moduleBreakdown()`, but
     * buckets by `DATE(t.created_at)` instead of by module. Used by
     * DashboardService to render daily charts on per-module dashboards
     * (flight / bus / etc.) without falling back to the `model.profit`
     * column — keeping the GL as the single source of truth.
     *
     * @param  array{from_date?: string, to_date?: string}  $filters
     * @return list<array{date: string, income: float, cogs: float, expense: float, profit: float}>
     */
    public function getDailyProfitByModule(string $module, array $filters = []): array
    {
        $moduleKey = $this->normalizeModuleKey($module);
        $maps = $this->clearingAccounts->moduleAccountMaps();
        $incomeClearing = $maps['income'];
        $expenseClearing = $maps['expense'];
        $prepaidAccounts = $this->clearingAccounts->prepaidAccountIdMap();
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
                't.created_at',
                't.from_account_id',
                't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.name as to_account_name',
                'to_acc.module_type as to_account_module_type',
                'from_acc.module_type as from_account_module_type',
                'tr.converted_amount',
                'tr.from_currency',
                'tr.to_currency',
            ]);

        $this->applyDateFilters($query, $filters);
        $this->applyRelevanceFilter($query, $allClearingIds);
        $query->where('t.module', $moduleKey);

        $daily = [];

        foreach ($query->orderBy('t.id')->cursor() as $tx) {
            $classification = $this->classify($tx, $incomeClearing, $expenseClearing, $prepaidAccounts);
            if ($classification === null) {
                continue;
            }

            $amount = $this->resolveAmountEGP($tx);
            if ($amount <= 0) {
                continue;
            }

            $date = substr((string) $tx->created_at, 0, 10); // 'YYYY-MM-DD'
            if (! isset($daily[$date])) {
                $daily[$date] = ['date' => $date, 'income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0];
            }

            if ($classification === 'revenue') {
                $daily[$date]['income'] += $amount;
            } elseif ($classification === 'revenue_reversal' || $classification === 'refund') {
                $daily[$date]['income'] -= $amount;
            } elseif ($classification === 'cogs') {
                $daily[$date]['cogs'] += $amount;
            } elseif ($classification === 'cogs_reversal') {
                $daily[$date]['cogs'] -= $amount;
            } elseif ($classification === 'operating_expense') {
                $daily[$date]['expense'] += $amount;
            }
        }

        $result = [];
        foreach ($daily as $d) {
            $d['income'] = round(max(0, $d['income']), 2);
            $d['cogs'] = round(max(0, $d['cogs']), 2);
            $d['expense'] = round(max(0, $d['expense']), 2);
            $d['profit'] = round($d['income'] - $d['cogs'] - $d['expense'], 2);
            $result[] = $d;
        }

        usort($result, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $result;
    }

    /**
     * Per-entity profit breakdown filtered by module + related type.
     *
     * Used by DashboardService for per-carrier / per-system / per-bus-company
     * tables. Reuses the same classification engine, then resolves each
     * transaction's entity_id via a single batch lookup (1 polymorphic hop
     * for direct lookups, 2-hop for `bus_inventories`).
     *
     * @param  string       $relatedType   FQCN of the related model
     *                                    (e.g., FlightBooking::class, BusBooking::class)
     * @param  string       $entityColumn  The column to extract from the related model
     *                                    (e.g., 'flight_carrier_id', 'flight_system_id',
     *                                    'company_id' from bus_inventories)
     * @param  array|null   $joinChain     Optional 2-hop configuration:
     *                                    ['table' => 'bus_inventories',
     *                                     'fk'    => 'inventory_id']
     *                                    Use when the entity_id lives on a
     *                                    join table rather than on the related model.
     * @param  array{from_date?: string, to_date?: string}  $filters
     * @return list<array{entity_id: int, income: float, cogs: float, expense: float, profit: float}>
     */
    public function getProfitByEntity(
        string $module,
        string $relatedType,
        string $entityColumn,
        ?array $joinChain = null,
        array $filters = []
    ): array {
        $moduleKey = $this->normalizeModuleKey($module);
        $maps = $this->clearingAccounts->moduleAccountMaps();
        $incomeClearing = $maps['income'];
        $expenseClearing = $maps['expense'];
        $prepaidAccounts = $this->clearingAccounts->prepaidAccountIdMap();
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
                't.id', 't.type', 't.module', 't.amount', 't.related_type', 't.related_id',
                't.from_account_id', 't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.module_type as to_account_module_type',
                'from_acc.module_type as from_account_module_type',
                'tr.converted_amount', 'tr.from_currency', 'tr.to_currency',
            ]);

        $this->applyDateFilters($query, $filters);
        $this->applyRelevanceFilter($query, $allClearingIds);
        $query->where('t.module', $moduleKey)
            ->where('t.related_type', $relatedType)
            ->whereNotNull('t.related_id');

        // First pass: classify each transaction + group by related_id
        // (no entity_id yet — we batch-load that next).
        $byRelatedId = []; // related_id => [['classification' => X, 'amount' => Y], ...]
        $relatedIds = [];

        foreach ($query->orderBy('t.id')->cursor() as $tx) {
            $classification = $this->classify($tx, $incomeClearing, $expenseClearing, $prepaidAccounts);
            if ($classification === null) {
                continue;
            }
            $amount = $this->resolveAmountEGP($tx);
            if ($amount <= 0) {
                continue;
            }
            $relatedId = (int) ($tx->related_id ?? 0);
            if ($relatedId === 0) {
                continue;
            }
            $relatedIds[$relatedId] = true;
            $byRelatedId[$relatedId][] = ['classification' => $classification, 'amount' => $amount];
        }

        if ($relatedIds === []) {
            return [];
        }

        $relatedIds = array_keys($relatedIds);

        // Batch-load the entity_id for every related_id — single query,
        // 1 or 2 hops depending on whether $joinChain is provided.
        $entityIdMap = $this->batchLoadEntityIds(
            $relatedIds,
            $relatedType,
            $entityColumn,
            $joinChain
        );

        // Aggregate by entity_id
        $buckets = []; // entity_id => [income, cogs, expense]
        foreach ($byRelatedId as $relatedId => $entries) {
            $entityId = $entityIdMap[$relatedId] ?? null;
            if ($entityId === null) {
                continue;
            }
            if (! isset($buckets[$entityId])) {
                $buckets[$entityId] = ['income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0];
            }
            foreach ($entries as $e) {
                $c = $e['classification'];
                $a = $e['amount'];
                if ($c === 'revenue') {
                    $buckets[$entityId]['income'] += $a;
                } elseif ($c === 'revenue_reversal' || $c === 'refund') {
                    $buckets[$entityId]['income'] -= $a;
                } elseif ($c === 'cogs') {
                    $buckets[$entityId]['cogs'] += $a;
                } elseif ($c === 'cogs_reversal') {
                    $buckets[$entityId]['cogs'] -= $a;
                } elseif ($c === 'operating_expense') {
                    $buckets[$entityId]['expense'] += $a;
                }
            }
        }

        $result = [];
        foreach ($buckets as $id => $b) {
            $result[] = [
                'entity_id' => (int) $id,
                'income' => round(max(0, $b['income']), 2),
                'cogs' => round(max(0, $b['cogs']), 2),
                'expense' => round(max(0, $b['expense']), 2),
                'profit' => round($b['income'] - $b['cogs'] - $b['expense'], 2),
            ];
        }

        usort($result, fn (array $a, array $b) => $b['profit'] <=> $a['profit']);

        return $result;
    }

    /**
     * Batch-load entity_id for a list of related_ids. Single query — 1
     * hop if $joinChain is null, 2 hops otherwise. Avoids the N+1 trap
     * that would happen if we loaded the related model per transaction.
     *
     * @param  list<int>    $relatedIds
     * @return array<int, int>  related_id => entity_id
     */
    private function batchLoadEntityIds(
        array $relatedIds,
        string $relatedType,
        string $entityColumn,
        ?array $joinChain
    ): array {
        if ($relatedIds === []) {
            return [];
        }

        $relatedTable = $this->getTableForModel($relatedType);

        if ($joinChain === null) {
            $rows = DB::table($relatedTable)
                ->whereIn('id', $relatedIds)
                ->select('id', $entityColumn)
                ->get();

            return $rows->pluck($entityColumn, 'id')->all();
        }

        // 2-hop: relatedTable.fk → joinTable.entityColumn
        $joinTable = $joinChain['table'];
        $joinFk = $joinChain['fk'];

        $rows = DB::table($relatedTable)
            ->join($joinTable, "{$relatedTable}.{$joinFk}", '=', "{$joinTable}.id")
            ->whereIn("{$relatedTable}.id", $relatedIds)
            ->select("{$relatedTable}.id as related_id", "{$joinTable}.{$entityColumn} as entity_id")
            ->get();

        return $rows->pluck('entity_id', 'related_id')->all();
    }

    /**
     * Resolve a related-model FQCN to its underlying table name. Hard-coded
     * for the modules that publish to the GL with a known related_type
     * (FlightBooking, BusBooking, HajjUmraBooking, VisaBooking, etc.).
     *
     * @return string  table name
     */
    private function getTableForModel(string $fqcn): string
    {
        return match ($fqcn) {
            \App\Models\Flight\FlightBooking::class => 'flight_bookings',
            \App\Models\Bus\BusBooking::class => 'bus_bookings',
            \App\Models\HajjUmraBooking::class => 'hajj_umra_bookings',
            \App\Models\VisaBooking::class => 'visa_bookings',
            \App\Models\Fawry\FawryTransaction::class => 'fawry_transactions',
            \App\Models\Wallet\WalletTransaction::class => 'wallet_transactions',
            \App\Models\Online\OnlineTransaction::class => 'online_transactions',
            default => 'unknown',
        };
    }
}
