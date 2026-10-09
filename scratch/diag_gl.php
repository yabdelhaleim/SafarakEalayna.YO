<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\AccountEntry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo 'Total entries: '.AccountEntry::count()."\n";
echo 'Sum debit: '.number_format(AccountEntry::sum('debit'), 2)."\n";
echo 'Sum credit: '.number_format(AccountEntry::sum('credit'), 2)."\n";

$unbalancedTxCount = DB::table('account_entries')
    ->select('transaction_id', DB::raw('SUM(debit) as d, SUM(credit) as c'))
    ->groupBy('transaction_id')
    ->havingRaw('ABS(SUM(debit) - SUM(credit)) > 0.01')
    ->count();

echo "Unbalanced Transactions System-Wide: $unbalancedTxCount\n";

// Online module entries check
$onlineDebit = DB::table('account_entries')
    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
    ->where('transactions.module', 'online')
    ->sum('account_entries.debit');

$onlineCredit = DB::table('account_entries')
    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
    ->where('transactions.module', 'online')
    ->sum('account_entries.credit');

echo 'Online Module Sum Debit: '.number_format($onlineDebit, 2)."\n";
echo 'Online Module Sum Credit: '.number_format($onlineCredit, 2)."\n";
echo 'Online Module Diff: '.number_format(abs($onlineDebit - $onlineCredit), 4)."\n";
