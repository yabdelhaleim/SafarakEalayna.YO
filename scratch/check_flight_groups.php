<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightBooking;
use Illuminate\Support\Facades\DB;

$allGroups = FlightGroup::with(['carrier', 'account'])->get();
echo "All Flight Groups (including soft deleted/inactive):\n";
foreach ($allGroups as $g) {
    $bookingsCount = FlightBooking::where('flight_group_id', $g->id)->count();
    $bookingsSum = FlightBooking::where('flight_group_id', $g->id)->sum('purchase_price');
    $txDebt = $g->groupTransactions()->where('type', 'debt')->sum('amount');
    $txPayment = $g->groupTransactions()->where('type', 'payment')->sum('amount');
    
    echo "ID: {$g->id} | Name: {$g->name} | Code: {$g->code} | Active: " . ($g->is_active ? 'Yes' : 'No') . " | Soft Deleted: " . ($g->deleted_at ? 'Yes' : 'No') . "\n";
    echo "  - Bookings Count: {$bookingsCount} | Bookings Sum: {$bookingsSum}\n";
    echo "  - Transactions Debt: {$txDebt} | Transactions Payment: {$txPayment}\n";
    echo "  - Account ID: {$g->account_id} | Account Balance: " . ($g->account ? $g->account->balance : 'N/A') . "\n";
    echo "-------------------------------------------------------\n";
}

$bookingsWithoutGroup = FlightBooking::whereNull('flight_group_id')->count();
echo "Bookings without flight group: {$bookingsWithoutGroup}\n";
