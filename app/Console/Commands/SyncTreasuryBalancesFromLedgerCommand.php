<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Signature(
    signature: 'accounts:sync-treasury-balances
        {--dry-run : Show changes without applying}
        {--force : Apply without confirmation}
        {--account= : Sync a single account id only}',
)]
class SyncTreasuryBalancesFromLedgerCommand extends Command
{
    protected $description = 'مزامنة أرصدة حسابات السيولة (خزائن/بنوك/محافظ) مع صافي قيود الدفتر';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyId = $this->option('account') ? (int) $this->option('account') : null;
        $tolerance = (float) config('accounting.reconciliation.balance_vs_entries_tolerance', 0.05);

        $ledgerNet = AccountEntry::query()
            ->selectRaw('account_id, SUM(COALESCE(credit, 0) - COALESCE(debit, 0)) AS net')
            ->groupBy('account_id')
            ->pluck('net', 'account_id')
            ->map(fn ($v) => round((float) $v, 2));

        $query = Account::query()->active();
        AccountModuleDivision::applyLiquidityTreasuryScope($query);

        if ($onlyId) {
            $query->where('id', $onlyId);
        }

        $accounts = $query->get();
        $changes = [];

        foreach ($accounts as $account) {
            $lastAfter = AccountEntry::query()
                ->where('account_id', $account->id)
                ->whereNotNull('balance_after')
                ->orderByDesc('id')
                ->value('balance_after');

            $ledger = $lastAfter !== null
                ? round((float) $lastAfter, 2)
                : round((float) ($ledgerNet[$account->id] ?? 0), 2);

            $stored = round((float) $account->balance, 2);
            $diff = round($stored - $ledger, 2);

            if (abs($diff) <= $tolerance) {
                continue;
            }

            $changes[] = [
                'account' => $account,
                'stored' => $stored,
                'ledger' => $ledger,
                'diff' => $diff,
            ];
        }

        if ($changes === []) {
            $this->info('جميع حسابات السيولة متطابقة مع الدفتر.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'الحساب', 'المخزّن', 'الدفتر', 'الفرق'],
            collect($changes)->map(fn ($c) => [
                $c['account']->id,
                $c['account']->name,
                $c['stored'],
                $c['ledger'],
                $c['diff'],
            ])->all()
        );

        if ($dryRun) {
            $this->warn('وضع المعاينة فقط — لم يُطبَّق أي تعديل.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('تطبيق مزامنة الأرصدة للحسابات أعلاه؟', true)) {
            return self::SUCCESS;
        }

        $userId = Auth::id() ?? 1;

        LedgerBalanceMutationGuard::run(function () use ($changes, $userId): void {
            DB::transaction(function () use ($changes, $userId): void {
                foreach ($changes as $change) {
                    /** @var Account $account */
                    $account = Account::query()->lockForUpdate()->findOrFail($change['account']->id);
                    $before = (float) $account->balance;
                    $account->balance = $change['ledger'];
                    $account->save();

                    AuditLog::create([
                        'user_id' => $userId,
                        'action' => 'sync_balance_from_ledger',
                        'model_type' => Account::class,
                        'model_id' => $account->id,
                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'accounts:sync-treasury-balances',
                        'old_values' => ['balance' => $before],
                        'new_values' => ['balance' => $change['ledger']],
                        'notes' => 'مزامنة رصيد الخزينة مع صافي قيود الدفتر',
                    ]);

                    $this->line("✓ #{$account->id} {$account->name}: {$before} → {$change['ledger']}");
                }
            });
        });

        $this->info('تمت المزامنة بنجاح.');

        return self::SUCCESS;
    }
}
