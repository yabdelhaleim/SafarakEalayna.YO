<?php

// ╔═══════════════════════════════════════════════════════════════════════════╗
// ║  FIND LAST PAYMENT OPERATION IN FLIGHT MODULE  (READ-ONLY)               ║
// ║  يعرض آخر عملية تسديد (سداد دفعة) تمت في موديول الطيران                  ║
// ║                                                                          ║
// ║  الاستخدام: php artisan tinker < tinker_find_last_payment.php           ║
// ║  أو: الصق المحتوى مباشرة في tinker                                       ║
// ╚═══════════════════════════════════════════════════════════════════════════╝

use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightBooking;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\User;

echo "════════════════════════════════════════════════════════════════\n";
echo "  آخر عملية تسديد (payment) في موديول الطيران\n";
echo "════════════════════════════════════════════════════════════════\n\n";

// 1) آخر FlightGroupTransaction من نوع payment
$lastPayment = FlightGroupTransaction::query()
    ->where('type', 'payment')
    ->orderByDesc('id')
    ->first();

if (! $lastPayment) {
    echo "⚠️  ما لقيناش أي عملية تسديد في جدول flight_group_transactions.\n";
    return;
}

echo "▌ FlightGroupTransaction (السجل الأعلى)\n";
echo "   ID              : #{$lastPayment->id}\n";
echo "   type            : {$lastPayment->type}\n";
echo "   amount          : {$lastPayment->amount}\n";
echo "   created_at      : {$lastPayment->created_at}\n";
echo "   updated_at      : {$lastPayment->updated_at}\n";
echo "   notes           : " . ($lastPayment->notes ?? '—') . "\n";

// 2) بيانات الـ Group
$group = FlightGroup::find($lastPayment->flight_group_id);
echo "\n▌ FlightGroup\n";
echo "   ID   : #" . ($group?->id ?? '?') . "\n";
echo "   name : " . ($group?->name ?? '???') . "\n";

// 3) بيانات الـ Booking (لو موجود)
$booking = $lastPayment->flight_booking_id
    ? FlightBooking::find($lastPayment->flight_booking_id)
    : null;

if ($booking) {
    echo "\n▌ FlightBooking\n";
    echo "   ID              : #{$booking->id}\n";
    echo "   booking_ref     : " . ($booking->booking_reference ?? '—') . "\n";
    echo "   status          : " . ($booking->status?->value ?? $booking->status ?? '—') . "\n";
    echo "   sale_price      : " . ($booking->sale_price ?? '—') . "\n";
    echo "   paid_amount     : " . ($booking->paid_amount ?? '—') . "\n";
    echo "   remaining       : " . ($booking->remaining_amount ?? '—') . "\n";
    echo "   currency        : " . ($booking->currency ?? '—') . "\n";

    // 4) العميل
    if ($booking->customer_id) {
        $customer = Customer::find($booking->customer_id);
        echo "\n▌ Customer\n";
        echo "   ID   : #" . ($customer?->id ?? '?') . "\n";
        echo "   name : " . ($customer?->name ?? '???') . "\n";
        echo "   phone: " . ($customer?->phone ?? '—') . "\n";
    }
}

// 5) الـ Transaction المالي المرتبط (يربط FGT بالـ GL)
$linkedTxn = Transaction::query()
    ->where('related_type', FlightGroupTransaction::class)
    ->where('related_id', $lastPayment->id)
    ->orderByDesc('id')
    ->first();

if ($linkedTxn) {
    $fromAcc = Account::find($linkedTxn->from_account_id);
    $toAcc   = Account::find($linkedTxn->to_account_id);

    echo "\n▌ GL Transaction (المالي)\n";
    echo "   ID        : #{$linkedTxn->id}\n";
    echo "   type      : " . ($linkedTxn->type?->value ?? $linkedTxn->type) . "\n";
    echo "   amount    : {$linkedTxn->amount}\n";
    echo "   currency  : " . ($linkedTxn->currency ?? '—') . "\n";
    echo "   created_at: {$linkedTxn->created_at}\n";
    echo "   notes     : " . ($linkedTxn->notes ?? '—') . "\n";

    echo "\n▌ Accounts (من → إلى)\n";
    echo "   from: " . ($fromAcc?->name ?? '?') . "  (balance={$fromAcc?->balance})\n";
    echo "   to  : " . ($toAcc?->name ?? '?') . "  (balance={$toAcc?->balance})\n";

    $alreadyReversed = str_starts_with((string) $linkedTxn->notes, 'عكس')
        || str_contains((string) $linkedTxn->notes, 'reversal');
    echo "\n   status: " . ($alreadyReversed ? '⚠️  معكوس' : '✅ لم يُعكس') . "\n";
} else {
    echo "\n▌ GL Transaction\n   (لا يوجد Transaction مالي مرتبط بهذه العملية)\n";
}

// 6) المستخدم اللي نفّذ العملية
$user = User::find($lastPayment->created_by);
echo "\n▌ Created By\n";
echo "   ID   : #" . ($user?->id ?? '?') . "\n";
echo "   name : " . ($user?->name ?? '???') . "\n";
echo "   email: " . ($user?->email ?? '—') . "\n";

// 7) ملخص سريع
echo "\n════════════════════════════════════════════════════════════════\n";
echo "  ملخص: عملية #{$lastPayment->id} — نوع {$lastPayment->type}\n";
echo "        مبلغ {$lastPayment->amount} — بتاريخ {$lastPayment->created_at}\n";
echo "════════════════════════════════════════════════════════════════\n";
