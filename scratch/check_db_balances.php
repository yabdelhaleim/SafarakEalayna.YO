<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Account;

echo "Customer Count: " . Customer::count() . "\n";
echo "Supplier Count: " . Supplier::count() . "\n";
echo "Account Count: " . Account::count() . "\n";

echo "\n--- Accounts with non-zero balance ---\n";
$accounts = Account::where('balance', '!=', 0)->get();
echo "Non-zero accounts count: " . $accounts->count() . "\n";
foreach ($accounts as $acc) {
    echo sprintf(
        "ID: %d | Name: %s | Type: %s | ModType: %s | Balance: %s (%s)\n",
        $acc->id,
        $acc->name,
        $acc->account_type,
        $acc->module_type,
        $acc->balance,
        $acc->currency
    );
}

echo "\n--- Customers with non-zero ledger balances ---\n";
$customers = Customer::whereHas('ledgerAccount', function($q) {
    $q->where('balance', '!=', 0);
})->with('ledgerAccount')->get();
echo "Non-zero customers count: " . $customers->count() . "\n";
foreach ($customers as $c) {
    echo sprintf(
        "ID: %d | Name: %s | Balance: %s\n",
        $c->id,
        $c->full_name ?: $c->name,
        $c->ledgerAccount->balance
    );
}

echo "\n--- Suppliers with non-zero balances ---\n";
$suppliers = Supplier::whereHas('account', function($q) {
    $q->where('balance', '!=', 0);
})->with('account')->get();
echo "Non-zero suppliers count: " . $suppliers->count() . "\n";
foreach ($suppliers as $s) {
    echo sprintf(
        "ID: %d | Name: %s | Balance: %s\n",
        $s->id,
        $s->name,
        $s->account->balance
    );
}
