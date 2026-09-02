<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\BusBookingStatus;
use App\Enums\BusCompanyPaymentStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\BusPaymentStatus;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusCompanyService;
use App\Services\Bus\BusInventoryService;
use App\Services\Bus\BusRefundService;
use Illuminate\Contracts\Console\Kernel;

echo "=== ENUM VALUES ===\n";
echo 'BusBookingStatus: '.json_encode(array_column(BusBookingStatus::cases(), 'value'))."\n";
echo 'BusPaymentStatus: '.json_encode(array_column(BusPaymentStatus::cases(), 'value'))."\n";
echo 'BusCompanyPaymentStatus: '.json_encode(array_column(BusCompanyPaymentStatus::cases(), 'value'))."\n";
echo 'BusInventoryPaymentType: '.json_encode(array_column(BusInventoryPaymentType::cases(), 'value'))."\n";

echo "\n=== DISCOVERING SERVICE RULES ===\n";
$services = [
    BusBookingService::class,
    BusCompanyService::class,
    BusInventoryService::class,
    BusRefundService::class,
];

foreach ($services as $s) {
    $ref = new ReflectionClass($s);
    echo "SERVICE: {$s}\n";
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->class === $s) {
            $params = [];
            foreach ($m->getParameters() as $p) {
                $params[] = ($p->hasType() ? $p->getType().' ' : '').'$'.$p->getName();
            }
            echo '  function '.$m->getName().'('.implode(', ', $params).")\n";
        }
    }
    echo "\n";
}
