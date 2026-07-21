<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Customer;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusBookingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::loginUsingId(1);

// Use a brand new customer
$newCust = Customer::create([
    "full_name" => "Test Customer EGP+USD",
    "phone" => "01099999999",
    "type" => "individual",
    "customer_tier" => "STANDARD",
    "nationality" => "EG",
]);
echo "Created customer id={$newCust->id}, account_id={$newCust->account_id}\n";
$initialAccount = Account::find($newCust->account_id);
echo "Initial account: #{$initialAccount->id} ccy={$initialAccount->currency} balance={$initialAccount->balance}\n";

$service = app(BusBookingService::class);
$egpInv = BusInventory::find(2);

// EGP booking
$b1 = $service->createBooking([
    "inventory_id" => $egpInv->id,
    "customer_id" => $newCust->id,
    "quantity" => 1,
]);
$newCust->refresh();
echo "After EGP booking: account_id={$newCust->account_id}\n";
$acc1 = Account::find($newCust->account_id);
echo "Account #{$acc1->id} ccy={$acc1->currency} balance={$acc1->balance}\n";

// USD booking
$usdInv = BusInventory::find(6);
$b2 = $service->createBooking([
    "inventory_id" => $usdInv->id,
    "customer_id" => $newCust->id,
    "quantity" => 1,
]);
$newCust->refresh();
echo "After USD booking: account_id={$newCust->account_id}\n";
$acc2 = Account::find($newCust->account_id);
echo "Account #{$acc2->id} ccy={$acc2->currency} balance={$acc2->balance}\n";

// Show all customer accounts
echo "\nAll accounts for this customer:\n";
foreach (Account::where("name", "like", "%Test Customer%")->get() as $a) {
    echo "  #{$a->id} {$a->name} ccy={$a->currency} balance={$a->balance}\n";
}
