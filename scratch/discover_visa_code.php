<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\VisaAgent;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Models\VisaDuration;
use App\Models\VisaPayment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "====================================================\n";
echo "       VISA CODE & MODEL DEEP DISCOVERY             \n";
echo "====================================================\n\n";

// 1. Inspect Enums related to Visa
$enumDir = glob(__DIR__.'/../app/Enums/*Visa*.php');
$allEnums = glob(__DIR__.'/../app/Enums/*.php');
echo "Visa Enums Found:\n";
foreach ($allEnums as $file) {
    if (str_contains(strtolower(basename($file)), 'visa')) {
        echo ' - '.basename($file)."\n";
    }
}
echo "\n";

// 2. Inspect Model Fillables and Relations
$models = [
    'VisaBooking' => VisaBooking::class,
    'VisaDetail' => VisaDetail::class,
    'VisaPayment' => VisaPayment::class,
    'VisaAgent' => class_exists(VisaAgent::class) ? VisaAgent::class : null,
    'VisaDuration' => class_exists(VisaDuration::class) ? VisaDuration::class : null,
];

foreach ($models as $name => $class) {
    if (! $class) {
        continue;
    }
    $obj = new $class;
    echo "MODEL: {$name} (Table: {$obj->getTable()})\n";
    echo 'Fillable: '.implode(', ', $obj->getFillable())."\n";
    echo 'Casts: '.json_encode($obj->getCasts())."\n";
    echo "\n";
}

// 3. Inspect Existing Tests
$testFiles = array_merge(
    glob(__DIR__.'/../tests/**/*Visa*.php'),
    glob(__DIR__.'/../tests/**/Visa*.php'),
    glob(__DIR__.'/../tests/*Visa*.php')
);
echo 'Visa Test Files Found ('.count($testFiles)."):\n";
foreach ($testFiles as $file) {
    echo ' - '.str_replace(realpath(__DIR__.'/..').'/', '', realpath($file))."\n";
}
echo "\n";

// 4. Count Existing Visa Records in DB
echo "=== EXISTING DB RECORD COUNTS ===\n";
foreach (['visa_agents', 'visa_durations', 'visa_details', 'visa_bookings', 'visa_payments'] as $tbl) {
    $count = DB::table($tbl)->count();
    echo "Table `{$tbl}`: {$count} records\n";
}
