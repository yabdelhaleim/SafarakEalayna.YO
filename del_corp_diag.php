<?php
/**
 * DIAGNOSTIC: معاينة حذف حجز طيران لعميل شركة
 *
 * الحجز المستهدف: FLT-20260706-97F3BD
 *
 * ⚠️ هذا السكربت للقراءة فقط — لا يُجري أي تعديل على قاعدة البيانات.
 *   الغرض منه:
 *     1. التحقق من وجود الحجز
 *     2. عرض كل السجلات المرتبطة (مسافرين/تذاكر/قطع/مدفوعات/مرتجعات/قيود GL)
 *     3. عرض أرصدة العميل/الناقل/الخزينة قبل الحذف
 *     4. معاينة صافي التغير في الأرصدة بعد الإلغاء والحذف
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "del_corp_diag.php";'
 */

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightPricing;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSegment;
use App\Models\Flight\FlightSystemTransaction;
use App\Models\Flight\FlightTicket;
use App\Models\Flight\RefundRequest;
use App\Models\Flight\TicketModification;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

$bookingRef = 'FLT-20260706-97F3BD';

echo "\n=========================================================\n";
echo "  DIAGNOSTIC: معاينة حذف الحجز {$bookingRef}\n";
echo "=========================================================\n";

// ─────────────────────────────────────────────────────────────
// [1] البحث عن الحجز (مع مراعاة SoftDeletes)
// ─────────────────────────────────────────────────────────────
$booking = FlightBooking::withTrashed()
    ->with([
        'customer.account',
        'employee.user',
        'account',
        'airlineAccount',
        'flightSystem',
        'flightCarrier',
        'flightGroup',
        'fromAirport',
        'toAirport',
        'passengers',
        'tickets',
        'segments',
        'payments.account',
        'refund',
    ])
    ->where(function ($q) use ($bookingRef) {
        $q->where('booking_reference', $bookingRef)
            ->orWhere('booking_number', $bookingRef)
            ->orWhere('pnr', $bookingRef);
    })
    ->first();

if (! $booking) {
    echo "\n✗ لم يتم العثور على الحجز بهذا المرجع: {$bookingRef}\n";
    echo "  تحقق من booking_reference / booking_number / pnr.\n";
    exit(1);
}

if ($booking->trashed()) {
    echo "\n⚠ الحجز محذوف Soft-Delete بالفعل (deleted_at = {$booking->deleted_at})\n";
    echo "  لا حاجة لتنفيذ الحذف. السكربت سيتوقف هنا للسلامة.\n";
    exit(2);
}

$bookingId = (int) $booking->id;

echo "\n[1] تفاصيل الحجز\n";
echo "─────────────────────────────────────────────────────────\n";
printf("  ID:                #%d\n", $booking->id);
printf("  Booking Ref:       %s\n", $booking->booking_reference);
printf("  Booking Number:    %s\n", $booking->booking_number);
printf("  PNR:               %s\n", $booking->pnr ?? '-');
printf("  Status:            %s\n", $booking->status?->value ?? $booking->status);
printf("  Airline:           %s\n", $booking->airline_name ?? $booking->airline ?? '-');
printf("  Route:             %s → %s\n", $booking->from_airport ?? '-', $booking->to_airport ?? '-');
printf("  Departure:         %s %s\n",
    $booking->departure_date?->format('Y-m-d') ?? '-',
    $booking->departure_time ?? '-'
);
printf("  Trip Type:         %s\n", $booking->trip_type ?? '-');
printf("  Currency:          %s\n", $booking->currency ?? '-');
printf("  Purchase:          %.2f %s\n", (float) $booking->purchase_price, $booking->currency ?? 'EGP');
printf("  Purchase (EGP):    %.2f\n", (float) ($booking->purchase_price_egp ?? $booking->purchase_price));
printf("  Purchase (FCY):    %s %s\n",
    $booking->purchase_price_foreign !== null ? number_format((float) $booking->purchase_price_foreign, 2) : '-',
    $booking->foreign_currency ?? '-'
);
printf("  Selling:           %.2f %s\n", (float) $booking->selling_price, $booking->currency ?? 'EGP');
printf("  Profit:            %.2f\n", (float) $booking->profit);
printf("  Balance Source:    %s\n", $booking->purchase_balance_source ?? '-');
printf("  Sale GL Tx ID:     %s\n", $booking->sale_gl_transaction_id ?? '-');
printf("  Created:           %s\n", $booking->created_at?->format('Y-m-d H:i:s'));
printf("  Created By:        %s (id=%s)\n",
    $booking->createdBy?->name ?? '-',
    $booking->created_by ?? '-'
);

