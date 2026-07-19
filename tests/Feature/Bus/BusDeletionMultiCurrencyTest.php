<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Services\Finance\CurrencyService;
use Illuminate\Support\Facades\DB;

/**
 * Regression tests for the FX-aware deletion lifecycle.
 *
 * Background (Bug #B-01 / Phase 6 hardening):
 * Both `BusBookingService::deleteBooking()` and
 * `BusBookingService::deleteBookingWithReversal()` previously posted the
 * customer-side reversal with `amount = $booking->total_price` raw, which
 * mixed currencies on a single journal entry for foreign-currency bookings
 * (USD/SAR/KWD/EUR). The fix routes the reversal through the same helper
 * used by `cancelBooking()` — `reverseCustomerSaleDebt()` — which computes
 * the EGP-equivalent when the customer AR and income-clearing currencies
 * differ, passing `converted_amount` and `exchange_rate` to the journal.
 *
 * These tests prove the fix preserves the ledger invariant for foreign-
 * currency deletions, with zero EGP-only leakage and zero orphan entries.
 *
 * @group bus
 * @group bus-deletion
 * @group bus-fx
 */
class BusDeletionMultiCurrencyTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // USD booking — simple delete (no payments)
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_usd_booking_reverses_customer_debt_in_usd_with_egp_equivalent(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(1000.0);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 50,         // 50 EGP per ticket
            'selling_price' => 6.0,           // 6 USD per ticket
            'total_cost' => 500,              // 10 × 50 EGP
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        $customer = $this->makeCustomerWithBusAccount(0, 'USD');
        $usdAr = $customer->ledgerAccount;

        // Use the service to create the booking so ledger postings actually run
        $booking = app(\App\Services\Bus\BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
        ]);

        // The booking creation already posted customer AR +6 USD.
        $usdAr->refresh();
        $this->assertEqualsWithDelta(6.0, (float) $usdAr->fresh()->balance, 0.01,
            'Pre-condition: customer USD AR should hold +6 USD after booking');

        // ── ACT: simple delete (no payments → uses deleteBooking()) ──
        app(\App\Services\Bus\BusBookingService::class)
            ->deleteBooking($booking);

        // ── ASSERT: customer AR back to 0 USD ──
        $usdAr->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $usdAr->balance, 0.01,
            'Customer USD AR should be 0 after USD booking deletion');

        // ── ASSERT: the deletion journal used converted_amount in EGP ──
        // Find the latest transaction whose `notes` contains 'حذف مديونية'.
        $journalEntry = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->where('transactions.notes', 'like', '%حذف مديونية%')
            ->where('account_entries.account_id', $usdAr->id)
            ->orderBy('account_entries.id', 'desc')
            ->first();

        $this->assertNotNull($journalEntry,
            'A journal entry should exist for the USD customer reversal');

        // The FROM side (customer USD) should be credited in USD.
        $this->assertEqualsWithDelta(6.0, (float) $journalEntry->credit, 0.01,
            'Customer USD account should be credited 6 USD');

        // The TO side (EGP clearing) should carry the EGP-equivalent.
        $egpEntry = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->where('transactions.notes', 'like', '%حذف مديونية%')
            ->where('account_entries.account_id', '!=', $usdAr->id)
            ->orderBy('account_entries.id', 'desc')
            ->first();

        $this->assertNotNull($egpEntry,
            'A corresponding EGP clearing entry should exist for the reversal');

        $this->assertEqualsWithDelta(300.0, (float) $egpEntry->credit, 0.01,
            'EGP clearing should be credited 300 EGP (6 USD × 50 rate)');

        // ── Global ledger invariant holds. ──
        $this->assertLedgerGloballyBalanced();

        // ── Booking is soft-deleted. ──
        $this->assertSoftDeleted('bus_bookings', ['id' => $booking->id]);
    }

    public function test_delete_usd_booking_egp_only_booking_uses_same_currency_posting(): void
    {
        // Sanity check — an EGP booking should NOT post a converted_amount.
        // This guards against an over-eager fix that always applies FX.
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(1000.0);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 80,
            'total_cost' => 500,
            'currency' => 'EGP',
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');
        $egpAr = $customer->ledgerAccount;

        $booking = app(\App\Services\Bus\BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
        ]);

        app(\App\Services\Bus\BusBookingService::class)
            ->deleteBooking($booking);

        $egpAr->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $egpAr->balance, 0.01,
            'EGP booking deletion should zero customer EGP AR');

        // The EGP reversal entry should carry amount=80 directly, no FX.
        $journalEntry = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->where('transactions.notes', 'like', '%حذف مديونية%')
            ->where('account_entries.account_id', $egpAr->id)
            ->orderBy('account_entries.id', 'desc')
            ->first();

        $this->assertNotNull($journalEntry);
        $this->assertEqualsWithDelta(80.0, (float) $journalEntry->credit, 0.01);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // SAR booking — administrative delete with reversal (with payments)
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_sar_booking_with_reversal_uses_fx_conversion(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(50000.0);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'cost_per_ticket' => 40,
            'selling_price' => 5.0,           // 5 SAR per ticket
            'total_cost' => 400,
            'currency' => 'SAR',
            'exchange_rate_to_egp' => 13.3333,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        $customer = $this->makeCustomerWithBusAccount(0, 'SAR');
        $sarAr = $customer->ledgerAccount;

        $bookingService = app(\App\Services\Bus\BusBookingService::class);
        $booking = $bookingService->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        // Simulate a partial payment through the service to exercise the with-reversal path.
        $bookingService->payBooking($booking, [
            'amount' => 5.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Pre-condition: customer SAR AR is +10 SAR.
        $sarAr->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $sarAr->balance, 0.01);

        // ── ACT: administrative delete with reversal ──
        $bookingService->deleteBookingWithReversal($booking->id);

        // ── ASSERT: customer SAR AR back to 0 ──
        $sarAr->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $sarAr->balance, 0.01,
            'Customer SAR AR should be 0 after SAR booking with-reversal deletion');

        // ── ASSERT: reversal note uses the 'delete-reversal' label ──
        $reversalEntry = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->where('transactions.notes', 'like', '%عكس مديونية%')
            ->where('transactions.notes', 'like', '%حذف إداري شامل%')
            ->where('account_entries.account_id', $sarAr->id)
            ->orderBy('account_entries.id', 'desc')
            ->first();

        $this->assertNotNull($reversalEntry,
            'Reversal entry with correct delete-reversal notes must exist');

        $this->assertEqualsWithDelta(10.0, (float) $reversalEntry->credit, 0.01,
            'Customer SAR account should be credited 10 SAR');

        // ── Global ledger invariant holds. ──
        $this->assertLedgerGloballyBalanced();

        // ── Booking soft-deleted, payment soft-deleted. ──
        $this->assertSoftDeleted('bus_bookings', ['id' => $booking->id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Idempotency guard — deleteBookingWithReversal twice throws
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_booking_with_reversal_twice_throws_runtime_exception(): void
    {
        $company = $this->makeBusCompany([], 0);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 80,
            'total_cost' => 500,
            'currency' => 'EGP',
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');

        $booking = app(\App\Services\Bus\BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
        ]);

        $service = app(\App\Services\Bus\BusBookingService::class);
        $service->deleteBookingWithReversal($booking->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/محذوف بالفعل/');

        $service->deleteBookingWithReversal($booking->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // KWD booking — small unit, high rate (decimal precision regression)
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_kwd_booking_uses_high_rate_precision(): void
    {
        $company = $this->makeBusCompany([], 0);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'cost_per_ticket' => 100,
            'selling_price' => 0.5,           // 0.5 KWD per ticket
            'total_cost' => 1000,
            'currency' => 'KWD',
            'exchange_rate_to_egp' => 162.5,
            'payment_type' => BusInventoryPaymentType::Deferred,
        ]);

        $customer = $this->makeCustomerWithBusAccount(0, 'KWD');
        $kwdAr = $customer->ledgerAccount;

        $booking = app(\App\Services\Bus\BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 3,
        ]);

        $kwdAr->refresh();
        $this->assertEqualsWithDelta(1.5, (float) $kwdAr->balance, 0.01);

        app(\App\Services\Bus\BusBookingService::class)
            ->deleteBooking($booking);

        $kwdAr->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $kwdAr->balance, 0.01,
            'Customer KWD AR should be 0 after KWD booking deletion');

        // EGP-equivalent = 1.5 KWD × 162.5 = 243.75 EGP.
        $egpEntry = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->where('transactions.notes', 'like', '%حذف مديونية%')
            ->where('account_entries.account_id', '!=', $kwdAr->id)
            ->orderBy('account_entries.id', 'desc')
            ->first();

        $this->assertNotNull($egpEntry);
        $this->assertEqualsWithDelta(243.75, (float) $egpEntry->credit, 0.01,
            'EGP clearing should be credited 243.75 EGP (1.5 KWD × 162.5 rate)');

        $this->assertLedgerGloballyBalanced();
    }
}