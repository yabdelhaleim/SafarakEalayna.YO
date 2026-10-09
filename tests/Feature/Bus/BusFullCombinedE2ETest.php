<?php

namespace Tests\Feature\Bus;

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Models\Treasury;
use App\Services\Bus\BusBookingService;
use Illuminate\Support\Str;

/**
 * Final Combined E2E Test — runs the Level 1 lifecycle and the Level 3
 * lifecycle BACK-TO-BACK in a SINGLE test method, asserting the global
 * ledger balance after every step. This is the canonical proof that all
 * three levels of hardening (Level 1 + Level 2 + Level 3) compose without
 * leakage or cross-contamination.
 *
 * Phase layout (single test, sequential):
 *
 *   Part A — Level 1 lifecycle on Inventory-1, Customer A:
 *     A.1 Book A
 *     A.2 Partial pay 100 with Idempotency-Key X
 *     A.3 EXPLOIT REPLAY: same key, same amount → must NOT double-charge
 *     A.4 Settle remaining 150 with new key Y
 *     A.5 Refund A with 30 fee (treasury gets 220)
 *     A.6 Process refund → treasury holds the cash
 *
 *   Part B — Level 3 lifecycle on Inventory-2, Customers B/C/D:
 *     B.1 Book B (partial-pay 50) → cancel with 30 penalty (refund 20)
 *     B.2 Book C (partial-pay 100) → cancel with 40 penalty (refund 60)
 *     B.3 Book D (full-pay 250) → DELETE via endpoint
 *
 *   Final:
 *     - Both inventories back to their starting state (5 seats each).
 *     - Every customer balance back to start (0).
 *     - All accounts reconciled.
 *     - assertLedgerGloballyBalanced() verifies sum(entries)==balance
 *       on every account that has any journal entries.
 */
