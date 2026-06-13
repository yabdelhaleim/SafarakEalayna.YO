<?php

namespace App\Services\Reports;

use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Fawry\FawryMachine;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * سجل عمليات الشحن (أنظمة/ناقلين/فوري) والتحويلات بين موديولات الخزينة.
 */
class FinanceOperationsReportService
{
    public function __construct(
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}

    /**
     * @param  array{
     *   from_date?: string,
     *   to_date?: string,
     *   operation_type?: string,
     *   module?: string,
     *   search?: string,
     *   per_page?: int,
     *   page?: int
     * }  $filters
     */
    public function report(array $filters): array
    {
        $prepaidMap = $this->clearingAccounts->prepaidAccountIdMap();
        $prepaidIds = array_keys($prepaidMap);
        $maps = $this->clearingAccounts->moduleAccountMaps();
        $expenseClearingIds = array_keys($maps['expense']);

        $relatedTypes = [
            FlightSystem::class,
            FlightCarrier::class,
            FawryMachine::class,
        ];

        $baseQuery = Transaction::query()
            ->with([
                'fromAccount:id,name,type,currency,module_type',
                'toAccount:id,name,type,currency,module_type',
                'createdBy:id,name',
            ])
            ->where(function (Builder $outer) use ($prepaidIds, $relatedTypes): void {
                $outer->whereIn('related_type', $relatedTypes);

                if ($prepaidIds !== []) {
                    $outer->orWhere(function (Builder $prepaid) use ($prepaidIds): void {
                        $prepaid->where('type', 'transfer')
                            ->where(function (Builder $legs) use ($prepaidIds): void {
                                $legs->whereIn('from_account_id', $prepaidIds)
                                    ->orWhereIn('to_account_id', $prepaidIds);
                            });
                    });
                }

                $outer->orWhere(function (Builder $moduleTransfer): void {
                    $moduleTransfer->where('type', 'transfer')
                        ->whereHas('fromAccount', function (Builder $from): void {
                            AccountModuleDivision::applyLiquidityTreasuryScope(
                                $from->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
                            );
                        })
                        ->whereHas('toAccount', function (Builder $to): void {
                            AccountModuleDivision::applyLiquidityTreasuryScope(
                                $to->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
                            );
                        })
                        ->whereColumn('from_account_id', '!=', 'to_account_id');
                });
            });

        if (! empty($filters['from_date'])) {
            $baseQuery->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $baseQuery->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (! empty($filters['module']) && $filters['module'] !== 'all') {
            $baseQuery->where('module', $filters['module']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $baseQuery->where(function (Builder $q) use ($term): void {
                $q->where('notes', 'like', $term)
                    ->orWhere('id', 'like', ltrim($term, '%'));
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        $page = max(1, (int) ($filters['page'] ?? 1));

        /** @var LengthAwarePaginator<int, Transaction> $paginator */
        $paginator = (clone $baseQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $tx) {
            $operationType = $this->resolveOperationType($tx, $prepaidMap, $expenseClearingIds);

            if (! empty($filters['operation_type']) && $filters['operation_type'] !== 'all') {
                if ($operationType !== $filters['operation_type']) {
                    continue;
                }
            }

            $items[] = $this->formatRow($tx, $operationType, $prepaidMap);
        }

        $allForSummary = (clone $baseQuery)->orderBy('id')->get();
        $fullSummary = [
            'total_recharges' => 0.0,
            'total_cogs' => 0.0,
            'total_module_transfers' => 0.0,
            'count_recharges' => 0,
            'count_cogs' => 0,
            'count_module_transfers' => 0,
            'total_operations' => $allForSummary->count(),
        ];
        foreach ($allForSummary as $tx) {
            $type = $this->resolveOperationType($tx, $prepaidMap, $expenseClearingIds);
            if (! empty($filters['operation_type']) && $filters['operation_type'] !== 'all' && $type !== $filters['operation_type']) {
                continue;
            }
            $amt = (float) $tx->amount;
            if ($type === 'recharge') {
                $fullSummary['total_recharges'] += $amt;
                $fullSummary['count_recharges']++;
            } elseif ($type === 'cogs') {
                $fullSummary['total_cogs'] += $amt;
                $fullSummary['count_cogs']++;
            } elseif ($type === 'module_transfer') {
                $fullSummary['total_module_transfers'] += $amt;
                $fullSummary['count_module_transfers']++;
            }
        }

        return [
            'summary' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $fullSummary),
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'period' => [
                'from' => $filters['from_date'] ?? null,
                'to' => $filters['to_date'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $prepaidMap
     * @param  list<int>  $expenseClearingIds
     */
    private function resolveOperationType(Transaction $tx, array $prepaidMap, array $expenseClearingIds): string
    {
        $fromId = (int) ($tx->from_account_id ?? 0);
        $toId = (int) ($tx->to_account_id ?? 0);
        $fromPrepaid = isset($prepaidMap[$fromId]);
        $toPrepaid = isset($prepaidMap[$toId]);
        $toExpenseClearing = in_array($toId, $expenseClearingIds, true);

        if ($toPrepaid && ! $fromPrepaid) {
            return 'recharge';
        }

        if ($fromPrepaid && $toExpenseClearing) {
            return 'cogs';
        }

        if ($tx->type === 'transfer' && $tx->fromAccount && $tx->toAccount) {
            $fromLiq = in_array($tx->fromAccount->type, AccountModuleDivision::LIQUIDITY_TYPES, true);
            $toLiq = in_array($tx->toAccount->type, AccountModuleDivision::LIQUIDITY_TYPES, true);
            if ($fromLiq && $toLiq && $fromId !== $toId) {
                $fromModule = (string) ($tx->fromAccount->module_type ?? '');
                $toModule = (string) ($tx->toAccount->module_type ?? '');
                if ($fromModule !== $toModule) {
                    return 'module_transfer';
                }
            }
        }

        if (in_array($tx->related_type, [FlightSystem::class, FlightCarrier::class, FawryMachine::class], true)) {
            return 'recharge';
        }

        return 'other';
    }

    /**
     * @param  array<int, string>  $prepaidMap
     * @return array<string, mixed>
     */
    private function formatRow(Transaction $tx, string $operationType, array $prepaidMap): array
    {
        $fromId = (int) ($tx->from_account_id ?? 0);
        $toId = (int) ($tx->to_account_id ?? 0);

        return [
            'id' => $tx->id,
            'date' => $tx->created_at?->toIso8601String(),
            'type' => $tx->type,
            'operation_type' => $operationType,
            'operation_label' => $this->operationLabel($operationType),
            'module' => $tx->module,
            'amount' => round((float) $tx->amount, 2),
            'notes' => $tx->notes,
            'related_type' => $tx->related_type,
            'related_id' => $tx->related_id,
            'prepaid_key' => $prepaidMap[$toId] ?? $prepaidMap[$fromId] ?? null,
            'from_account' => $tx->fromAccount ? [
                'id' => $tx->fromAccount->id,
                'name' => $tx->fromAccount->name,
                'type' => $tx->fromAccount->type,
                'currency' => $tx->fromAccount->currency,
                'module_type' => $tx->fromAccount->module_type,
            ] : null,
            'to_account' => $tx->toAccount ? [
                'id' => $tx->toAccount->id,
                'name' => $tx->toAccount->name,
                'type' => $tx->toAccount->type,
                'currency' => $tx->toAccount->currency,
                'module_type' => $tx->toAccount->module_type,
            ] : null,
            'created_by' => $tx->createdBy ? [
                'id' => $tx->createdBy->id,
                'name' => $tx->createdBy->name,
            ] : null,
        ];
    }

    private function operationLabel(string $type): string
    {
        return match ($type) {
            'recharge' => 'شحن رصيد',
            'cogs' => 'استهلاك تكلفة (COGS)',
            'module_transfer' => 'تحويل بين موديولات',
            default => 'عملية أخرى',
        };
    }
}
