<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Flight\FlightCarrier;

$carriers = FlightCarrier::all();
echo "Carriers count: " . count($carriers) . "\n";
foreach ($carriers as $c) {
    if ($c->balance < 0) {
        echo "Carrier ID: {$c->id} | Name: {$c->name} | Code: {$c->code} | Active: {$c->is_active} | Balance: {$c->balance} | Credit Limit: {$c->credit_limit} | Available: {$c->available_balance}\n";
    }
}
