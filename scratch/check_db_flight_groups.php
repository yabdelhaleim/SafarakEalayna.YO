<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightGroup;
use Illuminate\Support\Facades\DB;

$groups = FlightGroup::with(['account', 'carrier'])->get();
echo "Total Flight Groups: " . $groups->count() . "\n";
foreach ($groups as $g) {
    $totalDebt = (float) $g->groupTransactions()->where('type', 'debt')->sum('amount');
    $totalPayment = (float) $g->groupTransactions()->where('type', 'payment')->sum('amount');
    $calculated = $totalPayment - $totalDebt;
    $accountBalance = $g->account ? (float) $g->account->balance : 0.0;
    
    echo "ID: {$g->id}\n";
    echo "Name: {$g->name}\n";
    echo "Code: {$g->code}\n";
    echo "Carrier: " . ($g->carrier ? "{$g->carrier->name} ({$g->carrier->currency})" : "None") . "\n";
    echo "Account ID: {$g->account_id} (Balance: {$accountBalance})\n";
    echo "Calculated Balance (Payments - Debts): {$calculated}\n";
    echo "Transactions:\n";
    foreach ($g->groupTransactions as $t) {
        echo "  - Type: {$t->type}, Amount: {$t->amount}, Notes: {$t->notes}\n";
    }
    echo "---------------------------\n";
}

use App\Models\Flight\FlightCarrier;
$carriers = FlightCarrier::all();
echo "\nTotal Flight Carriers: " . $carriers->count() . "\n";
foreach ($carriers as $c) {
    echo "ID: {$c->id}, Name: {$c->name}, Code: {$c->code}, Currency: {$c->currency}, Balance: {$c->balance}\n";
}

use App\Models\Flight\AirlineAccount;
$airlines = AirlineAccount::all();
echo "\nTotal Airline Accounts: " . $airlines->count() . "\n";
foreach ($airlines as $a) {
    echo "ID: {$a->id}, Name: {$a->name}, Code: {$a->code}, Balance: {$a->balance}\n";
}

