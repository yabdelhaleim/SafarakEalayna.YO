// ╔═══════════════════════════════════════════════════════════════════════════╗
// ║  STEP 1 — FIND last partial debt payment(s) in a time window (READ-ONLY) ║
// ║  الصق في: php artisan tinker                                              ║
// ╚═══════════════════════════════════════════════════════════════════════════╝

use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightGroup;
use App\Models\Transaction;
use App\Models\Account;
use Carbon\Carbon;

// ── عدّل النافذة هنا لو عايز وقت مختلف
$from = Carbon::now()->subHours(2);   // من ساعتين
$to   = Carbon::now();                // للنهارده

// نوع العملية: 'payment' (سداد) أو 'debt' (إضافة دين)
// العملية اللي عايز ترجعها على الأرجح 'payment'
$type = 'payment';

echo "════════════════════════════════════════════════════════════════\n";
echo "  البحث عن {$type} بين {$from} و {$to}\n";
echo "════════════════════════════════════════════════════════════════\n\n";

$candidates = FlightGroupTransaction::query()
    ->where('type', $type)
    ->whereBetween('created_at', [$from, $to])
    ->orderByDesc('id')
    ->limit(20)
    ->get();

if ($candidates->isEmpty()) {
    echo "⚠️  ما لقيناش أي عملية {$type} في النافذة الزمنية.\n";
    echo "   وسّع النافذة: from <- Carbon::now()->subDay()\n";
    return;
}

foreach ($candidates as $tx) {
    $group = FlightGroup::find($tx->flight_group_id);
    $linkedTxn = Transaction::query()
        ->where('related_type', FlightGroupTransaction::class)
        ->where('related_id', $tx->id)
        ->orderByDesc('id')
        ->first();

    $fromAcc = $linkedTxn ? Account::find($linkedTxn->from_account_id) : null;
    $toAcc   = $linkedTxn ? Account::find($linkedTxn->to_account_id)   : null;

    echo "────────────────────────────────────────────────────────────\n";
    echo "FGT.ID #{$tx->id}  |  {$tx->type}  |  {$tx->amount}  |  {$tx->created_at}\n";
    echo "  Group: #{$tx->flight_group_id} " . ($group?->name ?? '???') . "\n";
    echo "  Notes: " . ($tx->notes ?? '—') . "\n";
    echo "  Booking: " . ($tx->flight_booking_id ?? '—') . "\n";

    if ($linkedTxn) {
        echo "  ↳ Transaction #{$linkedTxn->id} ({$linkedTxn->type->value}) {$linkedTxn->amount}\n";
        echo "    from: " . ($fromAcc?->name ?? '?') . " (bal {$fromAcc?->balance})\n";
        echo "    to:   " . ($toAcc?->name ?? '?') . " (bal {$toAcc?->balance})\n";
        echo "    notes: " . ($linkedTxn->notes ?? '—') . "\n";
        $alreadyReversed = str_starts_with((string) $linkedTxn->notes, 'عكس');
        echo "    status: " . ($alreadyReversed ? '⚠️  معكوس أصلاً' : '✅ لم يُعكس') . "\n";
    } else {
        echo "  ↳ لا يوجد Transaction مالي مرتبط\n";
    }
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  عدد العمليات: {$candidates->count()}  (آخر واحد = المحتمل)\n";
echo "════════════════════════════════════════════════════════════════\n";
