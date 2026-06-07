<?php

use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$customers = Customer::where('full_name', 'like', '%Banna%')
    ->orWhere('phone', 'like', '%01235131232%')
    ->get();

echo 'Found '.$customers->count()." customers:\n";

foreach ($customers as $customer) {
    echo "=====================================\n";
    echo "Customer: {$customer->full_name} (ID: {$customer->id}), Phone: {$customer->phone}\n";

    $relations = [
        'flightBookings' => 'Flight Bookings',
        'visaBookings' => 'Visa Bookings',
        'hajjUmraBookings' => 'Hajj/Umra Bookings',
        'busBookings' => 'Bus Bookings',
        'fawryTransactions' => 'Fawry Transactions',
        'onlineTransactions' => 'Online Transactions',
    ];

    foreach ($relations as $rel => $label) {
        $count = $customer->$rel()->count();
        echo "- $label count: $count\n";
        if ($count > 0) {
            foreach ($customer->$rel as $b) {
                echo "  * ID: {$b->id}, Module property: ".(is_object($b->module) ? json_encode($b->module) : var_export($b->module, true)).', Class: '.get_class($b)."\n";
            }
        }
    }
}
