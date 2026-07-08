<?php
/**
 * APPLY: حذف آمن لحجز طيران لعميل شركة (FLT-20260706-97F3BD)
 *
 * ⚠️ خطوات الأمان قبل التشغيل:
 *   1) شغّل del_corp_diag.php أولاً وراجع كل الأرقام
 *   2) تأكد أن الحجز فعلاً للعميل الصحيح وأن قرار الحذف مُعتمد
 *   3) أنشئ ملف التأكيد على السيرفر:
 *         touch /tmp/del_corp_97F3BD.confirmed
 *      (السكربت سيتوقف فوراً لو الملف غير موجود)
 *   4) خُذ backup من قاعدة البيانات قبل التشغيل
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "del_corp_apply.php";'
 *
 * ما يفعله السكربت (كله داخل DB::transaction واحد):
 *   1) FlightBookingService::cancelBooking() — عكس كامل لـ GL
 *      (يرجع رصيد الناقل/النظام، يعكس قيد البيع، يرد للعميل، يحدث الحالة)
 *   2) soft-delete للمدفوعات والمرتجعات (للحفاظ على audit trail)
 *   3) force-delete للمسافرين/التذاكر/القطع/التسعير
 *   4) force-delete لـ airline_credits / refund_requests / ticket_modifications
 *   5) force-delete لـ airline_transactions / system_transactions / group_transactions
 *   6) force-delete للقيود (transactions + account_entries) المرتبطة بالحجز
 *   7) force-delete لـ flight_bookings عبر DB::table (يتجاوز model event)
 *
 * في حالة فشل أي خطوة → rollback تلقائي ولا يتغير شيء في قاعدة البيانات.
 */

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\FlightPayment;
use App\Models\FlightPricing;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSegment;
use App\Models\Flight\FlightSystemTransaction;
use App\Models\Flight\FlightTicket;
use App\Models\Flight\RefundRequest;
use App\Models\Flight\TicketModification;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

$bookingRef = 'FLT-20260706-97F3BD';
$confirmFile = '/tmp/del_corp_97F3BD.confirmed';

echo "\n=========================================================\n";
echo "  APPLY: حذف الحجز {$bookingRef}\n";
echo "=========================================================\n";

// ─────────────────────────────────────────────────────────────
// [0] بوابة الأمان: يجب وجود ملف التأكيد
// ─────────────────────────────────────────────────────────────
if (! file_exists($confirmFile)) {
    echo "\n✗ لم يتم العثور على ملف التأكيد: {$confirmFile}\n\n";
    echo "  للسلامة، يجب إنشاء الملف قبل التشغيل:\n";
    echo "      touch {$confirmFile}\n\n";
    echo "  لو كنت فعلاً متأكد من الحذف، شغّل:\n";
    echo "      touch {$confirmFile} && php artisan tinker --execute='require \"del_corp_apply.php\";'\n";
    exit(10);
}
echo "  ✓ ملف التأكيد موجود ({$confirmFile})\n";

// ─────────────────────────────────────────────────────────────
// [1] البحث عن الحجز
// ─────────────────────────────────────────────────────────────
$booking = FlightBooking::withTrashed()
    ->with(['customer.ledgerAccount', 'flightCarrier', 'flightSystem', 'flightGroup', 'account'])
    ->where(function ($q) use ($bookingRef) {
        $q->where('booking_reference', $bookingRef)
            ->orWhere('booking_number', $bookingRef)
            ->orWhere('pnr', $bookingRef);
    })
    ->first();

if (! $booking) {
    echo "\n✗ لم يتم العثور على الحجز: {$bookingRef}\n";
    exit(20);
}
if ($booking->trashed()) {
    echo "\n⚠ الحجز محذوف Soft-Delete بالفعل — لا شيء للتنفيذ.\n";
    exit(21);
}

$bookingId = (int) $booking->id;

echo "\n[1] ملخص الحجز قبل الحذف\n";
echo "─────────────────────────────────────────────────────────\n";
printf("  ID:             #%d\n", $booking->id);
printf("  Ref:            %s\n", $booking->booking_reference);
printf("  PNR:            %s\n", $booking->pnr ?? '-');
printf("  Status:         %s\n", $booking->status?->value ?? $booking->status);
printf("  Customer:       %s (id=%d)\n",
    $booking->customer?->full_name ?? '-', $booking->customer_id
);
printf("  Carrier:        %s (id=%d, %s)\n",
    $booking->flightCarrier?->name ?? '-',
    $booking->flightCarrier?->id ?? 0,
    $booking->flightCarrier?->currency ?? '-'
);
printf("  System:         %s (id=%d, %s)\n",
    $booking->flightSystem?->name ?? '-',
    $booking->flightSystem?->id ?? 0,
    $booking->flightSystem?->currency ?? '-'
);
printf("  Currency:       %s\n", $booking->currency);
printf("  Purchase:       %.2f EGP / %s %s\n",
    (float) ($booking->purchase_price_egp ?? $booking->purchase_price),
    $booking->purchase_price_foreign !== null ? number_format((float) $booking->purchase_price_foreign, 2) : '-',
    $booking->foreign_currency ?? '-'
);
printf("  Selling:        %.2f EGP\n", (float) $booking->selling_price);
printf("  Paid:           %.2f EGP\n", (float) $booking->payments()->sum('amount'));

