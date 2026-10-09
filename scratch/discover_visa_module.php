<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

echo "====================================================\n";
echo "       VISA MODULE — PHASE 1 DISCOVERY SCRIPT       \n";
echo "====================================================\n\n";

// 1. Environment & DB Identity Check
echo "--- 1. ENVIRONMENT & DB IDENTITY CHECK ---\n";
$env = config('app.env');
$conn = config('database.default');
$host = config("database.connections.{$conn}.host");
$port = config("database.connections.{$conn}.port");
$database = config("database.connections.{$conn}.database");
$selectDb = DB::select('SELECT DATABASE() as db')[0]->db ?? '';
$laravelVer = app()->version();
$phpVer = PHP_VERSION;

echo "APP_ENV: {$env}\n";
echo "DB_CONNECTION: {$conn}\n";
echo "DB_HOST: {$host}\n";
echo "DB_PORT: {$port}\n";
echo "DB_DATABASE: {$database}\n";
echo "SELECT DATABASE(): {$selectDb}\n";
echo "Laravel Version: {$laravelVer}\n";
echo "PHP Version: {$phpVer}\n\n";

if ($env !== 'local' && $env !== 'testing') {
    echo "CRITICAL WARNING: APP_ENV is set to '{$env}'. Stopping discovery.\n";
    exit(1);
}

// 2. Discover Database Tables relating to Visa
echo "--- 2. VISA DATABASE TABLES DISCOVERY ---\n";
$allTables = DB::select('SHOW TABLES');
$tableKey = 'Tables_in_'.$database;
$visaTables = [];

foreach ($allTables as $t) {
    $tName = $t->$tableKey;
    if (str_contains(strtolower($tName), 'visa')) {
        $visaTables[] = $tName;
    }
}

echo 'Found Visa-related tables ('.count($visaTables).'): '.implode(', ', $visaTables)."\n\n";

// Inspect schema for each visa table
$tableSchemas = [];
foreach ($visaTables as $table) {
    $columns = Schema::getColumnListing($table);
    $columnDetails = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
    $indexes = DB::select("SHOW INDEX FROM `{$table}`");

    $tableSchemas[$table] = [
        'columns' => $columns,
        'column_details' => $columnDetails,
        'indexes' => $indexes,
    ];
}

// 3. Discover Visa Routes in API
echo "--- 3. VISA API ROUTES DISCOVERY ---\n";
$routes = Route::getRoutes();
$visaRoutes = [];

foreach ($routes as $route) {
    $uri = $route->uri();
    if (str_contains(strtolower($uri), 'visa')) {
        $visaRoutes[] = [
            'methods' => implode('|', $route->methods()),
            'uri' => $uri,
            'action' => $route->getActionName(),
            'middleware' => implode(',', $route->gatherMiddleware()),
        ];
    }
}

echo 'Found Visa-related API routes ('.count($visaRoutes)."):\n";
foreach ($visaRoutes as $r) {
    echo "  [{$r['methods']}] /{$r['uri']} -> {$r['action']}\n";
}
echo "\n";

// 4. Output Raw Discovery JSON for Analysis
$discoveryOutput = [
    'environment' => [
        'env' => $env,
        'connection' => $conn,
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'active_database' => $selectDb,
        'laravel_version' => $laravelVer,
        'php_version' => $phpVer,
    ],
    'visa_tables' => $visaTables,
    'table_schemas' => $tableSchemas,
    'visa_routes' => $visaRoutes,
];

file_put_contents(__DIR__.'/visa_raw_discovery.json', json_encode($discoveryOutput, JSON_PRETTY_PRINT));
echo "Raw discovery data saved to scratch/visa_raw_discovery.json\n";
