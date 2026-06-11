<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Flight\FlightBookingService;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot repairs for legacy ledger inconsistencies (single-leg postings, phantom balances).
 */
class LedgerRepairService
{
    public function __construct(
        protected LedgerClearingAccounts $clearingAccounts,
    ) {}

    /**
     * @return array{backfilled:int,skipped:int,errors:array<int,string>}
     */
    public function backfillLegacySingleLegPostings(?int $limit = null): array
    {
        $stats = ['backfilled' => 0, 'skipped' => 0, 'errors' => []];

        $singleLegIds = AccountEntry::query()
            ->whereNotNull('transaction_id')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('transaction_id');

        if ($limit !== null) {
            $singleLegIds = $singleLegIds->take($limit);
        }

        foreach ($singleLegIds as $transactionId) {
            try {
                $applied = LedgerBalanceMutationGuard::run(
                    fn () => DB::transaction(fn () => $this->backfillSingleLegTransaction((int) $transactionId))
                );

                if ($applied) {
                    $stats['backfilled']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors'][(int) $transactionId] = $e->getMessage();
                Log::warning('ledger_backfill_failed', [
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @return array{synced:int,zeroed:int,skipped:int}
     */
    public function syncCustomerBalancesFromLedger(int $actorUserId = 1): array
    {
        $stats = ['synced' => 0, 'zeroed' => 0, 'skipped' => 0];

        $customerAccounts = Account::query()
            ->where(function ($q) {
                $q->where('type', AccountType::Customer->value)
                    ->orWhere('name', 'like', '%حساب العميل%')
                    ->orWhere('name', 'like', '%ذممة عميل%');
            })
            ->get();

        LedgerBalanceMutationGuard::run(function () use ($customerAccounts, $actorUserId, &$stats): void {
            DB::transaction(function () use ($customerAccounts, $actorUserId, &$stats): void {
                foreach ($customerAccounts as $account) {
                    $target = $this->resolveBalanceFromEntries($account);

                    if (abs((float) $account->balance - $target) <= 0.02) {
                        $stats['skipped']++;

                        continue;
                    }

                    $before = (float) $account->balance;
                    $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
                    $locked->balance = $target;
                    $locked->save();

                    AuditLog::create([
                        'user_id' => $actorUserId,
                        'action' => 'sync_customer_balance_from_ledger',
                        'model_type' => Account::class,
                        'model_id' => $locked->id,
                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'ledger:repair',
                        'old_values' => ['balance' => $before],
                        'new_values' => ['balance' => $target],
                        'notes' => 'مزامنة رصيد ذممة العميل مع آخر قيد في الدفتر',
                    ]);

                    if ($target == 0.0 && $before != 0.0) {
                        $stats['zeroed']++;
                    } else {
                        $stats['synced']++;
                    }
                }
            });
        });

        return $stats;
    }

    /**
     * @return array{fixed:int}
     */
    public function fixMisclassifiedTreasuryModules(): array
    {
        $map = [
            'Flight Cashbox' => ['module_type' => 'flights', 'module' => 'flight'],
            'Bus Cashbox' => ['module_type' => 'bus', 'module' => 'bus'],
            'Main Cashbox' => ['module_type' => 'office', 'module' => 'general'],
        ];

        $fixed = 0;

        foreach ($map as $name => $attrs) {
            $count = Account::query()
                ->where('name', $name)
                ->where(function ($q) use ($attrs) {
                    $q->where('module_type', '!=', $attrs['module_type'])
                        ->orWhereNull('module_type')
                        ->orWhere('module', '!=', $attrs['module'])
                        ->orWhereNull('module');
                })
                ->update($attrs);

            $fixed += $count;
        }

        return ['fixed' => $fixed];
    }

    /**
     * @return array{backfill:array<string,mixed>,customers:array<string,mixed>,modules:array<string,mixed>,reconciliation:array<string,mixed>}
     */
    public function runFullRepair(?int $backfillLimit = null, int $actorUserId = 1): array
    {
        $reconcile = app(LedgerReconciliationService::class);

        $before = $reconcile->runPostingAndBalanceIntegrityScan();

        $backfill = $this->backfillLegacySingleLegPostings($backfillLimit);
        $flightSales = app(FlightBookingService::class)->backfillMissingCustomerSaleLedgers($backfillLimit);
        $customers = $this->syncCustomerBalancesFromLedger($actorUserId);
        $modules = $this->fixMisclassifiedTreasuryModules();

        $liquidityIds = Account::query()
            ->tap(fn ($q) => AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->pluck('id')
            ->all();
        $chains = $this->rebuildBrokenBalanceAfterChains($liquidityIds);

        $after = $reconcile->runPostingAndBalanceIntegrityScan();

        return [
            'backfill' => $backfill,
            'flight_sales' => $flightSales,
            'customers' => $customers,
            'modules' => $modules,
            'balance_chains' => $chains,
            'before' => [
                'global_delta' => $before['global_totals_delta'],
                'treasury_drift' => $before['treasury_liquidity_drift_count'] ?? 0,
                'customer_drift' => $before['customer_drift_count'] ?? $before['accounts_with_balance_drift'],
                'legacy_single_leg' => $before['legacy_single_leg_transactions'] ?? 0,
            ],
            'after' => [
                'global_delta' => $after['global_totals_delta'],
                'global_ok' => $after['global_totals_ok'],
                'treasury_drift' => $after['treasury_liquidity_drift_count'] ?? 0,
                'customer_drift' => $after['customer_drift_count'] ?? 0,
                'legacy_single_leg' => $after['legacy_single_leg_transactions'] ?? 0,
            ],
        ];
    }

    protected function backfillSingleLegTransaction(int $transactionId): bool
    {
        $transaction = Transaction::query()->lockForUpdate()->find($transactionId);
        if (! $transaction) {
            return false;
        }

        if ($transaction->entries()->count() !== 1) {
            return false;
        }

        /** @var AccountEntry $entry */
        $entry = $transaction->entries()->first();
        $amount = (float) $transaction->amount;
        if ($amount <= 0) {
            return false;
        }

        $type = $transaction->type instanceof TransactionType
            ? $transaction->type
            : TransactionType::tryFrom((string) $transaction->type);

        if ($type === null) {
            return false;
        }

        $module = $transaction->module instanceof \BackedEnum
            ? $transaction->module->value
            : (string) ($transaction->module ?? 'general');

        if ($type === TransactionType::Income) {
            $clearingId = $module === 'flight'
                ? $this->clearingAccounts->incomeContraIdForFlightBooking()
                : $this->clearingAccounts->incomeContraIdForModule($module);

            if ($clearingId === null) {
                return false;
            }

            $clearing = Account::query()->lockForUpdate()->findOrFail($clearingId);
            $clearing->balance = (float) $clearing->balance - $amount;
            $clearing->save();

            AccountEntry::create([
                'account_id' => $clearing->id,
                'transaction_id' => $transaction->id,
                'debit' => $amount,
                'credit' => 0.00,
                'balance_after' => $clearing->balance,
            ]);

            return true;
        }

        if ($type === TransactionType::Expense) {
            $clearingId = $this->clearingAccounts->expenseContraIdForModule($module);
            if ($clearingId === null) {
                return false;
            }

            $clearing = Account::query()->lockForUpdate()->findOrFail($clearingId);
            $clearing->balance = (float) $clearing->balance + $amount;
            $clearing->save();

            AccountEntry::create([
                'account_id' => $clearing->id,
                'transaction_id' => $transaction->id,
                'debit' => 0.00,
                'credit' => $amount,
                'balance_after' => $clearing->balance,
            ]);

            return true;
        }

        return false;
    }

    /**
     * @return array{accounts_fixed:int,entries_fixed:int}
     */
    public function rebuildBrokenBalanceAfterChains(?array $onlyAccountIds = null): array
    {
        $stats = ['accounts_fixed' => 0, 'entries_fixed' => 0];

        LedgerBalanceMutationGuard::run(function () use (&$stats, $onlyAccountIds): void {
            DB::transaction(function () use (&$stats, $onlyAccountIds): void {
                $accountIds = $onlyAccountIds ?? AccountEntry::query()
                    ->distinct()
                    ->pluck('account_id')
                    ->all();

                foreach ($accountIds as $accountId) {
                    $entries = AccountEntry::query()
                        ->where('account_id', $accountId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($entries->isEmpty()) {
                        continue;
                    }

                    $running = 0.0;
                    $fixed = 0;

                    foreach ($entries as $entry) {
                        $running = round($running + (float) $entry->credit - (float) $entry->debit, 2);
                        $storedAfter = $entry->balance_after !== null ? round((float) $entry->balance_after, 2) : null;

                        if ($storedAfter === null || abs($storedAfter - $running) > 0.02) {
                            $entry->balance_after = $running;
                            $entry->save();
                            $fixed++;
                        }
                    }

                    if ($fixed > 0) {
                        $account = Account::query()->lockForUpdate()->find($accountId);
                        if ($account && abs((float) $account->balance - $running) > 0.02) {
                            $account->balance = $running;
                            $account->save();
                        }
                        $stats['accounts_fixed']++;
                        $stats['entries_fixed'] += $fixed;
                    }
                }
            });
        });

        return $stats;
    }

    protected function resolveBalanceFromEntries(Account $account): float
    {
        $last = AccountEntry::query()
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->first();

        if ($last !== null && $last->balance_after !== null) {
            return round((float) $last->balance_after, 2);
        }

        if ($last !== null) {
            $net = AccountEntry::query()
                ->where('account_id', $account->id)
                ->selectRaw('SUM(COALESCE(credit,0) - COALESCE(debit,0)) as net')
                ->value('net');

            return round((float) ($net ?? 0), 2);
        }

        return 0.0;
    }
}