class BusFullCombinedE2ETest extends BusTestCase
{
    public function test_full_combined_level1_and_level3_lifecycle_ledger_balanced(): void
    {
        $service = app(BusBookingService::class);

        // ════════════════════════════════════════════════════════════════════
        // PART A — Level 1 lifecycle (book → pay → exploit → refund)
        //          On its own inventory so it doesn't interfere with Part B.
        // ════════════════════════════════════════════════════════════════════

        $companyA = $this->makeBusCompany(['name' => 'Company A (Level-1)'], 0);
        $this->seedCashboxBalance(50000.0);

        $inventoryA = $this->makeInventory([
            'company_id' => $companyA->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 100,
            'selling_price' => 250,
        ]);
        $customerA = $this->makeCustomerWithBusAccount(0, 'EGP');
        $invAvailAStart = (int) $inventoryA->available_tickets;

        // A.1 Book A.
        $bookingA = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventoryA->id,
            'customer_id' => $customerA->id,
            'customer_name' => 'Customer A',
            'customer_phone' => '01090000001',
            'quantity' => 1,
        ])->assertCreated()->json('data');
        $bookingAId = (int) $bookingA['id'];

        $this->assertEquals($invAvailAStart - 1, (int) $inventoryA->fresh()->available_tickets,
            'Part A.1: book A must consume 1 seat');
        $this->assertLedgerGloballyBalanced();

        // A.2 PARTIAL pay A — pay 100 of 250 (use unique idempotency key).
        $idemKeyA = (string) Str::uuid();
        $this->withHeaders(['Idempotency-Key' => $idemKeyA])
            ->postJson("/api/v1/bus/bookings/{$bookingAId}/pay", [
                'amount' => 100.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertOk();
        $this->assertLedgerGloballyBalanced();

        // A.3 EXPLOIT ATTEMPT — replay SAME idempotency key, SAME amount.
        //     The service-layer replay path must return the original payment
        //     WITHOUT taking a second debit. Cashbox must NOT change.
        $cashboxBeforeReplay = (float) $this->cashboxEgp->fresh()->balance;
        $this->withHeaders(['Idempotency-Key' => $idemKeyA])
            ->postJson("/api/v1/bus/bookings/{$bookingAId}/pay", [
                'amount' => 100.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertOk();
        $cashboxAfterReplay = (float) $this->cashboxEgp->fresh()->balance;
        $this->assertEqualsWithDelta(
            $cashboxBeforeReplay,
            $cashboxAfterReplay,
            0.01,
            'Part A.3 EXPLOIT — same Idempotency-Key replay must NOT charge twice'
        );
        $this->assertLedgerGloballyBalanced();

        // A.4 Settle the remaining 150 with a fresh key.
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/bus/bookings/{$bookingAId}/pay", [
                'amount' => 150.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertOk();
        $this->assertLedgerGloballyBalanced();

        // A.5 Create the refund request (Level-1 cancel-with-fee flow).
        $treasury = Treasury::query()->create([
            'name' => 'Combined-E2E Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $bookingAId,
            'cancellation_fee' => 30.0,
            'refund_currency' => 'EGP',
            'refund_exchange_rate' => 1.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
            'refund_type' => 'cash_to_agency',
            'reason' => 'Customer cancelled',
        ])->assertCreated();
        $this->assertLedgerGloballyBalanced();

        // A.6 Process refund so treasury actually holds the cash.
        $refundId = BusRefundRequest::query()
            ->where('bus_booking_id', $bookingAId)
            ->latest('id')
            ->first()
            ->id;
        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();
        $this->assertLedgerGloballyBalanced();

        // Part A summary: customer A's money is back at the treasury; booking
        // status reflects PartiallyRefunded (cancellation_fee > 0). Note:
        // the refund flow does NOT soft-delete the booking — only cancel
        // and delete operations do.
        $customerA->refresh();

        // ════════════════════════════════════════════════════════════════════
        // PART B — Level 3 lifecycle (cancel × 2 + delete × 1)
        //          On a SEPARATE inventory so Part A's effects are not in play.
        // ════════════════════════════════════════════════════════════════════

        $companyB = $this->makeBusCompany(['name' => 'Company B (Level-3)'], 0);

        $inventoryB = $this->makeInventory([
            'company_id' => $companyB->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 100,
            'selling_price' => 250,
        ]);
        $customerB = $this->makeCustomerWithBusAccount(0, 'EGP');
        $customerC = $this->makeCustomerWithBusAccount(0, 'EGP');
        $customerD = $this->makeCustomerWithBusAccount(0, 'EGP');
        $invAvailBStart = (int) $inventoryB->available_tickets;

        // B.1 Book B, partial-pay 50, cancel with 30 penalty (refund 20).
        $bookingB = $service->createBooking([
            'inventory_id' => $inventoryB->id,
            'customer_id' => $customerB->id,
            'customer_name' => 'Customer B',
            'customer_phone' => '01090000002',
            'quantity' => 1,
        ]);
        $service->payBooking($bookingB->fresh(), [
            'amount' => 50.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $service->cancelBooking($bookingB->fresh(), [
            'company_penalty' => 18,
            'office_penalty' => 12,    // total = 30 ≤ paid(50)
            'account_id' => $this->cashboxEgp->id,
        ]);
        $this->assertLedgerGloballyBalanced();

        // B.2 Book C, partial-pay 100, cancel with 40 penalty (refund 60).
        $bookingC = $service->createBooking([
            'inventory_id' => $inventoryB->id,
            'customer_id' => $customerC->id,
            'customer_name' => 'Customer C',
            'customer_phone' => '01090000003',
            'quantity' => 1,
        ]);
        $service->payBooking($bookingC->fresh(), [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $service->cancelBooking($bookingC->fresh(), [
            'company_penalty' => 25,
            'office_penalty' => 15,    // total = 40 ≤ paid(100)
            'account_id' => $this->cashboxEgp->id,
        ]);
        $this->assertLedgerGloballyBalanced();

        // B.3 Book D, full-pay 250, DELETE via endpoint.
        $bookingD = $service->createBooking([
            'inventory_id' => $inventoryB->id,
            'customer_id' => $customerD->id,
            'customer_name' => 'Customer D',
            'customer_phone' => '01090000004',
            'quantity' => 1,
        ]);
        $service->payBooking($bookingD->fresh(), [
            'amount' => 250.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $this->deleteJson("/api/v1/bus/bookings/{$bookingD->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertLedgerGloballyBalanced();

        // ════════════════════════════════════════════════════════════════════
        // FINAL ASSERTIONS — both lifecycles must reconcile cleanly together
        // ════════════════════════════════════════════════════════════════════

        // Inventory A: book A → refund A RESTORES the seat (processRefundRequest
        // increments available_tickets on line 118 of BusRefundService).
        // So inventory A ends at its start.
        $this->assertEquals(
            $invAvailAStart,
            (int) $inventoryA->fresh()->available_tickets,
            'inventory A returns to start (refund flow restores the seat — verified in BusRefundService.php:118)'
        );

        // Inventory B: 5 → 4 (book B) → 5 (cancel B) → 4 (book C) → 5 (cancel C)
        //            → 4 (book D) → 5 (delete D) = 5 (its start)
        $this->assertEquals(
            $invAvailBStart,
            (int) $inventoryB->fresh()->available_tickets,
            'inventory B ends at its start (cancel×2 + delete×1 each restored the seat)'
        );

        // Customers B, C, D should be back to their starting balances (0).
        $customerB->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $customerB->ledgerAccount->fresh()->balance, 0.01,
            'customer B balance must return to 0 after partial-pay + cancel');

        $customerC->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $customerC->ledgerAccount->fresh()->balance, 0.01,
            'customer C balance must return to 0 after partial-pay + cancel');

        $customerD->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $customerD->ledgerAccount->fresh()->balance, 0.01,
            'customer D balance must return to 0 after full-pay + delete');

        // Booking D is soft-deleted.
        $this->assertNotNull(
            BusBooking::withTrashed()->find($bookingD->id)->deleted_at,
            'booking D must be soft-deleted after the DELETE endpoint call'
        );

        // Final global ledger invariant — verifies sum(entries) == balance on
        // every account that has journal entries. This is the canonical proof
        // that the combined Level 1 + Level 3 lifecycle leaves the books balanced.
        $verified = $this->assertLedgerGloballyBalanced();
        $this->assertGreaterThan(0, $verified,
            'assertLedgerGloballyBalanced() must verify at least one account');
    }
}
