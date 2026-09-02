echo "\n=== FIND COGS (same period as dashboard) ===\n\n";

$svc = app(\App\Services\Reports\ProfitLossReportService::class);

// Use the same parameters as the failing dashboard call
$report = $svc->report([
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date'   => now()->toDateString(),
    'category'  => 'tourism',
]);

echo "Dashboard report:\n";
echo "  totalRevenues: {$report['totalRevenues']}\n";
echo "  totalCogs:     {$report['totalCogs']}\n";
echo "  totalExpenses: {$report['totalExpenses']}\n";
echo "  netProfit:     {$report['netProfit']}\n\n";

echo "Cogs list: " . json_encode($report['cogsList'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo "Revenue list: " . json_encode($report['revenuesList'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo "Expense list: " . json_encode($report['expensesList'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo "Refunds list: " . json_encode($report['refundsList'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== Raw query for ALL transactions in [start_of_month, today] ===\n";
$txs = DB::table('transactions as t')
    ->leftJoin('accounts as af', 't.from_account_id', '=', 'af.id')
    ->leftJoin('accounts as at', 't.to_account_id', '=', 'at.id')
    ->whereDate('t.created_at', '>=', now()->startOfMonth()->toDateString())
    ->whereDate('t.created_at', '<=', now()->toDateString())
    ->orderBy('t.id')
    ->get([
        't.id','t.type','t.module','t.amount','t.notes','t.created_at',
        't.from_account_id','t.to_account_id',
        'af.name as from_name','af.type as from_type',
        'at.name as to_name','at.type as to_type',
    ]);

$tourismModules = ['tourism','flights','hajj_umra','visas','flight'];

foreach ($txs as $tx) {
    if (!in_array($tx->module, $tourismModules)) continue;
    echo sprintf("tx#%d %s/%s %.2f | from=%s(id=%d %s) → to=%s(id=%d %s) | %s | %s\n",
        $tx->id, $tx->type, $tx->module, $tx->amount,
        $tx->from_name ?? 'NULL', $tx->from_account_id ?? 0, $tx->from_type ?? '-',
        $tx->to_name ?? 'NULL', $tx->to_account_id ?? 0, $tx->to_type ?? '-',
        mb_substr((string)$tx->notes, 0, 40),
        $tx->created_at
    );
}
echo "\n=== Done ===\n";
