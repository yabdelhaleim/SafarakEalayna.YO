<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->where('is_active', true)->first();
if (! $user) {
    echo "No active user\n";
    exit(1);
}

Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$endpoints = [
    '/api/v1/finance/accounts?per_page=100&is_active=1',
    '/api/v1/finance/accounts?per_page=100&is_active=true',
    '/api/v1/reports/transactions?per_page=15&page=1&from_date='.date('Y-m-d', strtotime('-30 days')).'&to_date='.date('Y-m-d'),
    '/api/v1/reports/financial/summary?from_date='.date('Y-m-d', strtotime('-30 days')).'&to_date='.date('Y-m-d'),
    '/api/v1/reports/financial/accounts-balance',
    '/api/v1/reports/capital-analysis?from_date='.date('Y-m-d', strtotime('-30 days')).'&to_date='.date('Y-m-d'),
];

foreach ($endpoints as $uri) {
    $request = Illuminate\Http\Request::create($uri, 'GET');
    $request->headers->set('Accept', 'application/json');
    try {
        $response = $kernel->handle($request);
        echo $uri.' => '.$response->getStatusCode()."\n";
        if ($response->getStatusCode() >= 500) {
            echo substr($response->getContent(), 0, 500)."\n";
        }
    } catch (Throwable $e) {
        echo $uri.' => EXCEPTION: '.$e->getMessage()."\n";
        echo $e->getFile().':'.$e->getLine()."\n";
    }
    $kernel->terminate($request, $response ?? null);
}
