<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Contracts\Console\Kernel;

$q = Account::query();
AccountModuleDivision::applyLiquidityTreasuryScope($q);
foreach ($q->active()->get() as $acc) {
    $last = AccountEntry::where('account_id', $acc->id)->whereNotNull('balance_after')->orderByDesc('id')->value('balance_after');
    $net = AccountEntry::where('account_id', $acc->id)->selectRaw('SUM(credit-debit) as n')->value('n');
    $stored = round((float) $acc->balance, 2);
    $ledger = $last !== null ? round((float) $last, 2) : round((float) $net, 2);
    if (abs($stored - $ledger) > 0.05) {
        echo "#{$acc->id} {$acc->name} stored={$stored} last_after={$last} net={$net} diff=".($stored - $ledger)."\n";
    }
}
