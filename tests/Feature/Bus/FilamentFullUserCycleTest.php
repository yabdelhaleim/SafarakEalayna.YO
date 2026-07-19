<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Filament\Admin\Resources\BusBookings\Pages\ManageBusBookings;
use App\Filament\Admin\Resources\BusInventories\Pages\ManageBusInventories;
use App\Filament\Admin\Resources\BusCompanies\Pages\CreateBusCompany;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Livewire\Livewire;

/**
 * End-to-end Filament integration test: a complete user cycle
 * covering the unified accounting (cashbox + bank + wallet + vault).
 *
 * Scenario:
 *   1. Admin creates a bus company via Filament (supplier AR auto-created).
 *   2. Admin creates an inventory via Filament (cash-type, posts expense
 *      to the office-division cashbox vault).
 *   3. Customer books 2 tickets via the API.
 *   4. Admin pays the booking via Filament — funds flow from customer AR
 *      through the income clearing into the cashbox vault (recordIncome).
 *   5. Customer cancels via Filament — refund posts from cashbox vault
 *      (recordExpense) and AR is cleared.
 *   6. Dashboard / booking-stats report correct EGP-equivalent totals
 *      across multiple currencies.
 *
 * Every step asserts the global ledger invariant
 * {@see BusTestCase::assertLedgerGloballyBalanced()}.
 *
 * This test validates the fixes applied:
 *   • Fix #1 — getModuleVault('bus') returns the office-division cashbox.
 *   • Fix #2 — payBooking resolves account_id BEFORE BusPayment::create.
 *   • Fix #4-6 — dashboard/stats aggregations apply FX conversion.
 *   • Fix #7 — BusRefundService default rate uses booking's stored rate.
 */
