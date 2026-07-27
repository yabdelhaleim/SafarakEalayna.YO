<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use Illuminate\Support\Facades\DB;

$kwdAccounts = Account::where('currency', 'KWD')->get();
echo "=== KWD Accounts ===" . PHP_EOL;
foreach ($kwdAccounts as $a) {
    $typeStr = is_object($a->type) ? $a->type->value : $a->type;
    echo "ID: {$a->id} | Name: {$a->name} | Type: {$typeStr} | Module: {$a->module} | ModuleType: {$a->module_type} | Balance: {$a->balance}" . PHP_EOL;
    // get entries
    $entries = DB::table('account_entries')->where('account_id', $a->id)->get();
    foreach ($entries as $e) {
        echo "   Entry ID: {$e->id} | Tx: {$e->transaction_id} | Debit: {$e->debit} | Credit: {$e->credit} | Bal After: {$e->balance_after} | Notes: {$e->notes}" . PHP_EOL;
    }
}

$systems = DB::table('flight_systems')->get();
echo PHP_EOL . "=== Flight Systems ===" . PHP_EOL;
foreach ($systems as $s) {
    echo "ID: {$s->id} | Name: {$s->name} | Currency: {$s->currency} | Balance: {$s->balance} | AccountID: {$s->account_id}" . PHP_EOL;
    if ($s->account_id) {
        $acc = Account::find($s->account_id);
        if ($acc) {
            echo "   Linked Account Balance: {$acc->balance} | Name: {$acc->name} | Type: {$acc->type}" . PHP_EOL;
        }
    }
}

$carriers = DB::table('flight_carriers')->get();
echo PHP_EOL . "=== Flight Carriers ===" . PHP_EOL;
foreach ($carriers as $c) {
    echo "ID: {$c->id} | Name: {$c->name} | Currency: {$c->currency} | Balance: {$c->balance} | AccountID: {$c->account_id}" . PHP_EOL;
    if ($c->account_id) {
        $acc = Account::find($c->account_id);
        if ($acc) {
            echo "   Linked Account Balance: {$acc->balance} | Name: {$acc->name} | Type: {$acc->type}" . PHP_EOL;
        }
    }
}
