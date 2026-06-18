<?php

namespace App\Services\Reports;

use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Income statement (P&L) aligned with strict double-entry.
 * Reads live ledger data only — no HTTP/response caching.
 */
class ProfitLossReportService
{
    /** @var array<string, list<string>> */
    private const TOURISM_MODULES = ['flight', 'hajj_umra', 'visa'];

    /** @var array<string, list<string>> */
    private const OFFICE_MODULES = ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'wallets', 'general', 'service'];

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
            ->select([
                't.id',
                't.type',
                't.module',
                't.amount',
                't.from_account_id',
                't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.name as to_account_name',
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

            $amount = (float) $tx->amount;
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
            ->select([
                't.id',
                't.type',
                't.module',
                't.amount',
                't.from_account_id',
                't.to_account_id',
                'to_acc.type as to_account_type',
                'to_acc.name as to_account_name',
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

            $amount = (float) $tx->amount;
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
        $clearingIds = $this->clearingIdsForModules(
            $incomeClearing,
            $expenseClearing,
            $modules
        );

        $query->where(function (Builder $q) use ($modules, $clearingIds): void {
            $q->whereIn('t.module', $modules);
            if ($clearingIds !== []) {
                $q->orWhereIn('t.from_account_id', $clearingIds)
                    ->orWhereIn('t.to_account_id', $clearingIds);
            }
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
            'bus' => 'وحدة الباص',
            'fawry' => 'فوري',
            'online' => 'الخدمات الإلكترونية',
            'wallet', 'wallet_transfer' => 'المحافظ والتحويلات',
            'service' => 'الخدمات',
            'general' => 'عام',
            default => 'أخرى ('.$module.')',
        };
    }
}
