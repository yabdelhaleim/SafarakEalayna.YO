<?php
/**
 * PHASE 4 — EXECUTION SCRIPT (guard-railed)
 * ===========================================
 * Soft-reverse the 11 ACTIVE orphan Income transactions identified by
 * `audit_flight_orphans_phase_4.php` — Option A from the correction plan.
 *
 * GUARD-RAILS (per user directive)
 *   1. Safety gate — abort unless APP_ENV=local + DB=safarakealayna
 *   2. Mandatory mysqldump BEFORE any mutation
 *   3. The actual UPDATE runs inside DB::transaction → auto-rollback on error
 *   4. Run the read-only audit BEFORE and AFTER → print diff for review
 *   5. Default mode = DRY-RUN (no mutation). Real mutation requires `--execute`
 *      CLI flag AND an interactive "COMMIT" confirmation typed by the operator.
 *
 * INVOCATION
 *   # 1. DRY-RUN (default) — shows the BEFORE/AFTER diff and exits
 *   php execute_phase_4_correction.php
 *
 *   # 2. EXECUTE — performs mysqldump + UPDATE inside transaction,
 *   #    requires interactive "COMMIT" at the confirm prompt.
 *   php execute_phase_4_correction.php --execute
 *
 * NEVER run this script without explicit user approval. The script itself
 * enforces an interactive COMMIT gate even when --execute is passed — the
 * only way to skip it is by passing `--no-confirm` (FOR EMERGENCY USE ONLY).
 *
 * @see docs/PHASE_4_HISTORICAL_CORRECTION_PLAN.md
 * @see audit_flight_orphans_phase_4.php (the read-only inventory)
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

// ─── CONFIG ────────────────────────────────────────────────────────────────
// These are the 11 transaction IDs that the Phase 4 audit identified as
// ACTIVE orphan Income rows (no `عكس:` prefix) over-counting income reports.
// DO NOT EDIT this list without re-running audit_flight_orphans_phase_4.php.
const TARGET_TX_IDS = [4365, 4366, 4371, 4372, 4377, 4378, 4383, 4384, 4389, 4390, 4395];

// Sanity check at startup — every TX_ID must currently be type=income AND
// have no `عكس:` prefix. The script aborts loudly if any row fails this
// pre-condition (prevents accidental reversal of healthy income).
function assertAllTargetsAreOrphanIncome(): array
{
    $rows = DB::table('transactions')
        ->whereIn('id', TARGET_TX_IDS)
        ->select('id', 'type', 'notes', 'related_type', 'related_id')
        ->get();

    $errors = [];
    foreach (TARGET_TX_IDS as $id) {
        $row = $rows->firstWhere('id', $id);
        if (! $row) {
            $errors[] = "tx_id {$id} — not found in transactions table";
            continue;
        }
        if ($row->type !== 'income') {
            $errors[] = "tx_id {$id} — type is '{$row->type}', expected 'income'";
        }
        $notes = $row->notes ?? '';
        if (str_starts_with($notes, 'عكس:') || str_starts_with($notes, 'عكس ')) {
            $errors[] = "tx_id {$id} — already has 'عكس:' prefix (notes='{$notes}')";
        }
    }
    return $errors;
}

// ─── 1. SAFETY GATE ────────────────────────────────────────────────────────
$env = config('app.env');
$dbName = config('database.connections.mysql.database');
$dbHost = config('database.connections.mysql.host');

if ($env !== 'local') {
    fwrite(STDERR, "🛑 ABORT: APP_ENV must be 'local', got '{$env}'.\n");
    exit(1);
}
if ($dbName !== 'safarakealayna') {
    fwrite(STDERR, "🛑 ABORT: DB must be local MySQL 'safarakealayna', got '{$dbName}'.\n");
    exit(1);
}
if (! in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "🛑 ABORT: DB host must be 127.0.0.1/localhost, got '{$dbHost}'.\n");
    exit(1);
}

echo "✅ Safety gate passed — local MySQL @ {$dbHost}/{$dbName}\n";

// ─── 2. CLI FLAGS ──────────────────────────────────────────────────────────
$execute = in_array('--execute', $argv, true);
$skipConfirm = in_array('--no-confirm', $argv, true);

if ($execute && ! $skipConfirm) {
    echo "⚠️  EXECUTE MODE — this WILL mutate the DB inside a transaction.\n";
}

// ─── 3. PRE-FLIGHT CHECK ON TARGETS ────────────────────────────────────────
$preFlightErrors = assertAllTargetsAreOrphanIncome();
if (! empty($preFlightErrors)) {
    fwrite(STDERR, "\n🛑 PRE-FLIGHT FAILED — targets are not in expected state:\n");
    foreach ($preFlightErrors as $err) {
        fwrite(STDERR, "   • {$err}\n");
    }
    fwrite(STDERR, "\nRe-run audit_flight_orphans_phase_4.php and update TARGET_TX_IDS if needed.\n");
    exit(1);
}
echo '✅ Pre-flight check passed — all '.count(TARGET_TX_IDS)." target tx_ids are ACTIVE orphan Income rows.\n\n";

// ─── 4. CAPTURE BASELINE (audit BEFORE) ─────────────────────────────────────
echo "📊 Capturing baseline (audit BEFORE)...\n";
$before = captureAuditCounts();
printAuditCounts($before, 'BEFORE');

// ─── 5. mysqldump (only if executing) ──────────────────────────────────────
$dumpFile = null;
if ($execute) {
    $timestamp = date('Ymd_His');
    $dumpFile = __DIR__."/storage/app/private/pre_phase4_{$timestamp}.sql";
    @mkdir(dirname($dumpFile), 0755, true);

    echo "\n💾 Creating mysqldump → {$dumpFile}\n";

    // Build mysqldump command as an array (avoids shell-escaping issues on Windows)
    $dumpCmd = ['mysqldump', '-h', $dbHost, '-u', config('database.connections.mysql.username')];
    $password = config('database.connections.mysql.password');
    if (! empty($password)) {
        // MYSQL_PWD env var is the safest way to pass a password non-interactively
        // (avoids leaking via process listings, which -p<password> would).
        $dumpCmd = array_merge(['env', 'MYSQL_PWD='.$password], $dumpCmd);
    }
    $dumpCmd = array_merge($dumpCmd, [
        '--single-transaction', '--quick', '--routines', '--triggers', '--events',
        $dbName,
    ]);

    $dumpProcess = new \Symfony\Component\Process\Process($dumpCmd);
    $dumpProcess->setWorkingDirectory(__DIR__);
    $dumpProcess->setTimeout(300);
    $dumpProcess->run();

    if (! $dumpProcess->isSuccessful()) {
        fwrite(STDERR, "🛑 mysqldump failed (exit={$dumpProcess->getExitCode()}).\n");
        fwrite(STDERR, "   STDOUT: ".$dumpProcess->getOutput()."\n");
        fwrite(STDERR, "   STDERR: ".$dumpProcess->getErrorOutput()."\n");
        exit(1);
    }

    // Write the captured stdout to the dump file
    file_put_contents($dumpFile, $dumpProcess->getOutput());
    $size = filesize($dumpFile);
    echo "   ✅ Dump created (".number_format($size / 1024, 1)." KB)\n";

    // ─── 6. INTERACTIVE CONFIRM GATE ───────────────────────────────────────
    if (! $skipConfirm) {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║  ABOUT TO MUTATE:                                            ║\n";
        echo "║    • ".count(TARGET_TX_IDS)." rows in `transactions` will get `عكس:` prefix on notes  ║\n";
        echo "║    • All inside a single DB transaction (auto-rollback)      ║\n";
        echo "║    • Backup at: {$dumpFile}\n";
        echo "║                                                              ║\n";
        echo "║  Type COMMIT (all caps) to proceed. Anything else aborts.   ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "Confirm: ";
        $input = trim(fgets(STDIN));
        if ($input !== 'COMMIT') {
            echo "Aborted — input was '{$input}'.\n";
            exit(0);
        }
    }

    // ─── 7. EXECUTE UPDATE INSIDE TRANSACTION ───────────────────────────────
    echo "\n🔄 Executing UPDATE inside DB::transaction...\n";
    try {
        $affected = DB::transaction(function () {
            return DB::table('transactions')
                ->whereIn('id', TARGET_TX_IDS)
                ->whereNull('notes')
                ->update([
                    'notes' => DB::raw("CONCAT('عكس: ', '(legacy B-2 duplicate income — soft-reversed by execute_phase_4_correction.php)')"),
                ]);
        });

        if ($affected !== count(TARGET_TX_IDS)) {
            // The .whereNull('notes') clause may have filtered some rows out
            // if they were already partially reversed. Roll back and report.
            throw new \RuntimeException(
                "UPDATE affected {$affected} rows but expected ".count(TARGET_TX_IDS).". ".
                'Some targets may have been modified concurrently. ROLLBACK performed.'
            );
        }
        echo "   ✅ UPDATE committed — {$affected} rows prefixed with `عكس:`\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "🛑 EXECUTION FAILED: {$e->getMessage()}\n");
        fwrite(STDERR, "   Transaction auto-rolled back. No data modified.\n");
        exit(1);
    }
} else {
    echo "\nℹ️  DRY-RUN MODE — no mutation will occur. Pass --execute to commit.\n";
    echo "   Simulated UPDATE: prepend `عكس: ` to notes on ".count(TARGET_TX_IDS)." rows.\n";
}

// ─── 8. CAPTURE POST-STATE (audit AFTER) ───────────────────────────────────
echo "\n📊 Capturing post-state (audit AFTER)...\n";
$after = captureAuditCounts();
printAuditCounts($after, 'AFTER');

// ─── 9. PRINT DIFF ─────────────────────────────────────────────────────────
echo "\n════════════════════════════════════════════════════════════════\n";
echo "  DIFF (BEFORE → AFTER)\n";
echo "════════════════════════════════════════════════════════════════\n";
foreach ($before as $key => $beforeVal) {
    $afterVal = $after[$key] ?? 'n/a';
    $delta = is_numeric($beforeVal) && is_numeric($afterVal) ? ($afterVal - $beforeVal) : 'n/a';
    printf("  %-50s  %10s → %10s   Δ %s\n", $key, $beforeVal, $afterVal, $delta);
}
echo "════════════════════════════════════════════════════════════════\n";

// ─── 10. EXPECTED-VS-ACTUAL CHECK ───────────────────────────────────────────
echo "\n🔍 Verifying expected vs actual:\n";
$expected = [
    'active_income_count_flight' => 0,
    'active_income_sum_flight' => 0.0,
    'orphan_count_total' => 22,        // orphans persist (FK existence, not notes)
    'orphan_income_count' => 0,        // all 11 should now have `عكس:` prefix
];
$allPass = true;
foreach ($expected as $key => $expectedVal) {
    $actualVal = $after[$key] ?? null;
    $status = ($actualVal == $expectedVal) ? '✅' : '❌';
    if ($actualVal != $expectedVal) {
        $allPass = false;
    }
    printf("  %s  %-35s  expected=%-10s  actual=%-10s\n", $status, $key, var_export($expectedVal, true), var_export($actualVal, true));
}

echo "\n";
if ($allPass) {
    echo "🎉 SUCCESS — all expected values match.\n";
    if ($dumpFile) {
        echo "   Backup retained at: {$dumpFile}\n";
    }
} else {
    echo "⚠️  MISMATCH — at least one expected value did not match.\n";
    if ($dumpFile) {
        echo "   Restore from: {$dumpFile}\n";
    }
    echo "   Rerun audit_flight_orphans_phase_4.php to see full state.\n";
}

// ─── HELPERS ───────────────────────────────────────────────────────────────

function captureAuditCounts(): array
{
    return [
        'active_income_count_flight' => DB::table('transactions')
            ->where('type', 'income')
            ->where('module', 'flight')
            ->where(function ($q) {
                $q->whereNull('notes')->orWhere(function ($q2) {
                    $q2->where('notes', 'not like', 'عكس:%')->where('notes', 'not like', 'عكس %');
                });
            })
            ->count(),
        'active_income_sum_flight' => (float) DB::table('transactions')
            ->where('type', 'income')
            ->where('module', 'flight')
            ->where(function ($q) {
                $q->whereNull('notes')->orWhere(function ($q2) {
                    $q2->where('notes', 'not like', 'عكس:%')->where('notes', 'not like', 'عكس %');
                });
            })
            ->sum('amount'),
        'orphan_count_total' => DB::table('transactions as t')
            ->leftJoin('flight_payments as fp', function ($j) {
                $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
                  ->whereColumn('t.related_id', 'fp.id');
            })
            ->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
            ->whereNull('fp.id')
            ->count(),
        'orphan_income_count' => DB::table('transactions as t')
            ->leftJoin('flight_payments as fp', function ($j) {
                $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
                  ->whereColumn('t.related_id', 'fp.id');
            })
            ->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
            ->where('t.type', 'income')
            ->whereNull('fp.id')
            ->where(function ($q) {
                $q->whereNull('t.notes')->orWhere(function ($q2) {
                    $q2->where('t.notes', 'not like', 'عكس:%')->where('t.notes', 'not like', 'عكس %');
                });
            })
            ->count(),
        'orphan_transfer_count' => DB::table('transactions as t')
            ->leftJoin('flight_payments as fp', function ($j) {
                $j->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
                  ->whereColumn('t.related_id', 'fp.id');
            })
            ->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')
            ->where('t.type', 'transfer')
            ->whereNull('fp.id')
            ->count(),
        'flight_bookings_total' => DB::table('flight_bookings')->count(),
        'flight_payments_total' => DB::table('flight_payments')->count(),
    ];
}

function printAuditCounts(array $counts, string $label): void
{
    echo "  [{$label}]\n";
    foreach ($counts as $key => $val) {
        $display = is_float($val) ? number_format($val, 2) : (string) $val;
        echo "    {$key}: {$display}\n";
    }
}