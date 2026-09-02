echo "=== SIMPLE BACKFILL (direct DB UPDATE) ===\n\n";

DB::table('transactions')->where('id', 4)->update([
    'notes' => 'عكس: سند قبض — تسديد مديونية: احمد',
    'updated_at' => now(),
]);

echo "Step 1 — DB update issued.\n";

$row = DB::table('transactions')->where('id', 4)->first();
echo "Step 2 — TX#4 raw notes from DB: " . $row->notes . "\n\n";

echo "Step 3 — Find ANY other un-reversed Customer-keyed flight income:\n";
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

echo "Count: " . $rows->count() . "\n";

if ($rows->count() > 0) {
    foreach ($rows as $r) {
        echo "  - tx#" . $r->id . " amount=" . $r->amount . "\n";
        DB::table('transactions')->where('id', $r->id)->update([
            'notes' => 'عكس: ' . ($r->notes ?? ''),
            'updated_at' => now(),
        ]);
        $after = DB::table('transactions')->where('id', $r->id)->value('notes');
        echo "    After: " . $after . "\n";
    }
}

echo "\n=== DONE ===\n";
