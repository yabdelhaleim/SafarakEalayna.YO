<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * سلسلة CRUD حقيقية على API الباص (Sanctum) مع التحقق من شكل الـ JSON الموحّد.
 */
class BusApiCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Bus CRUD Tester',
            'email' => 'bus-crud-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_bus_api_full_crud_deferred_chain_and_json_shape(): void
    {
        $travelDate = now()->addDays(5)->toDateString();

        // --- Company: create
        $createCompany = $this->postJson('/api/v1/bus/companies', [
            'name' => 'شركة CRUD تجريبية',
            'phone' => '01001234567',
            'is_active' => true,
        ]);
        $createCompany->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Bus company created successfully.')
            ->assertJsonStructure(['data' => ['id', 'name', 'phone']]);
        $companyId = (int) $createCompany->json('data.id');

        // --- Company: index (paginated)
        $indexCompanies = $this->getJson('/api/v1/bus/companies?per_page=5');
        $indexCompanies->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
                ],
            ]);

        // --- Company: show
        $showCompany = $this->getJson("/api/v1/bus/companies/{$companyId}");
        $showCompany->assertOk()
            ->assertJsonPath('data.id', $companyId)
            ->assertJsonPath('data.name', 'شركة CRUD تجريبية');

        // --- Company: update
        $updateCompany = $this->putJson("/api/v1/bus/companies/{$companyId}", [
            'name' => 'شركة CRUD (محدّثة)',
            'notes' => 'ملاحظة من الاختبار',
        ]);
        $updateCompany->assertOk()
            ->assertJsonPath('data.name', 'شركة CRUD (محدّثة)');

        // --- Inventory: create (deferred — بدون حساب)
        $createInv = $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $companyId,
            'route' => 'القاهرة - الإسكندرية',
            'travel_date' => $travelDate,
            'departure_time' => '07:30',
            'total_tickets' => 20,
            'cost_per_ticket' => 40,
            'selling_price' => 90,
            'payment_type' => 'deferred',
            'notes' => 'رحلة تجريبية',
        ]);
        $createInv->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'route', 'selling_price', 'payment_type']]);
        $inventoryId = (int) $createInv->json('data.id');

        // --- Inventory: index
        $indexInv = $this->getJson("/api/v1/bus/inventories?company_id={$companyId}");
        $indexInv->assertOk()->assertJsonPath('success', true);

        // --- Inventory: show
        $showInv = $this->getJson("/api/v1/bus/inventories/{$inventoryId}");
        $showInv->assertOk()->assertJsonPath('data.id', $inventoryId);

        // --- Inventory: update
        $patchInv = $this->patchJson("/api/v1/bus/inventories/{$inventoryId}", [
            'selling_price' => 95,
            'notes' => 'سعر محدّث من API',
        ]);
        $patchInv->assertOk()
            ->assertJsonPath('data.selling_price', 95);

        // --- Booking: create
        $createBooking = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventoryId,
            'customer_name' => 'عميل CRUD',
            'customer_phone' => '01009998877',
            'quantity' => 2,
        ]);
        $createBooking->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'quantity', 'total_price']]);
        $bookingId = (int) $createBooking->json('data.id');

        // --- Booking: index
        $indexBookings = $this->getJson('/api/v1/bus/bookings?inventory_id='.$inventoryId);
        $indexBookings->assertOk()->assertJsonPath('success', true);

        // --- Booking: show
        $showBooking = $this->getJson("/api/v1/bus/bookings/{$bookingId}");
        $showBooking->assertOk()->assertJsonPath('data.id', $bookingId);

        // --- Booking: destroy (pending only)
        $delBooking = $this->deleteJson("/api/v1/bus/bookings/{$bookingId}");
        $delBooking->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Booking deleted successfully.');

        // --- Inventory: destroy (no bookings)
        $delInv = $this->deleteJson("/api/v1/bus/inventories/{$inventoryId}");
        $delInv->assertOk()
            ->assertJsonPath('success', true);

        // --- Company: destroy
        $delCompany = $this->deleteJson("/api/v1/bus/companies/{$companyId}");
        $delCompany->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Bus company deleted successfully.');
    }

    public function test_bus_inventory_cash_fails_without_balance_then_succeeds(): void
    {
        $account = Account::query()->create([
            'name' => 'خزينة فارغة',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $company = $this->postJson('/api/v1/bus/companies', [
            'name' => 'شركة نقدي',
            'phone' => '01001110000',
        ]);
        $company->assertCreated();
        $companyId = (int) $company->json('data.id');

        $travelDate = now()->addDays(7)->toDateString();

        $fail = $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $companyId,
            'route' => 'Test cash route',
            'travel_date' => $travelDate,
            'total_tickets' => 5,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => 'cash',
            'account_id' => $account->id,
        ]);
        $fail->assertStatus(422)
            ->assertJsonPath('success', false);

        LedgerBalanceMutationGuard::run(function () use ($account) {
            $account->update(['balance' => 10_000]);
        });

        $ok = $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $companyId,
            'route' => 'Test cash route OK',
            'travel_date' => $travelDate,
            'total_tickets' => 5,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => 'cash',
            'account_id' => $account->id,
        ]);
        $ok->assertCreated()->assertJsonPath('success', true);

        $inventoryId = (int) $ok->json('data.id');
        $this->deleteJson("/api/v1/bus/inventories/{$inventoryId}")->assertOk();
        $this->deleteJson("/api/v1/bus/companies/{$companyId}")->assertOk();
    }

    public function test_bus_customers_index_works_successfully(): void
    {
        $company = \App\Models\Bus\BusCompany::query()->create([
            'name' => 'Test Bus Company',
            'phone' => '01009876543',
            'is_active' => true,
        ]);

        $inventory = \App\Models\Bus\BusInventory::query()->create([
            'company_id' => $company->id,
            'route' => 'Cairo - Alexandria',
            'travel_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '10:00:00',
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 100,
            'total_cost' => 1000,
            'remaining_debt' => 1000,
            'selling_price' => 150,
            'payment_type' => 'deferred',
        ]);

        $customer = \App\Models\Customer::query()->create([
            'full_name' => 'John Doe Bus Customer',
            'phone' => '01111222333',
        ]);

        \App\Models\Bus\BusBooking::query()->create([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
            'unit_price' => 150,
            'total_price' => 300,
            'paid_amount' => 100,
            'profit' => 100,
            'status' => \App\Enums\BusBookingStatus::Pending,
            'payment_status' => \App\Enums\BusPaymentStatus::Partial,
        ]);

        $response = $this->getJson('/api/v1/bus/customers');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'customers' => [
                        'data' => [
                            '*' => [
                                'id',
                                'full_name',
                                'phone',
                                'total_bus_bookings',
                                'total_bus_amount',
                                'total_bus_paid',
                                'bus_remaining_debt',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertCount(1, $response->json('data.customers.data'));
        $this->assertEquals('John Doe Bus Customer', $response->json('data.customers.data.0.full_name'));
        $this->assertEquals(300, $response->json('data.customers.data.0.total_bus_amount'));
        $this->assertEquals(100, $response->json('data.customers.data.0.total_bus_paid'));
        $this->assertEquals(200, $response->json('data.customers.data.0.bus_remaining_debt'));
    }
}
