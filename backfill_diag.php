echo "\n=== BACKFILL DIAGNOSTIC ===\n\n";

$svc = app(\App\Services\Finance\TransactionService::class);

$rows = DB::table('transactions')
    ->where('related_type', 'App\\Models\\Customer')
    ->where('type', 'income')
    ->where('module', 'flight')
    ->where(function ($q) {
        $q->whereNull('notes')
            ->orWhere(function ($q2) {
                $q2->where('notes', 'not like', 'عكس:%')
                    ->where('notes', 'not like', 'عكس %');
            });
    })
    ->get();

echo "Step 1 — Rows found by DB query: " . $rows->count() . "\n";
foreach ($rows as $r) echo "  row id={$r->id} amount={$r->amount} notes=" . mb_substr((string)$r->notes, 0, 40) . "\n";
echo "\n";

echo "Step 2 — Iterate and reverse:\n";
foreach ($rows as $row) {
    echo "  Processing row id={$row->id}...\n";
    $tx = \App\Models\Transaction::find($row->id);
    if (!$tx) {
        echo "    ! Transaction model NOT found (id={$row->id})\n";
        continue;
    }
    echo "    Found model. notes BEFORE: " . mb_substr((string)$tx->notes, 0, 50) . "\n";
    $originalNotes = $tx->notes;

    $svc->markTransactionReversed($tx);
    $tx->refresh();

    $changed = $tx->notes !== $originalNotes;
    echo "    notes AFTER:  " . mb_substr((string)$tx->notes, 0, 60) . "\n";
    echo "    Changed: " . ($changed ? "YES ✓" : "NO ✗ (BUG IN markTransactionReversed)") . "\n\n";
}

echo "Step 3 — Verify from DB (raw query, no model cache):\n";
$verify = DB::table('transactions')->whereIn('id', $rows->pluck('id'))->get(['id', 'notes']);
foreach ($verify as $v) {
    echo "  tx#{$v->id} notes=" . $v->notes . "\n";
}
echo "\n=== DONE ===\n";
