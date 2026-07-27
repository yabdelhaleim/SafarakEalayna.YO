<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$lastTxIds = DB::table('transactions')->orderByDesc('id')->limit(4)->pluck('id')->toArray();
$entries = DB::table('account_entries')->whereIn('transaction_id', $lastTxIds)->get();
echo "Total entries found for last txs: " . $entries->count() . PHP_EOL;
foreach ($entries as $e) {
    echo "entry_id={$e->id} tx_id={$e->transaction_id} debit={$e->debit} credit={$e->credit} notes=" . var_export($e->notes, true) . PHP_EOL;
}
