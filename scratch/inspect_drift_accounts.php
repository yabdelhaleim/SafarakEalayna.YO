<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel;

$ids = [637, 638, 27, 19, 18];

foreach ($ids as $id) {
    $acc = Account::find($id);
    if (! $acc) {
        echo "Account #$id not found\n";

        continue;
    }
    echo "\n=== Account #$id: {$acc->name} ===\n";
    echo "stored={$acc->balance} type={$acc->type->value} module_type={$acc->module_type}\n";

    $entries = AccountEntry::where('account_id', $id)->orderBy('id')->get();
    $net = $entries->sum(fn ($e) => (float) $e->credit - (float) $e->debit);
    echo "entries={$entries->count()} ledger_net={$net}\n";

    foreach ($entries as $e) {
        $tx = $e->transaction_id ? Transaction::find($e->transaction_id) : null;
        $txType = $tx ? ($tx->type instanceof BackedEnum ? $tx->type->value : $tx->type) : 'null';
        echo "  entry#{$e->id} tx#{$e->transaction_id} ({$txType}) D={$e->debit} C={$e->credit} after={$e->balance_after} notes=".($e->notes ?? '-')."\n";
        if ($tx) {
            echo '    tx_notes: '.($tx->notes ?? '-')." amount={$tx->amount}\n";
        }
    }
}

// Sample single-leg transaction
echo "\n=== Sample single-leg income tx ===\n";
$sample = AccountEntry::query()
    ->select('transaction_id')
    ->groupBy('transaction_id')
    ->havingRaw('COUNT(*) = 1')
    ->havingRaw('transaction_id IS NOT NULL')
    ->value('transaction_id');
if ($sample) {
    $tx = Transaction::with('entries')->find($sample);
    echo "tx#{$tx->id} type={$tx->type->value} amount={$tx->amount} module={$tx->module}\n";
    foreach ($tx->entries as $e) {
        echo "  acc#{$e->account_id} D={$e->debit} C={$e->credit}\n";
    }
}

// Count customer accounts with balance but no entries
$customerNoEntries = Account::where('type', 'customer')
    ->where('balance', '!=', 0)
    ->whereDoesntHave('entries')
    ->count();
$customerTotal = Account::where('type', 'customer')->count();
echo "\nCustomer accounts with balance but no entries: {$customerNoEntries} / {$customerTotal}\n";
