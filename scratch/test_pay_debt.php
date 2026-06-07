<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\CustomerController;
use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$controller = app(CustomerController::class);
$customer = Customer::find(602);

$request = Request::create('/api/v1/customers/602/pay-debt', 'POST', [
    'amount' => 10.00,
    'account_id' => 636,
    'notes' => 'تجربة تسديد مديونية فوري من سكربت',
    'module' => 'fawry',
]);

try {
    $response = $controller->payDebt($request, $customer);
    echo 'Status code: '.$response->getStatusCode()."\n";
    echo 'Response content: '.$response->getContent()."\n";
} catch (Throwable $e) {
    echo 'Exception thrown: '.$e->getMessage()."\n";
    echo $e->getTraceAsString()."\n";
}