// حفظ الأرصدة قبل
$customerBalanceBefore = $booking->customer?->ledgerAccount
    ? (float) $booking->customer->ledgerAccount->balance
    : 0.0;
$carrierBalanceBefore = $booking->flightCarrier
    ? (float) $booking->flightCarrier->available_balance
    : null;
$carrierCurrency      = $booking->flightCarrier?->currency;
$systemBalanceBefore  = $booking->flightSystem
    ? (float) $booking->flightSystem->available_balance
    : null;
$systemCurrency       = $booking->flightSystem?->currency;
$treasuryBalanceBefore = $booking->account
    ? (float) $booking->account->balance
    : null;
$treasuryCurrency     = $booking->account?->currency;

echo "\n  ── أرصدة قبل ──\n";
printf("    Customer:  %.2f EGP\n", $customerBalanceBefore);
if ($carrierBalanceBefore !== null) {
    printf("    Carrier:   %.4f %s\n", $carrierBalanceBefore, $carrierCurrency);
}
if ($systemBalanceBefore !== null) {
    printf("    System:    %.4f %s\n", $systemBalanceBefore, $systemCurrency);
}
if ($treasuryBalanceBefore !== null) {
    printf("    Treasury:  %.2f %s\n", $treasuryBalanceBefore, $treasuryCurrency);
}

// ─────────────────────────────────────────────────────────────
// [2] التنفيذ داخل transaction واحد
// ─────────────────────────────────────────────────────────────
echo "\n[2] تنفيذ الإلغاء + الحذف (DB::transaction)\n";
echo "─────────────────────────────────────────────────────────\n";

// جمع معرفات السجلات الفرعية قبل أي حذف (تحتاجها Step 5 للقيود المرتبطة)
$childIds = [
    'group_tx'      => FlightGroupTransaction::where('flight_booking_id', $bookingId)->pluck('id')->all(),
    'payments'      => FlightPayment::where('flight_booking_id', $bookingId)->pluck('id')->all(),
    'refunds'       => FlightRefund::where('flight_booking_id', $bookingId)->pluck('id')->all(),
    'airline_credit'=> AirlineCredit::where('flight_booking_id', $bookingId)->pluck('id')->all(),
    'refund_request'=> RefundRequest::where('flight_booking_id', $bookingId)->pluck('id')->all(),
    'mods'          => TicketModification::where('booking_id', $bookingId)->pluck('id')->all(),
];
echo "    Pre-collected child IDs:\n";
foreach ($childIds as $k => $v) {
    printf("      %-15s → %d\n", $k, count($v));
}

$svc = app(\App\Services\Flight\FlightBookingService::class);

