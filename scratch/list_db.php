<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Databases ===" . PHP_EOL;
$databases = DB::select("SHOW DATABASES");
foreach ($databases as $db) {
    $dbName = $db->Database ?? $db->{key((array)$db)};
    echo "  Database: {$dbName}" . PHP_EOL;
}

echo PHP_EOL . "=== Tables in Current DB ===" . PHP_EOL;
$tables = DB::select("SHOW TABLES");
foreach ($tables as $table) {
    $tableName = $table->{key((array)$table)};
    $count = DB::table($tableName)->count();
    echo "  Table: {$tableName} (Rows: {$count})" . PHP_EOL;
}
