<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$migrationFiles = glob(__DIR__ . '/../database/migrations/*.php');
$appliedMigrations = DB::table('migrations')->pluck('migration')->toArray();

echo "Found " . count($migrationFiles) . " migration files.\n";
echo "Found " . count($appliedMigrations) . " applied migrations in DB.\n";

foreach ($migrationFiles as $file) {
    $filename = basename($file, '.php');
    
    if (in_array($filename, $appliedMigrations)) {
        echo "Already applied: $filename\n";
        continue;
    }

    // Check for "renamed" migrations (different prefix but same suffix)
    $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
    $renamed = false;
    foreach ($appliedMigrations as $applied) {
        $appliedSuffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $applied);
        $appliedSuffix = preg_replace('/^0001_01_01_\d{6}_/', '', $appliedSuffix);
        
        if ($suffix === $appliedSuffix) {
            echo "Updating renamed migration: $applied -> $filename\n";
            DB::table('migrations')->where('migration', $applied)->update(['migration' => $filename]);
            $renamed = true;
            break;
        }
    }

    if ($renamed) continue;

    // Try to "fake" if table already exists
    if (str_starts_with($suffix, 'create_')) {
        $tableName = str_replace('create_', '', $suffix);
        $tableName = str_replace('_table', '', $tableName);
        
        if (Schema::hasTable($tableName)) {
            echo "Table $tableName already exists, faking migration: $filename\n";
            DB::table('migrations')->insert([
                'migration' => $filename,
                'batch' => 1
            ]);
            continue;
        }
    }

    // Try to "fake" if column already exists (for "add_..._to_..._table")
    if (preg_match('/^add_(.*)_to_(.*)_table$/', $suffix, $matches)) {
        $columnName = $matches[1];
        $tableName = $matches[2];
        
        // Handle multiple columns if name is something like "baggage_and_passport"
        $columns = explode('_and_', $columnName);
        $allExist = true;
        foreach ($columns as $col) {
            if (!Schema::hasColumn($tableName, $col)) {
                $allExist = false;
                break;
            }
        }

        if ($allExist) {
            echo "Column(s) $columnName already exist in $tableName, faking migration: $filename\n";
            DB::table('migrations')->insert([
                'migration' => $filename,
                'batch' => 1
            ]);
            continue;
        }
    }
    
    echo "Pending migration: $filename\n";
}

echo "Done.\n";
