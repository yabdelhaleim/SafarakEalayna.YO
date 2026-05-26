<?php

// Load Laravel bootstrap
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Explicitly set the pagination page resolver
Illuminate\Pagination\Paginator::currentPageResolver(fn() => 3);

$filters = ['type' => 'regular', 'per_page' => 15];
$customerService = app(App\Services\CustomerService::class);
$paginator = $customerService->getAllCustomers($filters);

$resource = App\Http\Resources\CustomerResource::collection($paginator);
$data = $resource->response()->getData(true);

echo "Actual API Page 3 Items with page resolver set to 3:\n";
foreach ($data['data'] as $i => $item) {
    echo "Item " . ($i + 1) . ": Name: {$item['full_name']}, Balance: {$item['balance']}, Type: {$item['type']}\n";
}