echo "\n  Customer: {$booking->customer?->full_name} (id={$booking->customer_id})\n";
if ($booking->customer?->account) {
    $custAcc = $booking->customer->account;
    printf("    Customer Account: #%d \"%s\" | balance=%.2f %s\n",
        $custAcc->id, $custAcc->name, (float) $custAcc->balance, $custAcc->currency
    );
}

if ($booking->flightCarrier) {
    printf("  Carrier: %s (id=%d) | currency=%s | balance=%.4f\n",
        $booking->flightCarrier->name,
        $booking->flightCarrier->id,
        $booking->flightCarrier->currency,
        (float) $booking->flightCarrier->available_balance
    );
}
if ($booking->flightSystem) {
    printf("  System:  %s (id=%d) | currency=%s | balance=%.4f\n",
        $booking->flightSystem->name,
        $booking->flightSystem->id,
        $booking->flightSystem->currency,
        (float) $booking->flightSystem->available_balance
    );
}
if ($booking->flightGroup) {
    printf("  Group:   %s (id=%d)\n", $booking->flightGroup->name, $booking->flightGroup->id);
}
if ($booking->account) {
    printf("  Treasury Account: #%d \"%s\" | balance=%.2f %s\n",
        $booking->account->id, $booking->account->name,
        (float) $booking->account->balance, $booking->account->currency
    );
}

// ─────────────────────────────────────────────────────────────
// [2] السجلات الفرعية
// ─────────────────────────────────────────────────────────────
echo "\n[2] السجلات المرتبطة\n";
echo "─────────────────────────────────────────────────────────\n";

$passengers = FlightPassenger::withTrashed()->where('flight_booking_id', $bookingId)->get();
$tickets    = FlightTicket::withTrashed()->where('flight_booking_id', $bookingId)->get();
$segments   = FlightSegment::withTrashed()->where('flight_booking_id', $bookingId)->get();
$payments   = FlightPayment::withTrashed()->where('flight_booking_id', $bookingId)->get();
$refunds    = FlightRefund::withTrashed()->where('flight_booking_id', $bookingId)->get();
$pricings   = FlightPricing::withTrashed()->where('flight_booking_id', $bookingId)->get();
$airlineTx  = AirlineTransaction::withTrashed()->where('flight_booking_id', $bookingId)->get();
$systemTx   = FlightSystemTransaction::withTrashed()->where('flight_booking_id', $bookingId)->get();
$groupTx    = FlightGroupTransaction::withTrashed()->where('flight_booking_id', $bookingId)->get();
$airlineCr  = AirlineCredit::withTrashed()->where('flight_booking_id', $bookingId)->get();
$refundReq  = RefundRequest::withTrashed()->where('flight_booking_id', $bookingId)->get();
$mods       = TicketModification::withTrashed()->where('booking_id', $bookingId)->get();

printf("  Passengers:                %d\n", $passengers->count());
printf("  Tickets:                   %d\n", $tickets->count());
printf("  Segments:                  %d\n", $segments->count());
printf("  Payments:                  %d (total=%.2f EGP)\n",
    $payments->count(),
    (float) $payments->sum('amount')
);
printf("  Refunds (FlightRefund):    %d (total refund=%.2f EGP)\n",
    $refunds->count(),
    (float) $refunds->sum('refund_amount')
);
printf("  FlightPricing:             %d\n", $pricings->count());
printf("  AirlineTransaction:        %d\n", $airlineTx->count());
printf("  FlightSystemTransaction:   %d\n", $systemTx->count());
printf("  FlightGroupTransaction:    %d\n", $groupTx->count());
printf("  AirlineCredit:             %d\n", $airlineCr->count());
printf("  RefundRequest:             %d\n", $refundReq->count());
printf("  TicketModification:        %d\n", $mods->count());

if ($payments->count() > 0) {
    echo "\n  ── تفاصيل المدفوعات ──\n";
    foreach ($payments as $p) {
        printf("    Pay#%-4d | amount=%10.2f %s | method=%s | account_id=%s | tx_id=%s | deleted=%s\n",
            $p->id, (float) $p->amount, $p->currency ?? 'EGP',
            $p->payment_method?->value ?? $p->payment_method ?? '-',
            $p->account_id ?? '-', $p->transaction_id ?? '-',
            $p->trashed() ? 'YES' : 'no'
        );
    }
}