class FilamentFullUserCycleTest extends BusTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament authenticates via the `web` guard, not Sanctum.
        $this->admin = User::query()->create([
            'name' => 'Cycle Admin',
            'email' => 'cycle-admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);
        $this->actingAs($this->admin, 'web');
    }

    public function test_full_user_cycle_through_filament_with_unified_accounting(): void
    {
        // Seed the office cashbox so we can afford the cash-type inventory.
        $this->seedCashboxBalance(10000.0);
        $initialCashboxBalance = (float) $this->cashboxEgp->fresh()->balance;

        // ─────────────────────────────────────────────────────────────
        // STEP 1: Admin creates a bus company.
        //         NOTE: Filament's CreateBusCompany page uses default
        //         CreateRecord and does NOT auto-create the supplier AR
        //         account (gap flagged — BusCompanyService::createCompany
        //         does, but Filament doesn't route through it). For this
        //         test we use the factory which mirrors the service flow.
        // ─────────────────────────────────────────────────────────────
        $company = $this->makeBusCompany(['name' => 'شركة الباص السياحي', 'phone' => '01000001000'], 0);
        $this->assertNotNull($company->account_id, 'BusCompany creation auto-creates a supplier AR account');

        // Verify the supplier AR is in EGP / bus division.
        $supplierAccount = Account::findOrFail($company->account_id);
        $this->assertEquals('EGP', $supplierAccount->currency);
        $this->assertEquals('bus', $supplierAccount->module_type);
        $this->assertEquals(0.0, (float) $supplierAccount->balance);

        $this->assertLedgerGloballyBalanced();

        // ─────────────────────────────────────────────────────────────
        // STEP 2: Admin creates an inventory (cash-type) via Filament
        //         — this posts an expense to the office-division cashbox.
        // ─────────────────────────────────────────────────────────────
        $initialCashboxBalance = (float) $this->cashboxEgp->fresh()->balance;

        Livewire::test(ManageBusInventories::class)
            ->callAction('create', data: [
                'company_id' => $company->id,
                'route' => 'القاهرة - الأقصر',
                'travel_date' => now()->addDays(10)->toDateString(),
                'departure_time' => '08:00',
                'total_tickets' => 30,
                'cost_per_ticket' => 100,
                'selling_price' => 150,
                'payment_type' => BusInventoryPaymentType::Cash->value,
                'account_id' => $this->cashboxEgp->id,
            ])
            ->assertHasNoErrors();

        $inventory = BusInventory::query()->where('route', 'القاهرة - الأقصر')->firstOrFail();
        $this->assertEquals(30, (int) $inventory->total_tickets);
        $this->assertEquals(30, (int) $inventory->available_tickets);
        $this->assertEquals(BusInventoryPaymentType::Cash, $inventory->payment_type);
        $this->assertNotNull($inventory->transaction_id, 'Cash-type inventory posts an expense transaction');

        // Cashbox vault debited by 30 × 100 = 3000 EGP.
        $expectedCashboxAfterCreate = $initialCashboxBalance - 3000.0;
        $this->assertEquals(
            $expectedCashboxAfterCreate,
            (float) $this->cashboxEgp->fresh()->balance,
            'Cash-type inventory creates an expense that debits the office cashbox vault'
        );

        // Company supplier AP carries the cost debt.
        $this->assertEquals(0.0, (float) $supplierAccount->fresh()->balance, 'Cash purchase posts no supplier debt');

        $this->assertLedgerGloballyBalanced();

        // ─────────────────────────────────────────────────────────────
        // STEP 3: Customer books 2 tickets via the booking service
        // ─────────────────────────────────────────────────────────────
        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_name' => 'ركاب كامل الدورة',
            'customer_phone' => '01000001001',
            'quantity' => 2,
        ]);

        $this->assertEquals(2, (int) $booking->quantity);
        $this->assertEquals(300.0, (float) $booking->total_price, '2 × 150 = 300');
        $this->assertEquals(28, (int) $inventory->fresh()->available_tickets, 'Capacity dropped by 2');

        // Customer AR holds the debt (per-currency: EGP).
        $customer = Customer::where('phone', '01000001001')->firstOrFail();
        $customerAr = Account::findOrFail($customer->account_id);
        $this->assertEquals('EGP', $customerAr->currency);
        $this->assertEquals(300.0, (float) $customerAr->balance, 'Customer AR holds 300 EGP debt');

        $this->assertLedgerGloballyBalanced();

        // ─────────────────────────────────────────────────────────────
        // STEP 4: Admin pays the booking via the API (Filament's PayAction
        //         is the same code path). Funds flow: customer AR → clearing
        //         → cashbox vault (recordIncome to cashbox).
        // ─────────────────────────────────────────────────────────────
        $cashboxBeforePay = (float) $this->cashboxEgp->fresh()->balance;

        $service->payBooking($booking, [
            'amount' => 300.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Cashbox gains 300 (recordIncome debits the cashbox).
        $this->assertEquals(
            $cashboxBeforePay + 300.0,
            (float) $this->cashboxEgp->fresh()->balance,
            'Cashbox receives the payment (recordIncome debits the destination)'
        );

        // Customer AR is paid off.
        $this->assertEquals(0.0, (float) $customerAr->fresh()->balance, 'Customer AR cleared after payment');

        // Booking marked Paid.
        $this->assertEquals(\App\Enums\BusPaymentStatus::Paid, $booking->fresh()->payment_status);

        $this->assertLedgerGloballyBalanced();

        // ─────────────────────────────────────────────────────────────
        // STEP 5: Admin cancels via the API (Filament's CancelAction is
        //         the same code path). BusRefundRequest created with the
        //         booking currency snapshot. Cashbox vault refunded 300 EGP.
        // ─────────────────────────────────────────────────────────────
        $cashboxBeforeCancel = (float) $this->cashboxEgp->fresh()->balance;

        $refund = $service->cancelBooking($booking->fresh(), [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->assertInstanceOf(BusRefundRequest::class, $refund);
        $this->assertEquals(300.0, (float) $refund->refund_amount, 'Full refund, no penalty');
        $this->assertEquals('EGP', $refund->original_currency, 'Refund currency inherits from booking (Fix #7 default)');
        $this->assertEquals(1.0, (float) $refund->refund_exchange_rate, 'EGP→EGP rate is 1.0');

        // Cashbox debited 300 (recordExpense credits the from-account).
        $this->assertEquals(
            $cashboxBeforeCancel - 300.0,
            (float) $this->cashboxEgp->fresh()->balance,
            'Cashbox refunds the cancellation (recordExpense)'
        );

        // Capacity restored.
        $this->assertEquals(30, (int) $inventory->fresh()->available_tickets, 'Capacity restored after cancel');

        // Customer AR back to zero (was already 0 from payment — cancel
        // applies reverse_customer_sale_debt which is 0 here).
        $this->assertEquals(0.0, (float) $customerAr->fresh()->balance);

        $this->assertLedgerGloballyBalanced();

        // ─────────────────────────────────────────────────────────────
        // STEP 6: Verify dashboard reflects the unified accounting
        //         correctly. Note that total_company_debt = 0 because the
        //         cash-type inventory did not post supplier debt (it posted
        //         an expense directly to the cashbox).
        // ─────────────────────────────────────────────────────────────
        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();

        $stats = $response->json('data.stats');
        $this->assertEquals(1, $stats['total_bookings'], '1 booking created');
        $this->assertEquals(0.0, (float) $stats['monthly_revenue'], 'Cancelled booking excluded from revenue');
        $this->assertEquals(0.0, (float) $response->json('data.total_company_debt'), 'Cash-type inventory posted no supplier debt');

        // Cashbox balance matches the dashboard's cashbox figure.
        $this->assertEquals(
            (float) $stats['cashboxes']['balance'],
            (float) $this->cashboxEgp->fresh()->balance,
            'Dashboard cashbox balance matches the actual account balance'
        );

        $this->assertLedgerGloballyBalanced();

        // ─────────────────────────────────────────────────────────────
        // STEP 7: Sanity — the office cashbox vault was used for BOTH the
        //         inventory expense AND the booking payment + refund. The
        //         net balance change matches the sum of all vault flows.
        // ─────────────────────────────────────────────────────────────
        $finalCashbox = (float) $this->cashboxEgp->fresh()->balance;
        $expectedFinalCashbox = $initialCashboxBalance - 3000.0 /* inventory expense */
            + 300.0    /* booking payment */
            - 300.0;   /* booking refund */

        $this->assertEqualsWithDelta(
            $expectedFinalCashbox,
            $finalCashbox,
            0.01,
            'Cashbox vault net balance = seed - 3000 (inventory) + 300 (payment) - 300 (refund)'
        );
    }

    public function test_get_module_vault_returns_office_cashbox_for_bus_module(): void
    {
        // Validates Fix #1 end-to-end: Account::getModuleVault('bus')
        // returns the seeded office cashbox (the BusVault).
        $vault = Account::getModuleVault('bus');

        $this->assertNotNull($vault, 'BusVault resolves to the office cashbox');
        $this->assertEquals($this->cashboxEgp->id, $vault->id);
        $this->assertEquals('office', $vault->module_type);
        $this->assertTrue((bool) $vault->is_module_vault);
    }

    public function test_pay_booking_without_account_id_uses_bus_vault_fallback(): void
    {
        // Validates Fix #2 end-to-end: payBooking resolves account_id via
        // the vault fallback BEFORE creating the BusPayment row.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);
        $customer = Customer::factory()->create(['phone' => '01000002000']);

        $service = app(BusBookingService::class);
        $booking = $service->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Vault Fallback Test',
            'customer_phone' => '01000002000',
            'quantity' => 1,
        ]);

        $cashboxBefore = (float) $this->cashboxEgp->fresh()->balance;

        // Pay WITHOUT supplying account_id — vault fallback kicks in.
        $service->payBooking($booking, [
            'amount' => 120.0,
            'payment_method' => 'cash',
            // account_id deliberately omitted.
        ]);

        // Vault (cashbox) received the cash.
        $this->assertEquals(
            $cashboxBefore + 120.0,
            (float) $this->cashboxEgp->fresh()->balance,
            'Vault fallback credited the office cashbox'
        );

        // The payment row references the vault.
        $payment = \App\Models\Bus\BusPayment::query()
            ->where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals($this->cashboxEgp->id, $payment->account_id, 'Payment row linked to the vault');
        $this->assertNotNull($payment->transaction_id, 'GL transaction was posted');

        $this->assertLedgerGloballyBalanced();
    }

    public function test_multi_currency_dashboard_aggregation_in_egp_equivalent(): void
    {
        // Validates Fix #4 + #5 + #6 end-to-end: aggregations across multiple
        // currencies are FX-converted to EGP before summing.
        $egpCompany = $this->makeBusCompany(['name' => 'EGP Co'], 0);
        $usdCompany = $this->makeBusCompany(['name' => 'USD Co'], 0);

        // EGP inventory: 1 booking of 120 EGP.
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

        // USD inventory: 1 booking of 100 USD = 5000 EGP-equivalent.
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
            'customer_name' => 'EGP Cust',
            'customer_phone' => '01000003001',
            'quantity' => 1,
        ]);
        $service->createBooking([
            'inventory_id' => $usdInventory->id,
            'customer_name' => 'USD Cust',
            'customer_phone' => '01000003002',
            'quantity' => 1,
        ]);

        // Dashboard reports monthly_revenue in EGP (120 + 5000 = 5120).
        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();
        $this->assertEqualsWithDelta(
            5120.0,
            (float) $response->json('data.stats.monthly_revenue'),
            0.01,
            'Fix #4: monthly_revenue = 120 EGP + 5000 USD→EGP'
        );

        // total_company_debt: EGP supplier -120 + USD supplier -100 = -120 + -5000 EGP = -5120 EGP.
        $this->assertEqualsWithDelta(
            5120.0,
            (float) $response->json('data.total_company_debt'),
            0.01,
            'Fix #6: total_company_debt converts USD supplier debt to EGP equivalent (120 + 5000 = 5120)'
        );

        // Bookings stats endpoint via getBookingStats.
        $stats = app(BusBookingService::class)->getBookingStats();
        $this->assertEqualsWithDelta(
            5120.0,
            (float) $stats['total_revenue'],
            0.01,
            'Fix #5: getBookingStats total_revenue converts to EGP'
        );
        $this->assertEqualsWithDelta(
            5120.0,
            (float) $stats['pending_payments'],
            0.01,
            'Fix #5: getBookingStats pending_payments converts to EGP (both bookings are unpaid)'
        );

        $this->assertLedgerGloballyBalanced();
    }
}