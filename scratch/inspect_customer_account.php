<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel;

$customer = Customer::find(602);
$account = Account::find($customer->account_id);

echo "Customer: {$customer->full_name}\n";
echo "Account ID: {$account->id} | Name: {$account->name} | Balance: {$account->balance}\n";

$entries = AccountEntry::where('account_id', $account->id)->orderBy('id', 'desc')->get();
echo "\n--- Entries ---\n";
foreach ($entries as $e) {
    $tx = Transaction::find($e->transaction_id);
    echo "Entry ID: {$e->id} | Date: {$e->created_at} | Debit: {$e->debit} | Credit: {$e->credit} | Balance After: {$e->balance_after}\n";
    if ($tx) {
        echo "  -> Transaction ID: {$tx->id} | From: {$tx->from_account_id} | To: {$tx->to_account_id} | Amount: {$tx->amount} | Notes: {$tx->notes}\n";
    }
}
