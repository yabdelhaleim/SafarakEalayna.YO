<?php

namespace Tests\Feature\Bus;

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\ExchangeRate;
use Database\Factories\Bus\BusCompanyFactory;
use Database\Factories\Bus\BusInventoryFactory;

/**
 * Smoke test for the Bus module test infrastructure (factories + BusTestCase).
 *
 * Confirms:
 *   1. BusTestCase seeds clearing accounts, exchange rates, and a unified cashbox/bank/wallet.
 *   2. The BusCompanyFactory / BusInventoryFactory produce well-formed rows.
 *   3. assertLedgerGloballyBalanced() works on a fresh DB (no entries ⇒ trivially true).
 *
 * If this test fails, the whole Bus test suite is broken — fix BusTestCase first.
 */
class BusInfrastructureSmokeTest extends BusTestCase
{
    public function test_clearing_accounts_are_seeded(): void
    {
        $this->assertNotNull($this->busIncomeClearing->id);
        $this->assertNotNull($this->busExpenseClearing->id);
        $this->assertEquals('إقفال إيرادات الباصات', $this->busIncomeClearing->name);
        $this->assertEquals('إقفال تكاليف الباصات', $this->busExpenseClearing->name);
    }

    public function test_exchange_rates_are_seeded(): void
    {
        $usdToEgp = ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'EGP')
            ->where('is_active', true)
            ->orderByDesc('effective_date')
            ->first();

        $this->assertNotNull($usdToEgp, 'USD→EGP rate should be seeded');
        $this->assertEquals(50.0, (float) $usdToEgp->rate);
    }

    public function test_unified_liquidity_accounts_are_seeded(): void
    {
        $this->assertEquals('office', $this->cashboxEgp->module_type);
        $this->assertEquals('EGP', $this->cashboxEgp->currency);
        $this->assertEquals('office', $this->bankEgp->module_type);
        $this->assertEquals('USD', $this->walletUsd->currency);
        $this->assertEquals('office', $this->walletUsd->module_type);
    }

    public function test_bus_company_factory_creates_company(): void
    {
        $company = BusCompanyFactory::new()->create();

        $this->assertInstanceOf(BusCompany::class, $company);
        $this->assertNotEmpty($company->name);
        $this->assertTrue($company->is_active);
    }

    public function test_bus_company_factory_with_account_creates_supplier_account(): void
    {
        $company = BusCompanyFactory::new()->withAccount(1500.0)->create();

        $this->assertNotNull($company->account_id);
        $this->assertEquals(1500.0, (float) $company->account->fresh()->balance);
        $this->assertEquals('bus', $company->account->module_type);
        $this->assertEquals('supplier', $company->account->type->value);
    }

    public function test_bus_inventory_factory_creates_inventory(): void
    {
        $inv = BusInventoryFactory::new()->create();

        $this->assertInstanceOf(BusInventory::class, $inv);
        $this->assertNotNull($inv->route);
        $this->assertGreaterThan(0, $inv->total_tickets);
    }

    public function test_bus_inventory_factory_sold_out_state(): void
    {
        $inv = BusInventoryFactory::new()->soldOut()->create();
        $this->assertEquals(0, $inv->available_tickets);
    }

    public function test_fresh_database_has_balanced_ledger(): void
    {
        // After seeding, every account should be in lockstep with its entries.
        // With zero entries, balance must already be at the seeded opening balance.
        $this->assertLedgerGloballyBalanced();
    }

    public function test_assert_account_balance_helper_works(): void
    {
        // BusTestCase now seeds liquidity accounts at ZERO balance to avoid the
        // opening-balance / ledger-entry mismatch that was failing the global
        // invariant. The helper is symmetric: balance vs. balance.
        $this->assertAccountBalance($this->cashboxEgp, 0.0);
    }

    public function test_helper_make_bus_company(): void
    {
        $company = $this->makeBusCompany([], 2500.0);

        $this->assertEquals(2500.0, (float) $company->account->fresh()->balance);
    }

    public function test_helper_make_customer_with_bus_account(): void
    {
        $customer = $this->makeCustomerWithBusAccount(750.0);

        $this->assertNotNull($customer->account_id);
        $this->assertEquals(750.0, (float) $customer->ledgerAccount->fresh()->balance);
    }

    public function test_helper_make_bus_bank(): void
    {
        $bank = $this->makeBusBank(50000.0);

        $this->assertEquals(50000.0, (float) $bank->fresh()->balance);
        $this->assertEquals('office', $bank->module_type);
    }

    public function test_helper_convert_uses_seeded_rates(): void
    {
        $result = $this->convert(100.0, 'USD', 'EGP');
        $this->assertEquals(5000.0, round($result['to_amount'], 2));
        $this->assertEquals(50.0, $result['rate']);
    }
}
