<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo 'APP_ENV: '.config('app.env')."\n";
echo 'DB_CONNECTION: '.config('database.default')."\n";
$conn = config('database.default');
echo 'DB_HOST: '.config("database.connections.{$conn}.host")."\n";
echo 'DB_DATABASE: '.config("database.connections.{$conn}.database")."\n";

try {
    $db = DB::select('SELECT DATABASE() as db');
    echo 'SELECT DATABASE(): '.($db[0]->db ?? 'N/A')."\n";
} catch (Throwable $e) {
    echo 'SELECT DATABASE() ERROR: '.$e->getMessage()."\n";
}
