<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Http\Requests\Bus\CancelBusBookingRequest;
use App\Http\Requests\Bus\PayBusBookingRequest;
use App\Http\Requests\Bus\PayInventoryDebtRequest;
use App\Http\Requests\Bus\StoreBusBookingRequest;
use App\Http\Requests\Bus\StoreBusCompanyRequest;
use App\Http\Requests\Bus\StoreBusInventoryRequest;
use App\Http\Requests\Bus\UpdateBusCompanyRequest;
use App\Http\Requests\Bus\UpdateBusInventoryRequest;
use Illuminate\Contracts\Console\Kernel;

$requests = [
    'StoreBusCompanyRequest' => StoreBusCompanyRequest::class,
    'UpdateBusCompanyRequest' => UpdateBusCompanyRequest::class,
    'StoreBusInventoryRequest' => StoreBusInventoryRequest::class,
    'UpdateBusInventoryRequest' => UpdateBusInventoryRequest::class,
    'StoreBusBookingRequest' => StoreBusBookingRequest::class,
    'PayBusBookingRequest' => PayBusBookingRequest::class,
    'CancelBusBookingRequest' => CancelBusBookingRequest::class,
    'PayInventoryDebtRequest' => PayInventoryDebtRequest::class,
];

foreach ($requests as $name => $class) {
    if (class_exists($class)) {
        $req = new $class;
        echo "=== REQUEST: {$name} ===\n";
        if (method_exists($req, 'rules')) {
            echo 'Rules: '.json_encode($req->rules(), JSON_PRETTY_PRINT)."\n";
        }
    }
}
