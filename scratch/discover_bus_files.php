<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function scanDirRecursive($dir, &$results = [])
{
    if (! is_dir($dir)) {
        return $results;
    }
    $files = scandir($dir);
    foreach ($files as $key => $value) {
        $path = realpath($dir.DIRECTORY_SEPARATOR.$value);
        if (! is_dir($path)) {
            $results[] = $path;
        } elseif ($value != '.' && $value != '..') {
            scanDirRecursive($path, $results);
        }
    }

    return $results;
}

$allFiles = [];
scanDirRecursive(__DIR__.'/../app', $allFiles);
scanDirRecursive(__DIR__.'/../routes', $allFiles);
scanDirRecursive(__DIR__.'/../database', $allFiles);
scanDirRecursive(__DIR__.'/../config', $allFiles);
scanDirRecursive(__DIR__.'/../tests', $allFiles);

$busFiles = [];
foreach ($allFiles as $file) {
    $rel = str_replace(realpath(__DIR__.'/..').DIRECTORY_SEPARATOR, '', $file);
    if (preg_match('/bus/i', $file) || preg_match('/trip/i', $file) || preg_match('/station/i', $file)) {
        $busFiles[] = $rel;

        continue;
    }
    $content = file_get_contents($file);
    if (preg_match('/BusBooking|BusTrip|BusRoute|BusSeat|BusOperator|BusStation|BusTicket|BusPassenger/i', $content)) {
        $busFiles[] = $rel;
    }
}

sort($busFiles);
echo 'TOTAL_BUS_FILES: '.count($busFiles)."\n";
foreach ($busFiles as $f) {
    echo $f."\n";
}
