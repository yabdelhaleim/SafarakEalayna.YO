echo "\n=== TX 364 ===\n";
$t = \App\Models\Transaction::find(364);
if ($t) {
    echo "id=".$t->id." type=".$t->type." module=".$t->module."\n";
    echo "from=".$t->from_account_id." to=".$t->to_account_id."\n";
    echo "amount=".$t->amount." currency=".$t->currency."\n";
    echo "related=".$t->related_type."#".$t->related_id."\n";
    echo "notes=".$t->notes."\n";
}
echo "\n=== TX 365 ===\n";
$t2 = \App\Models\Transaction::find(365);
if ($t2) {
    echo "id=".$t2->id." type=".$t2->type." module=".$t2->module."\n";
    echo "from=".$t2->from_account_id." to=".$t2->to_account_id."\n";
    echo "amount=".$t2->amount." currency=".$t2->currency."\n";
    echo "related=".$t2->related_type."#".$t2->related_id."\n";
    echo "notes=".$t2->notes."\n";
}
echo "\n=== ENTRIES 364 ===\n";
$es = \App\Models\AccountEntry::where('transaction_id',364)->get();
foreach ($es as $x) {
    echo "acc=".$x->account_id." dr=".$x->debit." cr=".$x->credit."\n";
}
echo "\n=== ENTRIES 365 ===\n";
$es2 = \App\Models\AccountEntry::where('transaction_id',365)->get();
if (count($es2) == 0) { echo "(no entries)\n"; }
foreach ($es2 as $x) {
    echo "acc=".$x->account_id." dr=".$x->debit." cr=".$x->credit."\n";
}
echo "\n=== ACCOUNTS ===\n";
$a = \App\Models\Account::find(27);
echo "27: ".$a->name." bal=".$a->balance." ".$a->currency."\n";
$a = \App\Models\Account::find(109);
echo "109: ".$a->name." bal=".$a->balance." ".$a->currency."\n";
echo "DONE\n";
