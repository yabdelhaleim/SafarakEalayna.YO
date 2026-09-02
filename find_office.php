echo "\n=== LOOKING FOR THE OFFICE PENALTY TRANSACTION ===\n\n";

echo "--- All flight transactions in chronological order ---\n";
$txs = DB::table('transactions as t')
    ->leftJoin('accounts as af', 't.from_account_id', '=', 'af.id')
    ->leftJoin('accounts as at', 't.to_account_id', '=', 'at.id')
    ->where('t.module', 'flight')
    ->orderBy('t.id')
    ->get([
        't.id as tid','t.type as ttype','t.amount as tamount','t.notes as tnotes',
        't.from_account_id','t.to_account_id','t.related_type','t.related_id','t.created_at',
        'af.name as from_name','af.type as from_atype',
        'at.name as to_name','at.type as to_atype',
    ]);

foreach ($txs as $tx) {
    echo sprintf("tx#%d %s | %.2f | from=%s(id=%d,type=%s) → to=%s(id=%d,type=%s) | rel=%s#%s | notes=%s\n",
        $tx->tid, $tx->ttype, $tx->tamount,
        $tx->from_name ?? 'NULL', $tx->from_account_id ?? 0, $tx->from_atype ?? '-',
        $tx->to_name ?? 'NULL', $tx->to_account_id ?? 0, $tx->to_atype ?? '-',
        class_basename($tx->related_type ?? ''), $tx->related_id ?? '',
        mb_substr((string)$tx->notes, 0, 60)
    );
}

echo "\n--- Search for office_penalty / غرامة / penalty in any note ---\n";
$penaltyTxs = DB::table('transactions as t')
    ->leftJoin('accounts as af', 't.from_account_id', '=', 'af.id')
    ->leftJoin('accounts as at', 't.to_account_id', '=', 'at.id')
    ->where('t.module', 'flight')
    ->where(function ($q) {
        $q->where('t.notes', 'like', '%office_penalty%')
          ->orWhere('t.notes', 'like', '%غرامة%')
          ->orWhere('t.notes', 'like', '%penalty%')
          ->orWhere('t.notes', 'like', '%office%');
    })
    ->get([
        't.id as tid','t.type as ttype','t.amount as tamount','t.notes as tnotes',
        'af.name as from_name','at.name as to_name',
    ]);

if ($penaltyTxs->isEmpty()) {
    echo "  ❌ NO transaction found with 'office_penalty' / 'غرامة' / 'penalty' in notes!\n";
    echo "  → The 100 office_penalty is recorded in flight_refunds table but NEVER posted as a transaction.\n";
} else {
    foreach ($penaltyTxs as $tx) {
        echo "  tx#{$tx->tid} {$tx->ttype} {$tx->tamount} | from={$tx->from_name} → to={$tx->to_name} | notes={$tx->tnotes}\n";
    }
}

echo "\n--- flight_refunds table full record ---\n";
$refund = DB::table('flight_refunds')->where('flight_booking_id', 1)->first();
print_r($refund);

echo "\n=== DONE ===\n";
