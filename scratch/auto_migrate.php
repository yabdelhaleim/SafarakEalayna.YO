<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

while (true) {
    echo "Running migrate...\n";
    try {
        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();
        echo $output;
    } catch (\Exception $e) {
        $output = $e->getMessage() . "\n" . Artisan::output();
        echo "Caught exception: " . $e->getMessage() . "\n";
        $exitCode = 1;
    }

    if ($exitCode === 0) {
        echo "Migrations finished successfully!\n";
        break;
    }

    // Identify the first pending migration from migrate:status
    Artisan::call('migrate:status');
    $statusOutput = Artisan::output();
    // Regex to match "Pending" line and extract the migration name
    if (preg_match('/\|\s+Pending\s+\|\s+([0-9_a-zA-Z]+)/', $statusOutput, $matches)) {
        $failedMigration = trim($matches[1]);
        echo "Identifying pending migration from table: '$failedMigration'\n";
    } elseif (preg_match('/([0-9_]{17,}[^ \.]+)\s+\.*?\s+Pending/', $statusOutput, $matches)) {
        $failedMigration = trim($matches[1]);
        echo "Identifying pending migration from list: '$failedMigration'\n";
    } else {
        $failedMigration = null;
    }

    if ($failedMigration) {
        if (strpos($output, 'duplicate column') !== false || 
            strpos($output, 'already exists') !== false ||
            strpos($output, 'Duplicate column') !== false) {
            
            echo "Faking migration '$failedMigration' because of existing schema.\n";
            $inserted = DB::table('migrations')->insert([
                'migration' => $failedMigration,
                'batch' => 999
            ]);
            echo "Insertion result: " . ($inserted ? "Success" : "Failed") . "\n";
            
            // Verify it's there
            $check = DB::table('migrations')->where('migration', $failedMigration)->exists();
            echo "Verification in DB: " . ($check ? "Found" : "NOT FOUND") . "\n";
        } else {
            echo "Migration failed with an unknown error. Stopping.\n";
            break;
        }
    } else {
        echo "No pending migrations found in status. Stopping.\n";
        break;
    }
}
