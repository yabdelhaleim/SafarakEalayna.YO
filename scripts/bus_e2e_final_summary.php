<?php

// Quick summary printer: reads the latest JSON report and produces a one-page summary
$report = json_decode(file_get_contents(__DIR__.'/../storage/logs/bus_e2e_final_report.json'), true);
if (! $report) {
    echo "No report found.\n";
    exit(1);
}

$defectCount = $report['defect_count'];
$opCount = count($report['operations']);
$snapCount = count($report['snapshots']);
$authzCount = count($report['authz_matrix']);
$runAt = $report['run_at'];

echo "=========================================\n";
echo "FINAL E2E RUN SUMMARY\n";
echo "=========================================\n";
echo "Run at: $runAt\n";
echo "Operations performed: $opCount\n";
echo "Balance snapshots: $snapCount\n";
echo "Authz probes: $authzCount\n";
echo "DEFECTS FOUND: $defectCount\n";

if ($defectCount > 0) {
    echo "\nDefects:\n";
    foreach ($report['defects'] as $d) {
        echo "  - {$d['label']}\n";
    }
}

echo "\nFinal balance snapshot:\n";
$final = end($report['snapshots']);
echo json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
