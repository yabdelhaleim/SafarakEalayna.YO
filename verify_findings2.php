<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Aggregate counts post-audit ===" . PHP_EOL;
echo "  cashboxes module_type IN (tourism, hajj_umra, flights, visas): " .
    DB::table('accounts')->whereIn('module_type', ['tourism','hajj_umra','flights','visas'])->where('type', 'cashbox')->count() . PHP_EOL;
echo "  cashboxes module_type=office + is_module_vault=1: " .
    DB::table('accounts')->where('module_type', 'office')->where('is_module_vault', 1)->count() . PHP_EOL;
echo "  cashboxes module_type=office + is_module_vault=0/null: " .
    DB::table('accounts')->where('module_type', 'office')->where('is_module_vault', '<>', 1)->where('type', 'cashbox')->count() . PHP_EOL;
echo "  cashboxes module_type=tourism (any vault flag): " .
    DB::table('accounts')->where('module_type', 'tourism')->where('type', 'cashbox')->count() . PHP_EOL;

echo PHP_EOL . "=== Ledger shape of a single Flight payment id=49 (look up its booking first) ===" . PHP_EOL;
$tx = DB::table('transactions')->where('related_type', 'App\Models\Flight\FlightPayment')
    ->whereIn('related_id', [49, 50, 51])->orderBy('related_id')->orderBy('id')->get();
echo "  Found " . count($tx) . " transactions on these payments:" . PHP_EOL;
foreach ($tx as $t) {
    $entries = DB::table('account_entries')->where('transaction_id', $t->id)->get();
    printf("    payment=%d tx=%d type=%s amount=%s from=%d to=%d entries=%d" . PHP_EOL,
        $t->related_id, $t->id, $t->type, $t->amount,
        $t->from_account_id ?? -1, $t->to_account_id ?? -1, count($entries));
    foreach ($entries as $e) {
        $d = number_format((float)($e->debit ?? 0), 2);
        $c = number_format((float)($e->credit ?? 0), 2);
        printf("      entry account=%d debit=%s credit=%s" . PHP_EOL, $e->account_id, $d, $c);
    }
}

echo PHP_EOL . "=== Account 818 (flight clearing / إقفال مبيعات الطيران) ledger entries ===" . PHP_EOL;
$entries = DB::table('account_entries')->where('account_id', 818)->orderBy('id', 'desc')->limit(10)->get();
foreach ($entries as $e) {
    $d = number_format((float)($e->debit ?? 0), 2);
    $c = number_format((float)($e->credit ?? 0), 2);
    printf("  id=%d tx=%d debit=%s credit=%s" . PHP_EOL, $e->id, $e->transaction_id, $d, $c);
}
