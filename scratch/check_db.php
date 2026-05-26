<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\AccountEntry;
use App\Models\Transaction;

$customer = Customer::where('full_name', 'like', '%النور%')->first();
if (!$customer) {
    echo "Customer 'النور' not found.\n";
    exit;
}

echo "Customer: {$customer->full_name} (ID: {$customer->id}, Account ID: {$customer->account_id})\n";

$entries = AccountEntry::where('account_id', $customer->account_id)->get();
echo "\n--- Account Entries ---\n";
foreach ($entries as $e) {
    $tx = Transaction::find($e->transaction_id);
    echo "Entry ID: {$e->id} | Date: {$e->created_at} | Debit: {$e->debit} | Credit: {$e->credit} | Balance After: {$e->balance_after}\n";
    if ($tx) {
        echo "  -> Transaction ID: {$tx->id} | Type: {$tx->type->value} | Module: {$tx->module->value} | Notes: {$tx->notes} | Related: {$tx->related_type} #{$tx->related_id}\n";
    } else {
        echo "  -> No transaction found for this entry!\n";
    }
}
