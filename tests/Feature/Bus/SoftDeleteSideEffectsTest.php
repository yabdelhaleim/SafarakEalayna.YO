<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusInventoryService;

/**
 * Soft-delete side-effects tests for the Bus module.
 *
 * What this covers:
 *   1. `deleteBookingWithReversal` → ledger stays balanced at every step.
 *   2. After booking soft-delete, the associated BusRefundRequest is
 *      STILL queryable (audit trail preserved).
 *   3. After BusInventory soft-delete, the `bookings` relation still
 *      returns the soft-deleted booking (Laravel's default `hasMany`
 *      does NOT filter by parent soft-delete — only by child soft-delete).
 *
 * The booking flow we drive:
 *   seed → book → pay → cancel (creates BusRefundRequest) → delete
 * Each step must keep `assertLedgerGloballyBalanced()` green AND must
 * leave the audit trail (refund request row) intact.
 */
class SoftDeleteSideEffectsTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — Full lifecycle: book → pay → cancel → delete
    // ─────────────────────────────────────────────────────────────────────

    public function test_full_lifecycle_book_pay_cancel_delete_keeps_ledger_balanced_and_refund_auditable(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);

        // ── Step 1: Book 2 tickets ─────────────────────────────────────
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Lifecycle Customer',
            'customer_phone' => '01000001000',
            'quantity' => 2,
        ]);

        $this->assertEquals(8, (int) $inventory->fresh()->available_tickets);
        $this->assertLedgerGloballyBalanced();

        // ── Step 2: Pay full 240 EGP ──────────────────────────────────
        $service->payBooking($booking, [
            'amount' => 240.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->assertEquals(240.0, (float) $booking->fresh()->paid_amount);
        $this->assertLedgerGloballyBalanced();

        // ── Step 3: Cancel with zero penalty → BusRefundRequest created ──
        $refund = $service->cancelBooking($booking->fresh(), [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->assertEquals(120.0, (float) $refund->refund_amount, 'No penalty → full refund');
        $this->assertEquals(240.0, (float) $refund->original_amount);
        $this->assertEquals('EGP', $refund->original_currency);

        // Capacity restored by the cancel.
        $this->assertEquals(10, (int) $inventory->fresh()->available_tickets);

        // Cashbox back to seed (full refund).
        $this->assertEquals(10000.0, (float) $this->cashboxEgp->fresh()->balance);

        $this->assertLedgerGloballyBalanced();

        // ── Step 4: Admin delete with full reversal ────────────────────
        $service->deleteBookingWithReversal($booking->id);

        // Booking is soft-deleted.
        $this->assertNotNull(BusBooking::withTrashed()->find($booking->id)->deleted_at);

        // Standard query (without trashed) excludes the booking.
        $this->assertNull(BusBooking::find($booking->id));

        // REFUND REQUEST IS STILL THERE — audit trail preserved.
        $refundAfterDelete = BusRefundRequest::find($refund->id);
        $this->assertNotNull($refundAfterDelete, 'GAP: BusRefundRequest should NOT be soft-deleted by deleteBookingWithReversal (audit trail contract)');
        $this->assertEquals(120.0, (float) $refundAfterDelete->refund_amount);
        $this->assertEquals('EGP', $refundAfterDelete->original_currency);
        $this->assertNull($refundAfterDelete->deleted_at);

        // The booking ↔ refund relation survives (withTrashed side).
        $bookingWithRefund = BusBooking::withTrashed()->with('refundRequests')->find($booking->id);
        $this->assertCount(1, $bookingWithRefund->refundRequests);

        // Ledger STILL balanced after the full reversal cycle.
        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — deleteBookingWithReversal on a paid (un-cancelled) booking
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_paid_booking_with_reversal_restores_cash_and_clears_all_ar(): void
    {
        // Pay-only → delete (no cancel in between). The reversal path
        // must still restore the cashbox balance AND clear the customer
        // AR — even when there's no prior BusRefundRequest.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Pay Then Delete',
            'customer_phone' => '01000001001',
            'quantity' => 1,
        ]);

        $service->payBooking($booking, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Cashbox went from 10000 → 10240 (120 EGP received).
        $this->assertEquals(10240.0, (float) $this->cashboxEgp->fresh()->balance);
        // Customer AR holds 120 EGP debt.
        $this->assertEquals(120.0, (float) $customer->ledgerAccount->fresh()->balance);

        // Soft-delete the booking with full reversal (no prior cancel).
        $service->deleteBookingWithReversal($booking->id);

        // Booking is gone (default scope).
        $this->assertNull(BusBooking::find($booking->id));

        // Capacity restored.
        $this->assertEquals(10, (int) $inventory->fresh()->available_tickets);

        // Cashbox back to the seed (the 120 EGP payment was reversed).
        $this->assertEquals(10000.0, (float) $this->cashboxEgp->fresh()->balance);

        // Customer AR fully cleared.
        $this->assertEquals(0.0, (float) $customer->ledgerAccount->fresh()->balance);

        // Company AP cleared (we no longer owe them).
        $companyAccount = Account::find($company->account_id);
        $this->assertEquals(0.0, (float) $companyAccount->fresh()->balance);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — Soft-deleting the inventory: bookings relation survives
    // ─────────────────────────────────────────────────────────────────────

    public function test_soft_deleted_inventory_bookings_relation_still_returns_associated_bookings(): void
    {
        // Laravel's default `hasMany` relationship does NOT filter by
        // the PARENT's soft-delete. So if we soft-delete a BusInventory,
        // calling `$inventory->bookings` still returns its bookings
        // (active AND soft-deleted).
        //
        // This is the contract the audit dashboard relies on: a deleted
        // inventory's full history must remain visible.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'Inventory Delete Test',
            'customer_phone' => '01000001002',
            'quantity' => 2,
        ]);

        // Soft-delete the inventory.
        $inventory->delete();
        $this->assertNotNull(BusInventory::withTrashed()->find($inventory->id)->deleted_at);

        // Relation still returns the booking.
        $inventoryTrashed = BusInventory::withTrashed()->find($inventory->id);
        $this->assertCount(1, $inventoryTrashed->bookings, 'Booking must remain visible via the parent relation');
        $this->assertEquals($booking->id, $inventoryTrashed->bookings->first()->id);

        // Capacity is still queryable directly.
        $this->assertEquals(3, (int) $inventoryTrashed->available_tickets, 'available_tickets preserved on soft-deleted inventory');

        // For comparison: soft-deleting the BOOKING (not the inventory)
        // does NOT exclude it from the relation — Laravel's hasMany
        // doesn't auto-filter the child side either.
        $booking->delete();
        $this->assertCount(1, $inventoryTrashed->bookings()->withTrashed()->get(), 'withTrashed() on the relation still returns the soft-deleted booking');
    }
}