<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "=== PHASE 2: SAFETY CHECK ===\n";
echo 'APP_ENV: '.config('app.env')."\n";
echo 'DB_CONNECTION: '.config('database.default')."\n";
$conn = config('database.default');
echo 'DB_HOST: '.config("database.connections.{$conn}.host")."\n";
echo 'DB_DATABASE: '.config("database.connections.{$conn}.database")."\n";
$db = DB::select('SELECT DATABASE() as db');
echo 'SELECT DATABASE(): '.($db[0]->db ?? 'N/A')."\n";

$isLocal = config('app.env') === 'local' || config('app.env') === 'testing';
$isLocalHost = in_array(config("database.connections.{$conn}.host"), ['127.0.0.1', 'localhost']);

echo 'Is Safe Local Test Database: '.($isLocal && $isLocalHost ? 'YES (SAFE TO PROCEED)' : 'NO (STOP)')."\n";

echo 'Existing Users Count: '.User::count()."\n";
echo 'Existing Customers Count: '.Customer::count()."\n";
echo 'Existing Bus Companies Count: '.BusCompany::count()."\n";
echo 'Existing Bus Inventories Count: '.BusInventory::count()."\n";
echo 'Existing Bus Bookings Count: '.BusBooking::count()."\n";
