<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\AccountEntry;
use App\Models\Transaction;

$companies = HajjUmraExecutingCompany::withTrashed()->get();
foreach ($companies as $c) {
    echo "=========================================\n";
    echo "Company ID: {$c->id} | Name: '{$c->name}' | Account ID: " . ($c->account_id ?? 'NULL') . "\n";
    if (!$c->account_id) {
        continue;
    }
    
    // Total entries
    $entries = AccountEntry::where('account_id', $c->account_id)->get();
    echo "Total entries in ledger: " . $entries->count() . "\n";
    foreach ($entries as $e) {
        $tx = Transaction::find($e->transaction_id);
        $txModule = $tx ? $tx->module->value : 'N/A';
        $txNotes = $tx ? $tx->notes : 'N/A';
        echo "  Entry ID: {$e->id} | Debit: {$e->debit} | Credit: {$e->credit} | Balance After: {$e->balance_after}\n";
        echo "    -> Transaction ID: " . ($tx ? $tx->id : 'N/A') . " | Module: {$txModule} | Notes: {$txNotes}\n";
    }
}
