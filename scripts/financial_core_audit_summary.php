<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * ════════════════════════════════════════════════════════════════════════════
 * FINANCIAL CORE AUDIT — SUMMARY SCRIPT (2026-08-14)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Reads the most recent JSON report from storage/logs/financial_core_audit_*.json
 * and prints a one-page defect summary on stdout.
 *
 * Usage:
 *   php scripts/financial_core_audit_summary.php
 *   php scripts/financial_core_audit_summary.php path/to/report.json
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ─── Resolve the report path ───
$reportPath = $argv[1] ?? null;
if (! $reportPath) {
    $logs = glob(__DIR__.'/../storage/logs/financial_core_audit_*_report.json');
    if (empty($logs)) {
        fwrite(STDERR, "❌ No report found in storage/logs/financial_core_audit_*_report.json\n");
        fwrite(STDERR, "Run the audit first: php scripts/financial_core_audit_run.php\n");
        exit(1);
    }
    rsort($logs); // newest first
    $reportPath = $logs[0];
}

if (! file_exists($reportPath)) {
    fwrite(STDERR, "❌ Report not found: $reportPath\n");
    exit(1);
}

$report = json_decode(file_get_contents($reportPath), true);
if (! $report) {
    fwrite(STDERR, "❌ Invalid JSON in: $reportPath\n");
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  FINANCIAL CORE AUDIT — DEFECT SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo '  Report: '.basename($reportPath)."\n";
echo '  Run at: '.($report['run_at'] ?? 'unknown')."\n";
echo '  Database: '.($report['database'] ?? 'unknown')."\n\n";

// ─── Phase-by-phase summary ───
$totalPassed = 0;
$totalFailed = 0;
$defects = $report['defects'] ?? [];

echo "  Phase Results:\n";
echo "  ─────────────────────────────────────────────────────────────────────\n";
foreach ($report['phases'] ?? [] as $phaseName => $phase) {
    $p = $phase['passed'] ?? 0;
    $f = $phase['failed'] ?? 0;
    $totalPassed += $p;
    $totalFailed += $f;
    $status = $f === 0 ? '✅' : '❌';
    printf("  %s %-40s %d passed, %d failed\n", $status, $phaseName, $p, $f);
}
echo "  ─────────────────────────────────────────────────────────────────────\n";
printf("  TOTAL: %d passed, %d failed\n\n", $totalPassed, $totalFailed);

// ─── Defect catalog ───
echo '  Defects ('.count($defects)." total):\n";
echo "  ─────────────────────────────────────────────────────────────────────\n";
if (empty($defects)) {
    echo "  (none — clean run!)\n";
} else {
    foreach ($defects as $i => $d) {
        $phase = $d['phase'] ?? '?';
        $label = $d['label'] ?? '?';
        echo '  #'.($i + 1)." [$phase] $label\n";
        // Print any ctx (excluding the obvious keys)
        unset($d['phase'], $d['label']);
        if (! empty($d)) {
            $ctx = [];
            foreach ($d as $k => $v) {
                if (is_scalar($v)) {
                    $ctx[] = "$k=".(string) $v;
                } elseif (is_array($v)) {
                    $ctx[] = "$k=".json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }
            if (! empty($ctx)) {
                echo '       '.implode(', ', array_slice($ctx, 0, 4))."\n";
            }
        }
    }
}
echo "\n";

// ─── Verdict ───
echo "  ─────────────────────────────────────────────────────────────────────\n";
$verdict = '✅ GO';
if ($totalFailed > 0 && $totalFailed <= 2) {
    $verdict = '⚠️  CONDITIONAL GO — review '.count($defects).' defect(s)';
} elseif ($totalFailed > 2) {
    $verdict = '❌ NO-GO — '.count($defects).' defect(s)';
}
echo "  Verdict: $verdict\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";
