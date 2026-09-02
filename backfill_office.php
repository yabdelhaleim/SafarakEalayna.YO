echo "\n=== BACKFILL office_penalty income for booking 1 ===\n\n";

$refund = DB::table('flight_refunds')->where('flight_booking_id', 1)->first();
if (!$refund) { echo "� No flight_refund for booking 1.\n"; return; }

echo "Refund record: refund_amount={$refund->refund_amount} airline_penalty={$refund->airline_penalty} office_penalty={$refund->office_penalty}\n\n";

$exists = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', 1)
    ->where('notes', 'like', '%office_penalty%')
    ->exists();

if ($exists) {
    echo "⚠️  Office penalty transaction already exists for booking 1 — skipping.\n";
} else {
    DB::table('transactions')->insert([
        'type' => 'income',
        'module' => 'flight',
        'amount' => $refund->office_penalty,
        'notes' => 'office_penalty kept from cancelled booking #FLT-20260828-03E664',
        'from_account_id' => 14,  // customer_account (احمد)
        'to_account_id' => $refund->account_id ?? 11, // cashbox
        'related_type' => 'App\\Models\\Flight\\FlightBooking',
        'related_id' => 1,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "✓ Inserted office_penalty income transaction: amount={$refund->office_penalty}\n";
}

echo "\n=== Run dashboard report to verify ===\n";
$svc = app(\App\Services\Reports\ProfitLossReportService::class);
$report = $svc->report([
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date'   => now()->toDateString(),
    'category'  => 'tourism',
]);
echo "  totalRevenues: {$report['totalRevenues']}\n";
echo "  totalCogs:     {$report['totalCogs']}\n";
echo "  totalExpenses: {$report['totalExpenses']}\n";
echo "  netProfit:     {$report['netProfit']}\n\n";
echo "Expected: revenue=100, cogs=100, profit=0\n";
echo "\n=== DONE ===\n";
