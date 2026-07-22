<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$proxy = \App\Helpers\CacheHelper::tags(['accounts']);
echo "Class: " . get_class($proxy) . PHP_EOL;
$res = $proxy->remember('test_key', 30, fn() => 'computed_value');
echo "Result: " . var_export($res, true) . PHP_EOL;

$keys = DB::table('cache')->where('key', 'like', '%test_key%')->get();
foreach ($keys as $k) {
    echo "Cache row: " . $k->key . PHP_EOL;
}

echo "FINANCE_LISTING_NAMESPACE constant: " . \App\Helpers\CacheHelper::FINANCE_LISTING_NAMESPACE . PHP_EOL;
