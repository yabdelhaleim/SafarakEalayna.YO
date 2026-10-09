<?php

// Read baseline_clean.txt and extract test names + status
$inputFile = $argv[1] ?? 'tmp_baseline/baseline_clean.txt';
$outputFile = $argv[2] ?? 'tmp_baseline/baseline_tests.txt';
$lines = file($inputFile);
$results = [];
$currentClass = '';

foreach ($lines as $line) {
    // Detect class line: "PASS Tests\Feature\Foo\BarTest" or "FAIL Tests\Feature\Foo\BarTest"
    if (preg_match('/^\s*(PASS|FAIL)\s+(Tests\\\\.+)$/', $line, $m)) {
        $currentClass = strip_tags($m[2]);
        $results[$currentClass] = [];

        continue;
    }
    // Detect test line: "✓ test name" or "⨯ test name"
    if (preg_match('/^\s*[✓⨯]\s+([^\x1b]+?)(?:\s+\d+\.\d+s)?\s*$/', $line, $m)) {
        $testName = trim(strip_tags($m[1]));
        if ($currentClass && $testName) {
            $results[$currentClass][] = $testName;
        }
    }
}

// Write summary
echo "Baseline summary:\n";
echo '  PASS classes: '.count(array_filter($results, function ($tests) {
    return ! empty($tests);
}))."\n";
echo '  Total test entries: '.array_sum(array_map('count', $results))."\n";

// Write to file for later comparison
$out = '';
foreach ($results as $class => $tests) {
    foreach ($tests as $t) {
        $out .= "$class::$t\n";
    }
}
file_put_contents($outputFile, $out);
echo 'Wrote '.count(explode("\n", trim($out)))." test entries to $outputFile\n";
