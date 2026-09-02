<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "====================================================\n";
echo "       VISA MODULE — DEEP INSPECTION DISCOVERY      \n";
echo "====================================================\n\n";

// 1. Inspect Files in app/Models/Visa or app/Models relating to Visa
$modelFiles = glob(__DIR__.'/../app/Models/Visa/*.php');
if (empty($modelFiles)) {
    $modelFiles = glob(__DIR__.'/../app/Models/Visa*.php');
}
echo 'Visa Models Found ('.count($modelFiles)."):\n";
foreach ($modelFiles as $file) {
    echo ' - '.basename($file)."\n";
}

// Also check all models in app/Models
$allModelFiles = glob(__DIR__.'/../app/Models/*.php');
foreach ($allModelFiles as $file) {
    if (str_contains(strtolower(basename($file)), 'visa')) {
        echo ' - '.basename($file)." (Root Models)\n";
    }
}
echo "\n";

// 2. Inspect Services
$serviceFiles = glob(__DIR__.'/../app/Services/Visa/*.php');
if (empty($serviceFiles)) {
    $serviceFiles = glob(__DIR__.'/../app/Services/*Visa*.php');
}
echo 'Visa Services Found ('.count($serviceFiles)."):\n";
foreach ($serviceFiles as $file) {
    echo ' - '.basename($file)."\n";
}
echo "\n";

// 3. Inspect Controllers
$controllerFiles = glob(__DIR__.'/../app/Http/Controllers/Api/V1/Visa/*.php');
$rootControllers = glob(__DIR__.'/../app/Http/Controllers/Api/V1/*Visa*.php');
$allControllers = array_merge($controllerFiles, $rootControllers);
echo 'Visa Controllers Found ('.count($allControllers)."):\n";
foreach ($allControllers as $file) {
    echo ' - '.basename($file)."\n";
}
echo "\n";

// 4. Inspect Form Requests
$requestFiles = glob(__DIR__.'/../app/Http/Requests/Visa/*.php');
$rootRequests = glob(__DIR__.'/../app/Http/Requests/*Visa*.php');
$allRequests = array_merge($requestFiles, $rootRequests);
echo 'Visa Form Requests Found ('.count($allRequests)."):\n";
foreach ($allRequests as $file) {
    echo ' - '.basename($file)."\n";
}
echo "\n";

// 5. Inspect Table Column details & Foreign Keys
$visaTables = ['visa_agents', 'visa_bookings', 'visa_details', 'visa_durations', 'visa_payments'];
$schemaData = [];

foreach ($visaTables as $table) {
    $cols = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
    $keys = DB::select("
        SELECT 
            COLUMN_NAME, 
            CONSTRAINT_NAME, 
            REFERENCED_TABLE_NAME, 
            REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$table}'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $schemaData[$table] = [
        'columns' => array_map(function ($c) {
            return [
                'field' => $c->Field,
                'type' => $c->Type,
                'null' => $c->Null,
                'key' => $c->Key,
                'default' => $c->Default,
                'extra' => $c->Extra,
                'comment' => $c->Comment,
            ];
        }, $cols),
        'foreign_keys' => $keys,
    ];
}

echo "=== TABLE STRUCTURES & FOREIGN KEYS ===\n";
foreach ($schemaData as $table => $data) {
    echo "\nTABLE: {$table}\n";
    echo "COLUMNS:\n";
    foreach ($data['columns'] as $c) {
        echo " - {$c['field']} ({$c['type']}) | Null: {$c['null']} | Default: ".var_export($c['default'], true)."\n";
    }
    if (! empty($data['foreign_keys'])) {
        echo "FOREIGN KEYS:\n";
        foreach ($data['foreign_keys'] as $fk) {
            echo " - {$fk->COLUMN_NAME} -> {$fk->REFERENCED_TABLE_NAME}({$fk->REFERENCED_COLUMN_NAME})\n";
        }
    }
}

file_put_contents(__DIR__.'/visa_deep_schema.json', json_encode($schemaData, JSON_PRETTY_PRINT));
