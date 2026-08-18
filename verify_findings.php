<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Flight booking transactions (sample: bookings 52, 60, 82) ===" . PHP_EOL;
$txs = DB::table('transactions as t')
    ->leftJoin('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')
    ->whereIn('t.related_id', [52, 60, 82])
    ->select('t.id', 't.type', 't.amount', 't.currency', 't.from_account_id', 't.to_account_id', 't.created_at',
             DB::raw('(SELECT COUNT(*) FROM account_entries WHERE transaction_id = t.id) ae_count'))
    ->orderBy('t.related_id')->orderBy('t.id')
    ->limit(40)->get();
foreach ($txs as $t) {
    printf("  booking=%-3d | tx_id=%-5d | type=%-10s | amount=%-10s | cur=%-4s | from=%-5s | to=%-5s | ae_count=%d" . PHP_EOL,
        $t->related_id, $t->id, $t->type, $t->amount, $t->currency, $t->from_account_id ?? 'NULL', $t->to_account_id ?? 'NULL', $t->ae_count);
}

echo PHP_EOL . "=== Duplicate (related_id, type, amount) tuples on Flight bookings ===" . PHP_EOL;
$dups = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->select('related_id', 'type', 'amount', 'currency', DB::raw('COUNT(*) cnt'))
    ->groupBy('related_id', 'type', 'amount', 'currency')
    ->having('cnt', '>', 1)
    ->limit(15)
    ->get();
foreach ($dups as $d) {
    printf("  booking=%-3d | type=%-10s | amount=%-10s | cur=%-4s | count=%d" . PHP_EOL,
        $d->related_id, $d->type, $d->amount, $d->currency, $d->cnt);
}

echo PHP_EOL . "=== Account 6 (WL_CASH_EGP — OFFICE used as stand-in) ===" . PHP_EOL;
$a = DB::table('accounts')->find(6);
echo "  id=" . $a->id . " name=" . $a->name . " module_type=" . $a->module_type . " is_vault=" . var_export($a->is_module_vault, true) . " balance=" . $a->balance . PHP_EOL;

echo PHP_EOL . "=== Account 818 (clearing/إقفال مبيعات الطيران) ===" . PHP_EOL;
$a = DB::table('accounts')->find(818);
echo "  id=" . $a->id . " name=" . $a->name . " module_type=" . $a->module_type . " is_vault=" . var_export($a->is_module_vault, true) . " balance=" . $a->balance . PHP_EOL;

echo PHP_EOL . "=== Audit-prefixed Customer accounts module_type ===" . PHP_EOL;
$results = DB::table('accounts')
    ->whereIn('id', DB::table('customers')
        ->where('full_name', 'like', 'TOURISM_FULL_AUDIT_20260818_%')
        ->pluck('account_id')->filter())
    ->limit(8)->get(['id', 'name', 'type', 'module_type', 'module', 'balance']);
foreach ($results as $c) {
    printf("  id=%-4d | type=%-10s | mt=%-15s | mod=%-15s | balance=%10.2f | %s" . PHP_EOL,
        $c->id, $c->type, $c->module_type ?? 'NULL', $c->module ?? 'NULL', $c->balance, substr($c->name ?? '', 0, 50));
}
