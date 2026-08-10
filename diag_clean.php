echo "=== TX 364 ===\n";
$t364 = \App\Models\Transaction::find(364);
if ($t364) {
    echo "id=".$t364->id." type=".$t364->type." module=".$t364->module."\n";
    echo "from=".$t364->from_account_id." to=".$t364->to_account_id."\n";
    echo "amount=".$t364->amount." currency=".$t364->currency."\n";
    echo "related=".$t364->related_type."#".$t364->related_id."\n";
    echo "notes=".substr((string)$t364->notes,0,80)."\n";
}
echo "=== TX 365 ===\n";
$t365 = \App\Models\Transaction::find(365);
if ($t365) {
    echo "id=".$t365->id." type=".$t365->type." module=".$t365->module."\n";
    echo "from=".$t365->from_account_id." to=".$t365->to_account_id."\n";
    echo "amount=".$t365->amount." currency=".$t365->currency."\n";
    echo "related=".$t365->related_type."#".$t365->related_id."\n";
    echo "notes=".substr((string)$t365->notes,0,80)."\n";
}
echo "=== ENTRIES 364 ===\n";
$e364 = \App\Models\AccountEntry::where('transaction_id',364)->get();
foreach ($e364 as $e) {
    echo "acc=".$e->account_id." dr=".$e->debit." cr=".$e->credit."\n";
}
echo "=== ENTRIES 365 ===\n";
$e365 = \App\Models\AccountEntry::where('transaction_id',365)->get();
if ($e365->count() == 0) { echo "(no entries)\n"; }
foreach ($e365 as $e) {
    echo "acc=".$e->account_id." dr=".$e->debit." cr=".$e->credit."\n";
}
echo "=== ACCOUNTS ===\n";
$a27 = \App\Models\Account::find(27);
echo "27: ".$a27->name." bal=".$a27->balance." ".$a27->currency."\n";
$a109 = \App\Models\Account::find(109);
echo "109: ".$a109->name." bal=".$a109->balance." ".$a109->currency."\n";
