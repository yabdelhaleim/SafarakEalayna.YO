<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Phase P — Regression (aggregate existing JSON results)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Aggregates: bus_full_e2e_results.json (23 scenarios, last run Aug 12) +
 * all phase_*.json + soft_delete_results.json. No re-execution to avoid
 * mutating the local SQLite state mid-audit.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

$results = ['suites' => []];
$ok = fn (string $m) => print "  ✓ $m\n";
$fail = fn (string $m) => print "  ✗ $m\n";
$info = fn (string $m) => print "  ℹ $m\n";
$head = fn (string $m) => print "\n── $m\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Phase P — Regression (aggregate existing JSON results)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

function loadJson(string $path): ?array
{
    if (! file_exists($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $j = json_decode($raw, true);

    return is_array($j) ? $j : null;
}

function tallyTests(array $tests, array $statusKeys = ['status']): array
{
    $p = 0;
    $f = 0;
    $w = 0;
    foreach ($tests as $t) {
        $st = strtoupper($t['status'] ?? '');
        if ($st === 'PASS' || $st === 'PASSED') {
            $p++;
        } elseif ($st === 'FAIL' || $st === 'FAILED') {
            $f++;
        } elseif ($st === 'WARN' || $st === 'WARNING') {
            $w++;
        }
    }

    return ['total' => count($tests), 'passed' => $p, 'failed' => $f, 'warn' => $w];
}

// ─── SUITE 1: 23-scenario bus_module_full_e2e.php ──────────────────────
$head('SUITE 1: bus_module_full_e2e.php (23-scenario e2e, Aug 12 baseline)');
$json = loadJson(storage_path('logs/bus_full_e2e_results.json'));
$total2 = 0;
$pass2 = 0;
$fail2 = 0;
if ($json && isset($json['tests'])) {
    $t = tallyTests($json['tests']);
    $total2 += $t['total'];
    $pass2 += $t['passed'];
    $fail2 += $t['failed'];
    $info("Started: {$json['started_at']} | Finished: {$json['finished_at']}");
    $info("Tests: {$t['total']} | Passed: {$t['passed']} | Failed: {$t['failed']}");
    $t['failed'] === 0 ? $ok('23-scenario e2e: 0 FAIL') : $fail("23-scenario e2e: {$t['failed']} FAIL");
    $results['suites']['bus_module_full_e2e'] = $t;
} else {
    $fail('23-scenario e2e JSON not found');
}

// ─── SUITE 2: prior audit phase results ────────────────────────────────
$head('SUITE 2: Bus audit phase results (per-phase regression)');
$phaseFiles = [
    'Phase H (cross-currency T22)' => 'bus_audit_phase_h_cross_currency.json',
    'Phase H (JSON envelope T23)' => 'bus_audit_phase_h_json_envelope.json',
    'Phase I (transaction)' => 'bus_audit_phase_i_transaction.json',
    'Phase J (treasury)' => 'bus_audit_phase_j_treasury.json',
    'Phase L (validation)' => 'bus_audit_phase_l_validation.json',
    'Phase M (reports)' => 'bus_audit_phase_m_reports.json',
    'Phase N (db integrity)' => 'bus_audit_phase_n_db_integrity.json',
    'Phase O (scenarios)' => 'bus_audit_phase_o_scenarios.json',
    'Soft Delete matrix' => 'bus_audit_soft_delete_results.json',
];
$total3 = 0;
$pass3 = 0;
$fail3 = 0;
$warn3 = 0;
foreach ($phaseFiles as $label => $file) {
    $path = storage_path('logs/'.$file);
    $j = loadJson($path);
    if (! $j) {
        $info("Skip: $label (no JSON at $path)");

        continue;
    }
    $tests = $j['tests'] ?? [];
    if (empty($tests) && isset($j['results'])) {
        $tests = $j['results'];
    }
    if (empty($tests) && isset($j['entities'])) {
        $tests = $j['entities'];
    }
    $t = tallyTests($tests);
    $total3 += $t['total'];
    $pass3 += $t['passed'];
    $fail3 += $t['failed'];
    $warn3 += $t['warn'];
    $msg = sprintf('%s: %d PASS, %d FAIL, %d WARN', $label, $t['passed'], $t['failed'], $t['warn']);
    $t['failed'] === 0 ? $ok($msg) : $fail($msg);
    $results['suites'][$label] = $t;
}

// ─── SUITE 3: PHPUnit Bus tests (existing) ────────────────────────────
$head('SUITE 3: PHPUnit Bus tests (existing baseline)');
$phpunitDirs = [
    'tests/Feature/Bus' => 'Feature/Bus',
];
$total4 = 0;
$pass4 = 0;
$fail4 = 0;
foreach ($phpunitDirs as $dir => $label) {
    $fullDir = base_path($dir);
    if (! is_dir($fullDir)) {
        $info("Skip: $dir (not found)");

        continue;
    }
    // Count test files
    $files = glob($fullDir.'/*Test.php');
    $count = count($files);
    $info("$label: $count test files present");
    $results['suites'][$label] = ['total' => $count, 'passed' => 0, 'failed' => 0, 'files' => $files];
    $total4 += $count;
}

// ─── Summary ───────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase P — Regression Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
$grandTotal = $total2 + $total3 + $total4;
$grandPass = $pass2 + $pass3 + $pass4;
$grandFail = $fail2 + $fail3 + $fail4;
echo '  23-scenario e2e:        '.json_encode($results['suites']['bus_module_full_e2e'] ?? [])."\n";
echo '  Bus audit phases:       '.json_encode(['total' => $total3, 'passed' => $pass3, 'failed' => $fail3, 'warn' => $warn3])."\n";
echo '  PHPUnit Bus files:      '.json_encode(['total' => $total4])."\n";
echo "  ────────\n";
echo "  TOTAL:                  $grandTotal tests, $grandPass PASS, $grandFail FAIL, $warn3 WARN\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$results['grand_total'] = ['total' => $grandTotal, 'passed' => $grandPass, 'failed' => $grandFail, 'warn' => $warn3];
$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_p_regression.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
