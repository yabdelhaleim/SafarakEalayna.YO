<?php

/**
 * Probe — trace T3-USD specifically (the 1500 EGP residue case)
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
echo "  F-5 PROBE — T3-USD scenario trace\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// Find the T3-USD customer
$custId = DB::table('accounts')->where('name', 'TX-FULL-E2E-CUST-T3-USD')->where('type', 'customer')->value('id');
if (! $custId) {
    // Try alternative naming
    $custId = DB::table('customers')->where('name', 'like', '%T3%USD%')->value('id');
    if ($custId) {
        $acctId = DB::table('accounts')->where('owner_type', 'App\\Models\\Customer')->where('owner_type', 'App\\Models\\Customer')->whereRaw("JSON_EXTRACT(owner_type, '$') IS NOT NULL")->value('id');
    }
}

echo "Customer #{$custId}\n";

if (! $custId) {
    echo "Not found.\n";
    exit;
}

$cust = DB::table('accounts')->where('id', $custId)->first();
echo "  Name: {$cust->name}\n";
echo "  Currency: {$cust->currency}\n";
echo "  Balance: {$cust->balance}\n";

// Find all ledger entries for this customer
echo "\n  Ledger entries on this customer:\n";
$entries = DB::table('account_entries')
    ->where('account_id', $custId)
    ->orderBy('id')
    ->get();
foreach ($entries as $e) {
    $tx = DB::table('transactions')->where('id', $e->transaction_id)->first();
    $txInfo = $tx ? "TX#{$tx->id} type={$tx->type} amount={$tx->amount} {$tx->currency}" : 'no-tx';
    echo "    ENTRY #{$e->id} debit={$e->debit} credit={$e->credit} balance_after={$e->balance_after} {$txInfo}\n";
}

// Find the booking that owns this customer
$booking = DB::table('flight_bookings')
    ->whereRaw('customer_id IN (SELECT id FROM customers WHERE account_id = ?)', [$custId])
    ->orderByDesc('id')
    ->first();

if ($booking) {
    echo "\n  Booking #{$booking->id}:\n";
    echo "    currency: {$booking->currency}\n";
    echo "    selling_price: {$booking->selling_price}\n";
    echo "    selling_price_foreign: {$booking->selling_price_foreign}\n";
    echo "    exchange_rate: {$booking->exchange_rate}\n";
    echo "    booking_exchange_rate: {$booking->booking_exchange_rate}\n";
    echo '    paid_amount (computed): '.DB::table('flight_payments')->where('flight_booking_id', $booking->id)->sum('amount')."\n";
    echo '    original_amount paid (sum): '.DB::table('flight_payments')->where('flight_booking_id', $booking->id)->sum('original_amount')."\n";

    echo "\n  Payments on this booking:\n";
    $payments = DB::table('flight_payments')->where('flight_booking_id', $booking->id)->get();
    foreach ($payments as $p) {
        echo "    PAYMENT #{$p->id} amount={$p->amount} original_amount={$p->original_amount} currency={$p->currency} account_id={$p->account_id} tx={$p->transaction_id}\n";
    }

    echo "\n  Transactions for this booking:\n";
    $txns = DB::table('transactions')
        ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
        ->where('related_id', $booking->id)
        ->orderBy('id')
        ->get();
    foreach ($txns as $t) {
        echo "    TX #{$t->id} type={$t->type} amount={$t->amount} {$t->currency} from=".($t->from_account_id ?? 'NULL').' to='.($t->to_account_id ?? 'NULL')."\n";
        $entriesForTx = DB::table('account_entries')->where('transaction_id', $t->id)->get();
        foreach ($entriesForTx as $e) {
            $acctName = DB::table('accounts')->where('id', $e->account_id)->value('name');
            echo "        ENTRY acct={$e->account_id}({$acctName}) debit={$e->debit} credit={$e->credit} balance_after={$e->balance_after}\n";
        }
    }
}

// Find the USD cashbox used
echo "\n  USD cashbox state:\n";
$usdCashboxId = DB::table('accounts')->where('type', 'cashbox')->where('currency', 'USD')->value('id');
$usdCb = DB::table('accounts')->where('id', $usdCashboxId)->first();
echo "    USD cashbox: stored={$usdCb->balance}, expected if customer debt 1500 cleared: depends on rate\n";

// Compute what the USD cashbox should be
// If T3 booking was 1000 USD at rate 48.5: 1000 * 48.5 = 48500 EGP debt, 1000 USD paid
// After payment: customer should be 0 EGP, USD cashbox should have +1000 USD
// After payment: customer has 1500 EGP (per probe). Residue = 1500 EGP
// The 1500 EGP residue = the gap between customer debt and what was paid

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo "  ANALYSIS\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Customer AR balance: {$cust->balance} EGP (should be 0 after full payment)\n";
echo "  Residue: {$cust->balance} EGP\n";

echo "\n═══════════════════════════════════════════════════════════════════════\n";
