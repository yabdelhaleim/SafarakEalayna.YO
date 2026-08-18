<?php

declare(strict_types=1);

/**
 * stress_metrics.php
 *
 * Phase 25.18/25.21 — Performance metrics aggregator.
 *
 * Reads the JSON artifacts produced by stress_run_phase / hot_* / reconcile
 * scripts and emits a combined performance + financial integrity report.
 *
 * Usage:
 *   php tests/scripts/stress_metrics.php
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

$dir = storage_path('app/stress');
$artifacts = glob($dir.'/phase-*.json') ?: [];

$metrics = [
    'phase_A' => [],
    'phase_B' => [],
    'phase_C' => ['hot_account' => null, 'hot_debt' => null, 'hot_booking' => null],
    'final_reconciliation' => null,
];

foreach ($artifacts as $file) {
    $name = basename($file, '.json');
    $payload = json_decode(file_get_contents($file), true) ?: [];
    if (str_starts_with($name, 'phase-A')) $metrics['phase_A'][$name] = $payload;
    elseif (str_starts_with($name, 'phase-B')) $metrics['phase_B'][$name] = $payload;
    elseif (str_starts_with($name, 'phase-C-hot-account')) $metrics['phase_C']['hot_account'] = $payload;
    elseif (str_starts_with($name, 'phase-C-hot-debt'))    $metrics['phase_C']['hot_debt']    = $payload;
    elseif (str_starts_with($name, 'phase-C-hot-booking')) $metrics['phase_C']['hot_booking'] = $payload;
    elseif (str_starts_with($name, 'phase-FINAL'))        $metrics['final_reconciliation'] = $payload;
}

$md  = "# Phase 25 — Performance Metrics\n\n";
$md .= "**Generated**: ".date('c')."  \n\n";

$md .= "## Phase A (20K tx, 10 workers)\n\n";
if (empty($metrics['phase_A'])) $md .= "_No artifacts found._\n\n";
else {
    foreach ($metrics['phase_A'] as $name => $p) {
        $md .= "### {$name}\n\n";
        $md .= "- tier: ".($p['tier'] ?? 'n/a')."\n";
        $md .= "- workers: ".($p['workers'] ?? 'n/a')."\n";
        $md .= "- elapsed_sec: ".($p['elapsed_sec'] ?? 'n/a')."\n";
        $md .= "- verdict: ".($p['reconciliation']['verdict'] ?? 'n/a')."\n\n";
    }
}

$md .= "## Phase B (50K tx, 25 workers)\n\n";
if (empty($metrics['phase_B'])) $md .= "_No artifacts found._\n\n";
else {
    foreach ($metrics['phase_B'] as $name => $p) {
        $md .= "### {$name}\n\n";
        $md .= "- tier: ".($p['tier'] ?? 'n/a')."\n";
        $md .= "- workers: ".($p['workers'] ?? 'n/a')."\n";
        $md .= "- elapsed_sec: ".($p['elapsed_sec'] ?? 'n/a')."\n";
        $md .= "- verdict: ".($p['reconciliation']['verdict'] ?? 'n/a')."\n\n";
    }
}

$md .= "## Phase C (50 workers hot-spot)\n\n";
foreach (['hot_account', 'hot_debt', 'hot_booking'] as $k) {
    $p = $metrics['phase_C'][$k];
    if (!$p) { $md .= "- {$k}: _no artifact_\n"; continue; }
    $md .= "### {$k}\n\n";
    foreach ($p as $k2 => $v) {
        if (is_scalar($v)) {
            $md .= "- {$k2}: {$v}\n";
        }
    }
    $md .= "\n";
}

$md .= "## Final reconciliation\n\n";
$fr = $metrics['final_reconciliation'];
if (!$fr) {
    $md .= "_No artifact found — run stress_reconcile.php first._\n";
} else {
    $md .= "- verdict: **{$fr['verdict']}**\n";
    $md .= "- per_account checked: {$fr['per_account']['checked']}\n";
    $md .= "- per_account failed:  {$fr['per_account']['failed']}\n";
    $md .= "- per_transaction checked: {$fr['per_transaction']['checked']}\n";
    $md .= "- per_transaction failed:  {$fr['per_transaction']['failed']}\n";
    $md .= "- orphan entries: {$fr['orphan_entries']['count']}\n";
    $md .= "- orphan transactions: {$fr['orphan_transactions']['count']}\n";
    $md .= "- duplicate income: {$fr['duplicate_income']['count']}\n";
    $md .= "- global diff: {$fr['totals']['diff']}\n";
}

file_put_contents($dir.'/phase-FINAL-metrics.md', $md);
file_put_contents($dir.'/phase-FINAL-metrics.json', json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fwrite(STDOUT, $md);
fwrite(STDOUT, "\nArtifacts written:\n");
fwrite(STDOUT, "  storage/app/stress/phase-FINAL-metrics.md\n");
fwrite(STDOUT, "  storage/app/stress/phase-FINAL-metrics.json\n");
exit(0);
