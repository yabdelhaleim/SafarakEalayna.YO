<?php

/**
 * Probe — what does the current ledger look like AFTER a full USD+USD payment?
 * Goal: confirm F-5 residue mechanism before designing the fix.
 */
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_flight_audit.sqlite';
putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  F-5 PROBE — current state of customer / cashbox / clearing balances\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// Show existing EGP-customer AR balance
echo "Customer AR (EGP) balances (positive = customer owes us):\n";
$customers = DB::table('accounts')
    ->where('type', 'customer')
    ->orderBy('id')
    ->get();
foreach ($customers as $c) {
    $ledgerSum = DB::table('account_entries')->where('account_id', $c->id)
        ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net') ?? 0;
    $drift = round((float) $c->balance - (float) $ledgerSum, 2);
    echo "  #{$c->id} {$c->name}: stored=".round($c->balance, 2)." {$c->currency}, ledger=".round($ledgerSum, 2).", drift={$drift}\n";
}

echo "\nCashbox balances:\n";
$cashboxes = DB::table('accounts')
    ->where('type', 'cashbox')
    ->orderBy('id')
    ->get();
foreach ($cashboxes as $cb) {
    $ledgerSum = DB::table('account_entries')->where('account_id', $cb->id)
        ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net') ?? 0;
    $drift = round((float) $cb->balance - (float) $ledgerSum, 2);
    echo "  #{$cb->id} {$cb->name}: stored=".round($cb->balance, 2)." {$cb->currency}, ledger=".round($ledgerSum, 2).", drift={$drift}\n";
}

echo "\nIncome clearing accounts (Flight module):\n";
$clearings = DB::table('accounts')
    ->where(function ($q) {
        $q->where('name', 'like', '%إقفال%')
            ->orWhere('name', 'like', '%clearing%')
            ->orWhere('name', 'like', '%income%');
    })
    ->orderBy('id')
    ->get();
foreach ($clearings as $cl) {
    $ledgerSum = DB::table('account_entries')->where('account_id', $cl->id)
        ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net') ?? 0;
    $drift = round((float) $cl->balance - (float) $ledgerSum, 2);
    echo "  #{$cl->id} {$cl->name}: stored=".round($cl->balance, 2)." {$cl->currency}, ledger=".round($ledgerSum, 2).", drift={$drift}\n";
}

// Pick the most recent booking with payment
$recentBooking = DB::table('flight_bookings')
    ->whereExists(function ($q) {
        $q->select(DB::raw(1))
            ->from('flight_payments')
            ->whereColumn('flight_payments.flight_booking_id', 'flight_bookings.id');
    })
    ->orderByDesc('id')
    ->first();

if (! $recentBooking) {
    echo "\n  ❌ No booking-with-payment found in DB. Run scenarios first.\n";
    exit(1);
}

echo "\n═══ Most recent booking with payment #{$recentBooking->id} ═══\n";
echo "  currency: {$recentBooking->currency}\n";
echo "  selling_price (stored col): {$recentBooking->selling_price}\n";
echo "  selling_price_foreign: {$recentBooking->selling_price_foreign}\n";
echo "  exchange_rate: {$recentBooking->exchange_rate}\n";
echo "  booking_exchange_rate: {$recentBooking->booking_exchange_rate}\n";
echo "  status: {$recentBooking->status}\n";

$totalPayments = DB::table('flight_payments')
    ->where('flight_booking_id', $recentBooking->id)
    ->selectRaw('SUM(amount) as total_amount_egp, SUM(original_amount) as total_orig, GROUP_CONCAT(DISTINCT currency) as currencies')
    ->first();
echo "\n  Total payments:\n";
echo "    amount (EGP): {$totalPayments->total_amount_egp}\n";
echo "    original_amount (foreign): {$totalPayments->total_orig}\n";
echo "    currencies: {$totalPayments->currencies}\n";

// Show all transactions on this booking
echo "\n  All transactions for this booking:\n";
$txns = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', $recentBooking->id)
    ->orderBy('id')
    ->get();
foreach ($txns as $t) {
    echo "    TX #{$t->id} type={$t->type} amount={$t->amount} {$t->currency} from=".($t->from_account_id ?? 'NULL').' to='.($t->to_account_id ?? 'NULL').' notes='.substr((string) $t->notes, 0, 60)."\n";
}

// Show all ledger entries for the accounts involved
echo "\n  Ledger entries on involved accounts (customer + cashbox + clearing):\n";
$involved = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', $recentBooking->id)
    ->pluck('id');
$entries = DB::table('account_entries')
    ->whereIn('transaction_id', $involved)
    ->orderBy('id')
    ->get();
foreach ($entries as $e) {
    $acctName = DB::table('accounts')->where('id', $e->account_id)->value('name');
    echo "    ENTRY #{$e->id} acct={$e->account_id}({$acctName}) debit={$e->debit} credit={$e->credit} balance_after={$e->balance_after} tx={$e->transaction_id}\n";
}

// Customer debt after payment
$customerAccountId = DB::table('accounts')->where('owner_type', 'App\\Models\\Customer')
    ->where('id', $recentBooking->customer_id.'', 'OR')
    ->orWhere(function ($q) {
        // Try join
    })
    ->value('id');
$customerAR = DB::table('customers')->where('id', $recentBooking->customer_id)->value('account_id');
if ($customerAR) {
    $custStored = DB::table('accounts')->where('id', $customerAR)->value('balance');
    echo "\n  Customer AR (account #$customerAR) balance after all payments: {$custStored}\n";
    echo "  Expected after FULL payment: 0 (debt cleared)\n";
    echo "  Residue: {$custStored}\n";
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