try {
    DB::transaction(function () use ($booking, $bookingId, $svc, $childIds) {

        // ── [2a] Step 1: عكس GL كامل عبر cancelBooking ──
        // ملاحظة: لو الحجز مُلغى أو مُسترد بالفعل، cancelBooking سيرمي — نكتشف ذلك ونكمل بدونه.
        $alreadyCancelled = in_array(
            $booking->status,
            [\App\Enums\FlightBookingStatus::CANCELLED, \App\Enums\FlightBookingStatus::REFUNDED],
            true
        );

        if ($alreadyCancelled) {
            echo "    [Step 1] الحجز مُلغى بالفعل — تخطّي cancelBooking.\n";
        } else {
            echo "    [Step 1] استدعاء cancelBooking (full reversal, penalties=0)...\n";
            $payment = $booking->payments()->first();
            $paymentAccountId = $payment ? (int) $payment->account_id : (int) ($booking->account_id ?? 0);

            try {
                $refund = $svc->cancelBooking($booking->fresh([
                    'customer.ledgerAccount', 'flightCarrier', 'flightSystem', 'flightGroup',
                    'passengers', 'tickets', 'segments', 'payments',
                ]), [
                    'airline_penalty' => 0,
                    'office_penalty'  => 0,
                    'account_id'      => $paymentAccountId ?: null,
                    'notes'           => "Hard-delete corporate booking #{$booking->id} ({$bookingRef})",
                ]);
                printf("      ✓ cancelBooking done. refund_amount=%.2f, status=%s\n",
                    (float) $refund->refund_amount, $refund->status
                );
            } catch (\Throwable $e) {
                echo "      ✗ cancelBooking FAILED: ".$e->getMessage()."\n";
                throw $e; // rollback
            }
        }

        // ── [2b] Step 2: soft-delete للمدفوعات والمرتجعات (audit trail) ──
        echo "    [Step 2] soft-delete المدفوعات والمرتجعات (audit preservation)...\n";
        $paymentsDeleted = FlightPayment::where('flight_booking_id', $bookingId)->delete();
        $refundsDeleted  = FlightRefund::where('flight_booking_id', $bookingId)->delete();
        printf("      ✓ payments deleted=%d, refunds deleted=%d\n", $paymentsDeleted, $refundsDeleted);

        // ── [2c] Step 3: force-delete للسجلات الفرعية ──
        echo "    [Step 3] force-delete للسجلات الفرعية...\n";
        $paxCount = FlightPassenger::where('flight_booking_id', $bookingId)->forceDelete();
        $tktCount = FlightTicket::where('flight_booking_id', $bookingId)->forceDelete();
        $segCount = FlightSegment::where('flight_booking_id', $bookingId)->forceDelete();
        $prcCount = FlightPricing::where('flight_booking_id', $bookingId)->forceDelete();
        printf("      ✓ passengers=%d, tickets=%d, segments=%d, pricing=%d\n",
            $paxCount, $tktCount, $segCount, $prcCount
        );

        // ── [2d] Step 4: حذف السجلات المالية الفرعية ──
        echo "    [Step 4] force-delete للسجلات المالية الفرعية...\n";
        $airlineTxCount = AirlineTransaction::where('flight_booking_id', $bookingId)->forceDelete();
        $systemTxCount  = FlightSystemTransaction::where('flight_booking_id', $bookingId)->forceDelete();
        $groupTxCount   = FlightGroupTransaction::where('flight_booking_id', $bookingId)->forceDelete();
        $airlineCrCount = AirlineCredit::where('flight_booking_id', $bookingId)->forceDelete();
        $refundReqCount = RefundRequest::where('flight_booking_id', $bookingId)->forceDelete();
        $modsCount      = TicketModification::where('booking_id', $bookingId)->forceDelete();
        printf("      ✓ airline_tx=%d, system_tx=%d, group_tx=%d, airline_credit=%d, refund_req=%d, mods=%d\n",
            $airlineTxCount, $systemTxCount, $groupTxCount,
            $airlineCrCount, $refundReqCount, $modsCount
        );

        // ── [2e] Step 5: حذف Transactions + AccountEntries المرتبطة ──
        // نجمع كل معرفات الـ transactions من:
        //   a) related_type = FlightBooking, related_id = booking
        //   b) related_type = FlightGroupTransaction, related_id ∈ group_tx ids
        //   c) related_type = FlightPayment/Refund, related_id ∈ payment/refund ids
        //   d) transactions المرتبطة بـ airline_credit / refund_request
        echo "    [Step 5] force-delete للقيود المحاسبية المرتبطة...\n";
        $relatedType = 'App\\Models\\Flight\\FlightBooking';
        $txIds = collect();

        // (a) القيود المرتبطة مباشرة بالحجز
        $txIds = $txIds->merge(
            Transaction::where('related_type', $relatedType)
                ->where('related_id', $bookingId)
                ->pluck('id')
        );

        // (b) القيود المرتبطة بـ group_transactions الخاصة بهذا الحجز
        if (! empty($childIds['group_tx'])) {
            $txIds = $txIds->merge(
                Transaction::where('related_type', 'App\\Models\\Flight\\FlightGroupTransaction')
                    ->whereIn('related_id', $childIds['group_tx'])
                    ->pluck('id')
            );
        }

        // (c) القيود المرتبطة بـ payments/refunds (المحذوفة soft في Step 2)
        if (! empty($childIds['payments'])) {
            $txIds = $txIds->merge(
                Transaction::where('related_type', 'App\\Models\\Flight\\FlightPayment')
                    ->whereIn('related_id', $childIds['payments'])
                    ->pluck('id')
            );
        }
        if (! empty($childIds['refunds'])) {
            $txIds = $txIds->merge(
                Transaction::where('related_type', 'App\\Models\\Flight\\FlightRefund')
                    ->whereIn('related_id', $childIds['refunds'])
                    ->pluck('id')
            );
        }

        // (d) القيود المرتبطة بـ airline_credit / refund_request
        if (! empty($childIds['airline_credit'])) {
            $txIds = $txIds->merge(
                Transaction::where('related_type', 'App\\Models\\Flight\\AirlineCredit')
                    ->whereIn('related_id', $childIds['airline_credit'])
                    ->pluck('id')
            );
        }
        if (! empty($childIds['refund_request'])) {
            $txIds = $txIds->merge(
                Transaction::where('related_type', 'App\\Models\\Flight\\RefundRequest')
                    ->whereIn('related_id', $childIds['refund_request'])
                    ->pluck('id')
            );
        }

        $txIds = $txIds->unique()->values()->all();

        if (! empty($txIds)) {
            $entryCount = AccountEntry::whereIn('transaction_id', $txIds)->forceDelete();
            $txCount    = DB::table('transactions')->whereIn('id', $txIds)->delete();
            printf("      ✓ transactions=%d, account_entries=%d\n", $txCount, $entryCount);
        } else {
            echo "      (لا توجد قيود مرتبطة)\n";
        }

        // ── [2f] Step 6: force-delete للحجز نفسه عبر DB::table (يتجاوز model event) ──
        echo "    [Step 6] force-delete للحجز عبر DB::table('flight_bookings')...\n";
        $deleted = DB::table('flight_bookings')->where('id', $bookingId)->delete();
        if ($deleted === 0) {
            throw new \RuntimeException("فشل حذف flight_bookings — لم يُحذف أي صف (id={$bookingId}).");
        }
        printf("      ✓ flight_bookings rows deleted: %d\n", $deleted);
    });

    echo "\n  ✓ كل العمليات تمت بنجاح.\n";
} catch (\Throwable $e) {
    echo "\n  ✗ FATAL ERROR: ".$e->getMessage()."\n";
    echo "  → تم عمل ROLLBACK — قاعدة البيانات لم تتغير.\n";
    exit(99);
}

