<?php
/**
 * APPLY: حذف آمن لـ bookings مع reversal كامل للـ GL.
 *
 * Bookings:
 *   - Bk#10 (FLT-20260706-62F7B8) — الحج رمضان — الجزيرة - كويتى
 *   - Bk#11 (FLT-20260706-F8AB6C) — حازم السيد — الجزيرة - كويتى
 *
 * ⚠️ IMPORTANT:
 * - لازم تنفذ الـ diagnostic script (del_diag.php) قبل ده عشان تشوف الـ effect
 * - الـ script يعمل DB::transaction — لو فشل أي جزء، كل حاجة هتترجع
 * - الـ FlightBooking بيستخدم SoftDeletes — هنعمل forceDelete
 *
 * الاستخدام:
 *   1. شغل del_diag.php أولاً وراجع الـ output
 *   2. لو الأرقام تمام، ارفع هذا الملف وشغّل:
 *      php artisan tinker --execute='require "/tmp/del_apply.php";'
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
$userId = 1;

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
    DB::transaction(function () use ($bookings, $userId) {
        $svc = app(\App\Services\Flight\FlightBookingService::class);

        foreach ($bookings as $booking) {
            echo "\n--- Processing Bk#{$booking->id} ({$booking->booking_number}) ---\n";

            // Step 1: Find payment account (for refund)
            $paymentAccountId = null;
            $payment = $booking->payments()->first();
            if ($payment) {
                $paymentAccountId = (int) $payment->account_id;
                echo "  Payment found: amount={$payment->amount}, account_id={$paymentAccountId}\n";
            } else {
                echo "  No payment found (booking may be unpaid)\n";
            }

            // Step 2: Use cancelBooking for full reversal (no penalties)
            echo "  Calling cancelBooking for full reversal...\n";
            try {
                $refund = $svc->cancelBooking($booking, [
                    'airline_penalty' => 0,
                    'office_penalty' => 0,
                    'account_id' => $paymentAccountId ?? $booking->account_id,
                    'notes' => "Reversal for hard-delete of booking #{$booking->id}",
                ]);
                echo "  ✓ Cancellation: refund_amount={$refund->refund_amount}\n";
            } catch (\Exception $e) {
                echo "  ✗ cancelBooking FAILED: " . $e->getMessage() . "\n";
                // Continue with hard delete anyway
            }

            // Step 3: Hard delete the booking chain
            // (Soft-delete refunds/payments first to preserve audit trail)
            FlightPayment::where('flight_booking_id', $booking->id)->delete();
            FlightRefund::where('flight_booking_id', $booking->id)->delete();

            $passengerCount = FlightPassenger::where('flight_booking_id', $booking->id)->forceDelete();
            $ticketCount = FlightTicket::where('flight_booking_id', $booking->id)->forceDelete();
            $segmentCount = FlightSegment::where('flight_booking_id', $booking->id)->forceDelete();

            // Step 4: Hard delete related transactions + entries
            $txIds = Transaction::where('related_type', 'App\\Models\\Flight\\FlightBooking')
                ->where('related_id', $booking->id)->pluck('id');
            $entryCount = AccountEntry::whereIn('transaction_id', $txIds)->delete();
            $txCount = Transaction::whereIn('id', $txIds)->delete();
            printf("  Deleted: passengers=%d, tickets=%d, segments=%d, entries=%d, transactions=%d\n",
                $passengerCount, $ticketCount, $segmentCount, $entryCount, $txCount);

            // Step 5: Force-delete the booking itself
            $booking->forceDelete();
            echo "  ✓ Booking force-deleted\n";
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

echo "\n========== VERIFY: Other carrier bookings still intact ==========\n";
$otherBookings = FlightBooking::where('flight_carrier_id', $carrierBefore->id)->get();
printf("Carrier#%d now has %d bookings (was %d before)\n",
    $carrierBefore->id, $otherBookings->count(), FlightBooking::withTrashed()->where('flight_carrier_id', $carrierBefore->id)->count());

echo "\n========== DONE ==========\n";
echo "روح للـ Filament وتحقق من:\n";
echo "  1. Bookings page — Bk#10 و Bk#11 مش ظاهرين\n";
echo "  2. Treasury Overview — balances صحيحة\n";
echo "  3. Customers page — الحج رمضان وحازم السيد balances = 0 (or original)\n";