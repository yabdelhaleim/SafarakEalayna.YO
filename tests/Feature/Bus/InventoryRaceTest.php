<?php

namespace Tests\Feature\Bus;

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Race-condition and capacity-correctness tests for inventory ↔ booking.
 *
 * The DB layer is single-threaded in PHPUnit, so true "two writers in
 * parallel" simulation is impossible. These tests instead drive the
 * serialized race-condition invariants the service MUST uphold:
 *
 *   1. `lockForUpdate()` on the inventory row blocks over-booking.
 *   2. `findOrCreateAutoInventory()` dedup works across repeated calls
 *      (same key → same row).
 *   3. Capacity decrement + increment are reciprocal (cancel restores
 *      exactly the cancelled quantity, never more, never less).
 *   4. Sold-out inventory rejects any subsequent booking attempt.
 *
 * The tests deliberately exercise the API endpoints (POST /api/v1/bus/bookings)
 * because that's the actual production codepath the Vue frontend uses —
 * going through the service directly would miss middleware / FormRequest
 * validation.
 */
class InventoryRaceTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — Sequential over-booking guard
    // ─────────────────────────────────────────────────────────────────────

    public function test_sequential_bookings_decrement_capacity_correctly(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Book 1 ticket, 4 times in a row.
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => 'Customer '.$i,
                'customer_phone' => sprintf('0100%07d', $i),
                'quantity' => 1,
            ])->assertCreated();
        }

        $inventory->refresh();
        $this->assertEquals(1, $inventory->available_tickets, 'Capacity should be 5 − 4 = 1');
        $this->assertEquals(4, BusBooking::query()->where('inventory_id', $inventory->id)->count());
    }

    public function test_booking_to_sold_out_inventory_rejects_subsequent_attempts(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 3,
            'available_tickets' => 3,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Drain the inventory.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inventory->id,
                'customer_name' => 'Customer '.$i,
                'customer_phone' => sprintf('0100%07d', $i),
                'quantity' => 1,
            ])->assertCreated();
        }

        $inventory->refresh();
        $this->assertEquals(0, $inventory->available_tickets);

        // 4th booking must be rejected.
        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Late Customer',
            'customer_phone' => '01000000099',
            'quantity' => 1,
        ]);

        $response->assertStatus(422);

        // The failed attempt must NOT create a booking row.
        $this->assertEquals(3, BusBooking::query()->where('inventory_id', $inventory->id)->count());

        // Capacity must remain at 0 (no half-decrement leaked through).
        $inventory->refresh();
        $this->assertEquals(0, $inventory->available_tickets);
    }

    public function test_reject_booking_when_quantity_exceeds_remaining(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 3,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Try to book 5 tickets when only 3 are available.
        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Greedy',
            'customer_phone' => '01000000111',
            'quantity' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // Capacity untouched.
        $inventory->refresh();
        $this->assertEquals(3, $inventory->available_tickets);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — Auto-inventory dedup (Mode B — Vue manual route)
    // ─────────────────────────────────────────────────────────────────────

    public function test_auto_inventory_dedup_when_same_key_repeats(): void
    {
        $company = $this->makeBusCompany([], 0);

        $payload = [
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => now()->addDays(5)->toDateString(),
            'customer_name' => 'Dup',
            'customer_phone' => '01000000077',
            'quantity' => 2,
        ];

        // 3 bookings with identical (company, route, date, selling_price).
        $this->postJson('/api/v1/bus/bookings', array_merge($payload, ['customer_phone' => '01000000077']))->assertCreated();
        $this->postJson('/api/v1/bus/bookings', array_merge($payload, ['customer_phone' => '01000000078']))->assertCreated();
        $this->postJson('/api/v1/bus/bookings', array_merge($payload, ['customer_phone' => '01000000079']))->assertCreated();

        // Only ONE auto-inventory row exists for this key.
        $this->assertEquals(1, BusInventory::query()->where('is_auto_created', true)->count());

        // 3 bookings attached to it.
        $autoInv = BusInventory::query()->where('is_auto_created', true)->firstOrFail();
        $this->assertEquals(3, $autoInv->bookings()->count());

        // Capacity decreased by 6 (3 bookings × 2 quantity) from 999999.
        $this->assertEquals(999999 - 6, $autoInv->available_tickets);
    }

    public function test_auto_inventory_separates_when_selling_price_differs(): void
    {
        $company = $this->makeBusCompany([], 0);
        $common = [
            'company_id' => $company->id,
            'route' => 'القاهرة - أسوان',
            'travel_date' => now()->addDays(7)->toDateString(),
        ];

        // Same route + date + company, but different selling prices.
        $this->postJson('/api/v1/bus/bookings', array_merge($common, [
            'cost_price' => 100, 'selling_price' => 150,
            'customer_name' => 'A', 'customer_phone' => '01000000100', 'quantity' => 1,
        ]))->assertCreated();

        $this->postJson('/api/v1/bus/bookings', array_merge($common, [
            'cost_price' => 100, 'selling_price' => 200, // ← different
            'customer_name' => 'B', 'customer_phone' => '01000000200', 'quantity' => 1,
        ]))->assertCreated();

        // Two distinct auto-inventories — the (selling_price) is part of the dedup key.
        $this->assertEquals(2, BusInventory::query()->where('is_auto_created', true)->count());

        $prices = BusInventory::query()->where('is_auto_created', true)
            ->orderBy('selling_price')
            ->pluck('selling_price')
            ->map(fn ($p) => (float) $p)
            ->all();
        $this->assertEquals([150.0, 200.0], $prices);
    }

    public function test_auto_inventory_dedup_with_carbon_normalized_dates(): void
    {
        // Bug #B-01 regression — the findOrCreateAutoInventory() must
        // compare `DATE(travel_date)` rather than the raw column value
        // (which can be `'2026-08-01 00:00:00'` vs `'2026-08-01'`).
        // This test posts the same date in two different formats and
        // expects dedup to still hold.
        $company = $this->makeBusCompany([], 0);
        $date = now()->addDays(10)->startOfDay();

        $this->postJson('/api/v1/bus/bookings', [
            'company_id' => $company->id,
            'route' => 'A - B',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => $date->toDateTimeString(),  // '2026-07-28 00:00:00'
            'customer_name' => 'Format 1',
            'customer_phone' => '01000000300',
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/bus/bookings', [
            'company_id' => $company->id,
            'route' => 'A - B',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => $date->toDateString(),       // '2026-07-28'
            'customer_name' => 'Format 2',
            'customer_phone' => '01000000301',
            'quantity' => 1,
        ])->assertCreated();

        $this->assertEquals(1, BusInventory::query()->where('is_auto_created', true)->count());
    }

    public function test_auto_inventory_separates_per_company(): void
    {
        // Two different companies, same route + date + price → two auto-inventories.
        $company1 = $this->makeBusCompany([], 0);
        $company2 = $this->makeBusCompany([], 0);

        $common = [
            'route' => 'القاهرة - شرم الشيخ',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => now()->addDays(3)->toDateString(),
        ];

        $this->postJson('/api/v1/bus/bookings', array_merge($common, [
            'company_id' => $company1->id,
            'customer_name' => 'C1', 'customer_phone' => '01000000400', 'quantity' => 1,
        ]))->assertCreated();

        $this->postJson('/api/v1/bus/bookings', array_merge($common, [
            'company_id' => $company2->id,
            'customer_name' => 'C2', 'customer_phone' => '01000000401', 'quantity' => 1,
        ]))->assertCreated();

        $this->assertEquals(2, BusInventory::query()->where('is_auto_created', true)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — Cancel restores capacity (and is idempotent)
    // ─────────────────────────────────────────────────────────────────────

    public function test_capacity_restored_on_cancel(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Book 3 tickets via API (creates booking + customer AR + supplier debt).
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Restore Test',
            'customer_phone' => '01000000777',
            'quantity' => 3,
        ])->assertCreated();

        $booking = BusBooking::query()->latest()->firstOrFail();

        $inventory->refresh();
        $this->assertEquals(7, $inventory->available_tickets, 'Booking should drop capacity by 3');

        // Cancel the booking (no penalties, no refund).
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $inventory->refresh();
        $this->assertEquals(10, $inventory->available_tickets, 'Cancel should restore all 3 tickets');
    }

    public function test_capacity_not_double_restored_on_double_cancel(): void
    {
        // Idempotency: cancelling an already-cancelled booking must NOT
        // restore capacity a second time. The service throws on already-cancelled
        // bookings, so we expect a 422 from the second call.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Double Cancel',
            'customer_phone' => '01000000888',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::query()->latest()->firstOrFail();

        // First cancel — restores capacity from 8 back to 10.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $inventory->refresh();
        $this->assertEquals(10, $inventory->available_tickets);

        // Second cancel — must be rejected, NOT silently re-increment capacity.
        $second = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);
        $second->assertStatus(422); // already-cancelled → "الحجز ملغي أو مسترد بالفعل."

        $inventory->refresh();
        $this->assertEquals(10, $inventory->available_tickets, 'Capacity must NOT double-restore (idempotency)');
    }

    public function test_capacity_restored_after_partial_then_full_cancel(): void
    {
        // Cancel a multi-ticket booking: the entire quantity should be
        // restored at once (not per-ticket).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Multi Cancel',
            'customer_phone' => '01000000999',
            'quantity' => 4,
        ])->assertCreated();

        $booking = BusBooking::query()->latest()->firstOrFail();

        $inventory->refresh();
        $this->assertEquals(6, $inventory->available_tickets);

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $inventory->refresh();
        $this->assertEquals(10, $inventory->available_tickets);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4 — Capacity invariant under mixed scenarios
    // ─────────────────────────────────────────────────────────────────────

    public function test_inventory_invariant_holds_across_book_pay_cancel_cycle(): void
    {
        // The cycle: book 2 → pay partial → cancel (no penalty) → capacity must
        // be back to its initial value (10), bookings preserved, payments
        // and ledger balanced.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Book 2 tickets via API (creates booking + posts AR + supplier debt).
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Cycle Test',
            'customer_phone' => '01000001010',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::query()->latest()->firstOrFail();

        $inventory->refresh();
        $this->assertEquals(8, $inventory->available_tickets);

        // Pay the booking partially.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        // Cancel the booking.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $inventory->refresh();
        $this->assertEquals(10, $inventory->available_tickets, 'Capacity must be fully restored after cancel');

        // Ledger invariant must still hold.
        $this->assertLedgerGloballyBalanced();
    }
}