// ─────────────────────────────────────────────────────────────
// [3] أرصدة بعد الحذف
// ─────────────────────────────────────────────────────────────
echo "\n[3] أرصدة بعد الحذف (للتأكيد)\n";
echo "─────────────────────────────────────────────────────────\n";

if ($booking->customer?->account_id) {
    $customerBalanceAfter = (float) Account::where('id', $booking->customer->account_id)->value('balance');
    printf("  Customer:  %.2f → %.2f EGP (delta: %+.2f)\n",
        $customerBalanceBefore, $customerBalanceAfter,
        $customerBalanceAfter - $customerBalanceBefore
    );
}
if ($booking->flightCarrier) {
    $carrierBalanceAfter = (float) \App\Models\Flight\FlightCarrier::where('id', $booking->flightCarrier->id)->value('available_balance');
    printf("  Carrier:   %.4f → %.4f %s (delta: %+.4f)\n",
        $carrierBalanceBefore, $carrierBalanceAfter, $carrierCurrency,
        $carrierBalanceAfter - $carrierBalanceBefore
    );
}
if ($booking->flightSystem) {
    $systemBalanceAfter = (float) \App\Models\Flight\FlightSystem::where('id', $booking->flightSystem->id)->value('available_balance');
    printf("  System:    %.4f → %.4f %s (delta: %+.4f)\n",
        $systemBalanceBefore, $systemBalanceAfter, $systemCurrency,
        $systemBalanceAfter - $systemBalanceBefore
    );
}
if ($booking->account) {
    $treasuryBalanceAfter = (float) Account::where('id', $booking->account->id)->value('balance');
    printf("  Treasury:  %.2f → %.2f %s (delta: %+.2f)\n",
        $treasuryBalanceBefore, $treasuryBalanceAfter, $treasuryCurrency,
        $treasuryBalanceAfter - $treasuryBalanceBefore
    );
}

// ─────────────────────────────────────────────────────────────
// [4] تحقق نهائي
// ─────────────────────────────────────────────────────────────
echo "\n[4] تحقق نهائي\n";
echo "─────────────────────────────────────────────────────────\n";

$stillExists = FlightBooking::withTrashed()->where('id', $bookingId)->exists();
printf("  FlightBooking#%d still exists: %s\n",
    $bookingId,
    $stillExists ? '✗ YES (FAIL!)' : '✓ NO (success)'
);

$remainingTx = Transaction::where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', $bookingId)
    ->count();
printf("  Transactions remaining: %s\n", $remainingTx === 0 ? '✓ 0' : "✗ {$remainingTx}");

$remainingPmt = FlightPayment::withTrashed()->where('flight_booking_id', $bookingId)->count();
printf("  Payments remaining:     %s\n", $remainingPmt === 0 ? '✓ 0' : "✗ {$remainingPmt}");

echo "\n=========================================================\n";
echo "  ✓ تم حذف الحجز #{$bookingId} ({$bookingRef}) بالكامل.\n";
echo "=========================================================\n";
echo "  للسلامة الإضافية:\n";
echo "    - تحقق في Filament من:\n";
echo "        * /admin/flight-bookings (الحجز غير ظاهر)\n";
echo "        * /admin/customers (رصد العميل رجع صح)\n";
echo "        * /admin/flight-carriers (رصيد الناقل رجع)\n";
echo "    - نظّف ملف التأكيد:\n";
echo "        rm {$confirmFile}\n";
echo "=========================================================\n";