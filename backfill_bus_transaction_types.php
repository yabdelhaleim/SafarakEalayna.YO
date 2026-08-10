<?php
/**
 * One-shot backfill script — reclassifies historical bus module transactions.
 *
 * Why this exists
 * ----------------
 * TransactionService::recordJournalTransfer() used to hardcode
 * `type = TransactionType::Transfer` regardless of the semantic meaning
 * of the journal. As a result, every bus booking cost posting, customer
 * payment, cancellation reversal, etc. ended up labelled as a "transfer"
 * in the database, which broke the BusTreasury Vue page (everything
 * showed as a withdrawal under the old ternary, and after the frontend
 * fix everything shows as a transfer — still wrong).
 *
 * Scope
 * -----
 * Only touches rows where:
 *   - `module` = 'bus'
 *   - `type`   = 'transfer'  (the legacy hardcoded value)
 *
 * Rows already classified correctly (income/expense/refund/writeoff)
 * are skipped so this script is safe to re-run.
 *
 * Classification rule
 * -------------------
 *   1. Determine whether the journal is a "reversal" by scanning the
 *      `notes` for keywords: إلغاء / عكس / حذف / استرداد.
 *   2. Look at the destination account (to_account) name:
 *        - "إقفال إيرادات ..." → income (or refund if reversal)
 *        - "إقفال تكاليف ..."  → expense (always — reversals of a cost
 *          are also expenses, since the cost was originally recorded as
 *          an expense and unwinding it just reverses the entry)
 *   3. If to_account doesn't match a clearing account, check
 *      from_account name (covers the rare case of clearing→customer
 *      refund journals where the customer is the destination):
 *        - "إقفال إيرادات ..." on from → refund (income being reversed)
 *   4. Fallback by notes pattern:
 *        - "تحصيل"   → income
 *        - "تكلفة"   → expense (or refund if reversal)
 *        - "استرداد" → refund
 *        - "مصروف"   → expense
 *   5. Anything we can't classify confidently stays as 'transfer' and
 *      is reported in the summary so an operator can review.
 *
 * Usage
 * -----
 *   php backfill_bus_transaction_types.php                       # dry-run (default)
 *   php backfill_bus_transaction_types.php --apply               # actually update
 *   php backfill_bus_transaction_types.php --apply --module=bus,flight
 *   php backfill_bus_transaction_types.php --apply --full-backup # full DB dump first
 *
 * The script is idempotent: running it twice produces no second wave of
 * changes because it filters on `type='transfer'`.
 *
 * Backups
 * --------
 * With --apply, a SQL snapshot of the rows-about-to-change is always
 * written to storage/app/backups/. Add --full-backup to also dump the
 * whole database via mysqldump (using Laravel's DB credentials from
 * config/database.php — no shell access required).
 */

declare(strict_types=1);

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$argv = $_SERVER['argv'];
$apply = in_array('--apply', $argv, true);
$fullBackup = in_array('--full-backup', $argv, true);
$moduleArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--module=')) {
        $moduleArg = explode('=', $a, 2)[1];
    }
}
$modules = $moduleArg
    ? array_map('trim', explode(',', $moduleArg))
    : [TransactionModule::Bus->value];

if ($fullBackup && ! $apply) {
    fwrite(STDERR, "--full-backup only makes sense with --apply; ignoring.\n");
    $fullBackup = false;
}

echo "=== Bus transaction-type backfill ===\n";
echo 'Mode: '.($apply ? 'APPLY (writing to DB)' : 'DRY-RUN (no writes)')."\n";
echo 'Modules: '.implode(', ', $modules)."\n\n";

$stats = [
    'scanned'    => 0,
    'updated'    => 0,
    'skipped'    => 0,
    'unresolved' => 0,
    'by_target'  => [],
    'backup'     => null,
];

