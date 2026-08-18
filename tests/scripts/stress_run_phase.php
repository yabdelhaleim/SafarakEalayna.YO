<?php

declare(strict_types=1);

/**
 * stress_run_phase.php
 *
 * Phase 25 — Single-phase orchestration entry point.
 *
 * Usage:
 *   php tests/scripts/stress_run_phase.php --phase=A --tier=sqlite
 *   php tests/scripts/stress_run_phase.php --phase=B --tier=mysql --workers=25
 *   php tests/scripts/stress_run_phase.php --phase=C --tier=mysql --workers=50
 *
 * Steps per invocation:
 *   1. Safety guard (must PASS)
 *   2. If tier=mysql: create safarak_stress schema if missing
 *   3. Run seeder for the requested phase (A/B; C is reuse)
 *   4. For tier=mysql only: run the concurrency bundle for the requested phase
 *   5. Run reconciliation
 *
 * Output:
 *   storage/app/stress/phase-{A|B|C}-{tier}.json (metrics)
 *   storage/app/stress/phase-FINAL-reconciliation.json (verdict)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Config;
use Tests\Stress\Support\StressReconciliation;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$phase = 'A';
$tier = 'sqlite';
$workers = null;
foreach ($argv as $arg) {
    if (preg_match('/^--phase=([ABC])$/i', $arg, $m)) $phase = strtoupper($m[1]);
    if (preg_match('/^--tier=(sqlite|mysql)$/i', $arg, $m)) $tier = strtolower($m[1]);
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) $workers = (int) $m[1];
}

// Default workers per phase (matches plan)
if ($workers === null) {
    $workers = match ($phase) {
        'A' => 10,
        'B' => 25,
        'C' => 50,
    };
}

// ── Safety guard
try {
    StressSafetyGuard::assertSafeEnvironment($tier);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "\n═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  Phase {$phase} / {$tier} / workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

// ── Tier-specific DB switch
if ($tier === 'mysql') {
    fwrite(STDOUT, "→ Tier=mysql — verifying safarak_stress schema…\n");
    // The setup script is idempotent (CREATE DATABASE is no-op if exists).
    $setupExit = 0;
    passthru('php '.escapeshellarg(__DIR__.'/stress_setup_mysql.php'), $setupExit);
    if ($setupExit !== 0) {
        fwrite(STDERR, "🛑 Setup failed.\n");
        exit($setupExit);
    }
}

$phaseStart = microtime(true);

// ── Seeding
fwrite(STDOUT, "\n── Seeding ──\n");
$seederExit = 0;
$seederCmd = 'php -d memory_limit=2G '.escapeshellarg(__DIR__.'/stress_seeder_bulk.php').' --phase='.$phase;
passthru($seederCmd, $seederExit);
if ($seederExit !== 0) {
    fwrite(STDERR, "🛑 Seeder failed.\n");
    exit($seederExit);
}

// ── Concurrency (MySQL tier only — curl_multi + parallel CLI workers)
if ($tier === 'mysql' && $phase !== 'C') {
    fwrite(STDOUT, "\n── Concurrency (workers={$workers}) ──\n");
    // For Phases A and B we run a generic concurrent-transfers loop.
    $hotCmd = sprintf(
        'php -d memory_limit=2G %s --workers=%d 2>&1',
        escapeshellarg(__DIR__.'/stress_concurrent_transfers.php'),
        $workers
    );
    passthru($hotCmd, $concExit);
    // Hot* scripts are advisory for Phase A/B; failures are recorded but
    // do NOT block phase progression (those are recorded separately).
}

// ── Reconciliation
fwrite(STDOUT, "\n── Reconciliation ──\n");
$report = StressReconciliation::runAll();
fwrite(STDOUT, "Verdict: {$report['verdict']}\n");
if ($report['verdict'] !== 'PASS') {
    fwrite(STDERR, "Reconciliation FAILED:\n".json_encode($report, JSON_PRETTY_PRINT)."\n");
}

// Phase artifact
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents(
    $dir."/phase-{$phase}-{$tier}.json",
    json_encode([
        'phase' => $phase,
        'tier' => $tier,
        'workers' => $workers,
        'ran_at' => date('c'),
        'elapsed_sec' => round(microtime(true) - $phaseStart, 2),
        'reconciliation' => $report,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

exit($report['verdict'] === 'PASS' ? 0 : 1);
