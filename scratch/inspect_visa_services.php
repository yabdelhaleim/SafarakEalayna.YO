<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use ReflectionClass;

$services = [
    'App\Services\Visa\VisaBookingService',
    'App\Services\Visa\VisaModificationService',
    'App\Services\Visa\VisaRefundService',
    'App\Http\Controllers\Api\V1\Visa\VisaBookingController',
    'App\Http\Controllers\Api\V1\Visa\VisaAgentApiController',
    'App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController',
    'App\Http\Controllers\Api\V1\Visa\VisaTreasuryController',
    'App\Http\Controllers\Api\V1\VisaController',
];

echo "=== VISA SERVICES & CONTROLLERS METHOD DISCOVERY ===\n\n";

foreach ($services as $svc) {
    if (class_exists($svc)) {
        $ref = new ReflectionClass($svc);
        echo "CLASS: {$svc}\n";
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->class === $svc && ! str_starts_with($m->name, '__')) {
                $params = array_map(fn ($p) => '$'.$p->name, $m->getParameters());
                echo "  - {$m->name}(".implode(', ', $params).")\n";
            }
        }
        echo "\n";
    }
}
