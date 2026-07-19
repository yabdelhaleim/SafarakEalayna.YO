<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Dashboard / Statement currency-correctness tests.
 *
 * What this covers:
 *   1. Dashboard `monthly_revenue` aggregates EGP-only bookings correctly.
 *   2. Dashboard `monthly_revenue` does NOT properly FX-convert foreign-
 *      currency bookings today (GAP — pinned here so a future fix surfaces).
 *   3. Dashboard `total_company_debt` reflects EGP supplier balance only —
 *      a foreign-currency supplier would corrupt the figure today.
 *   4. Customer statement is per-currency (per-account) — no currency
 *      mixing at the statement layer.
 *
 * Complements {@see DashboardTest} which covers the dashboard happy path
 * with EGP-only data.
 */
class DashboardStatementCurrencyTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — Dashboard monthly_revenue baseline (EGP only)
    // ─────────────────────────────────────────────────────────────────────

    public function test_dashboard_monthly_revenue_matches_sum_of_egp_bookings(): void
    {
        // Three EGP bookings of 120 each → monthly_revenue should be 360.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $service = app(BusBookingService::class);
        for ($i = 1; $i <= 3; $i++) {
            $service->createBooking([
                'inventory_id' => $inventory->id,
                'customer_name' => "EGP Customer $i",
                'customer_phone' => sprintf('010000007%02d', $i),
                'quantity' => 1,
            ]);
        }

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();

        $this->assertEqualsWithDelta(
            360.0,
            (float) $response->json('data.stats.monthly_revenue'),
            0.01,
            '3 × 120 EGP = 360'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — Dashboard monthly_revenue mixes currencies (BUG pinned)
    // ─────────────────────────────────────────────────────────────────────

    public function test_dashboard_monthly_revenue_mixes_currencies_as_known_bug(): void
    {
        // KNOWN BUG — pinned here. The dashboard's monthly_revenue sums
        // the raw `total_price` column across bookings WITHOUT applying FX
        // conversion. When a USD booking is posted, the dashboard reports
        // its `total_price` (100) as if it were EGP, mixing currencies.
        //
        // What SHOULD happen (post-fix): each booking should be converted
        // to EGP via CurrencyService::convert() before summing.
        //
        // For now this test PASSES by pinning the current (broken) sum.
        // When the fix lands, this test should be flipped to assert
        // monthly_revenue === 120 (EGP) + 5000 (USD→EGP) = 5120.

        $egpCompany = $this->makeBusCompany(['name' => 'EGP Co'], 0);
        $usdCompany = $this->makeBusCompany(['name' => 'USD Co'], 0);

        $egpInventory = $this->makeInventory([
            'company_id' => $egpCompany->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);
        $usdInventory = $this->makeInventory([
            'company_id' => $usdCompany->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        $service = app(BusBookingService::class);
        $service->createBooking([
            'inventory_id' => $egpInventory->id,
            'customer_name' => 'EGP Customer',
            'customer_phone' => '01000000800',
            'quantity' => 1,
        ]);
        $service->createBooking([
            'inventory_id' => $usdInventory->id,
            'customer_name' => 'USD Customer',
            'customer_phone' => '01000000801',
            'quantity' => 1,
        ]);

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();

        // BUG: dashboard sums raw values → 120 + 100 = 220 (mixes currencies).
        // Expected after fix: 120 (EGP) + 5000 (USD→EGP) = 5120.
        $this->assertEqualsWithDelta(
            220.0,
            (float) $response->json('data.stats.monthly_revenue'),
            0.01,
            'BUG: monthly_revenue sums raw total_price (120 EGP + 100 USD = 220, currencies mixed). '
            .'Fix: apply CurrencyService::convert() before summing.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — Dashboard total_company_debt (EGP supplier)
    // ─────────────────────────────────────────────────────────────────────

    public function test_dashboard_total_company_debt_reflects_egp_supplier_balance(): void
    {
        // Baseline: one EGP booking generates a 100 EGP supplier debt
        // (we owe the company). Dashboard should report 100.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'Debt Test',
            'customer_phone' => '01000000900',
            'quantity' => 1,
        ]);

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();

        $this->assertEqualsWithDelta(
            100.0,
            (float) $response->json('data.total_company_debt'),
            0.5,
            '1 × 100 EGP supplier debt'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4 — Customer statement is per-currency (per-account)
    // ─────────────────────────────────────────────────────────────────────

    public function test_customer_statement_returns_per_currency_items_with_currency_metadata(): void
    {
        // The customer statement uses AccountService::getAccountStatement()
        // which is per-ACCOUNT — each customer-AR account has a single
        // currency, so the statement is inherently per-currency. The
        // items list is ordered by entry creation time and each item is
        // denominated in the account's currency (no mixing).
        //
        // We book a USD trip to ensure the customer has a USD AR account,
        // then verify the statement is non-empty AND that the account's
        // currency is preserved on the response.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'Statement Customer',
            'customer_phone' => '01000000901',
            'quantity' => 1,
        ]);

        $customer = $booking->customer;
        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEquals('USD', $customerAccount->currency);

        $response = $this->getJson("/api/v1/customers/{$customer->id}/statement");
        $response->assertOk();

        // The statement stats must be denominated in the account's currency.
        $stats = $response->json('data.stats');
        $this->assertEquals(
            (float) $customerAccount->balance,
            (float) $stats['closing_balance'],
            'Customer statement closing_balance matches the USD account balance (100 USD)'
        );
        $this->assertGreaterThan(0, (float) $stats['period_debit'], 'The USD sale generated at least one debit entry');

        // The items list is non-empty (at least the booking sale entry).
        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertGreaterThan(0, count($items), 'Customer statement should have at least one item (the booking sale)');

        // No EGP figures appear in the USD account's statement
        // (the figure 100.0 USD is the booking's total_price; if the
        // dashboard mistakenly summed 100 into a 120 EGP booking we'd
        // see 220, but the statement is per-account so it stays clean).
        $this->assertLessThan(
            150.0,
            (float) $stats['closing_balance'],
            'Per-currency contract: USD account closing balance must stay under 150 (not mixed with EGP)'
        );
    }
}