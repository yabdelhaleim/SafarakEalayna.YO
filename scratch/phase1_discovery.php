<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusCompanyPayment;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\TreasuryTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

echo "=== ROUTE DISCOVERY ===\n";
$routeList = [];
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (str_contains($uri, 'bus') || str_contains($uri, 'company') || str_contains($uri, 'inventor') || str_contains($uri, 'booking')) {
        $action = $route->getActionName();
        $methods = implode('|', $route->methods());
        $middleware = implode(',', $route->gatherMiddleware());
        $routeList[] = [
            'methods' => $methods,
            'uri' => $uri,
            'action' => $action,
            'middleware' => $middleware,
        ];
        echo "[$methods] /{$uri} -> {$action} (mw: {$middleware})\n";
    }
}

echo "\n=== MODEL DISCOVERY ===\n";
$modelClasses = [
    BusCompany::class,
    BusInventory::class,
    BusBooking::class,
    BusPayment::class,
    BusCompanyPayment::class,
    BusRefundRequest::class,
    Customer::class,
    TreasuryTransaction::class,
];

foreach ($modelClasses as $m) {
    if (! class_exists($m)) {
        echo "Model missing: {$m}\n";

        continue;
    }
    $ref = new ReflectionClass($m);
    echo "MODEL: {$m}\n";
    echo '  Table: '.(new $m)->getTable()."\n";
    echo '  Fillable: '.implode(', ', (new $m)->getFillable())."\n";
    echo '  Casts: '.json_encode((new $m)->getCasts())."\n";
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
    $relations = [];
    foreach ($methods as $meth) {
        if ($meth->class === $m && $meth->getNumberOfParameters() === 0) {
            $returnType = (string) $meth->getReturnType();
            if (str_contains($returnType, 'Relation') || str_contains($meth->getName(), 'Relation') || in_array($meth->getName(), ['company', 'inventory', 'customer', 'payments', 'companyPayments', 'refundRequests', 'account', 'treasuryTransactions', 'bookings'])) {
                $relations[] = $meth->getName();
            }
        }
    }
    echo '  Relations: '.implode(', ', $relations)."\n\n";
}
