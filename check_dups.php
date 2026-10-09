<?php
$dbPath = realpath('storage/app/local_flight_audit.sqlite');
putenv('DB_CONNECTION=sqlite'); putenv("DB_DATABASE=$dbPath");
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "=== Test: §18.3 duplicate booking_ref ===\n";
echo "Looking for duplicates of TX-AE2E-FORGE-7B26B7 / TX-AE2E-FORGE-58B870:\n";
foreach (['TX-AE2E-FORGE-7B26B7', 'TX-AE2E-FORGE-58B870', 'TX-AE2E-PERMA-E95F67'] as $ref) {
    $rows = DB::table('flight_bookings')->where('booking_reference', $ref)->get();
    echo "  $ref: " . $rows->count() . " rows";
    foreach ($rows as $r) {
        echo "  [id={$r->id} deleted={$r->deleted_at}]";
    }
    echo "\n";
}

echo "\n=== Test: §4 USD-USD payment (likely F-5 residue) ===\n";
$usdBooking = DB::table('flight_bookings')->where('booking_reference', 'like', 'TX-AE2E-USD-%')->whereNull('deleted_at')->orderBy('id','desc')->first();
echo "USD booking: id={$usdBooking->id} selling_egp={$usdBooking->selling_price} selling_foreign={$usdBooking->selling_price_foreign} rate={$usdBooking->exchange_rate} status={$usdBooking->status}\n";

// §18.3 should fail per F-1 — verify
echo "\n=== F-1 invariant verification ===\n";
$dups = DB::table('flight_bookings')->where('booking_reference', 'TX-AE2E-FORGE-7B26B7')->whereNull('deleted_at')->get();
echo "Live FORGE-7B26B7 count: " . $dups->count() . " (should be 1 if F-1 working)\n";
foreach ($dups as $r) echo "  id={$r->id} deleted_at={$r->deleted_at}\n";

// §11.1 update-prices 422 — try directly
echo "\n=== Test: §11.1 update-prices (need to see why 422) ===\n";
echo "Trying to update booking 47 with selling_price=12000, purchase_price=9000:\n";
$rc = DB::table('flight_carriers')->where('currency', 'EGP')->first();
echo "EGP carrier id: " . ($rc->id ?? 'null') . "\n";
