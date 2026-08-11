<?php
/**
 * APPLY — Backfill `customers.module_type` from existing bookings.
 *
 * Companion to scripts/dryrun_backfill_customers_module_type.php.
 * The dry-run script is the place to *review* the proposal; this script
 * is the place to *execute* it.
 *
 * Safety guards (in order)
 * ------------------------
 *   1. Default mode = preview (no writes). Must pass `--apply`.
 *   2. With --apply, the script asks the operator to TYPE the literal
 *      confirmation string "YES-APPLY-PROD" before any write happens.
 *      This is different from `--yes`: the operator has to actively
 *      type the string, so it can't be triggered by a stray shell pipe.
 *   3. Before the first UPDATE, a backup table is created:
 *        customers_module_type_backup_YYYYMMDD_HHMMSS
 *      that holds (id, full_name, old_module_type, backfilled_at).
 *      This table is the one-stop rollback source.
 *   4. A rollback SQL file is also written to storage/app/backups/
 *      so an operator can run it without going through the app.
 *   5. The actual UPDATE is wrapped in DB::transaction. If anything
 *      throws mid-flight, the whole thing rolls back.
 *   6. After UPDATE, a verification sample is printed (10 random
 *      rows) so the operator can sanity-check the result on the spot.
 *
 * Classification rule (mirrors the dry-run script)
 * ------------------------------------------------
 *   - 0 modules with bookings  → SKIP (stays NULL)
 *   - 1 module with bookings   → UPDATE module_type to that module's
 *                                  canonical name (see $PROPOSED_VALUES)
 *   - 2+ modules with bookings → SKIP (stays NULL — needs manual review)
 *
 * Usage
 * -----
 *   php scripts/backfill_customers_module_type.php               # preview
 *   php scripts/backfill_customers_module_type.php --apply       # apply w/ prompt
 *
 *   # If you already reviewed the dry-run report and just want to
 *   # pipe the confirmation in (only do this in a controlled session):
 *   echo YES-APPLY-PROD | php scripts/backfill_customers_module_type.php --apply
 *
 * Rollback
 * --------
 *   mysql ... < storage/app/backups/customers_module_type_rollback_YYYYMMDD_HHMMSS.sql
 *
 *   Or in PHP:
 *   UPDATE customers c
 *   JOIN customers_module_type_backup_YYYYMMDD_HHMMSS b ON b.id = c.id
 *   SET c.module_type = b.old_module_type;
 *
 * @see scripts/dryrun_backfill_customers_module_type.php
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// ── Laravel bootstrap ─────────────────────────────────────────────────────
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apply = in_array('--apply', $_SERVER['argv'], true);

// ── Classification constants (kept in sync with dry-run script) ──────────
$MODULE_NAMES = ['flight', 'bus', 'hajj', 'visa', 'online', 'fawry', 'wallet'];
$PROPOSED_VALUES = [
    'flight' => 'flights',
    'bus'    => 'bus',
    'hajj'   => 'hajj_umra',
    'visa'   => 'visas',
    'online' => 'online',
    'fawry'  => 'office',
    'wallet' => 'office',
];

// ── Helpers ───────────────────────────────────────────────────────────────
function fmt(int $n): string { return number_format($n, 0, '.', ','); }
function trunc(?string $s, int $n = 40): string {
    if ($s === null || $s === '') return '(empty)';
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
}

// ── Discover which rows would change ──────────────────────────────────────
echo "\n";
echo str_repeat('═', 78)."\n";
echo "  APPLY — Backfill customers.module_type from existing bookings\n";
echo '  Run timestamp: '.date('Y-m-d H:i:s')."\n";
echo '  Mode:          '.($apply ? '*** APPLY (writes enabled) ***' : 'preview (no writes)')."\n";
echo str_repeat('═', 78)."\n\n";

$nullRows = DB::table('customers as c')
    ->whereNull('c.deleted_at')
    ->whereNull('c.module_type')
    ->selectRaw('
        c.id, c.full_name, c.phone, c.type,
        EXISTS(SELECT 1 FROM flight_bookings     fb WHERE fb.customer_id = c.id AND fb.deleted_at IS NULL) AS has_flight,
        EXISTS(SELECT 1 FROM bus_bookings        bb WHERE bb.customer_id = c.id AND bb.deleted_at IS NULL) AS has_bus,
        EXISTS(SELECT 1 FROM hajj_umra_bookings  hb WHERE hb.customer_id = c.id AND hb.deleted_at IS NULL) AS has_hajj,
        EXISTS(SELECT 1 FROM visa_bookings       vb WHERE vb.customer_id = c.id AND vb.deleted_at IS NULL) AS has_visa,
        EXISTS(SELECT 1 FROM online_transactions ot WHERE ot.customer_id = c.id AND ot.deleted_at IS NULL) AS has_online,
        EXISTS(SELECT 1 FROM fawry_transactions  ft WHERE ft.client_id   = c.id AND ft.deleted_at IS NULL) AS has_fawry,
        EXISTS(SELECT 1 FROM wallet_transactions wt WHERE wt.customer_id = c.id AND wt.deleted_at IS NULL) AS has_wallet
    ')
    ->get();

$toUpdate  = [];   // [customer_id => proposed_module_type]
$toSkip    = ['conflict' => [], 'untouched' => []];
$byModule  = [];

foreach ($nullRows as $row) {
    $flags = [];
    foreach ($MODULE_NAMES as $m) {
        if ((int) $row->{'has_'.$m} === 1) {
            $flags[] = $m;
        }
    }
    $n = count($flags);

    if ($n === 0) {
        $toSkip['untouched'][] = (int) $row->id;
        continue;
    }
    if ($n >= 2) {
        $toSkip['conflict'][] = (int) $row->id;
        continue;
    }
    $proposed = $PROPOSED_VALUES[$flags[0]];
    $toUpdate[(int) $row->id] = $proposed;
    $byModule[$proposed] = ($byModule[$proposed] ?? 0) + 1;
}

// ── Print plan ────────────────────────────────────────────────────────────
echo "[plan] Rows to UPDATE (1 booking module only): ".fmt(count($toUpdate))."\n";
foreach ($byModule as $module => $count) {
    echo "        - module_type='{$module}': ".fmt($count)."\n";
}
echo "[plan] Rows to SKIP (conflicts, ≥2 modules):   ".fmt(count($toSkip['conflict']))."\n";
echo "[plan] Rows to SKIP (untouched, 0 modules):    ".fmt(count($toSkip['untouched']))."\n\n";

if (empty($toUpdate)) {
    echo "Nothing to update. Exiting.\n\n";
    exit(0);
}

// ── Preview mode stops here ───────────────────────────────────────────────
if (! $apply) {
    echo "This was a preview. Pass --apply to actually write.\n";
    echo "  php scripts/backfill_customers_module_type.php --apply\n\n";
    exit(0);
}

// ── Interactive confirmation ──────────────────────────────────────────────
echo str_repeat('─', 78)."\n";
echo "  !!! ABOUT TO WRITE TO THE DATABASE !!!\n";
echo str_repeat('─', 78)."\n\n";

echo "Before continuing, this script will:\n";
echo "  1. Create a backup table (only the rows that will change)\n";
echo "  2. UPDATE ".fmt(count($toUpdate))." rows in `customers.module_type`\n";
echo "  3. Write a rollback SQL file to storage/app/backups/\n\n";

echo "Type exactly: YES-APPLY-PROD\n";
echo "  (anything else aborts safely)\n\n";

$confirmation = readline('> ');
if (trim($confirmation) !== 'YES-APPLY-PROD') {
    echo "\nConfirmation mismatch. Aborted. No writes performed.\n\n";
    exit(1);
}

echo "\nConfirmed. Proceeding...\n\n";

// ── Create backup table ───────────────────────────────────────────────────
$stamp     = date('Ymd_His');
$backupTbl = "customers_module_type_backup_{$stamp}";
$rollbackPath = storage_path("app/backups/customers_module_type_rollback_{$stamp}.sql");

if (! is_dir(dirname($rollbackPath))) {
    mkdir(dirname($rollbackPath), 0755, true);
}

echo "[1/4] Creating backup table `{$backupTbl}`...\n";

DB::statement("
    CREATE TABLE `{$backupTbl}` (
        `id`              BIGINT UNSIGNED NOT NULL,
        `full_name`       VARCHAR(255)    NOT NULL,
        `phone`           VARCHAR(50)     DEFAULT NULL,
        `old_module_type` VARCHAR(50)     DEFAULT NULL,
        `backfilled_at`   DATETIME        NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$ids = array_keys($toUpdate);
$chunks = array_chunk($ids, 500);
foreach ($chunks as $chunk) {
    $idList = implode(',', array_map('intval', $chunk));
    DB::statement("
        INSERT INTO `{$backupTbl}` (id, full_name, phone, old_module_type, backfilled_at)
        SELECT id, full_name, phone, module_type, NOW()
        FROM customers
        WHERE id IN ({$idList})
    ");
}
$backupCount = DB::table($backupTbl)->count();
echo "    ✓ Backed up {$backupCount} rows into `{$backupTbl}`.\n\n";

// ── Write rollback SQL file ───────────────────────────────────────────────
echo "[2/4] Writing rollback SQL file...\n";
$rollbackLines = [
    "-- Rollback script generated by scripts/backfill_customers_module_type.php",
    "-- Created: ".date('c'),
    "-- Backup table: {$backupTbl}",
    "--",
    "-- USAGE:",
    "--   mysql ... < {$rollbackPath}",
    "--",
    "-- This restores customers.module_type from the backup table.",
    "",
    "START TRANSACTION;",
    "",
    "UPDATE customers c",
    "JOIN `{$backupTbl}` b ON b.id = c.id",
    "SET c.module_type = b.old_module_type;",
    "",
    "-- Verify:",
    "SELECT COUNT(*) AS still_at_correct_value",
    "FROM customers c",
    "JOIN `{$backupTbl}` b ON b.id = c.id",
    "WHERE (",
    "    (b.old_module_type IS NULL AND c.module_type IS NULL)",
    "    OR c.module_type = b.old_module_type",
    ");",
    "",
    "COMMIT;",
    "",
];
file_put_contents($rollbackPath, implode("\n", $rollbackLines));
echo "    ✓ {$rollbackPath}\n\n";

// ── Apply UPDATE inside a transaction ─────────────────────────────────────
echo "[3/4] Applying UPDATE in a single transaction...\n";

$applied = 0;
try {
    DB::transaction(function () use ($toUpdate, &$applied) {
        // Group by proposed module_type for cleaner SQL batches.
        $byMod = [];
        foreach ($toUpdate as $id => $mod) {
            $byMod[$mod][] = $id;
        }
        foreach ($byMod as $mod => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $idList = implode(',', array_map('intval', $chunk));
                DB::statement("
                    UPDATE customers
                    SET module_type = '{$mod}', updated_at = NOW()
                    WHERE id IN ({$idList})
                      AND module_type IS NULL
                ");
            }
        }
        $applied = count($toUpdate);
    });
} catch (\Throwable $e) {
    echo "    ✗ UPDATE failed: ".$e->getMessage()."\n";
    echo "    Transaction rolled back. Nothing was written.\n\n";
    // Drop the now-useless backup table so it doesn't clutter the DB.
    DB::statement("DROP TABLE IF EXISTS `{$backupTbl}`");
    exit(1);
}

echo "    ✓ Applied {$applied} UPDATEs.\n\n";

// ── Verify ────────────────────────────────────────────────────────────────
echo "[4/4] Verifying...\n";

$remainingNulls = DB::table('customers')->whereNull('deleted_at')->whereNull('module_type')->count();
$newlyTagged = DB::table($backupTbl)->count();

echo "    Total active customers with module_type=NULL after backfill: ".fmt($remainingNulls)."\n";
echo "    Rows updated in this run:                                   ".fmt($newlyTagged)."\n\n";

$sampleIds = array_slice(array_keys($toUpdate), 0, 10);
if (! empty($sampleIds)) {
    $idList = implode(',', array_map('intval', $sampleIds));
    $sample = DB::select("
        SELECT c.id, c.full_name, c.module_type AS new_value, b.old_module_type
        FROM customers c
        JOIN `{$backupTbl}` b ON b.id = c.id
        WHERE c.id IN ({$idList})
    ");
    echo "  Sample of 10 updated rows:\n";
    foreach ($sample as $r) {
        printf("    #%-4d  %-30s  %s → %s\n",
            $r->id,
            trunc($r->full_name, 28),
            $r->old_module_type ?? 'NULL',
            $r->new_value ?? 'NULL'
        );
    }
    echo "\n";
}

echo str_repeat('═', 78)."\n";
echo "  BACKFILL COMPLETE\n";
echo str_repeat('═', 78)."\n\n";

echo "  Backup table (keep for safe rollback): `{$backupTbl}`\n";
echo "  Rollback SQL: {$rollbackPath}\n\n";

echo "  Suggested next steps:\n";
echo "    1. Verify a few rows on the production UI / DB.\n";
echo "    2. Apply the Filament + BookingService code fixes (separate PR).\n";
echo "    3. Run: php artisan cache:clear\n";
echo "    4. Drop the backup table when you're confident:\n";
echo "         DROP TABLE `{$backupTbl}`;\n\n";

exit(0);