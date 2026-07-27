<?php
// Debug script to inspect unbalanced transactions
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\AccountEntry;

// Show transactions and their entries
$txs = DB::table('transactions')->get();
foreach ($txs as $tx) {
    $entries = DB::table('account_entries')->where('transaction_id', $tx->id)->get();
    $sumDebit = $entries->sum('debit');
    $sumCredit = $entries->sum('credit');
    $diff = abs($sumDebit - $sumCredit);
    echo "TX #{$tx->id} type={$tx->type} amount={$tx->amount} from={$tx->from_account_id} to={$tx->to_account_id}\n";
    foreach ($entries as $e) {
        echo "  Entry: account_id={$e->account_id} debit={$e->debit} credit={$e->credit}\n";
    }
    echo "  SUM debit={$sumDebit} SUM credit={$sumCredit} DIFF={$diff}\n";
    if ($diff > 0.001) {
        echo "  *** UNBALANCED ***\n";
    }
    echo "\n";
}

// Also show entries with NULL transaction_id (opening balances)
$openings = DB::table('account_entries')->whereNull('transaction_id')->get();
echo "\nOpening entries (transaction_id IS NULL):\n";
foreach ($openings as $o) {
    echo "  account_id={$o->account_id} debit={$o->debit} credit={$o->credit} notes={$o->notes}\n";
}