if ($refunds->count() > 0) {
    echo "\n  ── تفاصيل المرتجعات ──\n";
    foreach ($refunds as $r) {
        printf("    Ref#%-4d | total_paid=%.2f | refund=%.2f | airline_pen=%.2f | office_pen=%.2f | status=%s\n",
            $r->id, (float) $r->total_paid, (float) $r->refund_amount,
            (float) $r->airline_penalty, (float) $r->office_penalty,
            $r->status ?? '-'
        );
    }
}

// ─────────────────────────────────────────────────────────────
// [3] القيود المحاسبية المرتبطة (Transactions + AccountEntries)
// ─────────────────────────────────────────────────────────────
echo "\n[3] القيود المحاسبية (Transactions / AccountEntries)\n";
echo "─────────────────────────────────────────────────────────\n";

$relatedType = 'App\\Models\\Flight\\FlightBooking';
$txs = Transaction::withTrashed()
    ->where('related_type', $relatedType)
    ->where('related_id', $bookingId)
    ->orderBy('id')
    ->get();

printf("  عدد القيود المرتبطة: %d\n", $txs->count());

$txIds = $txs->pluck('id')->toArray();
$entries = collect();
if (! empty($txIds)) {
    $entries = AccountEntry::withTrashed()
        ->whereIn('transaction_id', $txIds)
        ->with('account:id,name,type,module_type,currency')
        ->orderBy('id')
        ->get();
}
printf("  عدد القيود الفرعية (AccountEntry): %d\n", $entries->count());

if ($txs->count() > 0) {
    echo "\n  ── Transactions ──\n";
    foreach ($txs as $t) {
        printf("    Tx#%-5d | %-12s | amount=%10.2f | from=%-5s | to=%-5s | mod=%s | notes=%s\n",
            $t->id,
            $t->type?->value ?? '-',
            (float) $t->amount,
            $t->from_account_id ?? '-',
            $t->to_account_id ?? '-',
            $t->module ?? '-',
            mb_substr((string) ($t->notes ?? ''), 0, 60)
        );
    }
}

if ($entries->count() > 0) {
    echo "\n  ── AccountEntries ──\n";
    foreach ($entries as $e) {
        $acc = $e->account;
        printf("    Ent#%-6d | Tx#%-5d | %-40s (id=%-3d) | D=%10.2f | C=%10.2f | bal_after=%10.2f\n",
            $e->id, $e->transaction_id,
            mb_substr((string) ($acc->name ?? '?'), 0, 40),
            $acc->id ?? 0,
            (float) $e->debit, (float) $e->credit, (float) $e->balance_after
        );
    }
}

// ─────────────────────────────────────────────────────────────
// [4] أرصدة "قبل" الحذف
// ─────────────────────────────────────────────────────────────
echo "\n[4] أرصدة قبل الحذف\n";
echo "─────────────────────────────────────────────────────────\n";

$customerBalanceBefore = $booking->customer?->account
    ? (float) $booking->customer->account->balance
    : 0.0;
$carrierBalanceBefore = $booking->flightCarrier
    ? (float) $booking->flightCarrier->available_balance
    : null;
$systemBalanceBefore = $booking->flightSystem
    ? (float) $booking->flightSystem->available_balance
    : null;
$treasuryBalanceBefore = $booking->account
    ? (float) $booking->account->balance
    : null;

printf("  Customer Account balance: %.2f EGP\n", $customerBalanceBefore);
if ($carrierBalanceBefore !== null) {
    printf("  Carrier balance:         %.4f %s\n",
        $carrierBalanceBefore, $booking->flightCarrier->currency
    );
}
if ($systemBalanceBefore !== null) {
    printf("  System balance:          %.4f %s\n",
        $systemBalanceBefore, $booking->flightSystem->currency
    );
}
if ($treasuryBalanceBefore !== null) {
    printf("  Treasury balance:        %.2f %s\n",
        $treasuryBalanceBefore, $booking->account->currency
    );
}

// ─────────────────────────────────────────────────────────────
// [5] معاينة صافي التغير بعد الإلغاء + الحذف
// ─────────────────────────────────────────────────────────────
echo "\n[5] معاينة صافي التغير بعد الحذف (التوقع فقط، لم يُنفَّذ شيء)\n";
echo "─────────────────────────────────────────────────────────\n";

