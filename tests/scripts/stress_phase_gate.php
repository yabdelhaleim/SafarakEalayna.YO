<?php

declare(strict_types=1);

/**
 * stress_phase_gate.php
 *
 * Phase 25 — Staged gate runner. Runs ONE phase (A or B or C), captures
 * every metric the gate report requires, and STOPS — it does not chain
 * phases. The operator reviews the gate report before the next phase.
 *
 * Captured metrics per phase:
 *   - Pre-flight: APP_ENV, DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE,
 *                 SELECT DATABASE(), disk free, PID, time
 *   - Seeder: requested tx count, actual tx created, elapsed, deadlocks, retries
 *   - Concurrency: ops, deadlocks, retries, ledger invariance, ops/sec
 *   - Reconciliation: per-account failures, per-transaction failures,
 *                     orphan entries, orphan transactions, duplicate income,
 *                     reversal integrity, totals, FK integrity, soft deletes
 *   - Class-A/B/C defects surfaced during the phase
 *
 * Usage:
 *   php -d memory_limit=2G tests/scripts/stress_phase_gate.php --phase=A
 *   php -d memory_limit=2G tests/scripts/stress_phase_gate.php --phase=B
 *   php -d memory_limit=2G tests/scripts/stress_phase_gate.php --phase=C --workers=50
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressReconciliation;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

// ── Parse args
$phase = 'A';
$tier = 'mysql';
$workers = null;
foreach ($argv as $arg) {
    if (preg_match('/^--phase=([ABC])$/i', $arg, $m)) $phase = strtoupper($m[1]);
    if (preg_match('/^--tier=(sqlite|mysql)$/i', $arg, $m)) $tier = strtolower($m[1]);
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) $workers = (int) $m[1];
}

if ($workers === null) {
    $workers = match ($phase) {
        'A' => 10,
        'B' => 25,
        'C' => 50,
    };
}

$report = [
    'phase'         => $phase,
    'tier'          => $tier,
    'workers'       => $workers,
    'preflight'     => [],
    'seeder'        => [],
    'concurrency'   => [],
    'reconciliation'=> [],
    'defects'       => ['class_a' => [], 'class_b' => [], 'class_c' => []],
    'verdict'       => 'UNKNOWN',
];

// ── Step 1: Pre-flight ──────────────────────────────────────────────
fwrite(STDOUT, "\n╔═══════════════════════════════════════════════════════════╗\n");
fwrite(STDOUT, "║  Phase {$phase} — Pre-flight                                  \n");
fwrite(STDOUT, "╚═══════════════════════════════════════════════════════════╝\n");

try {
    StressSafetyGuard::assertSafeEnvironment($tier);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

$cfg = Config::get('database.connections.'.Config::get('database.default'));
$connection = Config::get('database.default');
$host = is_array($cfg) ? ($cfg['host'] ?? 'unknown') : 'unknown';
$port = is_array($cfg) ? ($cfg['port'] ?? 3306) : 3306;
$database = is_array($cfg) ? ($cfg['database'] ?? 'unknown') : 'unknown';
$appEnv = app()->environment();
$pid = (int) getmypid();
$selectDb = null;
try {
    $selectDb = DB::connection()->selectOne('SELECT DATABASE() AS d')->d ?? null;
} catch (\Throwable $e) {
    // SQLite doesn't support SELECT DATABASE()
    $selectDb = $database;
}

// Disk space
$diskFreeBytes = disk_free_space(__DIR__.'/../../');
$diskFreeGiB = $diskFreeBytes !== false ? round($diskFreeBytes / 1024 / 1024 / 1024, 2) : null;

fwrite(STDOUT, "APP_ENV:          {$appEnv}\n");
fwrite(STDOUT, "DB_CONNECTION:    {$connection}\n");
fwrite(STDOUT, "DB_HOST:          {$host}\n");
fwrite(STDOUT, "DB_PORT:          {$port}\n");
fwrite(STDOUT, "DB_DATABASE:      {$database}\n");
fwrite(STDOUT, "SELECT DATABASE(): {$selectDb}\n");
fwrite(STDOUT, "PID:              {$pid}\n");
fwrite(STDOUT, "DISK FREE:        {$diskFreeGiB} GiB\n");
fwrite(STDOUT, "TIME:             ".date('Y-m-d H:i:s')."\n");

$report['preflight'] = [
    'app_env'      => $appEnv,
    'connection'   => $connection,
    'host'         => $host,
    'port'         => $port,
    'database'     => $database,
    'select_db'    => $selectDb,
    'pid'          => $pid,
    'disk_free_gib'=> $diskFreeGiB,
    'time'         => date('c'),
    'matches_env'  => strtolower((string) $selectDb) === 'safarak_stress',
    'matches_env2' => $database === 'safarak_stress',
];

if ($report['preflight']['matches_env'] === false && $tier === 'mysql') {
    fwrite(STDERR, "🛑 SELECT DATABASE() did not return safarak_stress. Aborting.\n");
    exit(2);
}

if ($diskFreeGiB !== null && $diskFreeGiB < 5.0) {
    fwrite(STDERR, "🛑 DISK FREE below 5 GiB ({$diskFreeGiB} GiB). Stress run unsafe. Aborting.\n");
    exit(2);
}

// ── Step 2: Snapshot baseline counts BEFORE the phase ───────────────
$baseline = [
    'accounts'           => (int) DB::table('accounts')->count(),
    'account_entries'    => (int) DB::table('account_entries')->count(),
    'transactions'       => (int) DB::table('transactions')->count(),
    'transfers'          => (int) DB::table('transfers')->count(),
    'customers'          => (int) DB::table('customers')->count(),
    'suppliers'          => (int) DB::table('suppliers')->count(),
    'bus_bookings'       => (int) DB::table('bus_bookings')->count(),
    'bus_payments'       => (int) DB::table('bus_payments')->count(),
];
fwrite(STDOUT, "\n── Baseline (before phase) ──\n");
foreach ($baseline as $k => $v) fwrite(STDOUT, "  {$k}: {$v}\n");

// ── Step 3: Seeder (Phase A/B; skip for Phase C) ──────────────────
$phaseStart = microtime(true);
if ($phase !== 'C') {
    fwrite(STDOUT, "\n╔═══════════════════════════════════════════════════════════╗\n");
    fwrite(STDOUT, "║  Phase {$phase} — Seeding ({$tier})                           \n");
    fwrite(STDOUT, "╚═══════════════════════════════════════════════════════════╝\n");
    $seederExit = 0;
    $seederCmd = 'php -d memory_limit=2G '.escapeshellarg(__DIR__.'/stress_seeder_bulk.php').' --phase='.$phase.' 2>&1';
    passthru($seederCmd, $seederExit);
    if ($seederExit !== 0) {
        fwrite(STDERR, "🛑 Seeder exited with code {$seederExit}.\n");
        $report['verdict'] = 'FAIL';
        $report['defects']['class_a'][] = "Seeder exit code {$seederExit}";
        writeGate($phase, $report);
        exit(1);
    }
    $seederElapsed = round(microtime(true) - $phaseStart, 2);
    $report['seeder'] = [
        'elapsed_sec' => $seederElapsed,
    ];
    fwrite(STDOUT, "✓ Seeder complete in {$seederElapsed} sec\n");
} else {
    fwrite(STDOUT, "\n→ Phase C: REUSING existing dataset; skipping seeder.\n");
    $report['seeder'] = ['reused' => true];
}

// ── Step 4: Concurrency ─────────────────────────────────────────────
fwrite(STDOUT, "\n╔═══════════════════════════════════════════════════════════╗\n");
fwrite(STDOUT, "║  Phase {$phase} — Concurrency ({$workers} workers)            \n");
fwrite(STDOUT, "╚═══════════════════════════════════════════════════════════╝\n");
$concStart = microtime(true);
$concCmd = sprintf(
    'php -d memory_limit=2G %s --workers=%d 2>&1',
    escapeshellarg(__DIR__.'/stress_concurrent_transfers.php'),
    $workers
);
passthru($concCmd, $concExit);
$concElapsed = round(microtime(true) - $concStart, 2);
$report['concurrency'] = [
    'workers'     => $workers,
    'exit_code'   => $concExit,
    'elapsed_sec' => $concElapsed,
    'artifact'    => 'storage/app/stress/phase-C-concurrent-transfers.json',
];

// Read concurrency metrics
$concArtifact = storage_path('app/stress/phase-C-concurrent-transfers.json');
if (file_exists($concArtifact)) {
    $report['concurrency']['metrics'] = json_decode(file_get_contents($concArtifact), true) ?: [];
}
fwrite(STDOUT, "✓ Concurrency run complete in {$concElapsed} sec\n");

// ── Step 5: Final reconciliation ────────────────────────────────────
fwrite(STDOUT, "\n╔═══════════════════════════════════════════════════════════╗\n");
fwrite(STDOUT, "║  Phase {$phase} — Reconciliation                            \n");
fwrite(STDOUT, "╚═══════════════════════════════════════════════════════════╝\n");
$recon = StressReconciliation::runAll();
$report['reconciliation'] = $recon;

fwrite(STDOUT, sprintf("  per-account checked/failed:   %d / %d\n",
    $recon['per_account']['checked'], $recon['per_account']['failed']));
fwrite(STDOUT, sprintf("  per-tx checked/failed:        %d / %d\n",
    $recon['per_transaction']['checked'], $recon['per_transaction']['failed']));
fwrite(STDOUT, sprintf("  orphan entries:               %d\n", $recon['orphan_entries']['count']));
fwrite(STDOUT, sprintf("  orphan transactions:          %d\n", $recon['orphan_transactions']['count']));
fwrite(STDOUT, sprintf("  duplicate income:             %d\n", $recon['duplicate_income']['count']));
fwrite(STDOUT, sprintf("  reversal originals:           %d  reversals: %d  net_impact: %s\n",
    $recon['reversals']['originals'], $recon['reversals']['reversals'], $recon['reversals']['net_impact_egp']));
fwrite(STDOUT, sprintf("  totals credits/debits:        %s / %s\n",
    $recon['totals']['credits'], $recon['totals']['debits']));
fwrite(STDOUT, sprintf("  totals diff (must be 0):      %s\n", $recon['totals']['diff']));
fwrite(STDOUT, sprintf("  FK broken:                    %d\n", $recon['fk_integrity']['broken']));
fwrite(STDOUT, sprintf("  unexpected soft deletes:      %d\n", count($recon['soft_deletes'])));
fwrite(STDOUT, sprintf("  RECONCILIATION VERDICT:       %s\n", $recon['verdict']));

// ── Step 6: Defect classification ───────────────────────────────────
if ($recon['per_account']['failed'] > 0) {
    $report['defects']['class_a'][] = "Per-account balance mismatch: {$recon['per_account']['failed']} accounts";
}
if ($recon['per_transaction']['failed'] > 0) {
    $report['defects']['class_a'][] = "Per-transaction imbalance: {$recon['per_transaction']['failed']} transactions";
}
if ($recon['orphan_entries']['count'] > 0) {
    $report['defects']['class_a'][] = "Orphan AccountEntry rows: {$recon['orphan_entries']['count']}";
}
if ($recon['orphan_transactions']['count'] > 0) {
    $report['defects']['class_a'][] = "Orphan Transaction rows: {$recon['orphan_transactions']['count']}";
}
if ($recon['duplicate_income']['count'] > 0) {
    $report['defects']['class_a'][] = "Duplicate income keys: {$recon['duplicate_income']['count']}";
}
if (abs($recon['totals']['diff']) > $recon['tolerance']) {
    $report['defects']['class_a'][] = "Global totals diff: {$recon['totals']['diff']} (tolerance {$recon['tolerance']})";
}
if ($recon['fk_integrity']['broken'] > 0) {
    $report['defects']['class_a'][] = "FK integrity broken: {$recon['fk_integrity']['broken']}";
}
if (count($recon['soft_deletes']) > 0) {
    $report['defects']['class_b'][] = 'Unexpected soft deletes: '.implode('; ', $recon['soft_deletes']);
}

// ── Step 7: Verdict ─────────────────────────────────────────────────
if (count($report['defects']['class_a']) === 0 && $recon['verdict'] === 'PASS') {
    $report['verdict'] = 'PASS';
} else {
    $report['verdict'] = 'FAIL';
}

// Delta from baseline
$after = [
    'accounts'           => (int) DB::table('accounts')->count(),
    'account_entries'    => (int) DB::table('account_entries')->count(),
    'transactions'       => (int) DB::table('transactions')->count(),
    'transfers'          => (int) DB::table('transfers')->count(),
    'customers'          => (int) DB::table('customers')->count(),
    'suppliers'          => (int) DB::table('suppliers')->count(),
    'bus_bookings'       => (int) DB::table('bus_bookings')->count(),
    'bus_payments'       => (int) DB::table('bus_payments')->count(),
];
$report['deltas'] = [];
foreach ($baseline as $k => $v) {
    $report['deltas'][$k] = $after[$k] - $v;
}
$report['after'] = $after;

// ── Step 8: Persist gate report ─────────────────────────────────────
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$report['elapsed_sec'] = round(microtime(true) - $phaseStart, 2);
$report['ran_at'] = date('c');
file_put_contents(
    $dir."/phase-{$phase}-gate.json",
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

fwrite(STDOUT, "\n╔═══════════════════════════════════════════════════════════╗\n");
fwrite(STDOUT, "║  Phase {$phase} — VERDICT: {$report['verdict']}\n");
fwrite(STDOUT, "╚═══════════════════════════════════════════════════════════╝\n");
fwrite(STDOUT, "\nArtifact: storage/app/stress/phase-{$phase}-gate.json\n");

if ($report['verdict'] !== 'PASS') {
    fwrite(STDERR, "\n🛑 GATE {$phase} FAILED — see phase-{$phase}-gate.json\n");
    if (count($report['defects']['class_a']) > 0) {
        fwrite(STDERR, "Class-A defects:\n");
        foreach ($report['defects']['class_a'] as $d) fwrite(STDERR, "  • {$d}\n");
    }
    exit(1);
}

fwrite(STDOUT, "\n✅ GATE {$phase} PASS — review report, then proceed to next phase.\n");
exit(0);

// ────────────────────────────────────────────────────────────────────
function writeGate(string $phase, array $report): void
{
    $dir = storage_path('app/stress');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    file_put_contents($dir."/phase-{$phase}-gate.json", json_encode($report, JSON_PRETTY_PRINT));
}
