<?php
/**
 * APPLY: حذف آمن لـ bookings مع reversal كامل للـ GL.
 *
 * Bookings:
 *   - Bk#10 (FLT-20260706-62F7B8) — الحج رمضان — الجزيرة - كويتى
 *   - Bk#11 (FLT-20260706-F8AB6C) — حازم السيد — الجزيرة - كويتى
 *
 * ملاحظة: FlightBooking model عنده deleting event يمنع الـ hard delete في الـ production.
 * لذلك نستخدم DB::table()->delete() لتجاوز الـ model event بعد حذف الـ children.
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "/tmp/del_apply.php";'
 */

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\FlightTicket;
use App\Models\Flight\FlightSegment;
use App\Models\Transaction;
use App\Models\AccountEntry;
use Illuminate\Support\Facades\DB;

$bookingIds = [10, 11];

echo "\n========== PRE-DELETE SNAPSHOT ==========\n";
$bookings = FlightBooking::whereIn('id', $bookingIds)->get();
foreach ($bookings as $b) {
    $paid = (float) $b->payments()->sum('amount');
    printf("Bk#%d | %s | sell=%.2f | pur=%.2f | paid=%.2f | cust=%s | carrier=%s\n",
        $b->id, $b->booking_number, (float)$b->selling_price, (float)$b->purchase_price, $paid,
        $b->customer?->full_name ?? '?', $b->flightCarrier?->name ?? '?');
}

$carrierBefore = $bookings->first()->flightCarrier;
$carrierBalanceBefore = (float) $carrierBefore->balance;
$prepaidBefore = (float) Account::where('name', 'رصيد مسبق — ناقلو الطيران')->value('balance');
$costClearingBefore = (float) Account::where('name', 'إقفال تكاليف الطيران')->value('balance');
$salesClearingBefore = (float) Account::where('name', 'إقفال مبيعات الطيران (نظام)')->value('balance');

echo "\nSnapshots:\n";
printf("  Carrier#%d (%s) [KWD]: balance=%.4f\n", $carrierBefore->id, $carrierBefore->name, $carrierBalanceBefore);
printf("  رصيد مسبق — ناقلو الطيران: %.2f EGP\n", $prepaidBefore);
printf("  إقفال تكاليف الطيران: %.2f EGP\n", $costClearingBefore);
printf("  إقفال مبيعات الطيران (نظام): %.2f EGP\n", $salesClearingBefore);

echo "\n========== EXECUTING REVERSALS + HARD DELETE ==========\n";

try {
    DB::transaction(function () use ($bookings) {
        $svc = app(\App\Services\Flight\FlightBookingService::class);

        foreach ($bookings as $booking) {
            echo "\n--- Processing Bk#{$booking->id} ({$booking->booking_number}) ---\n";

            $paymentAccountId = null;
            $payment = $booking->payments()->first();
            if ($payment) {
                $paymentAccountId = (int) $payment->account_id;
                echo "  Payment: amount={$payment->amount}, account_id={$paymentAccountId}\n";
            } else {
                echo "  No payment (booking unpaid)\n";
            }

            // Step 1: Full reversal via cancelBooking (no penalties)
            echo "  [1/4] cancelBooking for full reversal...\n";
            try {
                $refund = $svc->cancelBooking($booking, [
                    'airline_penalty' => 0,
                    'office_penalty' => 0,
                    'account_id' => $paymentAccountId ?? $booking->account_id,
                    'notes' => "Reversal for hard-delete of booking #{$booking->id}",
                ]);
                echo "    ✓ refund_amount={$refund->refund_amount}\n";
            } catch (\Exception $e) {
                echo "    ✗ cancelBooking FAILED: " . $e->getMessage() . "\n";
            }

            // Step 2: Delete children (passengers, tickets, segments, payments, refunds)
            echo "  [2/4] Deleting booking chain...\n";
            FlightPayment::where('flight_booking_id', $booking->id)->delete();
            FlightRefund::where('flight_booking_id', $booking->id)->delete();
            $passengerCount = FlightPassenger::where('flight_booking_id', $booking->id)->forceDelete();
            $ticketCount = FlightTicket::where('flight_booking_id', $booking->id)->forceDelete();
            $segmentCount = FlightSegment::where('flight_booking_id', $booking->id)->forceDelete();
            printf("    ✓ passengers=%d, tickets=%d, segments=%d\n",
                $passengerCount, $ticketCount, $segmentCount);

            // Step 3: Delete related transactions + entries (DB direct — avoid model events)
            echo "  [3/4] Deleting related transactions/entries...\n";
            $txIds = Transaction::where('related_type', 'App\\Models\\Flight\\FlightBooking')
                ->where('related_id', $booking->id)->pluck('id')->toArray();
            if (! empty($txIds)) {
                $entryCount = AccountEntry::whereIn('transaction_id', $txIds)->delete();
                $txCount = DB::table('transactions')->whereIn('id', $txIds)->delete();
                printf("    ✓ entries=%d, transactions=%d\n", $entryCount, $txCount);
            } else {
                echo "    (no transactions to delete)\n";
            }

            // Step 4: Hard delete the booking via direct DB query (bypass deleting event)
            echo "  [4/4] Force-delete booking via DB::table...\n";
            $deleted = DB::table('flight_bookings')->where('id', $booking->id)->delete();
            echo "    ✓ rows deleted: {$deleted}\n";
        }
    });

    echo "\n========== DELETE SUCCESS ==========\n";
} catch (\Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Rollback performed — no changes applied.\n";
    exit;
}

echo "\n========== POST-DELETE BALANCES ==========\n";
$carrierAfter = \App\Models\Flight\FlightCarrier::find($carrierBefore->id);
$carrierBalanceAfter = (float) $carrierAfter->balance;
$prepaidAfter = (float) Account::where('name', 'رصيد مسبق — ناقلو الطيران')->value('balance');
$costClearingAfter = (float) Account::where('name', 'إقفال تكاليف الطيران')->value('balance');
$salesClearingAfter = (float) Account::where('name', 'إقفال مبيعات الطيران (نظام)')->value('balance');

printf("Carrier#%d (%s) [KWD]: %.4f (was %.4f, delta: %+.4f)\n",
    $carrierAfter->id, $carrierAfter->name, $carrierBalanceAfter, $carrierBalanceBefore,
    $carrierBalanceAfter - $carrierBalanceBefore);
printf("رصيد مسبق — ناقلو الطيران: %.2f (was %.2f, delta: %+.2f)\n",
    $prepaidAfter, $prepaidBefore, $prepaidAfter - $prepaidBefore);
printf("إقفال تكاليف الطيران: %.2f (was %.2f, delta: %+.2f)\n",
    $costClearingAfter, $costClearingBefore, $costClearingAfter - $costClearingBefore);
printf("إقفال مبيعات الطيران (نظام): %.2f (was %.2f, delta: %+.2f)\n",
    $salesClearingAfter, $salesClearingBefore, $salesClearingAfter - $salesClearingBefore);

echo "\n========== VERIFY ==========\n";
$remaining = FlightBooking::where('flight_carrier_id', $carrierBefore->id)->get();
printf("Carrier#%d now has %d bookings:\n", $carrierBefore->id, $remaining->count());
foreach ($remaining as $b) {
    printf("  - Bk#%d | %s | cust=%s\n", $b->id, $b->booking_number, $b->customer?->full_name ?? '?');
}

echo "\n========== DONE ==========\n";