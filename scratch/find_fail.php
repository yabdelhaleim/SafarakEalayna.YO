<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$files = glob(storage_path('logs/visa_production_full_test_v3_*.json'));
$latest = end($files);
echo "Reading: " . basename($latest) . PHP_EOL;
$data = json_decode(file_get_contents($latest), true);
echo "Pass: {$data['pass']} | Fail: {$data['fail']} | Total: {$data['total']}" . PHP_EOL;
foreach ($data['results'] as $k => $v) {
    if (!$v['passed']) {
        echo "FAILED: {$k} => {$v['detail']}" . PHP_EOL;
    }
}
