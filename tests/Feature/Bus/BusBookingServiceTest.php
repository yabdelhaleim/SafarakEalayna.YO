<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusRefundRequest;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusInventoryService;

/**
 * Direct service-level tests for the booking + inventory services.
 *
 * These tests complement {@see InventoryRaceTest} (which exercises the
 * API endpoints) and {@see InventoryServiceTest} (which covers inventory
 * CRUD only). They focus on:
 *
 *   1. `BusBookingService::createBooking()` — Mode B (no inventory_id)
 *      → `findOrCreateAutoInventory()` is invoked with the right defaults.
 *   2. Dedup invariant of `findOrCreateAutoInventory()` (same key →
 *      same row) — verified at the service level, bypassing FormRequest
 *      validation entirely.
 *   3. `BusBookingService::cancelBooking()` side-effects:
 *      - Inventory capacity restored.
 *      - Customer AR reversed.
 *      - Company debt reversed.
 *      - Ledger globally balanced.
 *   4. `BusRefundRequest` row created with all currency-snapshot fields
 *      populated correctly (so refund reports can show original currency
 *      after EGP-base equivalent is computed).
 *   5. `BusInventoryService::getAvailableInventories()` filter contract.
 *
 * Going through the service directly (rather than the API) makes these
 * tests pin the *service contract* itself — the same contract used by
 * Filament pages and tinker scripts.
 */
class BusBookingServiceTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — Auto-inventory creation (Mode B — no inventory_id)
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_booking_via_service_auto_creates_inventory_when_no_inventory_id(): void
    {
        $company = $this->makeBusCompany([], 0);
        $service = app(BusBookingService::class);

        $this->assertEquals(0, BusInventory::query()->count(), 'No inventory yet');

        $booking = $service->createBooking([
            'company_id' => $company->id,
            'route' => 'القاهرة - شرم الشيخ',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => now()->addDays(7)->toDateString(),
            'customer_name' => 'Auto Test',
            'customer_phone' => '01000000500',
            'quantity' => 2,
        ]);

        // Booking was created.
        $this->assertNotNull($booking->id);
        $this->assertEquals(2, (int) $booking->quantity);

        // Auto-inventory was created with the canonical defaults.
        $this->assertEquals(1, BusInventory::query()->count());
        $inventory = BusInventory::query()->firstOrFail();

        $this->assertTrue((bool) $inventory->is_auto_created);
        $this->assertEquals(BusInventoryPaymentType::Deferred, $inventory->payment_type);
        $this->assertEquals(999999, (int) $inventory->total_tickets, 'Auto inventory starts with sentinel capacity');
        $this->assertEquals(999997, (int) $inventory->available_tickets, '999999 − 2 booked tickets');
        $this->assertEquals(80.0, (float) $inventory->cost_per_ticket);
        $this->assertEquals(120.0, (float) $inventory->selling_price);
        $this->assertEquals($company->id, $inventory->company_id);
        $this->assertEquals('القاهرة - شرم الشيخ', $inventory->route);
        $this->assertEquals(now()->addDays(7)->toDateString(), $inventory->travel_date->toDateString());
        $this->assertNull($inventory->account_id);
        $this->assertNull($inventory->transaction_id);

        // Booking points to the auto-inventory.
        $this->assertEquals($inventory->id, $booking->inventory_id);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — Auto-inventory dedup at service level
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_booking_via_service_dedups_auto_inventory_on_identical_keys(): void
    {
        // Mode B relies on `findOrCreateAutoInventory()` which uses
        // `(company_id, route, DATE(travel_date), selling_price, cost_per_ticket)`
        // as the dedup key. We pin that contract at the service level so
        // the invariant is preserved even if the API layer changes.
        $company = $this->makeBusCompany([], 0);
        $service = app(BusBookingService::class);

        $common = [
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => now()->addDays(10)->toDateString(),
        ];

        $booking1 = $service->createBooking(array_merge($common, [
            'customer_name' => 'A', 'customer_phone' => '01000000600', 'quantity' => 1,
        ]));
        $booking2 = $service->createBooking(array_merge($common, [
            'customer_name' => 'B', 'customer_phone' => '01000000601', 'quantity' => 2,
        ]));

        // Both bookings attached to ONE inventory row.
        $this->assertEquals(1, BusInventory::query()->where('is_auto_created', true)->count());
        $this->assertEquals($booking1->inventory_id, $booking2->inventory_id);

        $inventory = BusInventory::query()->where('is_auto_created', true)->firstOrFail();
        $this->assertEquals(2, $inventory->bookings()->count());
        $this->assertEquals(999996, (int) $inventory->available_tickets, '999999 − 1 − 2');

        // Different price → separate inventory (already covered at API level,
        // but pinning it here keeps the contract test self-contained).
        $booking3 = $service->createBooking(array_merge($common, [
            'selling_price' => 150, // differs
            'customer_name' => 'C', 'customer_phone' => '01000000602', 'quantity' => 1,
        ]));

        $this->assertEquals(2, BusInventory::query()->where('is_auto_created', true)->count());
        $this->assertNotEquals($booking1->inventory_id, $booking3->inventory_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — Cancel side-effects: capacity + ledger
    // ─────────────────────────────────────────────────────────────────────

    public function test_cancel_booking_via_service_restores_capacity_and_balances_ledger(): void
    {
        // Drives the canonical cancel flow at the service level. After
        // booking 2 tickets, paying them off, then cancelling with no
        // penalty, we expect:
        //   - Inventory capacity restored to 10.
        //   - Customer AR fully reversed (balance 0).
        //   - Company debt fully reversed (balance 0).
        //   - Ledger globally balanced (every account: balance == Σ(debit−credit)).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);
        $this->seedCashboxBalance(10000.0);

        $service = app(BusBookingService::class);

        // Create a customer up-front so we can later inspect their AR balance.
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
            'customer_name' => 'Cancel Test',
            'customer_phone' => '01000000700',
        ]);

        // Sanity: capacity dropped, AR posted, company debt posted.
        $inventory->refresh();
        $this->assertEquals(8, (int) $inventory->available_tickets);

        $customerAccount = $customer->ledgerAccount;
        $companyAccount = Account::find($company->account_id);
        $this->assertEquals(240.0, (float) $customerAccount->fresh()->balance, 'Customer owes 2×120');
        $this->assertEquals(-160.0, (float) $companyAccount->fresh()->balance, 'We owe the company 2×80');

        // Pay the booking fully.
        $service->payBooking($booking, [
            'amount' => 240.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Customer AR stays untouched by payment — only the cash side moves.
        // The booking's payment_status flips to Paid but the AR ledger row
        // remains on the books until cancellation clears it (this is the
        // design Phase 7 wired in `cancelBooking`).
        $this->assertEquals(240.0, (float) $customerAccount->fresh()->balance, 'Payment does NOT touch customer AR');
        $this->assertEquals(10240.0, (float) $this->cashboxEgp->fresh()->balance, 'Cashbox +240 from payment');

        // Cancel with no penalty — full refund goes back to the customer.
        $refund = $service->cancelBooking($booking, [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->assertInstanceOf(BusRefundRequest::class, $refund);
        $this->assertEquals(BusBookingStatus::Refunded, $booking->fresh()->status);

        // Capacity restored.
        $inventory->refresh();
        $this->assertEquals(10, (int) $inventory->available_tickets, 'Cancel must restore all 2 tickets');

        // Cashbox refunded 240 (back to 10k seed).
        $this->assertEquals(10000.0, (float) $this->cashboxEgp->fresh()->balance);

        // Customer AR cleared by the cancellation's reverseCustomerSaleDebt.
        $this->assertEquals(0.0, (float) $customerAccount->fresh()->balance, 'Cancel reverses customer AR');

        // Company debt cleared (we settled nothing to the company yet, so the
        // 160 supplier debt that was recorded at booking must be reversed).
        $this->assertEquals(0.0, (float) $companyAccount->fresh()->balance);

        // Ledger invariant holds across all touched accounts.
        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4 — BusRefundRequest currency snapshot
    // ─────────────────────────────────────────────────────────────────────

    public function test_cancel_booking_via_service_creates_refund_request_with_currency_snapshot(): void
    {
        // When a multi-currency booking is cancelled, the BusRefundRequest
        // row must preserve the original currency + FX snapshot so refund
        // history / audit reports can show what was actually charged.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(50000.0);

        // Build a USD-priced inventory (foreign currency) to exercise the
        // snapshot fields on the refund record.
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 50.0,
            'selling_price' => 100.0,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        $service = app(BusBookingService::class);
        $customer = $this->makeCustomerWithBusAccount(0, 'USD');

        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
            'customer_name' => 'FX Refund',
            'customer_phone' => '01000000800',
        ]);

        // Booking was created in USD.
        $this->assertEquals('USD', $booking->currency);
        $this->assertEquals(50.0, (float) $booking->exchange_rate_to_egp);

        // Pay the booking from the EGP cashbox (FX-aware path in payBooking).
        // 100 USD × 50 EGP/USD = 5000 EGP is debited to the EGP cashbox
        // (i.e. the cashbox gains the EGP-equivalent of the foreign payment).
        $service->payBooking($booking, [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->assertEquals(55000.0, (float) $this->cashboxEgp->fresh()->balance, 'EGP cashbox +5000 after FX payment (gains EGP-equivalent)');

        // Cancel with zero penalty — refund snapshot fields must capture USD.
        $refund = $service->cancelBooking($booking, [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id, // EGP cashbox — refund posts in EGP-equivalent
        ]);

        // BusRefundRequest fields.
        $this->assertInstanceOf(BusRefundRequest::class, $refund);
        $this->assertEquals($booking->id, $refund->bus_booking_id);
        $this->assertEquals($company->id, $refund->company_id);
        $this->assertEquals('cancel', $refund->refund_type);

        // Currency snapshot.
        $this->assertEquals('USD', $refund->original_currency);
        $this->assertEquals(100.0, (float) $refund->original_amount, '1 × $100');
        $this->assertEquals('USD', $refund->refund_currency);
        $this->assertEquals(50.0, (float) $refund->refund_exchange_rate);
        $this->assertEquals(0.0, (float) $refund->cancellation_fee);
        $this->assertEquals(100.0, (float) $refund->refund_amount, 'No penalty → full refund');

        // base_currency_refund = refund_amount × rate (EGP-equivalent).
        $this->assertEquals(5000.0, (float) $refund->base_currency_refund, '100 USD × 50 EGP/USD = 5000 EGP');

        // Status & timestamps.
        $this->assertEquals('processed', $refund->status);
        $this->assertNotNull($refund->processed_at);
        $this->assertNotNull($refund->transaction_id, 'Refund posts an EGP-side expense transaction');

        // EGP cashbox should be back at the seeded amount (50000): +5000 from FX pay, -5000 refund.
        $this->assertEquals(50000.0, (float) $this->cashboxEgp->fresh()->balance);

        // Company debt fully reversed (we owed 2500 EGP from booking; cancel wiped it).
        $companyAccount = Account::find($company->account_id);
        $this->assertEquals(0.0, (float) $companyAccount->fresh()->balance);

        // Customer AR cleared by reverseCustomerSaleDebt.
        $this->assertEquals(0.0, (float) $customer->ledgerAccount->fresh()->balance);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5 — getAvailableInventories filter contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_get_available_inventories_service_filters_by_company_date_and_capacity(): void
    {
        // getAvailableInventories() is the bus booking form's source for
        // "available trips". Its filter contract must be pinned so the
        // Vue form never lists sold-out or past-date trips.
        $companyA = $this->makeBusCompany(['name' => 'A'], 0);
        $companyB = $this->makeBusCompany(['name' => 'B'], 0);

        $inventoryService = app(BusInventoryService::class);

        // Inventory #1: company A, future date, has seats → must appear.
        $future = $inventoryService->createInventory([
            'company_id' => $companyA->id,
            'route' => 'Future A',
            'travel_date' => now()->addDays(5)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        // Inventory #2: company A, future date, SOLD OUT → must NOT appear.
        $soldOut = $inventoryService->createInventory([
            'company_id' => $companyA->id,
            'route' => 'Sold Out A',
            'travel_date' => now()->addDays(7)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);
        $soldOut->update(['available_tickets' => 0]);

        // Inventory #3: company A, PAST date → must NOT appear.
        $inventoryService->createInventory([
            'company_id' => $companyA->id,
            'route' => 'Past A',
            'travel_date' => now()->subDays(2)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        // Inventory #4: company B, future date, has seats → must NOT appear
        // when filtering by company A.
        $inventoryService->createInventory([
            'company_id' => $companyB->id,
            'route' => 'Future B',
            'travel_date' => now()->addDays(3)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        // Filter window: from today onward.
        $result = $inventoryService->getAvailableInventories(
            $companyA->id,
            now()->toDateString()
        );

        // Only #1 (Future A, company A, has seats) passes the filters.
        $this->assertCount(1, $result);
        $this->assertEquals('Future A', $result->first()->route);
        $this->assertEquals($future->id, $result->first()->id);

        // Switching to company B → only #4.
        $resultB = $inventoryService->getAvailableInventories(
            $companyB->id,
            now()->toDateString()
        );
        $this->assertCount(1, $resultB);
        $this->assertEquals('Future B', $resultB->first()->route);
    }
}