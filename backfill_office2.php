echo "=== TRY: raw SQL INSERT with error handling ===\n\n";

try {
    $sql = "INSERT INTO transactions (type, module, amount, notes, from_account_id, to_account_id, related_type, related_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $result = DB::insert($sql, [
        'income',
        'flight',
        100,
        'office_penalty kept from cancelled booking #FLT-20260828-03E664',
        14,
        11,
        'App\\Models\\Flight\\FlightBooking',
        1,
        1,
        now(),
        now(),
    ]);
    echo "Raw insert result: " . var_export($result, true) . "\n";
} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
}

echo "\n=== Verify ===\n";
$count = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', 1)
    ->where('notes', 'like', '%office_penalty%')
    ->count();
echo "office_penalty tx count: $count\n";

$report = app(\App\Services\Reports\ProfitLossReportService::class)->report([
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date'   => now()->toDateString(),
    'category'  => 'tourism',
]);
echo "Dashboard: revenue=" . $report['totalRevenues'] . " cogs=" . $report['totalCogs'] . " profit=" . $report['netProfit'] . "\n";
echo "\n=== DONE ===\n";
