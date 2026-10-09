<?php

namespace Tests\Feature\Bus;

use App\Enums\BusPaymentStatus;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Services\Bus\BusBookingService;

/**
 * Level 3 — Full lifecycle deletion tests for the Bus module.
 *
 * The accounting invariant being pinned here: deleting a booking must
 * leave the system EXACTLY in the same state as if the booking had
 * never been created. This is the canonical contract of
 * `BusBookingService::deleteBookingWithReversal()` — every ledger entry
 * posted at booking-creation or at payment-time must be reversed with
 * the opposite effect, atomically.
 *
 * Step 4 also pins the SERVICE-level guard:
 * `BusBookingService::deleteBooking()` (the simple path, which cannot
 * reverse payments) MUST refuse any booking that has at least one
 * BusPayment row, even if a future caller bypasses the controller.
 *
 * Coverage:
 *   • test_partial_paid_booking_delete_via_endpoint_restores_all_balances_exactly
 *   • test_fully_paid_booking_delete_via_endpoint_restores_all_balances_exactly
 *   • test_partial_paid_multi_payment_booking_delete_restores_all_balances_exactly
 *   • test_simple_deleteBooking_service_throws_on_paid_booking
 *   • test_simple_deleteBooking_service_throws_on_partial_paid_booking
 *   • test_booking_deletion_does_not_affect_other_bookings_balances
 *   • test_full_e2e_lifecycle_three_bookings_two_paid_one_cancelled_one_deleted_ledger_balanced
 *     (covers Step 6 — the comprehensive reconciliation scenario)
 */
class BusDeletionLifecycleTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // STEP 2 — Partial-paid booking: DELETE via endpoint restores ALL
    // balances (company AP, customer AR, cashbox, inventory) to EXACTLY
    // the pre-booking state.
    // ─────────────────────────────────────────────────────────────────────

    public function test_partial_paid_booking_delete_via_endpoint_restores_all_balances_exactly(): void
    {
        // ── Setup ────────────────────────────────────────────────────────────
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 200,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        // Snapshot all balances BEFORE the booking exists.
        $cashboxBefore          = (float) $this->cashboxEgp->fresh()->balance;       // 10000
        $companyBalanceBefore   = (float) $company->account->fresh()->balance;       // 0
        $customerBalanceBefore  = (float) $customer->ledgerAccount->fresh()->balance; // 0
        $inventoryAvailBefore   = (int) $inventory->available_tickets;               // 10

        // ── Act: book (qty 1 → total_price 200, company cost 80) ───────────
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Partial Pay Test',
            'customer_phone' => '01090000099',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals(200.0, (float) $booking->total_price);

        // ── Act: PARTIAL pay — only 50 of the 200 ──────────────────────────
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 50,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $booking->paid_amount, 0.01,
            'paid_amount must reflect the partial payment');
        $this->assertEquals(BusPaymentStatus::Partial, $booking->payment_status);

        // Capture mid-state (after partial pay, before delete) for the deletion assertions.
        $cashboxMid             = (float) $this->cashboxEgp->fresh()->balance;
        $companyBalanceMid      = (float) $company->account->fresh()->balance;
        $customerBalanceMid     = (float) $customer->ledgerAccount->fresh()->balance;
        $inventoryAvailMid      = (int) $inventory->fresh()->available_tickets;

        // Sanity-check the mid-state is non-trivial (otherwise the test is vacuous).
        $this->assertNotEqualsWithDelta($cashboxBefore, $cashboxMid, 0.01,
            'partial pay must have changed the cashbox');
        $this->assertNotEqualsWithDelta($companyBalanceBefore, $companyBalanceMid, 0.01,
            'booking must have changed the company AP');
        $this->assertNotEqualsWithDelta($customerBalanceBefore, $customerBalanceMid, 0.01,
            'booking must have changed the customer AR');

        // ── Act: DELETE via the public endpoint (routes to deleteBookingWithReversal)
        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // ── Assert: every balance is back to the pre-booking snapshot ───────

        // 1. Booking is soft-deleted (not hard-deleted).
        $this->assertNotNull(
            BusBooking::withTrashed()->find($booking->id)->deleted_at,
            'booking must be soft-deleted, not hard-deleted'
        );

        // 2. Inventory available_tickets restored to pre-booking count.
        $inventory->refresh();
        $this->assertEquals($inventoryAvailBefore, (int) $inventory->available_tickets,
            "inventory tickets must return to {$inventoryAvailBefore} after deletion");

        // 3. Company AP balance restored to pre-booking (= 0).
        $company->refresh();
        $this->assertEqualsWithDelta(
            $companyBalanceBefore,
            (float) $company->account->fresh()->balance,
            0.01,
            'company balance must return to pre-booking value (cost reversal)'
        );

        // 4. Customer AR balance restored to pre-booking (= 0).
        $customer->refresh();
        $this->assertEqualsWithDelta(
            $customerBalanceBefore,
            (float) $customer->ledgerAccount->fresh()->balance,
            0.01,
            'customer balance must return to pre-booking value (no leftover debt)'
        );

        // 5. Cashbox restored to pre-partial-pay (the 50 received must be reversed).
        $this->assertEqualsWithDelta(
            $cashboxBefore,
            (float) $this->cashboxEgp->fresh()->balance,
            0.01,
            'cashbox must return to pre-partial-pay value (50 EGP reversed)'
        );

        // 6. The BusPayment row is soft-deleted (audit trail preserved).
        $paymentCount = BusPayment::withTrashed()
            ->where('booking_id', $booking->id)
            ->count();
        $this->assertEquals(1, $paymentCount,
            'the partial-payment BusPayment row must be soft-deleted (audit trail preserved)');

        // 7. Global ledger invariant holds.
        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 3 — Fully-paid booking: DELETE via endpoint restores ALL
    // balances to the pre-booking state (cashbox must come back FULL).
    // ─────────────────────────────────────────────────────────────────────

    public function test_fully_paid_booking_delete_via_endpoint_restores_all_balances_exactly(): void
    {
        // ── Setup ────────────────────────────────────────────────────────────
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 200,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        // Snapshot pre-booking balances.
        $cashboxBefore          = (float) $this->cashboxEgp->fresh()->balance;
        $companyBalanceBefore   = (float) $company->account->fresh()->balance;
        $customerBalanceBefore  = (float) $customer->ledgerAccount->fresh()->balance;
        $inventoryAvailBefore   = (int) $inventory->available_tickets;

        // ── Act: book ───────────────────────────────────────────────────────
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Full Pay Test',
            'customer_phone' => '01090000098',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals(200.0, (float) $booking->total_price);

        // ── Act: FULL pay ───────────────────────────────────────────────────
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(BusPaymentStatus::Paid, $booking->payment_status);

        // After full pay, cashbox grew by 200 — must come back fully on deletion.
        $cashboxAfterFullPay = (float) $this->cashboxEgp->fresh()->balance;
        $this->assertEqualsWithDelta($cashboxBefore + 200.0, $cashboxAfterFullPay, 0.01,
            'cashbox must grow by the full 200 on full payment');

        // ── Act: DELETE ─────────────────────────────────────────────────────
        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // ── Assert ──────────────────────────────────────────────────────────
        $this->assertNotNull(BusBooking::withTrashed()->find($booking->id)->deleted_at);

        $inventory->refresh();
        $this->assertEquals($inventoryAvailBefore, (int) $inventory->available_tickets);

        $company->refresh();
        $this->assertEqualsWithDelta($companyBalanceBefore, (float) $company->account->fresh()->balance, 0.01);

        $customer->refresh();
        $this->assertEqualsWithDelta($customerBalanceBefore, (float) $customer->ledgerAccount->fresh()->balance, 0.01);

        $this->assertEqualsWithDelta(
            $cashboxBefore,
            (float) $this->cashboxEgp->fresh()->balance,
            0.01,
            'cashbox must return to the pre-booking value (full 200 reversed)'
        );

        $paymentCount = BusPayment::withTrashed()->where('booking_id', $booking->id)->count();
        $this->assertEquals(1, $paymentCount, 'the full-payment row must be soft-deleted');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 2 (extended) — Multiple partial payments followed by delete.
    // ─────────────────────────────────────────────────────────────────────

    public function test_partial_paid_multi_payment_booking_delete_restores_all_balances_exactly(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 250,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $cashboxBefore          = (float) $this->cashboxEgp->fresh()->balance;
        $companyBalanceBefore   = (float) $company->account->fresh()->balance;
        $customerBalanceBefore  = (float) $customer->ledgerAccount->fresh()->balance;
        $inventoryAvailBefore   = (int) $inventory->available_tickets;

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Multi Partial',
            'customer_phone' => '01090000097',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();

        // 3 partial payments with different Idempotency-Keys (per cashier pattern).
        foreach ([100, 70, 80] as $amount) {
            $this->withHeaders(['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])
                ->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                    'amount' => $amount,
                    'payment_method' => 'cash',
                    'account_id' => $this->cashboxEgp->id,
                ])->assertOk();
        }

        $booking->refresh();
        $this->assertEqualsWithDelta(250.0, (float) $booking->paid_amount, 0.01);
        $this->assertEquals(BusPaymentStatus::Paid, $booking->payment_status);

        $this->assertEquals(3, BusPayment::where('booking_id', $booking->id)->count(),
            'three partial payments should create three BusPayment rows');

        // ── Act: DELETE ─────────────────────────────────────────────────────
        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // ── Assert ──────────────────────────────────────────────────────────
        $this->assertNotNull(BusBooking::withTrashed()->find($booking->id)->deleted_at);

        $inventory->refresh();
        $this->assertEquals($inventoryAvailBefore, (int) $inventory->available_tickets);

        $company->refresh();
        $this->assertEqualsWithDelta($companyBalanceBefore, (float) $company->account->fresh()->balance, 0.01);

        $customer->refresh();
        $this->assertEqualsWithDelta($customerBalanceBefore, (float) $customer->ledgerAccount->fresh()->balance, 0.01);

        $this->assertEqualsWithDelta(
            $cashboxBefore,
            (float) $this->cashboxEgp->fresh()->balance,
            0.01,
            'cashbox must return to pre-booking value (all 3 partial payments reversed)'
        );

        // All 3 payments soft-deleted (audit trail).
        $paymentCount = BusPayment::withTrashed()->where('booking_id', $booking->id)->count();
        $this->assertEquals(3, $paymentCount, 'all 3 partial-payment rows must be soft-deleted');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 4 — SERVICE-level guard: BusBookingService::deleteBooking()
    // (the simple path) MUST throw on any booking with payments.
    // This proves the protection works even if a future caller bypasses
    // the HTTP controller and invokes the simple method directly.
    // ─────────────────────────────────────────────────────────────────────

    public function test_simple_deleteBooking_service_throws_on_paid_booking(): void
    {
        // Create + fully pay a booking.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(5000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Service Guard Test',
            'customer_phone' => '01090000096',
            'quantity' => 1,
        ]);

        $service->payBooking($booking, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count(),
            'one payment row must exist before we attempt the simple delete');

        // Capture pre-attempt state so we can prove the failed call had NO effect.
        $cashboxBefore   = (float) $this->cashboxEgp->fresh()->balance;
        $customerBefore  = (float) $customer->ledgerAccount->fresh()->balance;
        $companyBefore   = (float) $company->account->fresh()->balance;
        $invAvailBefore  = (int) $inventory->fresh()->available_tickets;

        // ── Act: call the SIMPLE deleteBooking (not the reversal variant) ──
        $threwExpectedException = false;
        try {
            $service->deleteBooking($booking->fresh());
        } catch (\Exception $e) {
            $threwExpectedException = true;
            // The message must point the caller to the safe alternative.
            $this->assertMatchesRegularExpression(
                '/مدفوعات|deleteBookingWithReversal/',
                $e->getMessage(),
                'exception message must point the caller to deleteBookingWithReversal'
            );
        }

        $this->assertTrue($threwExpectedException,
            'BusBookingService::deleteBooking MUST throw on a paid booking');

        // ── Assert: the failed call had NO side effects ─────────────────────
        $this->assertNotNull(BusBooking::find($booking->id),
            'the booking row must STILL exist (the simple delete was refused)');
        $this->assertNull(BusBooking::find($booking->id)->deleted_at,
            'the booking must NOT be soft-deleted (the simple delete was refused)');
        $this->assertEquals(1, BusPayment::where('booking_id', $booking->id)->count(),
            'the payment row must STILL exist (no reversal happened)');

        $this->assertEqualsWithDelta($cashboxBefore,   (float) $this->cashboxEgp->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($customerBefore,  (float) $customer->ledgerAccount->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($companyBefore,   (float) $company->account->fresh()->balance, 0.01);
        $this->assertEquals($invAvailBefore, (int) $inventory->fresh()->available_tickets);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_simple_deleteBooking_service_throws_on_partial_paid_booking(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(5000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 200,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Partial Service Guard',
            'customer_phone' => '01090000095',
            'quantity' => 1,
        ]);

        $service->payBooking($booking, [
            'amount' => 50.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/مدفوعات|deleteBookingWithReversal/');

        $service->deleteBooking($booking->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 6 — Comprehensive E2E: 3-seat inventory, 2 bookings (one
    // partial-paid + cancelled, one full-paid + deleted). Verify the
    // state of EVERY account after the dust settles.
    // ─────────────────────────────────────────────────────────────────────

    public function test_full_e2e_lifecycle_three_bookings_two_paid_one_cancelled_one_deleted_ledger_balanced(): void
    {
        // ── Setup ────────────────────────────────────────────────────────────
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 3,
            'available_tickets' => 3,
            'cost_per_ticket' => 100,    // supplier cost
            'selling_price' => 250,      // customer price
        ]);

        $customerA = $this->makeCustomerWithBusAccount(0, 'EGP');  // will be cancelled
        $customerB = $this->makeCustomerWithBusAccount(0, 'EGP');  // will be deleted

        // Snapshot pre-flow balances (no bookings yet).
        $cashboxStart    = (float) $this->cashboxEgp->fresh()->balance;       // 10000
        $companyStart    = (float) $company->account->fresh()->balance;       // 0
        $custAStart      = (float) $customerA->ledgerAccount->fresh()->balance; // 0
        $custBStart      = (float) $customerB->ledgerAccount->fresh()->balance; // 0
        $invAvailStart   = (int) $inventory->available_tickets;               // 3

        $service = app(BusBookingService::class);

        // ── Act 1: Book seat 1 for customerA ───────────────────────────────
        $bookingA = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customerA->id,
            'customer_name' => 'Customer A',
            'customer_phone' => '01090000001',
            'quantity' => 1,
        ]);
        $this->assertEquals(250.0, (float) $bookingA->total_price);
        $this->assertEquals(2, (int) $inventory->fresh()->available_tickets);

        // ── Act 2: Book seat 2 for customerB ───────────────────────────────
        $bookingB = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customerB->id,
            'customer_name' => 'Customer B',
            'customer_phone' => '01090000002',
            'quantity' => 1,
        ]);
        $this->assertEquals(1, (int) $inventory->fresh()->available_tickets);

        // ── Act 3: Partial-pay customer A (50 of 250) ───────────────────────
        $service->payBooking($bookingA->fresh(), [
            'amount' => 50.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $bookingA->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $bookingA->paid_amount, 0.01);

        // ── Act 4: Fully pay customer B (250 of 250) ────────────────────────
        $service->payBooking($bookingB->fresh(), [
            'amount' => 250.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $bookingB->refresh();
        $this->assertEquals(BusPaymentStatus::Paid, $bookingB->payment_status);

        // ── Act 5: Cancel customer A's booking with 30 penalty ──────────────
        // Cancel rule: `penalty ≤ paid` (the refund logic only makes sense if the
        // customer paid enough to cover the penalty). Customer A paid 50, so the
        // max combined penalty is 50. We pick 30 (18 company + 12 office).
        //   - refund = paid - penalty = 50 - 30 = 20 (cash returned from cashbox)
        //   - arReversalAmount = totalPrice - max(paid, penalty) = 250 - 50 = 200
        //     (the unpaid portion of the sale debt is reversed)
        //   - supplier cost of A (100) is reversed via applyCompanyCreditOnCancel
        $service->cancelBooking($bookingA->fresh(), [
            'company_penalty' => 18,
            'office_penalty' => 12,  // total = 30, within the 50 paid
            'account_id' => $this->cashboxEgp->id,
        ]);

        $bookingA->refresh();
        $this->assertNotNull($bookingA->status);

        $inventory->refresh();
        // After cancel: customer A's seat returned. customer B still holds 1 seat.
        $this->assertEquals(2, (int) $inventory->fresh()->available_tickets,
            'cancellation must return the cancelled seat to inventory');

        // ── Act 6: DELETE customer B's fully-paid booking ───────────────────
        $service->deleteBookingWithReversal($bookingB->id, 1);

        $bookingB->refresh();
        $this->assertNotNull($bookingB->deleted_at, 'booking B must be soft-deleted');

        $inventory->refresh();
        // After delete: B's seat returned. A's seat was already returned on cancel.
        // So all 3 seats must be available again.
        $this->assertEquals($invAvailStart, (int) $inventory->fresh()->available_tickets,
            "after cancel + delete, all 3 seats must be back to inventory ({$invAvailStart})");

        // ── Assert: every balance is restored to its starting value ─────────

        // Company AP: starts at 0, after 2 bookings posted -100 each = -200.
        // After cancel A: `applyCompanyCreditOnCancel` credits back
        // `totalCost - companyPenalty = 100 - 18 = 82` to the company
        // (the 18 is kept by the office as the company-penalty fee).
        //   → company AP = -200 + 82 = -118
        // After delete B: full cost reversed (no penalty on delete) → +100.
        //   → company AP = -118 + 100 = -18
        // The -18 represents the portion of the cancellation penalty that the
        // office retains on behalf of the company — economically, the office
        // has collected 18 from the customer and not yet credited it to the
        // company. Booking B's 100 cost was fully reversed.
        $company->refresh();
        $this->assertEqualsWithDelta(
            -18.0,
            (float) $company->account->fresh()->balance,
            0.01,
            'company AP after cancel + delete = -18 (office-retained 18 of company penalty; B cost fully reversed)'
        );

        // Customer A: starts at 0.
        // Book → AR +250. Pay 50 → AR +200. Cancel with 30 penalty → arReversal=200
        // is reversed → AR = 0. Refund 20 is paid in cash from cashbox (NOT credited
        // back to AR — the customer physically receives 20 in cash).
        // Net: customer A AR = 0 (the 20 refund is cash, not a ledger entry).
        $customerA->refresh();
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerA->ledgerAccount->fresh()->balance,
            0.01,
            'customer A balance after cancel: sale debt fully reversed (refund is cash-out, not AR credit)'
        );

        // Customer B: starts at 0. After book: +250 AR. After pay 250: AR back to 0. After delete: stays at 0.
        $customerB->refresh();
        $this->assertEqualsWithDelta(
            $custBStart,
            (float) $customerB->ledgerAccount->fresh()->balance,
            0.01,
            'customer B balance must return to 0 (fully paid + delete = all reversed)'
        );

        // Cashbox: starts at 10000.
        // After pay A 50: cashbox = 10050.
        // After pay B 250: cashbox = 10300.
        // After cancel A (refund 20 from cashbox): cashbox = 10280.
        // After delete B (full reversal of 250 payment): cashbox = 10280 - 250 + 250 = 10280
        //   wait — the reversal of payment moves money FROM cashbox TO AR, then the reversal
        //   of sale moves money FROM AR TO clearing. Net cashbox effect of a paid-then-deleted
        //   booking: -250 + 250 = 0. But we already had +250 from the original pay. So:
        //   cashbox = 10000 + 50 (A pay) - 20 (A refund) + 250 (B pay) - 250 (B pay reversal) = 10030
        $this->assertEqualsWithDelta(
            $cashboxStart + 30.0,  // 50 received from A - 20 refunded = 30 net (B is a wash)
            (float) $this->cashboxEgp->fresh()->balance,
            0.01,
            'cashbox must net to original + 30 (A: +50 received - 20 refunded; B: 0 net from full pay+delete)'
        );

        // Global ledger invariant holds throughout the flow.
        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Side-effect isolation: deleting one booking must NOT change another
    // booking's balances (regression guard against cross-booking leakage).
    // ─────────────────────────────────────────────────────────────────────

    public function test_booking_deletion_does_not_affect_other_bookings_balances(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);
        $customerA = $this->makeCustomerWithBusAccount(0, 'EGP');
        $customerB = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);

        // Booking A — paid fully, stays alive.
        $bookingA = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customerA->id,
            'customer_name' => 'Keep Alive',
            'customer_phone' => '01090000010',
            'quantity' => 1,
        ]);
        $service->payBooking($bookingA, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Booking B — also paid, will be deleted.
        $bookingB = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customerB->id,
            'customer_name' => 'Will Be Deleted',
            'customer_phone' => '01090000011',
            'quantity' => 1,
        ]);
        $service->payBooking($bookingB, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Capture A's balances.
        $custABefore   = (float) $customerA->ledgerAccount->fresh()->balance;
        $companyBefore = (float) $company->account->fresh()->balance;

        // ── Act: delete booking B ───────────────────────────────────────────
        $this->deleteJson("/api/v1/bus/bookings/{$bookingB->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // ── Assert: A's balances are UNCHANGED ──────────────────────────────
        $customerA->refresh();
        $this->assertEqualsWithDelta(
            $custABefore,
            (float) $customerA->ledgerAccount->fresh()->balance,
            0.01,
            'deleting booking B must NOT change customer A balance'
        );

        // Company balance: after both bookings posted 2 × (-80 cost) = -160.
        // After deleting B, B's supplier cost (80) is reversed, so company balance
        // becomes -80 (only A's cost remains). The CORRECT expectation is therefore
        // NOT $companyBefore (-160) but $companyBefore + 80 — the exact amount of
        // B's cost that gets reversed. This proves the deletion isolates to B.
        $company->refresh();
        $this->assertEqualsWithDelta(
            $companyBefore + 80.0,
            (float) $company->account->fresh()->balance,
            0.01,
            'company balance must increase by exactly B\'s cost (80 EGP) — no cross-leakage'
        );

        // Booking B is fully reversed: customer B balance is back to 0.
        $customerB->refresh();
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerB->ledgerAccount->fresh()->balance,
            0.01,
            'customer B balance must return to 0 after deletion'
        );

        // Booking A is still alive and paid.
        $bookingA->refresh();
        $this->assertNull($bookingA->deleted_at, 'booking A must still be active');
        $this->assertEqualsWithDelta(120.0, (float) $bookingA->paid_amount, 0.01);

        $this->assertLedgerGloballyBalanced();
    }
}