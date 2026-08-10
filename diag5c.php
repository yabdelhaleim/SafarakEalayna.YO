function _s($v) {
    if (is_object($v)) {
        if (property_exists($v, 'value')) { return $v->value; }
        if (method_exists($v, '__toString')) { return (string)$v; }
        return get_class($v);
    }
    return (string)$v;
}
echo "=== TX 364 ===\n";
$t = \App\Models\Transaction::find(364);
if ($t) {
    echo "id=".$t->id."\n";
    echo "type="._s($t->type)."\n";
    echo "module="._s($t->module)."\n";
    echo "from=".$t->from_account_id." to=".$t->to_account_id."\n";
    echo "amount=".$t->amount." currency=".$t->currency."\n";
    echo "related=".$t->related_type."#".$t->related_id."\n";
    echo "notes=".$t->notes."\n";
}
echo "=== TX 365 ===\n";
$t2 = \App\Models\Transaction::find(365);
if ($t2) {
    echo "id=".$t2->id."\n";
    echo "type="._s($t2->type)."\n";
    echo "module="._s($t2->module)."\n";
    echo "from=".$t2->from_account_id." to=".$t2->to_account_id."\n";
    echo "amount=".$t2->amount." currency=".$t2->currency."\n";
    echo "related=".$t2->related_type."#".$t2->related_id."\n";
    echo "notes=".$t2->notes."\n";
}
echo "=== ENTRIES 364 ===\n";
foreach (\App\Models\AccountEntry::where('transaction_id',364)->get() as $x) {
    echo "acc=".$x->account_id." dr=".$x->debit." cr=".$x->credit."\n";
}
echo "=== ENTRIES 365 ===\n";
$es2 = \App\Models\AccountEntry::where('transaction_id',365)->get();
if (count($es2) == 0) { echo "(no entries)\n"; }
foreach ($es2 as $x) {
    echo "acc=".$x->account_id." dr=".$x->debit." cr=".$x->credit."\n";
}
echo "=== ACCOUNTS ===\n";
$a = \App\Models\Account::find(27);
echo "27: bal=".$a->balance." ".$a->currency."\n";
$a = \App\Models\Account::find(109);
echo "109: bal=".$a->balance." ".$a->currency."\n";
echo "DONE\n";
