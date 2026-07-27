<?php

/**
 * Local Bus module verification runner.
 *
 * Runs the complete isolated Bus feature suite and emits a machine-readable
 * summary. PHPUnit's RefreshDatabase keeps all writes inside the local test
 * database; this script must never be pointed at a production environment.
 */

declare(strict_types=1);

$root = __DIR__;
$command = PHP_BINARY.' artisan test tests/Feature/Bus --compact';

$descriptor = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptor, $pipes, $root);
if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start the Bus test suite.\n");
    exit(2);
}

$output = stream_get_contents($pipes[1]);
$errorOutput = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$combined = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output.$errorOutput) ?? ($output.$errorOutput);
$summary = [
    'module' => 'bus',
    'environment' => getenv('APP_ENV') ?: 'unknown',
    'command' => $command,
    'exit_code' => $exitCode,
    'tests' => null,
    'passed' => null,
    'failed' => null,
    'assertions' => null,
    'status' => $exitCode === 0 ? 'passed' : 'failed',
];

if (preg_match('/Tests:\s+(?:(\d+) failed,\s+)?(\d+) passed\s+\((\d+) assertions\)/', $combined, $matches)) {
    $summary['failed'] = isset($matches[1]) ? (int) $matches[1] : 0;
    $summary['passed'] = (int) $matches[2];
    $summary['assertions'] = (int) $matches[3];
    $summary['tests'] = $summary['failed'] + $summary['passed'];
}

$reportPath = $root.'/bus_module_local_test_report.json';
file_put_contents(
    $reportPath,
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
);

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
fwrite(STDOUT, "Report: {$reportPath}\n");

exit($exitCode === 0 && ($summary['failed'] ?? 1) === 0 ? 0 : 1);
