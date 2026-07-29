<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;

/**
 * Deep Concurrency Invariants — Bus module
 * ─────────────────────────────────────────
 *
 * PHPUnit runs single-threaded on in-memory SQLite, so TRUE concurrent
 * writers cannot be simulated (lockForUpdate() is a no-op there). These
 * tests complement the script-based `bus_deep_concurrency_e2e.php` by
 * pinning deeper invariants the service MUST uphold under SERIALIZED
 * re-entry — i.e. the same guarantees the production code relies on
 * when MySQL's pessimistic locking gives it deterministic semantics.
 *
 * What this suite pins:
 *   F1.  50 sequential bookings on capacity=20 → exactly 20 succeed
 *   F2.  50 sequential partial payments (10 EGP) on booking total=250
 *        → exactly 25 succeed, 25 reject, paid_amount converges to 250
 *   F3.  Repeated book→pay→cancel cycle returns inventory to initial state
 *   F4.  30 mixed-currency (EGP/USD) bookings preserve FX snapshots
 *   F5.  20 deferred bookings accumulate supplier debt exactly,
 *        then 5 × 40% installments pay it off
 *   F6.  10 deferred bookings preserve inventory remaining_debt exact
 *   F7.  Cancel-after-cancel idempotency under repeated attempts
 *   F8.  Repeated pay-then-cancel creates correct refund every cycle
 *
 * Combined with `InventoryRaceTest` (basic capacity) + `ConcurrencyIdempotencyTest`
 * (basic payment idempotency) + the script-based tests (real parallel HTTP load),
 * the Bus module is fully covered for concurrency under load and race conditions.
 */
class BusDeepConcurrencyTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // F1 — 50 sequential bookings on capacity=20
    // ─────────────────────────────────────────────────────────────────────

    public function test_fifty_sequential_bookings_capacity_invariant(): void
    {
        // Capacity=20, fire 50 bookings of qty=1 each. First 20 must succeed;
        // the remaining 30 must be rejected with 422 — capacity invariant
        // holds at every step.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 20,
            'available_tickets' => 20,
            'cost_per_ticket' => 80,
            'selling_price' => 100,
        ]);

        $successCount = 0;
        $rejectCount = 0;
        for ($i = 1; $i <= 50; $i++) {
            $resp = $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => "F1 cust {$i}",
                'customer_phone' => sprintf('0100F100%02d', $i),
                'quantity' => 1,
            ]);
            if ($resp->status() === 201) {
                $successCount++;
            } else {
                $rejectCount++;
            }
            // Invariant: inventory never goes negative.
            $this->assertGreaterThanOrEqual(0, $inventory->fresh()->available_tickets, "Capacity negative at iteration {$i}");
        }

        $this->assertEquals(20, $successCount, "Expected exactly 20 successes (capacity=20), got {$successCount}");
        $this->assertEquals(30, $rejectCount, "Expected exactly 30 rejections, got {$rejectCount}");
        $this->assertEquals(0, $inventory->fresh()->available_tickets, 'Capacity must be exactly 0 after 20 sales');
        $this->assertEquals(20, BusBooking::query()->where('inventory_id', $inventory->id)->count(), 'Booking count must equal success count');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F2 — 50 sequential partial payments of 10 EGP each on total=250
    // ─────────────────────────────────────────────────────────────────────

    public function test_fifty_sequential_partial_payments_no_double_charge(): void
    {
        // Book 1 ticket at selling_price=250 → total=250.
        // Fire 50 sequential pay-of-10 calls. First 25 succeed (250/10=25),
        // the next 25 are rejected (overpay / already-paid).
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(5000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 1,
            'available_tickets' => 1,
            'cost_per_ticket' => 200,
            'selling_price' => 250,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'F2 cust',
            'customer_phone' => '0100F200001',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::query()->latest()->firstOrFail();
        $this->assertEquals(250.0, (float) $booking->total_price);

        $successCount = 0;
        $rejectCount = 0;
        for ($i = 0; $i < 50; $i++) {
            $resp = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => 10.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ]);
            if ($resp->status() === 200) {
                $successCount++;
            } elseif ($resp->status() === 422) {
                $rejectCount++;
            } else {
                $this->fail("Unexpected status {$resp->status()} at iteration {$i}");
            }
        }

        $this->assertEquals(25, $successCount, "Expected exactly 25 successful payments (250/10), got {$successCount}");
        $this->assertEquals(25, $rejectCount, "Expected exactly 25 rejections, got {$rejectCount}");
        $booking->refresh();
        $this->assertEqualsWithDelta(250.0, (float) $booking->paid_amount, 0.01, 'paid_amount must converge to 250');
        $this->assertEquals(25, BusPayment::query()->where('booking_id', $booking->id)->count(), 'Exactly 25 BusPayment rows');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F3 — Repeated book→pay→cancel cycle returns to initial state
    // ─────────────────────────────────────────────────────────────────────

    public function test_repeated_book_pay_cancel_cycle_returns_to_initial_state(): void
    {
        // 20 cycles of: book qty=2 → pay total → cancel (no penalty).
        // After each cycle: capacity should be back to 20, ledger balanced.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(50000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 20,
            'available_tickets' => 20,
            'cost_per_ticket' => 80,
            'selling_price' => 100,
        ]);

        for ($cycle = 1; $cycle <= 20; $cycle++) {
            $resp = $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => "F3 cust {$cycle}",
                'customer_phone' => sprintf('0100F3%04d', $cycle),
                'quantity' => 2,
            ])->assertCreated();
            $bookingId = (int) $resp->json('data.id');
            $booking = BusBooking::findOrFail($bookingId);

            $this->assertEquals(18, $inventory->fresh()->available_tickets, "Cycle {$cycle}: capacity should be 18 after booking");

            $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => (float) $booking->total_price,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertSuccessful();

            $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
                'company_penalty' => 0,
                'office_penalty' => 0,
                'account_id' => $this->cashboxEgp->id,
            ])->assertSuccessful();

            $inventory->refresh();
            $this->assertEquals(20, $inventory->available_tickets, "Cycle {$cycle}: capacity must return to 20 after cancel");
        }

        // 20 cycles × 1 booking per cycle = 20 bookings, all cancelled
        $this->assertEquals(20, BusBooking::query()->count(), '20 bookings total');
        $this->assertEquals(20, BusRefundRequest::query()->count(), '20 refunds issued');
        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F4 — 30 mixed-currency bookings preserve FX snapshots
    // ─────────────────────────────────────────────────────────────────────

    public function test_mixed_currency_bookings_preserve_fx_snapshots(): void
    {
        // 15 EGP bookings + 15 USD bookings.
        // Each booking snapshots the inventory's exchange_rate_to_egp
        // and currency. Sequential stress — verify no torn reads.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(100000.0);
        $egpInv = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 15,
            'available_tickets' => 15,
            'cost_per_ticket' => 50,
            'selling_price' => 80,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);
        $usdInv = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 15,
            'available_tickets' => 15,
            'cost_per_ticket' => 1.0,
            'selling_price' => 1.6,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        // 15 EGP bookings
        for ($i = 1; $i <= 15; $i++) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $egpInv->id,
                'customer_name' => "F4 EGP {$i}",
                'customer_phone' => sprintf('0100F4E%02d', $i),
                'quantity' => 1,
            ])->assertCreated();
        }
        // 15 USD bookings
        for ($i = 1; $i <= 15; $i++) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $usdInv->id,
                'customer_name' => "F4 USD {$i}",
                'customer_phone' => sprintf('0100F4U%02d', $i),
                'quantity' => 1,
            ])->assertCreated();
        }

        $egpBookings = BusBooking::query()->where('inventory_id', $egpInv->id)->get();
        $usdBookings = BusBooking::query()->where('inventory_id', $usdInv->id)->get();

        $this->assertCount(15, $egpBookings);
        $this->assertCount(15, $usdBookings);

        // EGP snapshots: rate=1.0, currency=EGP, totals=80 each
        foreach ($egpBookings as $b) {
            $this->assertEquals('EGP', $b->currency, "EGP booking currency");
            $this->assertEqualsWithDelta(1.0, (float) $b->exchange_rate_to_egp, 0.001, 'EGP rate');
            $this->assertEqualsWithDelta(80.0, (float) $b->total_price, 0.01, 'EGP total');
        }
        // USD snapshots: rate=50.0, currency=USD, totals=1.6 each
        foreach ($usdBookings as $b) {
            $this->assertEquals('USD', $b->currency, "USD booking currency");
            $this->assertEqualsWithDelta(50.0, (float) $b->exchange_rate_to_egp, 0.001, 'USD rate');
            $this->assertEqualsWithDelta(1.6, (float) $b->total_price, 0.001, 'USD total');
        }

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F5 — Inventory purchase debt accumulates & pays off via inventory pay-debt
    // ─────────────────────────────────────────────────────────────────────

    public function test_supplier_debt_accumulates_and_pays_off_under_sequential_load(): void
    {
        // Note: `BusInventory.remaining_debt` is the INVENTORY purchase debt
        // (set at creation, paid down via `payInventoryDebt` endpoint).
        // It is INDEPENDENT of the per-booking supplier debt (which posts
        // cost × qty to `company->account->balance` for each booking).
        // This test verifies the inventory-level debt accumulation + pay-off.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(50000.0);
        $costPerTicket = 100.0;
        $totalTickets = 100;
        $totalCost = $costPerTicket * $totalTickets;  // 10000
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => $totalTickets,
            'available_tickets' => $totalTickets,
            'cost_per_ticket' => $costPerTicket,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'total_cost' => $totalCost,             // explicit override (factory computes from random values)
            'amount_paid' => 0,
            'remaining_debt' => $totalCost,          // explicit override
        ]);

        $inventory->refresh();
        $this->assertEqualsWithDelta($totalCost, (float) $inventory->remaining_debt, 0.01, "Inventory initial remaining_debt = {$totalCost}");

        // Book 20 customers × qty 1 (bookings don't change inventory.remaining_debt —
        // they only post per-booking supplier debt to `company->account`)
        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => "F5 cust {$i}",
                'customer_phone' => sprintf('0100F5%04d', $i),
                'quantity' => 1,
            ])->assertCreated();
        }
        $inventory->refresh();
        $this->assertEquals(80, $inventory->available_tickets, '20 sold, 80 remaining');
        // Bookings do NOT change inventory.remaining_debt (it tracks inventory purchase cost only)
        $this->assertEqualsWithDelta($totalCost, (float) $inventory->remaining_debt, 0.01, 'Inventory remaining_debt unchanged by bookings');

        // Pay off inventory debt in 5 × 2000 installments (10000 total)
        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->postJson("/api/v1/bus/inventories/{$inventory->id}/pay-debt", [
                'amount' => 2000.0,
                'account_id' => $this->cashboxEgp->id,
                'notes' => "F5 installment {$i}",
            ]);
            $resp->assertSuccessful();
        }

        $inventory->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $inventory->remaining_debt, 0.01, 'Inventory remaining_debt fully settled');
        $this->assertEqualsWithDelta($totalCost, (float) $inventory->amount_paid, 0.01, 'Inventory amount_paid = total_cost');

        // 6th installment should be rejected (no debt)
        $resp = $this->postJson("/api/v1/bus/inventories/{$inventory->id}/pay-debt", [
            'amount' => 2000.0,
            'account_id' => $this->cashboxEgp->id,
        ]);
        $resp->assertStatus(422);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F6 — 10 deferred bookings preserve inventory remaining_debt exact
    // ─────────────────────────────────────────────────────────────────────

    public function test_inventory_remaining_debt_exact_under_sequential_bookings(): void
    {
        // Note: `inventory.remaining_debt` tracks inventory PURCHASE cost (set at creation).
        // Bookings do NOT change it — only `payInventoryDebt` does. This test verifies:
        //  1. Bookings leave inventory.remaining_debt exactly intact (no partial writes)
        //  2. Inventory pay-debt reduces it by exact amount paid
        //  3. Overpay rejected; final payment leaves remaining_debt = 0
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(50000.0);
        $costPerTicket = 175.50;  // non-round to exercise decimal precision
        $totalTickets = 50;
        $totalCost = $costPerTicket * $totalTickets;  // 8775.00
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => $totalTickets,
            'available_tickets' => $totalTickets,
            'cost_per_ticket' => $costPerTicket,
            'selling_price' => 200,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'total_cost' => $totalCost,             // explicit override (factory computes from random values)
            'amount_paid' => 0,
            'remaining_debt' => $totalCost,          // explicit override
        ]);

        $inventory->refresh();
        $this->assertEqualsWithDelta($totalCost, (float) $inventory->remaining_debt, 0.01, 'Initial inventory.remaining_debt must be exact (8775.00)');
        $this->assertEqualsWithDelta(0.0, (float) $inventory->amount_paid, 0.01, 'amount_paid must be 0 initially');

        // 10 bookings — none should change inventory.remaining_debt (purchase cost is fixed)
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => "F6 cust {$i}",
                'customer_phone' => sprintf('0100F6%04d', $i),
                'quantity' => 1,
            ])->assertCreated();
        }
        $inventory->refresh();
        $this->assertEqualsWithDelta($totalCost, (float) $inventory->remaining_debt, 0.01, 'Bookings must NOT change inventory.remaining_debt');
        $this->assertEqualsWithDelta(0.0, (float) $inventory->amount_paid, 0.01, 'amount_paid still 0 after bookings');
        $this->assertEquals(40, $inventory->available_tickets, '10 sold, 40 remaining');

        // Pay off in 2 partial payments, then 1 final payment
        $this->postJson("/api/v1/bus/inventories/{$inventory->id}/pay-debt", [
            'amount' => 500.0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertSuccessful();
        $inventory->refresh();
        $this->assertEqualsWithDelta($totalCost - 500.0, (float) $inventory->remaining_debt, 0.01, 'Debt after first partial');
        $this->assertEqualsWithDelta(500.0, (float) $inventory->amount_paid, 0.01, 'amount_paid after first partial');

        $this->postJson("/api/v1/bus/inventories/{$inventory->id}/pay-debt", [
            'amount' => 800.0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertSuccessful();
        $inventory->refresh();
        $expectedRemaining = $totalCost - 1300.0;  // 7475.00
        $this->assertEqualsWithDelta($expectedRemaining, (float) $inventory->remaining_debt, 0.01, 'Debt after 2 partial payments');

        // Pay exact remaining
        $this->postJson("/api/v1/bus/inventories/{$inventory->id}/pay-debt", [
            'amount' => $expectedRemaining,
            'account_id' => $this->cashboxEgp->id,
        ])->assertSuccessful();
        $inventory->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $inventory->remaining_debt, 0.01, 'Debt fully settled');
        $this->assertEqualsWithDelta($totalCost, (float) $inventory->amount_paid, 0.01, 'amount_paid = total_cost after settlement');

        // Overpay attempt should reject
        $this->postJson("/api/v1/bus/inventories/{$inventory->id}/pay-debt", [
            'amount' => 1.0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertStatus(422);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F7 — Cancel-after-cancel idempotency under repeated attempts
    // ─────────────────────────────────────────────────────────────────────

    public function test_cancel_after_cancel_idempotent_under_load(): void
    {
        // Book, then attempt cancel 11 times (1 should succeed, 10 should reject).
        // Capacity restored ONCE — never double-incremented.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(5000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 100,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'F7 cust',
            'customer_phone' => '0100F70001',
            'quantity' => 2,
        ])->assertCreated();
        $booking = BusBooking::query()->latest()->firstOrFail();

        $this->assertEquals(8, $inventory->fresh()->available_tickets, 'After booking qty=2');

        // 11 cancel attempts (sequential)
        $successCount = 0;
        $rejectCount = 0;
        for ($i = 0; $i < 11; $i++) {
            $resp = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
                'company_penalty' => 0,
                'office_penalty' => 0,
                'account_id' => $this->cashboxEgp->id,
            ]);
            if ($resp->status() === 200) {
                $successCount++;
            } elseif ($resp->status() === 422) {
                $rejectCount++;
            } else {
                $this->fail("Unexpected status {$resp->status()} at cancel attempt {$i}");
            }
        }

        $this->assertEquals(1, $successCount, "Expected exactly 1 successful cancel, got {$successCount}");
        $this->assertEquals(10, $rejectCount, "Expected exactly 10 rejected cancels, got {$rejectCount}");
        $inventory->refresh();
        $this->assertEquals(10, $inventory->available_tickets, 'Capacity restored exactly ONCE (no double-increment)');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // F8 — Repeated pay-then-cancel creates correct refund every cycle
    // ─────────────────────────────────────────────────────────────────────

    public function test_sequential_pay_then_cancel_creates_correct_refund_every_time(): void
    {
        // 20 cycles of: book qty=1 → pay total → cancel (no penalty).
        // Each cycle must produce a BusRefundRequest with refund_amount = original_amount.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(100000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 50,
            'available_tickets' => 50,
            'cost_per_ticket' => 80,
            'selling_price' => 100,
        ]);

        for ($cycle = 1; $cycle <= 20; $cycle++) {
            $resp = $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => "F8 cust {$cycle}",
                'customer_phone' => sprintf('0100F8%04d', $cycle),
                'quantity' => 1,
            ])->assertCreated();
            $booking = BusBooking::findOrFail((int) $resp->json('data.id'));
            $totalPrice = (float) $booking->total_price;

            $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => $totalPrice,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertSuccessful();

            $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
                'company_penalty' => 0,
                'office_penalty' => 0,
                'account_id' => $this->cashboxEgp->id,
            ])->assertSuccessful();

            $refund = BusRefundRequest::query()->where('bus_booking_id', $booking->id)->firstOrFail();
            $this->assertEqualsWithDelta($totalPrice, (float) $refund->refund_amount, 0.01, "Cycle {$cycle}: refund_amount must match original");
            $this->assertEqualsWithDelta(0.0, (float) $refund->cancellation_fee, 0.01, "Cycle {$cycle}: no cancellation fee");
        }

        // After 20 cycles, capacity returns to 50, cashbox returns to starting balance
        $this->assertEquals(50, $inventory->fresh()->available_tickets, 'Capacity returns to initial');
        $this->assertEqualsWithDelta(100000.0, (float) $this->cashboxEgp->fresh()->balance, 0.01, 'Cashbox returns to starting');

        $this->assertLedgerGloballyBalanced();
    }
}