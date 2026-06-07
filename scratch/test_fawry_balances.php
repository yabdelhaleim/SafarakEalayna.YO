<?php

// Boot Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\Fawry\FawryTransactionController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$controller = app(FawryTransactionController::class);

echo "=== CUSTOMER BALANCES ===\n";
$request = new Request([
    'status' => 'all',
]);
$response = $controller->customerBalances($request);
$data = json_decode($response->getContent(), true);

foreach ($data['data'] as $row) {
    if ($row['client_id'] == 602) {
        print_r($row);
    }
}

echo "\n=== CUSTOMER STATEMENT ===\n";
$request = new Request([
    'client_id' => 602,
]);
$response = $controller->customerStatement($request);
$statementData = json_decode($response->getContent(), true);
print_r($statementData['data']);
