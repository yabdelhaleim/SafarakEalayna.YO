<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

function tval($v) {
    if ($v instanceof \BackedEnum) return $v->value;
    return (string) $v;
}

$acc = Account::find(81);
$entries = AccountEntry::where('account_id', $acc->id)->get();
echo "Account 81 ({$acc->name}) has " . $entries->count() . " entries" . PHP_EOL;
foreach ($entries as $e) {
    $tx = Transaction::find($e->transaction_id);
    $txType = tval($tx?->type);
    $txMod = tval($tx?->module);
    echo "  txn={$e->transaction_id} (type={$txType}, mod={$txMod}) | d={$e->debit} c={$e->credit} bal_after={$e->balance_after} | notes=" . substr((string)$e->notes, 0, 30) . PHP_EOL;
}

echo PHP_EOL . "=== Recompute from credit-debit for all wallet module ===" . PHP_EOL;
$net = (float) AccountEntry::where('account_id', $acc->id)
    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
    ->where('transactions.module', 'wallet')
    ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as net')
    ->value('net');
echo "  Computed net for account {$acc->id}: {$net}" . PHP_EOL;

echo PHP_EOL . "=== WalletTransaction 10 (T2 in test) ===" . PHP_EOL;
$tx = WalletTransaction::find(10);
echo "  income_tx_id={$tx->income_transaction_id} expense_tx_id={$tx->expense_transaction_id}" . PHP_EOL;

$inc = Transaction::find($tx->income_transaction_id);
$exp = Transaction::find($tx->expense_transaction_id);
echo "  inc: from={$inc->from_account_id} to={$inc->to_account_id} amount={$inc->amount} mod=" . tval($inc->module) . PHP_EOL;
echo "  exp: from={$exp->from_account_id} to={$exp->to_account_id} amount={$exp->amount} mod=" . tval($exp->module) . PHP_EOL;

echo PHP_EOL . "=== All related transactions for WT#10 ===" . PHP_EOL;
foreach (Transaction::where('related_type', WalletTransaction::class)->where('related_id', 10)->get() as $rt) {
    echo "  TX#{$rt->id}: from={$rt->from_account_id} to={$rt->to_account_id} amount={$rt->amount} mod=" . tval($rt->module) . " notes=" . substr((string)$rt->notes, 0, 60) . PHP_EOL;
    foreach (AccountEntry::where('transaction_id', $rt->id)->get() as $e) {
        $a = Account::find($e->account_id);
        $aType = tval($a?->type);
        echo "    Entry: acct={$a->id} ({$a->name}, {$aType}) | d={$e->debit} c={$e->credit} bal_after={$e->balance_after}" . PHP_EOL;
    }
}