// In APPLY mode, take a SQL snapshot of the rows we're about to touch so
// the operator can roll back without needing DB credentials. The dump
// goes to storage/app/backups/ next to the application so it doesn't need
// a writable $HOME or /tmp.
if ($apply) {
    $backupDir = __DIR__.'/storage/app/backups';
    if (! is_dir($backupDir) && ! @mkdir($backupDir, 0755, true) && ! is_dir($backupDir)) {
        fwrite(STDERR, "FATAL: could not create backup directory: {$backupDir}\n");
        exit(1);
    }
    $stats['backup'] = [];

    // ── Optional full DB dump via mysqldump (using Laravel credentials) ──
    if ($fullBackup) {
        $fullBackupFile = $backupDir.'/full_db_pre_backfill_'.date('Ymd_His').'.sql';
        $cfg = config('database.connections.'.config('database.default'));

        if (($cfg['driver'] ?? null) === 'mysql' && ! empty($cfg['database'])) {
            $mysqldumpBin = trim((string) @shell_exec('command -v mysqldump 2>/dev/null'));

            if ($mysqldumpBin !== '') {
                // Pass password via env var so it doesn't leak in `ps`.
                $env = 'MYSQL_PWD='.escapeshellarg((string) ($cfg['password'] ?? ''));
                $cmd = sprintf(
                    '%s mysqldump --host=%s --port=%s --user=%s --single-transaction --routines --triggers %s > %s 2>/dev/null',
                    $env,
                    escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
                    escapeshellarg((string) ($cfg['port'] ?? '3306')),
                    escapeshellarg((string) ($cfg['username'] ?? 'root')),
                    escapeshellarg((string) $cfg['database']),
                    escapeshellarg($fullBackupFile)
                );

                $exit = 0;
                passthru($cmd, $exit);
                if ($exit === 0 && file_exists($fullBackupFile) && filesize($fullBackupFile) > 0) {
                    echo "Full DB backup: {$fullBackupFile} (".number_format(filesize($fullBackupFile))." bytes)\n";
                    $stats['backup'][] = $fullBackupFile;
                } else {
                    @unlink($fullBackupFile);
                    fwrite(STDERR, "WARN: mysqldump failed (exit {$exit}); falling back to row-level backup only.\n");
                }
            } else {
                fwrite(STDERR, "WARN: mysqldump binary not found on PATH; skipping full DB dump.\n");
                echo "      (Install mysql-client or run a manual dump if you need a full snapshot.)\n";
            }
        } else {
            fwrite(STDERR, "WARN: --full-backup only supports MySQL connections; current driver is '".($cfg['driver'] ?? 'unknown')."'.\n");
        }
    }

    // ── Row-level backup (always written when --apply is set) ────────
    $backupFile = $backupDir.'/bus_tx_type_pre_backfill_'.date('Ymd_His').'.sql';

    $rows = DB::table('transactions')
        ->whereIn('module', $modules)
        ->where('type', TransactionType::Transfer->value)
        ->get(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'notes']);

    $fh = fopen($backupFile, 'w');
    if ($fh === false) {
        fwrite(STDERR, "FATAL: could not open backup file for writing: {$backupFile}\n");
        exit(1);
    }
    fwrite($fh, "-- Bus transaction-type backfill snapshot\n");
    fwrite($fh, '-- Generated: '.date('c')."\n");
    fwrite($fh, '-- Rows: '.count($rows)."\n");
    fwrite($fh, "-- To restore: UPDATE transactions SET type = '<original>' WHERE id IN (...);\n\n");
    foreach ($rows as $r) {
        $notes = str_replace("'", "''", (string) $r->notes);
        fwrite($fh,
            "UPDATE transactions SET type='".$r->type."' WHERE id=".$r->id.
            "; -- notes='".$notes."'\n"
        );
    }
    fclose($fh);
    $stats['backup'][] = $backupFile;

    echo "Row-level backup: {$backupFile} (".count($rows)." rows)\n\n";
}

// Process in chunks to avoid loading the whole history into memory.
Transaction::query()
    ->whereIn('module', $modules)
    ->where('type', TransactionType::Transfer->value)
    ->orderBy('id')
    ->chunkById(500, function ($rows) use ($apply, &$stats) {
        foreach ($rows as $tx) {
            $stats['scanned']++;
            $newType = classify($tx);

            if ($newType === null) {
                $stats['unresolved']++;
                echo "  [unresolved] tx#{$tx->id} notes=\"{$tx->notes}\"\n";
                continue;
            }

            if ($newType === TransactionType::Transfer->value) {
                $stats['skipped']++;
                continue; // Genuinely a transfer — leave it alone.
            }

            $stats['updated']++;
            $stats['by_target'][$newType] = ($stats['by_target'][$newType] ?? 0) + 1;

            echo "  tx#{$tx->id}: transfer → {$newType} | notes=\"{$tx->notes}\"\n";

            if ($apply) {
                DB::transaction(function () use ($tx, $newType) {
                    Transaction::where('id', $tx->id)->update(['type' => $newType]);
                });
            }
        }
    });

echo "\n=== Summary ===\n";
echo "Scanned:    {$stats['scanned']}\n";
echo "Updated:    {$stats['updated']}\n";
echo "Skipped:    {$stats['skipped']} (already correct / genuine transfers)\n";
echo "Unresolved: {$stats['unresolved']}\n";
if (! empty($stats['by_target'])) {
    echo "By target type:\n";
    foreach ($stats['by_target'] as $type => $count) {
        echo "  - {$type}: {$count}\n";
    }
}
echo "\n";
echo $apply ? "DONE (writes applied).\n" : "DRY-RUN complete. Re-run with --apply to commit.\n";

