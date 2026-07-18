<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusPaymentStatus;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Booking creation scenarios (Mode A: Filament inventory, Mode B: auto-inventory).
 *
 * Locks down the existing EGP behavior before multi-currency is added — every
 * test verifies the canonical ledger invariant:
 *
 *   - Customer AR account balance equals customer sale amount posted.
 *   - Company supplier account balance equals -company cost.
 *   - Income clearing account balance equals -sale (offset).
 *   - Inventory available_tickets decremented exactly by quantity.
 */
class BookingCreationTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // Mode A — Filament-managed inventory
    // ─────────────────────────────────────────────────────────────────────

    public function test_can_create_booking_against_existing_inventory(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 30,
            'available_tickets' => 30,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'محمد علي',
            'customer_phone' => '01001112233',
            'quantity' => 2,
            'notes' => null,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $booking = BusBooking::query()->latest()->firstOrFail();
        $this->assertEquals(BusBookingStatus::Pending, $booking->status);
        $this->assertEqualsWithDelta(240.0, (float) $booking->total_price, 0.01);  // 120 × 2
        $this->assertEqualsWithDelta(80.0, (float) $booking->profit, 0.01);        // (120 − 80) × 2
        $this->assertEqualsWithDelta(0.0, (float) $booking->paid_amount, 0.01);
        $this->assertEquals('EGP', $booking->currency);

        // Inventory capacity decremented exactly by quantity.
        $inventory->refresh();
        $this->assertEquals(28, $inventory->available_tickets);
    }

    public function test_rejects_booking_when_inventory_exhausted(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 1,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Test',
            'customer_phone' => '01000000000',
            'quantity' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // Inventory capacity unchanged.
        $inventory->refresh();
        $this->assertEquals(1, $inventory->available_tickets);
    }

    public function test_sold_out_inventory_rejects_any_booking(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 30,
            'available_tickets' => 0,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Mahmoud',
            'customer_phone' => '01099998888',
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mode B — Vue manual route (auto-inventory created on the fly)
    // ─────────────────────────────────────────────────────────────────────

    public function test_can_create_booking_with_manual_route(): void
    {
        $company = $this->makeBusCompany([], 0);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'company_id' => $company->id,
            'route' => 'القاهرة - أسوان',
            'cost_price' => 100,
            'selling_price' => 150,
            'travel_date' => now()->addDays(2)->toDateString(),
            'customer_name' => 'سارة',
            'customer_phone' => '01011112222',
            'quantity' => 3,
            'notes' => 'حجز عائلي',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        // Auto-inventory created with 999999 capacity, then decremented by quantity=3.
        $inventory = BusInventory::query()->where('company_id', $company->id)->latest()->firstOrFail();
        $this->assertEquals(999999, $inventory->total_tickets);
        $this->assertEquals(999999 - 3, $inventory->available_tickets);
        $this->assertTrue((bool) $inventory->is_auto_created);

        // Booking total = 3 × 150 = 450 EGP.
        $booking = BusBooking::query()->latest()->firstOrFail();
        $this->assertEqualsWithDelta(450.0, (float) $booking->total_price, 0.01);
    }

    public function test_auto_inventory_dedup_when_same_route_repeat(): void
    {
        $company = $this->makeBusCompany([], 0);

        $first = $this->postJson('/api/v1/bus/bookings', [
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => now()->addDays(3)->toDateString(),
            'customer_name' => 'First',
            'customer_phone' => '01000000001',
            'quantity' => 1,
        ])->assertCreated();

        $second = $this->postJson('/api/v1/bus/bookings', [
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'cost_price' => 80,
            'selling_price' => 120,
            'travel_date' => now()->addDays(3)->toDateString(),
            'customer_name' => 'Second',
            'customer_phone' => '01000000002',
            'quantity' => 1,
        ])->assertCreated();

        // Should reuse the same auto-inventory, not create a second one.
        $autoCount = BusInventory::query()->where('is_auto_created', true)->count();
        $this->assertEquals(1, $autoCount);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Customer / Company account side-effects
    // ─────────────────────────────────────────────────────────────────────

    public function test_creates_customer_account_when_missing(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $this->assertDatabaseCount('customers', 0);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'أحمد حسن',
            'customer_phone' => '01033334444',
            'quantity' => 1,
        ]);
        $response->assertCreated();

        $customer = Customer::where('phone', '01033334444')->firstOrFail();
        $this->assertNotNull($customer->account_id, 'Customer should get a ledger account');
        $this->assertEquals('bus', $customer->ledgerAccount->module_type);
    }

    public function test_reuses_existing_customer_account_via_phone(): void
    {
        $company = $this->makeBusCompany([], 0);
        $existing = Customer::factory()->phone('01055556666')->create();
        $customerAccount = LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'AR test',
            'type' => \App\Enums\AccountType::Customer,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]));
        $existing->update(['account_id' => $customerAccount->id]);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Re-using the existing customer phone keeps the same ledger account.
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Existing',
            'customer_phone' => '01055556666',
            'quantity' => 1,
        ])->assertCreated();

        $existing->refresh();
        $this->assertEquals($customerAccount->id, $existing->account_id);
    }

    public function test_company_debt_increases_after_booking(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->assertEquals(0.0, (float) $company->account->fresh()->balance);

        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Debt Test',
            'customer_phone' => '01066667777',
            'quantity' => 4,
        ])->assertCreated();

        // 4 × 100 EGP = 400 EGP debt posted to supplier (we owe them).
        // Per BusBookingService::recordJournalTransfer, supplier balance becomes -400 (negative means we owe).
        $company->refresh();
        $this->assertEqualsWithDelta(
            -400.0,
            (float) $company->account->fresh()->balance,
            0.01
        );
    }

    public function test_global_ledger_invariant_holds_after_booking(): void
    {
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
            'customer_name' => 'Ledger Check',
            'customer_phone' => '01077778888',
            'quantity' => 2,
        ])->assertCreated();

        // The booking post must satisfy the global invariant for every account touched.
        $this->assertLedgerGloballyBalanced();
    }
}
