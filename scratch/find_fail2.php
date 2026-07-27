<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$files = glob(storage_path('logs/visa_production_full_test_v3_*.json'));
$latest = end($files);
$data = json_decode(file_get_contents($latest), true);

$trueCount = 0;
$falseCount = 0;
foreach ($data['results'] as $k => $v) {
    if ($v['passed']) {
        $trueCount++;
    } else {
        $falseCount++;
        echo "FALSE STEP: {$k}\n";
    }
}
echo "True count in results: {$trueCount} | False count in results: {$falseCount}\n";
echo "JSON root pass: {$data['pass']} | JSON root fail: {$data['fail']}\n";