/**
 * Returns the new TransactionType value, or null if the row can't be
 * classified confidently.
 *
 * Order of resolution (most specific → least specific):
 *   1. Notes-pattern keywords — distinguishes "reversal" vs "original"
 *      without ambiguity (e.g. "عكس مديونية" only appears on refunds,
 *      "تسجيل مديونية" only on sales).
 *   2. Clearing-account direction — handles rows whose notes are blank
 *      or generic (E2E test seeds, inventory adjustments, etc.).
 *   3. Returns null when nothing matches so the row is reported as
 *      "unresolved" rather than silently reclassified.
 */
function classify(Transaction $tx): ?string
{
    $notes = (string) ($tx->notes ?? '');

    // Eager-load the two accounts if not already attached.
    $tx->loadMissing(['fromAccount:id,name', 'toAccount:id,name']);
    $toName   = (string) ($tx->toAccount?->name ?? '');
    $fromName = (string) ($tx->fromAccount?->name ?? '');

    // ── 1. Notes-based classification (most reliable) ──────────────
    // Customer-debt reversals (cancel/delete): "عكس مديونية ...", "حذف مديونية ...", "إلغاء مديونية ..."
    if (preg_match('/(عكس مديونية|حذف مديونية|إلغاء مديونية)/u', $notes)) {
        return TransactionType::Refund->value;
    }
    // Cost reversals (cancel/delete booking): "عكس تكلفة ...", "حذف تكلفة ...", "إلغاء تكلفة ..."
    if (preg_match('/(عكس تكلفة|حذف تكلفة|إلغاء تكلفة)/u', $notes)) {
        return TransactionType::Expense->value;
    }
    // Cash refund to customer (recordExpense path): "استرداد حجز باص ..."
    if (preg_match('/استرداد/u', $notes)) {
        return TransactionType::Refund->value;
    }
    // Paying off supplier debt at the office: "تسديد دين شركة باصات ..."
    if (preg_match('/تسديد دين شركة/u', $notes)) {
        return TransactionType::Expense->value;
    }
    // Customer payment to office: "تحصيل دفعة حجز باص ..."
    if (preg_match('/تحصيل/u', $notes)) {
        return TransactionType::Income->value;
    }
    // Booking cost posting: "تكلفة حجز باص ..."
    if (preg_match('/تكلفة/u', $notes)) {
        return TransactionType::Expense->value;
    }
    // Generic expense word: "مصروف ..."
    if (preg_match('/مصروف/u', $notes)) {
        return TransactionType::Expense->value;
    }
    // Sale-side debt recognition (recordSaleToCustomer): "حجز تذكرة باص للعميل ..."
    if (preg_match('/حجز تذكرة باص للعميل/u', $notes)) {
        return TransactionType::Income->value;
    }
    // Reverse of a customer payment: "عكس: تحصيل دفعة ..."
    if (preg_match('/عكس.*تحصيل/u', $notes)) {
        return TransactionType::Refund->value;
    }

    // ── 2. Clearing-account direction fallback ──────────────────────
    // The clearing accounts are seeded with Arabic names that include
    // "إقفال إيرادات" (income) or "إقفال تكاليف" (expense). Direction
    // matters here: a journal FROM income_clearing TO customer_account is
    // an income recognition (sale), whereas the reverse TO income_clearing
    // is a refund.
    if (str_contains($fromName, 'إقفال إيرادات') && ! str_contains($toName, 'إقفال إيرادات')) {
        // income_clearing → elsewhere → income (e.g. recordSaleToCustomer)
        return TransactionType::Income->value;
    }
    if (str_contains($toName, 'إقفال إيرادات') && ! str_contains($fromName, 'إقفال إيرادات')) {
        // elsewhere → income_clearing → refund (e.g. cancel customer debt)
        return TransactionType::Refund->value;
    }
    if (str_contains($toName, 'إقفال تكاليف') || str_contains($toName, 'إقفال تكلفة')) {
        // company → expense_clearing → expense
        return TransactionType::Expense->value;
    }
    if (str_contains($fromName, 'إقفال تكاليف') || str_contains($fromName, 'إقفال تكلفة')) {
        // expense_clearing → company → expense reversal
        return TransactionType::Expense->value;
    }

    return null; // Couldn't classify — leave as transfer and report.
}
