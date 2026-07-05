<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightCarrier;
use App\Models\Customer;
use App\Models\Account;

echo "--- Flight Systems ---\n";
foreach (FlightSystem::all() as $fs) {
    echo "ID: {$fs->id} | Name: {$fs->name} | Balance: {$fs->balance} {$fs->currency}\n";
}

echo "\n--- Flight Carriers ---\n";
foreach (FlightCarrier::all() as $fc) {
    echo "ID: {$fc->id} | Name: {$fc->name} | Balance: {$fc->balance} {$fc->currency}\n";
}

echo "\n--- Accounts (Cashboxes) ---\n";
foreach (Account::where('type', 'cashbox')->where('module_type', 'flights')->get() as $acc) {
    echo "ID: {$acc->id} | Name: {$acc->name} | Balance: {$acc->balance} {$acc->currency}\n";
}

echo "\n--- First Customer ---\n";
$cust = Customer::first();
if ($cust) {
    echo "ID: {$cust->id} | Name: {$cust->full_name}\n";
}
