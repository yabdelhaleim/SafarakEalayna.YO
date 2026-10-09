echo "\n=== FULL BOOKING RECONCILIATION — booking id=1 ===\n\n";

$booking = DB::table('flight_bookings')->where('id', 1)->first();
echo "BOOKING: {$booking->booking_number}\n";
echo "  selling_price:  {$booking->selling_price} EGP\n";
echo "  purchase_price: {$booking->purchase_price} EGP\n";
echo "  customer_id:    {$booking->customer_id}\n";
echo "  status:         {$booking->status}\n";
echo "  flight_carrier_id:  {$booking->flight_carrier_id}\n";
echo "  flight_group_id:    {$booking->flight_group_id}\n\n";

echo "=== REFUND RECORD ===\n";
$refund = DB::table('flight_refunds')->where('flight_booking_id', 1)->first();
if ($refund) {
    echo "  refund_amount: {$refund->refund_amount} EGP\n";
    echo "  airline_penalty: {$refund->airline_penalty} EGP\n";
    echo "  office_penalty:  {$refund->office_penalty} EGP\n";
    echo "  total_paid (at refund time): {$refund->total_paid} EGP\n";
    echo "  status: {$refund->status}\n";
    echo "  notes: {$refund->notes}\n";
}
echo "\n";

echo "=== ALL TRANSACTIONS ===\n";
$txs = DB::table('transactions as t')
    ->leftJoin('accounts as af', 't.from_account_id', '=', 'af.id')
    ->leftJoin('accounts as at', 't.to_account_id', '=', 'at.id')
    ->where('t.module', 'flight')
    ->where(function ($q) use ($booking) {
        $q->where(function ($q2) use ($booking) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')->where('t.related_id', $booking->id);
        })->orWhere(function ($q2) use ($booking) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightPayment')->whereIn('t.related_id', DB::table('flight_payments')->where('flight_booking_id', $booking->id)->pluck('id') ?: [0]);
        })->orWhere(function ($q2) use ($booking) {
            $q2->where('t.related_type', 'App\\Models\\Customer')->where('t.related_id', $booking->customer_id);
        })->orWhere(function ($q2) use ($booking) {
            $q2->where('t.related_type', 'App\\Models\\Flight\\FlightGroupTransaction')->where('t.related_id', $booking->flight_group_id);
        });
    })
    ->orderBy('t.id')
    ->get();

echo sprintf("%-4s %-9s %-9s %-22s %-22s %s\n", 'ID', 'TYPE', 'AMOUNT', 'FROM', 'TO', 'NOTES');
echo str_repeat('-', 130) . "\n";
foreach ($txs as $tx) {
    echo sprintf("%-4d %-9s %-9.2f %-22s %-22s %s\n",
        $tx->id, $tx->type, $tx->amount,
        mb_substr(($tx->from_name ?? 'NULL'), 0, 22),
        mb_substr(($tx->to_name ?? 'NULL'), 0, 22),
        mb_substr((string)$tx->notes, 0, 60)
    );
}
echo "\n=== SUPPLIER GROUP BALANCE ===\n";
$g = DB::table('flight_groups')->where('id', $booking->flight_group_id)->first();
if ($g) echo "  Group '{$g->name}' current balance: {$g->balance} EGP\n";
echo "\n=== CUSTOMER BALANCE ===\n";
$c = DB::table('customers')->where('id', $booking->customer_id)->first();
$ledger = DB::table('accounts')->where('owner_type', 'customer')->where('owner_id', $booking->customer_id)->first();
if ($ledger) echo "  Customer '{$c->full_name}' ledger balance: {$ledger->balance} EGP\n";

echo "\n=== DONE ===\n";
