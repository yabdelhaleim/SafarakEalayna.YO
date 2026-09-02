echo "\n=== FIND THE 100 COGS in tourism ===\n\n";

$clearing = app(\App\Services\Finance\LedgerClearingAccounts::class);
$maps = $clearing->moduleAccountMaps();
$incomeClearing = $maps['income'];
$expenseClearing = $maps['expense'];
$prepaidAccounts = $clearing->prepaidAccountIdMap();

$txs = DB::table('transactions as t')
    ->leftJoin('accounts as af', 't.from_account_id', '=', 'af.id')
    ->leftJoin('accounts as at', 't.to_account_id', '=', 'at.id')
    ->whereDate('t.created_at', today())
    ->orderBy('t.id')
    ->get([
        't.id','t.type','t.module','t.amount','t.notes',
        't.from_account_id','t.to_account_id',
        'af.name as from_name','af.type as from_type',
        'at.name as to_name','at.type as to_type',
    ]);

$tourismModules = ['tourism','flights','hajj_umra','visas','flight'];

echo "All today's transactions classified as cogs or cogs_reversal (tourism only):\n";
foreach ($txs as $tx) {
    if (!in_array($tx->module, $tourismModules)) continue;

    $fromId = (int) ($tx->from_account_id ?? 0);
    $toId = (int) ($tx->to_account_id ?? 0);
    $fromExpense = $fromId > 0 && isset($expenseClearing[$fromId]);
    $toExpense   = $toId   > 0 && isset($expenseClearing[$toId]);
    $fromPrepaid = $fromId > 0 && isset($prepaidAccounts[$fromId]);
    $toPrepaid   = $toId   > 0 && isset($prepaidAccounts[$toId]);

    $cls = null;
    if ($tx->type === 'expense') $cls = 'operating_expense';
    elseif ($tx->type === 'transfer') {
        if ($fromPrepaid && $toExpense && !$toPrepaid) $cls = 'cogs';
        elseif ($toPrepaid && $fromExpense && !$fromPrepaid) $cls = 'cogs_reversal';
        elseif ($toExpense && !$fromExpense && !$fromPrepaid) $cls = 'cogs';
        elseif ($fromExpense && !$toExpense) $cls = 'cogs_reversal';
    }

    if ($cls && str_contains($cls, 'cogs')) {
        printf("tx#%d %s/%s %.2f | from=%s(toId=%d, fromExp=%s, fromPre=%s) | to=%s(toId=%d, toExp=%s, toPre=%s) | CLS=%s | notes=%s\n",
            $tx->id, $tx->type, $tx->module, $tx->amount,
            $tx->from_name ?? 'NULL', $fromId, $fromExpense?'Y':'N', $fromPrepaid?'Y':'N',
            $tx->to_name ?? 'NULL', $toId, $toExpense?'Y':'N', $toPrepaid?'Y':'N',
            $cls, mb_substr((string)$tx->notes, 0, 50)
        );
    }
}

echo "\n=== All today's transactions in tourism with account details ===\n";
foreach ($txs as $tx) {
    if (!in_array($tx->module, $tourismModules)) continue;
    echo sprintf("tx#%d %s/%s %.2f | from=%s(toId=%d type=%s) → to=%s(toId=%d type=%s) | %s\n",
        $tx->id, $tx->type, $tx->module, $tx->amount,
        $tx->from_name ?? 'NULL', $tx->from_account_id ?? 0, $tx->from_type ?? '-',
        $tx->to_name ?? 'NULL', $tx->to_account_id ?? 0, $tx->to_type ?? '-',
        mb_substr((string)$tx->notes, 0, 50)
    );
}
echo "\n=== Done ===\n";
