<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$dir = __DIR__.'/../vendor/filament/tables/src/Actions';
if (is_dir($dir)) {
    $files = scandir($dir);
    echo "Files in vendor/filament/tables/src/Actions:\n";
    print_r($files);
} else {
    echo "Directory does not exist!\n";
}
