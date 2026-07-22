<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Enums\TransactionType;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ───────────────────────────────────────────────────────────────────
 * Migration: Fix buggy transfer-direction entries recorded before the
 *           2026-07-22 reversal-direction fix in TransactionService.
 *
 * RUN:  php artisan ledger:fix-reversal-direction
 *       php artisan ledger:fix-reversal-direction --dry-run
 *
 * PROBLEM:
 *   Older `recordTransfer()` and `recordJournalTransfer()` writes
 *   produced ledger entries in the WRONG direction:
 *
 *     Source account (losing money)    → credit amount, debit 0
 *     Destination account (gaining)   → debit amount, credit 0
 *
 *   This is the OPPOSITE of the project's documented invariant
 *   (`balance = SUM(credit) - SUM(debit)`).
 *
 * HOW THIS MIGRATION DETECTS BAD ENTRIES
 *   For each transfer-type Transaction (not opening entries, not
 *   reversals), we look at the `from_account_id` and `to_account_id`
 *   fields on the Transaction row.  Then we examine the actual
 *   AccountEntry rows for that transaction:
 *
 *     - The from-account should have a `debit = amount, credit = 0` entry
 *       (per project invariant)
 *     - The to-account   should have a `credit = amount, debit = 0` entry
 *
 *   If any entry is wrong-direction (credit on from, debit on to),
 *   we SWAP its debit and credit columns so the invariant holds.
 *
 * SAFETY:
 *   - Opening entries (transaction_id IS NULL) are NEVER touched.
 *   - Reversal entries (notes starting with "عكس:") are NEVER touched —
 *     they always pair correctly with their original (both wrong or both
 *     right), so swapping one without the other would re-introduce the
 *     imbalance.
 *   - Heavily logged; produces a JSON report for audit.
 *
 * IDEMPOTENT:  Running twice produces no further changes — the second
 *              run will detect correctly-directioned entries and skip them.
 * ───────────────────────────────────────────────────────────────────
 */
#[Signature(
    signature: 'ledger:fix-reversal-direction
        {--dry-run : Preview the fix without writing to the database}
        {--force : Apply without confirmation}
        {--report : Emit JSON report to stdout}
        {--limit= : Limit how many transactions to process (for staged rollout)}',
)]
class FixReversalDirectionCommand extends Command
{
    protected $description = 'Migration: إصلاح اتجاه قيود التحويل القديمة المسجلة في الاتجاه المعاكس لـ invariant المشروع';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');
        $report = (bool) $this->option('report');
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // 1) Gather candidate transactions:
        //    - TYPE in (Transfer, Income, Expense)   ← ledger movements (not Internal/Adjustment)
        //    - HAS both from_account_id and to_account_id
        //    - SKIP reversal pairs: detect by looking at notes on the entry.
        //      We pick transactions whose EXISTS at least one entry whose
        //      notes is NOT a "عكس:" reversal note.
        $txQuery = Transaction::query()
            ->whereIn('type', [
                TransactionType::Transfer->value,
                TransactionType::Income->value,
                TransactionType::Expense->value,
            ])
            ->whereNotNull('from_account_id')
            ->whereNotNull('to_account_id');

        if ($limit !== null && $limit > 0) {
            $txQuery->limit($limit);
        }

        $candidates = $txQuery->orderBy('id')->get();

        $this->info(sprintf(
            'Found %d candidate transactions to scan%s.',
            $candidates->count(),
            $dryRun ? ' (DRY RUN)' : ''
        ));

        $stats = [
            'scanned' => 0,
            'fixable_transactions' => 0,
            'entries_swapped' => 0,
            'alerts' => [],
            'fixed_transactions' => [],
        ];

