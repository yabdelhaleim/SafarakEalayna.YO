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

echo "=== ACTIVE Wallet Transactions ===" . PHP_EOL;
foreach (WalletTransaction::all() as $tx) {
    $cust = $tx->customer_id ? Customer::find($tx->customer_id) : null;
    $type = $tx->type instanceof \App\Enums\WalletTransactionType ? $tx->type->value : (string) $tx->type;
    echo sprintf("  WT#%d | cust_id=%s (acct=%s) | amount=%s | total=%s | type=%s | notes=%s\n",
        $tx->id, $tx->customer_id ?? 'NULL', $cust?->account_id ?? '—', $tx->amount, $tx->total_amount, $type, $tx->notes);
}
echo PHP_EOL . "=== Customer account balances ===" . PHP_EOL;
foreach (Account::where('type', 'customer')->where('module_type', 'wallet_transfer')->get() as $a) {
    $t = $a->type instanceof \App\Enums\AccountType ? $a->type->value : (string) $a->type;
    echo sprintf("  [%d] %s | balance=%s | type=%s\n", $a->id, $a->name, $a->balance, $t);
}
echo PHP_EOL . "=== Tx 1 (T2) ledger entries ===" . PHP_EOL;
$tx = WalletTransaction::find(1);
if ($tx) {
    $income = Transaction::find($tx->income_transaction_id);
    echo "  inc_tx={$income->id} | from={$income->from_account_id} to={$income->to_account_id}" . PHP_EOL;
    foreach (AccountEntry::where('transaction_id', $income->id)->get() as $e) {
        $a = Account::find($e->account_id);
        $t = $a?->type instanceof \App\Enums\AccountType ? $a->type->value : (string) $a?->type;
        echo "    acct={$a->id} ({$a->name}, {$t}) | d={$e->debit} c={$e->credit} bal_after={$e->balance_after}" . PHP_EOL;
    }
}
