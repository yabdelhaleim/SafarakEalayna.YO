<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Phase Baseline (Re-run existing flight_module_full_e2e.php)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يجبر SQLite على البيئة ثم يستدعي flight_module_full_e2e.php كـ subprocess
 * منفصل بحيث تستخدم نفس قاعدة بيانات الـ Audit (storage/app/local_flight_audit.sqlite).
 *
 * الـ Output:
 *   - storage/logs/flight_audit_baseline_results.json
 *   - عرض في stdout
 */

// ─── Step 0: Verify audit DB exists ──────────────────────────────────────
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_flight_audit.sqlite';
$projectRoot = realpath(__DIR__.'/..');

if (! file_exists($dbPath)) {
    echo "❌ FATAL: Audit DB not found at $dbPath\n";
    echo "   Run  php scripts/flight_audit_setup.php  first\n";
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Flight Baseline — Re-running flight_module_full_e2e.php\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  DB: $dbPath\n\n";

// ─── Step 1: Run the baseline as a subprocess ───────────────────────────
//   We don't bootstrap in the wrapper so the baseline's own bootstrap
//   doesn't conflict with our Laravel helpers. We pass env vars via the
//   env parameter of proc_open so the child inherits the SQLite config.
$baselineScript = __DIR__.'/flight_module_full_e2e.php';
if (! file_exists($baselineScript)) {
    echo "❌ FATAL: $baselineScript not found\n";
    exit(1);
}

// Build env vars for the child (inherits parent env, then overrides DB_*)
$env = [
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $dbPath,
    'PATH' => getenv('PATH'),
    'SystemRoot' => getenv('SystemRoot'),
    'TEMP' => getenv('TEMP'),
    'TMP' => getenv('TMP'),
    'SystemDrive' => getenv('SystemDrive'),
];

$php = PHP_BINARY ?: 'php';
$cmd = sprintf(
    '"%s" -d "display_errors=1" -d "error_reporting=E_ALL" -f %s',
    $php,
    escapeshellarg($baselineScript)
);

$start = microtime(true);
$startTime = date('Y-m-d H:i:s');
$returnCode = 0;

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptors, $pipes, $projectRoot, $env);
if (! is_resource($process)) {
    echo "❌ FATAL: Could not start subprocess\n";
    exit(1);
}

fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errOutput = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$returnCode = proc_close($process);

echo $output;
if ($errOutput) {
    echo "\n--- stderr ---\n$errOutput\n";
}

$end = microtime(true);
$duration = round($end - $start, 2);

// ─── Step 2: Try to read the existing JSON results ──────────────────────
$resultsJson = $projectRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'flight_full_e2e_results.json';
$baselineResults = [];
if (file_exists($resultsJson)) {
    $baselineResults = json_decode(file_get_contents($resultsJson), true) ?? [];
}

// ─── Step 3: Print summary ──────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Baseline Run Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Started:  $startTime\n";
echo "  Duration: {$duration}s\n";
echo "  Exit:     $returnCode\n";

if ($baselineResults) {
    $passed = $baselineResults['verdict']['passed'] ?? 0;
    $failed = $baselineResults['verdict']['failed'] ?? 0;
    $tests = $baselineResults['tests'] ?? [];
    echo "  Passed:   $passed\n";
    echo "  Failed:   $failed\n";
    echo '  Total:    '.count($tests)."\n";
    echo '  Issues:   '.count($baselineResults['verdict']['issues'] ?? [])."\n";
} else {
    echo "  (No JSON results file found at $resultsJson)\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n\n";
exit($returnCode);
