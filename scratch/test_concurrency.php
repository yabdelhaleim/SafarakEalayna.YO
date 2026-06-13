<?php

/**
 * Quick concurrency smoke test for the local API server.
 * Usage: php scratch/test_concurrency.php [base_url] [concurrency]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$concurrency = max(2, (int) ($argv[2] ?? 4));
$path = '/api/v1/health';

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->where('is_active', true)->first();
$token = $user?->createToken('concurrency-test')->plainTextToken;

$mh = curl_multi_init();
$handles = [];
$started = microtime(true);

for ($i = 0; $i < $concurrency; $i++) {
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: application/json',
            $token ? 'Authorization: Bearer '.$token : null,
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1.0);
} while ($running > 0);

$elapsed = round((microtime(true) - $started) * 1000);
$ok = 0;
$codes = [];

foreach ($handles as $ch) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $codes[] = $code;
    if ($code >= 200 && $code < 300) {
        $ok++;
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

echo "Base URL: {$base}\n";
echo "Concurrent requests: {$concurrency}\n";
echo "HTTP codes: ".implode(', ', $codes)."\n";
echo "Successful: {$ok}/{$concurrency}\n";
echo "Total wall time: {$elapsed} ms\n";

if ($ok === $concurrency && $elapsed < 5000) {
    echo "PASS: server handled concurrent requests.\n";
    exit(0);
}

echo "WARN: slow or failed responses — check PHP_CLI_SERVER_WORKERS or use Laragon Apache.\n";
exit(1);