$totalPaid   = (float) $payments->sum('amount');
$saleAmount  = (float) $booking->selling_price;
$purchaseEGP = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);

printf("  إجمالي مدفوعات العميل:        %.2f EGP\n", $totalPaid);
printf("  sale_gl_transaction:          %.2f EGP (سيُعكس بالكامل)\n", $saleAmount);
printf("  purchase_price (EGP):         %.2f (سيُعاد لرصيد الناقل/النظام)\n", $purchaseEGP);
printf("  cash refund إلى العميل:       %.2f EGP (refund = paid - penalties)\n", $totalPaid);

if ($booking->flightCarrier) {
    $balCurrency = $booking->flightCarrier->currency;
    if (strtoupper($balCurrency) === 'EGP') {
        $carrierDelta = $purchaseEGP;
    } elseif (strtoupper($balCurrency) === strtoupper($booking->currency ?? '')) {
        $carrierDelta = (float) ($booking->purchase_price_foreign ?? $purchaseEGP);
    } else {
        // تحويل تقريبي (لن يُستخدم فعلياً — للعرض فقط)
        $carrierDelta = null;
    }
    if ($carrierDelta !== null) {
        printf("  → Carrier balance change:     +%.4f %s (سيُضاف)\n", $carrierDelta, $balCurrency);
    } else {
        printf("  → Carrier balance change:     سيتم الحساب داخل cancelBooking (عملة مختلطة)\n");
    }
}
if ($booking->flightSystem) {
    $balCurrency = $booking->flightSystem->currency;
    if (strtoupper($balCurrency) === 'EGP') {
        $systemDelta = $purchaseEGP;
    } elseif (strtoupper($balCurrency) === strtoupper($booking->currency ?? '')) {
        $systemDelta = (float) ($booking->purchase_price_foreign ?? $purchaseEGP);
    } else {
        $systemDelta = null;
    }
    if ($systemDelta !== null) {
        printf("  → System balance change:      +%.4f %s (سيُضاف)\n", $systemDelta, $balCurrency);
    } else {
        printf("  → System balance change:      سيتم الحساب داخل cancelBooking (عملة مختلطة)\n");
    }
}

echo "\n  ⚠ هذا عرض تقديري. الأرقام الفعلية تُحسب داخل cancelBooking\n";
echo "    باستخدام purchaseAmountInBalanceCurrency() + lockedRateFromBookingSnapshot().\n";

// ─────────────────────────────────────────────────────────────
// [6] ملخص الحذف
// ─────────────────────────────────────────────────────────────
echo "\n[6] ملخص ما سيتم حذفه فعلياً في del_corp_apply.php\n";
echo "─────────────────────────────────────────────────────────\n";
echo "  [a] قبل الحذف: استدعاء FlightBookingService::cancelBooking()\n";
echo "      - يعكس قيد sale_gl_transaction (customer ← clearing)\n";
echo "      - يرجع رصيد الناقل/النظام/المجموعة\n";
echo "      - يسدد نقدية للعميل (إن وُجدت مدفوعات)\n";
echo "      - يحدث الحالة إلى REFUNDED/CANCELLED ويضيف سجل FlightRefund\n";
echo "  [b] بعد الإلغاء الناجح: hard-delete للسجلات الفرعية\n";
echo "      - flight_payments, flight_refunds (soft-delete للحفاظ على الـ audit)\n";
echo "      - flight_passengers, flight_tickets, flight_segments, flight_pricings\n";
echo "      - airline_credits, refund_requests, ticket_modifications\n";
echo "      - airline_transactions, flight_system_transactions, flight_group_transactions\n";
echo "      - transactions + account_entries المرتبطة\n";
echo "  [c] حذف flight_bookings عبر DB::table لتجاوز model event\n\n";

echo "=========================================================\n";
echo "  ✓ انتهى الـ diagnostic. لم يُعدَّل شيء في قاعدة البيانات.\n";
echo "=========================================================\n";
echo "  الخطوة التالية: راجع الأرقام جيداً، ثم:\n";
echo "    1) ضع ملف التأكيد على السيرفر (مطلوب للأمان):\n";
echo "         touch /tmp/del_corp_97F3BD.confirmed\n";
echo "    2) شغّل:\n";
echo "         php artisan tinker --execute='require \"del_corp_apply.php\";'\n";
echo "=========================================================\n";