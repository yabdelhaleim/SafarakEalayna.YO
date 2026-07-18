<?php
/**
 * Standalone runner for the manual deletion test.
 * Bootstraps Laravel and executes scripts_temp_test_deletion.php directly.
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Now execute the test script
echo "Booted. Running test...\n";
require __DIR__ . '/scripts_temp_test_deletion.php';
echo "Test script finished. Result file:\n";
echo storage_path('logs/manual_test_result.json') . "\n";