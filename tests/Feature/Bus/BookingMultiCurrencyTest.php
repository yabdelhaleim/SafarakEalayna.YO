<?php

namespace Tests\Feature\Bus;

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Services\Finance\CurrencyService;

/**
 * Multi-currency booking scenarios.
 *
 * Establishes the contract for USD/SAR/KWD bookings:
 *
 *   1. Inventory `currency` & `exchange_rate_to_egp` are mirrored to booking.
 *   2. Customer AR account is created in booking currency (not always EGP).
 *   3. Company debt is always posted in EGP (the operating currency).
 *   4. Income-clearing offset is recorded in EGP (system-wide pivot).
 *   5. Refund-currency mismatches with the original booking are tolerated
 *      only when a converting `converted_amount` is supplied.
 *
 * LEDGER INVARIANT — asserted after every test:
 *   for every Account: balance == SUM(account_entries.debit) - SUM(credit).
 *
 * NB: All multi-currency tests rely on `BusTestCase::setUp()` which seeds:
 *   - cashboxEgp (EGP, 100k)
 *   - bankEgp   (EGP, 250k)
 *   - walletEgp (EGP, 50k)
 *   - walletUsd (USD, 5k)
 *   - 50.0 USD→EGP, 13.33 SAR→EGP, 162.5 KWD→EGP exchange rates
 */
class BookingMultiCurrencyTest extends BusTestCase
{
    public function test_egp_booking_keeps_all_accounts_in_egp(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'EGP Customer',
            'customer_phone' => '01070000001',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals('EGP', $booking->currency);

        $customer = \App\Models\Customer::where('phone', '01070000001')->firstOrFail();
        $this->assertEquals('EGP', $customer->ledgerAccount->currency);
    }

    public function test_usd_booking_mirrors_currency_to_booking_and_customer(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'cost_per_ticket' => 2.00,    // ~100 EGP @ 50/USD
            'selling_price' => 3.00,      // ~150 EGP @ 50/USD
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
            'total_tickets' => 5,
            'available_tickets' => 5,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'USD Customer',
            'customer_phone' => '01070000002',
            'quantity' => 2,
        ]);
        $response->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals('USD', $booking->currency);
        $this->assertEqualsWithDelta(50.0, (float) $booking->exchange_rate_to_egp, 0.001);

        // 2 × $3 = $6 USD, customer AR reflects USD price.
        $this->assertEqualsWithDelta(6.0, (float) $booking->total_price, 0.01);

        $customer = \App\Models\Customer::where('phone', '01070000002')->firstOrFail();
        $this->assertEquals('USD', $customer->ledgerAccount->currency);
        $this->assertEqualsWithDelta(6.0, (float) $customer->ledgerAccount->fresh()->balance, 0.01);
    }

    public function test_sar_booking_with_egp_company_debt_converts_correctly(): void
    {
        $company = $this->makeBusCompany([], 0);
        // 1 SAR ≈ 13.33 EGP. Cost 50 SAR → 666.5 EGP. Selling 80 SAR → 1066.4 EGP.
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'cost_per_ticket' => 50.00,
            'selling_price' => 80.00,
            'currency' => 'SAR',
            'exchange_rate_to_egp' => 13.3333,
            'total_tickets' => 3,
            'available_tickets' => 3,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'SAR Customer',
            'customer_phone' => '01070000003',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals('SAR', $booking->currency);

        // 2 × 80 SAR = 160 SAR (booking currency).
        $this->assertEqualsWithDelta(160.0, (float) $booking->total_price, 0.01);

        // Company debt should be posted in EGP (the operating currency).
        // 2 × 50 SAR × 13.3333 = 1333.33 EGP.
        $company->refresh();
        $this->assertEqualsWithDelta(
            -1333.33,
            (float) $company->account->fresh()->balance,
            0.05
        );

        $this->assertLedgerGloballyBalanced();
    }

    public function test_kwd_booking_handles_small_unit_with_high_rate(): void
    {
        // KWD is ~162.5 EGP per 1 KWD. Small numbers (1-5 KWD) result in large EGP equivalents.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'cost_per_ticket' => 1.00,    // 162.5 EGP per ticket
            'selling_price' => 1.50,      // 243.75 EGP per ticket
            'currency' => 'KWD',
            'exchange_rate_to_egp' => 162.5,
            'total_tickets' => 5,
            'available_tickets' => 5,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'KWD Customer',
            'customer_phone' => '01070000004',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEquals('KWD', $booking->currency);

        // 2 × 1.5 KWD = 3.0 KWD.
        $this->assertEqualsWithDelta(3.0, (float) $booking->total_price, 0.01);

        // Company debt in EGP: 2 × 1 × 162.5 = 325 EGP.
        $company->refresh();
        $this->assertEqualsWithDelta(-325.0, (float) $company->account->fresh()->balance, 0.05);

        // Inventory available decremented correctly (in KWD-units).
        $inventory->refresh();
        $this->assertEquals(3, $inventory->available_tickets);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_multi_currency_booking_passes_convert_via_currency_service(): void
    {
        // Confirms that the conversion helper inside the service uses the seeded FX rates.
        $cs = app(CurrencyService::class);
        $usdToEgp = $cs->convert(100.0, 'USD', 'EGP');
        $sarToEgp = $cs->convert(100.0, 'SAR', 'EGP');

        $this->assertEqualsWithDelta(5000.0, $usdToEgp['to_amount'], 0.01);
        $this->assertEqualsWithDelta(50.0, $usdToEgp['rate'], 0.001);
        $this->assertEqualsWithDelta(1333.33, $sarToEgp['to_amount'], 0.05);
    }
}
