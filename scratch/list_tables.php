<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select('SHOW TABLES');
$dbName = DB::getDatabaseName();
$prop = "Tables_in_" . $dbName;

echo "Database: {$dbName}\n";
foreach ($tables as $table) {
    $tableName = $table->$prop;
    $count = DB::table($tableName)->count();
    echo "Table: {$tableName} | Rows: {$count}\n";
}
