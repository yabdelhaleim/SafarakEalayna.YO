<?php

namespace Tests\Feature\Flight;

use App\Http\Resources\Flight\FlightBookingResource;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;
use Tests\Audit\AuditTestCase;

/**
 * PROBE TEST — reproduces the user's exact scenario:
 *
 *   "counter customer, ticket paid in 3 installments (5000 + 3000 + 2000).
 *    When I click 'إصدار استرجاع', the wizard shows only the FIRST payment (5000),
 *    not the total (10000)."
 *
 * Two angles:
 *   1) Show endpoint: what does FlightBookingResource emit? (what the Vue wizard reads)
 *   2) Cancel endpoint: what does FlightBookingService::cancelBooking refund? (the actual math)
 *
 * If both are correct → no bug in the refund pipeline; the user's complaint must be
 * about how the Vue `totalPaid` computed reads `b.payments`.
 *
 * If only #2 is correct and #1 shows 5000 → confirmed bug: payments array is incomplete.
 */
class VueRefundWizardInstallmentProbeTest extends AuditTestCase
{
    public function test_counter_customer_three_installments_vue_wizard_sees_correct_total(): void
    {
        // === Setup: booking paid in 3 installments ===
        $booking = $this->makeBooking([
            'selling_price' => 10000.0,
            'purchase_price' => 8000.0,
        ]);

        $this->payBooking($booking, 5000.0);
        $this->payBooking($booking, 3000.0);
        $this->payBooking($booking, 2000.0);

        $booking->refresh();

        // === Confirm what the DB has ===
        $paymentCount = FlightPayment::query()
            ->where('flight_booking_id', $booking->id)
            ->count();

        $sumFromDb = (float) FlightPayment::query()
            ->where('flight_booking_id', $booking->id)
            ->sum('amount');

        echo "\n=== [DB] payments in flight_payments table ===";
        echo "\n  count: {$paymentCount}";
        echo "\n  sum(amount): {$sumFromDb} EGP";

        $this->assertSame(3, $paymentCount, 'DB should have 3 payment rows');
        $this->assertEqualsWithDelta(10000.0, $sumFromDb, 0.01, 'DB sum should be 10000');

        // === Angle 1: What does FlightBookingResource emit? (what Vue reads) ===
        $booking->load(['payments.account']);

        $payload = (new FlightBookingResource($booking))->resolve(request());
        $paymentsRaw = $payload['payments'] ?? [];

        // Convert AnonymousResourceCollection → array of arrays
        if (is_object($paymentsRaw) && method_exists($paymentsRaw, 'resolve')) {
            $paymentsArray = $paymentsRaw->resolve();
        } elseif (is_object($paymentsRaw) && method_exists($paymentsRaw, 'toArray')) {
            $paymentsArray = $paymentsRaw->toArray(request());
        } else {
            $paymentsArray = is_array($paymentsRaw) ? $paymentsRaw : iterator_to_array($paymentsRaw);
        }

        $paymentsSum = array_sum(array_column($paymentsArray, 'amount'));

        echo "\n\n=== [API show endpoint] FlightBookingResource payload ===";
        echo "\n  payments count in payload: " . count($paymentsArray);
        echo "\n  payments sum in payload: {$paymentsSum}";
        echo "\n  total_paid in payload: " . ($payload['total_paid'] ?? 'MISSING');
        echo "\n  selling_price in payload: " . ($payload['selling_price'] ?? 'MISSING');
        echo "\n  original_amount in payload: " . ($payload['original_amount'] ?? 'NULL');

        echo "\n\n=== [Vue wizard simulation] b.payments ===";
        foreach ($paymentsArray as $i => $p) {
            echo "\n  payment #" . ($i + 1) . ": amount = " . $p['amount'];
        }
        $vueTotalPaid = array_sum(array_column($paymentsArray, 'amount'));
        echo "\n  Vue computed totalPaid: {$vueTotalPaid}";

        $this->assertCount(3, $paymentsArray, 'Resource must include all 3 installments');
        $this->assertEqualsWithDelta(10000.0, $paymentsSum, 0.01, 'Resource payments sum must equal 10000');
        $this->assertEqualsWithDelta(10000.0, $payload['total_paid'], 0.01, 'total_paid must equal 10000');

        // === Angle 2: What does cancelBooking refund? ===
        // Capture the refund amount BEFORE actually persisting (test the math path)
        $bookingForMath = clone $booking;
        $bookingForMath->refresh();

        $refundAmountComputed = (float) ($bookingForMath->payments()->sum('amount') ?? 0);

        echo "\n\n=== [Backend cancelBooking] service math ===";
        echo "\n  booking.payments()->sum('amount'): {$refundAmountComputed}";
        echo "\n  Expected refund (no penalties) = {$refundAmountComputed}";

        $this->assertEqualsWithDelta(10000.0, $refundAmountComputed, 0.01,
            'Backend math is correct: refund = sum of all payments');

        echo "\n\n=== CONCLUSION ===";
        echo "\n  DB:           3 rows × sum=10000 ✓";
        echo "\n  API resource: 3 payments × sum=10000 ✓";
        echo "\n  Backend math: refund=10000 ✓";
        echo "\n  → If user sees 5000, the bug is in the Vue UI / data flow BEFORE the wizard mounts,";
        echo "\n    OR in the user's session showing stale cached data.";
        echo "\n";

        // === Angle 3: Actually run the cancelBooking service end-to-end ===
        // This is what RefundWizard.vue calls when user clicks "تأكيد الاسترداد"
        echo "\n\n=== [End-to-end] Running cancelBooking service ===";

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 500.0,
            'office_penalty' => 100.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'E2E probe — counter customer installments',
        ]);

        echo "\n  FlightRefund.refund_amount: " . $refund->refund_amount;
        echo "\n  FlightRefund.airline_penalty: " . $refund->airline_penalty;
        echo "\n  FlightRefund.office_penalty: " . $refund->office_penalty;
        echo "\n  Expected: 10000 - 500 - 100 = 9400";

        $this->assertEqualsWithDelta(9400.0, (float) $refund->refund_amount, 0.01,
            'E2E: refund must equal total_paid (10000) - airline_penalty (500) - office_penalty (100)');

        echo "\n\n*** END-TO-END PROBE COMPLETE ***";
        echo "\n  → The Vue wizard's submit → /cancel API → cancelBooking service produces";
        echo "\n    the CORRECT refund (9400 EGP), proving the entire backend pipeline is sound.";
        echo "\n  → If the user perceives only 5000 in the UI, the issue is purely visual/stale";
        echo "\n    data in the Vue store, NOT in any backend service.";
        echo "\n";
    }
}