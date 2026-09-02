<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = [
    'bus_companies',
    'bus_inventories',
    'bus_bookings',
    'bus_payments',
    'bus_company_payments',
    'bus_refund_requests',
    'customers',
    'accounts',
    'account_entries',
    'treasury_transactions',
];

$output = "# BUS DATABASE MAP\n\n";
$output .= 'Database: '.DB::getDatabaseName()."\n";
$output .= 'Driver: '.DB::getDriverName()."\n\n";

foreach ($tables as $table) {
    if (! Schema::hasTable($table)) {
        $output .= "## Table: {$table} (NOT FOUND)\n\n";

        continue;
    }
    $output .= "## Table: `{$table}`\n\n";
    $columns = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
    $indexes = DB::select("SHOW INDEX FROM `{$table}`");

    $output .= "| Column | Type | Nullable | Key | Default | Extra | Comment |\n";
    $output .= "| --- | --- | --- | --- | --- | --- | --- |\n";
    foreach ($columns as $col) {
        $null = $col->Null === 'YES' ? 'YES' : 'NO';
        $default = $col->Default !== null ? "`{$col->Default}`" : 'NULL';
        $comment = str_replace("\n", ' ', $col->Comment ?? '');
        $output .= "| `{$col->Field}` | `{$col->Type}` | {$null} | `{$col->Key}` | {$default} | `{$col->Extra}` | {$comment} |\n";
    }
    $output .= "\n### Indexes for `{$table}`\n\n";
    $output .= "| Key Name | Column Name | Non Unique | Index Type |\n";
    $output .= "| --- | --- | --- | --- |\n";
    foreach ($indexes as $idx) {
        $output .= "| `{$idx->Key_name}` | `{$idx->Column_name}` | {$idx->Non_unique} | `{$idx->Index_type}` |\n";
    }
    $output .= "\n---\n\n";
}

// Check Foreign keys in database
$fkQuery = "
SELECT 
    TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    REFERENCED_TABLE_SCHEMA = ?
    AND TABLE_NAME IN ('".implode("','", $tables)."')
";
$fks = DB::select($fkQuery, [DB::getDatabaseName()]);

$output .= "## Foreign Keys Map\n\n";
$output .= "| Table | Column | Constraint | Referenced Table | Referenced Column |\n";
$output .= "| --- | --- | --- | --- | --- |\n";
foreach ($fks as $fk) {
    $output .= "| `{$fk->TABLE_NAME}` | `{$fk->COLUMN_NAME}` | `{$fk->CONSTRAINT_NAME}` | `{$fk->REFERENCED_TABLE_NAME}` | `{$fk->REFERENCED_COLUMN_NAME}` |\n";
}

file_put_contents(__DIR__.'/../BUS_DATABASE_MAP.md', $output);
file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_DATABASE_MAP.md', $output);
echo "BUS_DATABASE_MAP.md generated successfully!\n";
