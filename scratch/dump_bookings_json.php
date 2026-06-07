<?php

use App\Http\Controllers\Api\V1\CustomerController;
use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$controller = app(CustomerController::class);
$customer = Customer::findOrFail(301);
$request = Request::create('/api/v1/customers/301/statement', 'GET');

$response = $controller->statement($request, $customer);
$data = json_decode($response->getContent(), true);

echo "SUCCESS!\n";
echo 'Keys: '.implode(', ', array_keys($data['data']))."\n";

$bookings = $data['data']['bookings'];
echo 'Bookings keys: '.implode(', ', array_keys($bookings))."\n";

foreach ($bookings as $key => $list) {
    echo "  - Module: $key, Type: ".gettype($list).', Count: '.count($list)."\n";
    if (count($list) > 0) {
        echo '    First item keys: '.implode(', ', array_keys($list[0]))."\n";
        echo '    First item data: '.json_encode($list[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    }
}
