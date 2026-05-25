<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\Treasury;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Employee $targetEmployee;

    protected Account $account;

    protected Customer $customer;

    protected Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        Employee::create(['user_id' => $this->user->id, 'status' => 'active']);
        Sanctum::actingAs($this->user);

        $this->targetEmployee = Employee::create([
            'user_id' => $this->user->id,
            'full_name' => 'Bonus Target',
            'phone' => '01000000001',
            'position' => 'Agent',
            'salary' => 5000,
            'hire_date' => now(),
            'status' => 'active',
        ]);

        $this->account = Account::create([
            'name' => 'Test Account',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $this->customer = Customer::factory()->create();

        $this->treasury = Treasury::create([
            'name' => 'Main Treasury',
            'currency' => 'EGP',
            'current_balance' => 100000,
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────
    // 1. Employee Bonuses
    // ──────────────────────────────────────────────

    public function test_employee_bonus_creation(): void
    {
        $response = $this->postJson('/api/v1/employee/bonuses/bonus', [
            'employee_id' => $this->targetEmployee->id,
            'amount' => 500,
            'reason' => 'Performance bonus',
            'account_id' => $this->account->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Bonus added successfully.');
    }

    public function test_employee_deduction_creation(): void
    {
        $response = $this->postJson('/api/v1/employee/bonuses/deduction', [
            'employee_id' => $this->targetEmployee->id,
            'amount' => 200,
            'reason' => 'Late attendance',
            'account_id' => $this->account->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Deduction added successfully.');
    }

    public function test_employee_draw_creation(): void
    {
        $response = $this->postJson('/api/v1/employee/bonuses/draw', [
            'employee_id' => $this->targetEmployee->id,
            'amount' => 1000,
            'reason' => 'Salary advance',
            'account_id' => $this->account->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Salary draw added successfully.');
    }

    public function test_employee_bonus_list(): void
    {
        $this->postJson('/api/v1/employee/bonuses/bonus', [
            'employee_id' => $this->targetEmployee->id,
            'amount' => 300,
            'reason' => 'Test bonus',
            'account_id' => $this->account->id,
        ]);

        $response = $this->getJson('/api/v1/employee/bonuses?per_page=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
                ],
            ]);
    }

    // ──────────────────────────────────────────────
    // 2. Bus Refunds
    // ──────────────────────────────────────────────

    public function test_bus_refund_flow(): void
    {
        $travelDate = now()->addDays(5)->toDateString();

        $company = $this->postJson('/api/v1/bus/companies', [
            'name' => 'Refund Test Company',
            'phone' => '01001112233',
            'is_active' => true,
        ]);
        $company->assertCreated()->assertJsonPath('success', true);
        $companyId = (int) $company->json('data.id');

        $inventory = $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $companyId,
            'route' => 'Cairo - Alexandria',
            'travel_date' => $travelDate,
            'departure_time' => '08:00',
            'total_tickets' => 20,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => 'deferred',
        ]);
        $inventory->assertCreated()->assertJsonPath('success', true);
        $inventoryId = (int) $inventory->json('data.id');

        $booking = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventoryId,
            'customer_name' => 'Refund Customer',
            'customer_phone' => '01009998877',
            'quantity' => 2,
        ]);
        $booking->assertCreated()->assertJsonPath('success', true);
        $bookingId = (int) $booking->json('data.id');

        $refund = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $bookingId,
            'destination' => 'agency_treasury',
            'treasury_id' => $this->treasury->id,
            'refund_type' => 'cash_to_agency',
            'notes' => 'Test refund',
        ]);
        $refund->assertStatus(201)->assertJsonPath('success', true);
        $refundId = (int) $refund->json('data.id');

        $process = $this->postJson("/api/v1/bus/refunds/{$refundId}/process");
        $process->assertOk()->assertJsonPath('success', true);
    }

    // ──────────────────────────────────────────────
    // 3. Invoices CRUD
    // ──────────────────────────────────────────────

    public function test_invoice_crud(): void
    {
        $create = $this->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'notes' => 'Test invoice',
            'items' => [
                [
                    'description' => 'Service fee',
                    'quantity' => 1,
                    'unit_price' => 1500,
                ],
            ],
        ]);
        $create->assertStatus(201)->assertJsonPath('success', true);
        $invoiceId = (int) $create->json('data.id');

        $index = $this->getJson('/api/v1/invoices?per_page=10');
        $index->assertOk()->assertJsonPath('success', true);

        $show = $this->getJson("/api/v1/invoices/{$invoiceId}");
        $show->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.id', $invoiceId);

        $update = $this->putJson("/api/v1/invoices/{$invoiceId}", [
            'notes' => 'Updated notes',
        ]);
        $update->assertOk()->assertJsonPath('success', true);

        $delete = $this->deleteJson("/api/v1/invoices/{$invoiceId}");
        $delete->assertOk()->assertJsonPath('success', true);
    }

    // ──────────────────────────────────────────────
    // 4. Hajj/Umra Bookings & Payments
    // ──────────────────────────────────────────────

    public function test_hajj_umra_booking_and_payment(): void
    {
        DB::table('programs')->insert([
            'program_name' => 'Test Hajj Program',
            'program_type' => 'hajj',
            'total_nights' => 14,
            'accommodation_type' => 'double',
            'mecca_hotel_name' => 'Mecca Hotel',
            'mecca_nights' => 10,
            'medina_hotel_name' => 'Medina Hotel',
            'medina_nights' => 4,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDays(14)->toDateString(),
            'airline' => 'Saudi Airlines',
            'executing_company' => 'Test Company',
            'departure_point' => 'Cairo',
        ]);
        $programId = (int) DB::getPdo()->lastInsertId();

        $account = Account::create([
            'name' => 'Hajj Account',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $booking = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $programId,
            'purchase_price' => 8000,
            'selling_price' => 10000,
            'account_id' => $account->id,
        ]);
        $booking->assertStatus(201)->assertJsonPath('success', true);
        $bookingId = (int) $booking->json('data.id');

        $payment = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $account->id,
            'notes' => 'First installment',
        ]);
        $payment->assertStatus(201)->assertJsonPath('success', true);
    }

    // ──────────────────────────────────────────────
    // 5. Visa Bookings & Payments
    // ──────────────────────────────────────────────

    public function test_visa_booking_and_payment(): void
    {
        $account = Account::create([
            'name' => 'Visa Account',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $booking = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $this->customer->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'Saudi Arabia',
            ],
            'purchase_price' => 2000,
            'selling_price' => 3000,
            'account_id' => $account->id,
        ]);
        $booking->assertStatus(201)->assertJsonPath('success', true);
        $bookingId = (int) $booking->json('data.id');

        $payment = $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 1500,
            'payment_method' => 'cash',
            'account_id' => $account->id,
        ]);
        $payment->assertStatus(201)->assertJsonPath('success', true);
    }
}
