$t = \App\Models\Transaction::find(364);
echo "364: id=".$t->id." from=".$t->from_account_id." to=".$t->to_account_id." amount=".$t->amount." currency=".$t->currency."\n";
foreach (\App\Models\AccountEntry::where('transaction_id',364)->get() as $x) {
    echo "e364: acc=".$x->account_id." dr=".$x->debit." cr=".$x->credit."\n";
}
$t365 = \App\Models\Transaction::find(365);
if (is_null($t365)) { echo "365exists: NO\n"; } else { echo "365exists: YES\n"; }
$t = \App\Models\Transaction::find(366);
echo "366: id=".$t->id." from=".$t->from_account_id." to=".$t->to_account_id." amount=".$t->amount." currency=".$t->currency."\n";
echo "366notes: ".$t->notes."\n";
foreach (\App\Models\AccountEntry::where('transaction_id',366)->get() as $x) {
    echo "e366: acc=".$x->account_id." dr=".$x->debit." cr=".$x->credit."\n";
}
$a = \App\Models\Account::find(27);
echo "acc27: bal=".$a->balance." ".$a->currency."\n";
$a = \App\Models\Account::find(109);
echo "acc109: bal=".$a->balance." ".$a->currency."\n";
echo "DONE\n";