        foreach ($candidates as $tx) {
            $stats['scanned']++;
            $entries = AccountEntry::query()
                ->where('transaction_id', $tx->id)
                ->orderBy('id')
                ->get();

            if ($entries->isEmpty()) {
                continue; // nothing to fix
            }

            // Skip transactions that contain ANY reversal entry (notes starts with 'عكس:').
            // Reversal pairs must be touched together; if the original is wrong,
            // so is the reversal, but swapping them as a pair would zero the impact
            // — leaving a manual review marker is safer.
            $hasReversal = $entries->contains(fn ($e) => str_starts_with((string) $e->notes, 'عكس:'));
            if ($hasReversal) {
                $stats['alerts'][] = sprintf(
                    'tx=%d contains reversal entries — skip (manual review).',
                    $tx->id
                );
                continue;
            }

            // Determine expected direction
            $amount = (float) ($tx->amount ?? 0);
            if ($amount === 0.0) {
                continue; // zero-amount transactions are not the bug
            }

            $fromId = (int) $tx->from_account_id;
            $toId   = (int) $tx->to_account_id;

            $needsFix = false;
            foreach ($entries as $e) {
                $aid = (int) $e->account_id;
                $entryAmount = (float) max((float) $e->debit, (float) $e->credit);

                if ($aid === $fromId) {
                    // Source account: should be DEBIT (project convention)
                    if ((float) $e->credit > 0 && (float) $e->debit === 0.0) {
                        $needsFix = true;
                        break;
                    }
                } elseif ($aid === $toId) {
                    // Destination account: should be CREDIT
                    if ((float) $e->debit > 0 && (float) $e->credit === 0.0) {
                        $needsFix = true;
                        break;
                    }
                }
            }

            if (! $needsFix) {
                continue; // entries already match the project's convention
            }

            // We've found a buggy transaction.
            $stats['fixable_transactions']++;

            // SAFETY GUARD: print what we'd do
            $typeStr = $tx->type instanceof TransactionType ? $tx->type->value : (string) $tx->type;
            $this->line(sprintf(
                '  • tx=%d type=%s amount=%s from=%d to=%d  → %s',
                $tx->id,
                $typeStr,
                number_format($amount, 2),
                $fromId,
                $toId,
                $dryRun ? 'WOULD swap debit↔credit on its entries' : 'swapping debit↔credit on its entries'
            ));

            if ($dryRun) {
                continue;
            }

            // Apply the fix
            $applied = false;
            DB::transaction(function () use ($entries, &$applied, &$stats, $tx) {
                foreach ($entries as $e) {
                    DB::table('account_entries')
                        ->where('id', $e->id)
                        ->update([
                            'debit' => (float) $e->credit,
                            'credit' => (float) $e->debit,
                        ]);
                    $applied = true;
                    $stats['entries_swapped']++;
                }
            });

            if ($applied) {
                $stats['fixed_transactions'][] = $tx->id;
            }
        }

        // 3) Re-verify the invariant after the fix
        $this->newLine();
        $this->info('── Post-fix verification ──');
        $drift = $this->countBalanceDrift();
        $this->line(sprintf(
            'Accounts with balance vs SUM(credit-debit) drift: %d',
            $drift
        ));

        if ($drift === 0) {
            $this->info('✓ All accounts now satisfy the invariant.');
        } else {
            $this->warn('⚠ Some accounts still have drift — review manually.');
        }

        if ($report) {
            $this->newLine();
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('── SUMMARY ──');
        $this->table(['المؤشر', 'القيمة'], [
            ['Transactions scanned', $stats['scanned']],
            ['Fixable transactions found', $stats['fixable_transactions']],
            ['Entries swapped', $stats['entries_swapped']],
            ['Reverted-back-to-invariance accounts (post-fix drift)', $drift],
            ['Mode', $dryRun ? 'DRY RUN' : 'APPLIED'],
        ]);

        if (! empty($stats['alerts'])) {
            $this->warn('── Alerts ──');
            foreach (array_slice($stats['alerts'], 0, 20) as $a) {
                $this->line('  ' . $a);
            }
            if (count($stats['alerts']) > 20) {
                $this->comment('(... عرض 20 تنبيه فقط)');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Count how many accounts have `balance != SUM(credit - debit)` on entries.
     * Used by the post-fix verification.
     */
    private function countBalanceDrift(): int
    {
        $rows = DB::select("
            SELECT a.id, a.name, a.type, a.currency, a.balance,
                   COALESCE(SUM(e.credit), 0) - COALESCE(SUM(e.debit), 0) AS net
            FROM accounts a
            LEFT JOIN account_entries e ON e.account_id = a.id
            GROUP BY a.id
            HAVING ABS(a.balance - net) > 0.01
        ");
        return count($rows);
    }
}
