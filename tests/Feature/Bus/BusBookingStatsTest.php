<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;

/**
 * Tests for BusBookingService::getBookingStats().
 *
 * Covers Bug #5 — previously `total_revenue` and `pending_payments` did a raw
 * SUM(total_price) across all currencies, silently mixing USD/SAR amounts with
 * EGP. Fix: group by currency, convert each group to EGP via CurrencyService.
 */
class BusBookingStatsTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // 1 — EGP-only bookings: stats are a simple sum, no FX involved
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stats_with_egp_only_bookings(): void
    {
        $service = app(BusBookingService::class);

        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 20,
            'available_tickets' => 20,
            'cost_per_ticket'   => 50,
            'selling_price'     => 100,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        $c1 = Customer::factory()->create(['phone' => '01000100001']);
        $c2 = Customer::factory()->create(['phone' => '01000100002']);

        $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $c1->id,
            'customer_name'  => 'Ahmed',
            'customer_phone' => '01000100001',
            'quantity'       => 2,
        ]);
        $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $c2->id,
            'customer_name'  => 'Sara',
            'customer_phone' => '01000100002',
            'quantity'       => 1,
        ]);

        $stats = $service->getBookingStats();

        $this->assertEquals(2, $stats['total_bookings']);
        $this->assertEquals(2, $stats['pending_bookings']);
        $this->assertEquals(0, $stats['paid_bookings']);
        // 2 tickets x 100 EGP + 1 ticket x 100 EGP = 300 EGP
        $this->assertEqualsWithDelta(300.0, $stats['total_revenue'], 0.01, 'EGP-only revenue');
        $this->assertEqualsWithDelta(300.0, $stats['pending_payments'], 0.01, 'all pending');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — Mixed EGP + USD bookings: revenue converts USD to EGP before summing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stats_mixed_egp_and_usd_converts_correctly(): void
    {
        $service = app(BusBookingService::class);

        // EGP booking: 120 EGP x 1
        $companyEgp = $this->makeBusCompany([], 0);
        $invEgp = $this->makeInventory([
            'company_id'        => $companyEgp->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 60,
            'selling_price'     => 120,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        // USD booking: 10 USD x 1. BusTestCase seeds 1 USD = 50 EGP.
        $companyUsd = $this->makeBusCompany([], 0);
        $invUsd = $this->makeInventory([
            'company_id'           => $companyUsd->id,
            'total_tickets'        => 10,
            'available_tickets'    => 10,
            'cost_per_ticket'      => 5,
            'selling_price'        => 10,
            'payment_type'         => BusInventoryPaymentType::Deferred->value,
            'currency'             => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        $cEgp = Customer::factory()->create(['phone' => '01000200001']);
        $cUsd = Customer::factory()->create(['phone' => '01000200002']);

        $service->createBooking([
            'inventory_id'   => $invEgp->id,
            'customer_id'    => $cEgp->id,
            'customer_name'  => 'Khaled EGP',
            'customer_phone' => '01000200001',
            'quantity'       => 1,
        ]);
        $service->createBooking([
            'inventory_id'   => $invUsd->id,
            'customer_id'    => $cUsd->id,
            'customer_name'  => 'Khaled USD',
            'customer_phone' => '01000200002',
            'quantity'       => 1,
        ]);

        $stats = $service->getBookingStats();

        // Before fix: 120 + 10 = 130 (wrong — currencies mixed).
        // After fix:  120 EGP + (10 USD x 50) = 620 EGP.
        $this->assertGreaterThan(130.0, $stats['total_revenue'],
            'Revenue must exceed the naive raw-sum (130) — FX conversion must be applied');
        $this->assertEqualsWithDelta(620.0, $stats['total_revenue'], 1.0,
            'Mixed revenue: 120 EGP + 10 USD@50 = 620 EGP-equivalent');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — Cancelled bookings are excluded from total_revenue
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cancelled_bookings_excluded_from_revenue(): void
    {
        $service = app(BusBookingService::class);

        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 40,
            'selling_price'     => 80,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        $c1 = Customer::factory()->create(['phone' => '01000300001']);
        $c2 = Customer::factory()->create(['phone' => '01000300002']);

        $booking1 = $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $c1->id,
            'customer_name'  => 'Active',
            'customer_phone' => '01000300001',
            'quantity'       => 1,
        ]);
        $booking2 = $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $c2->id,
            'customer_name'  => 'Cancelled',
            'customer_phone' => '01000300002',
            'quantity'       => 1,
        ]);

        // Fix: cancelBooking takes BusBooking model instance, not ID
        $service->cancelBooking($booking2);

        $stats = $service->getBookingStats();

        $this->assertEquals(2, $stats['total_bookings']);
        $this->assertEquals(1, $stats['cancelled_bookings']);
        $this->assertEqualsWithDelta(80.0, $stats['total_revenue'], 0.01,
            'Cancelled booking must not contribute to total_revenue');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 — pending_payments excludes fully-paid bookings
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pending_payments_excludes_fully_paid(): void
    {
        $service = app(BusBookingService::class);
        $this->seedCashboxBalance(50000);

        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 30,
            'selling_price'     => 60,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);

        $c1 = Customer::factory()->create(['phone' => '01000400001']);
        $c2 = Customer::factory()->create(['phone' => '01000400002']);

        $booking1 = $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $c1->id,
            'customer_name'  => 'Paid',
            'customer_phone' => '01000400001',
            'quantity'       => 1,
        ]);
        $service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $c2->id,
            'customer_name'  => 'Unpaid',
            'customer_phone' => '01000400002',
            'quantity'       => 1,
        ]);

        $service->payBooking($booking1, [
            'amount'         => 60.0,
            'payment_method' => 'cash',
            'account_id'     => $this->cashboxEgp->id,
        ]);

        $stats = $service->getBookingStats();

        $this->assertEqualsWithDelta(60.0, $stats['pending_payments'], 0.01,
            'Only the unpaid booking (60 EGP) should remain as pending');
    }
}
