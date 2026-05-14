<?php

namespace Tests\Feature;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusBookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected BusInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Bus API Tester',
            'email' => 'bus-api-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $this->account = Account::query()->create([
            'name' => 'Test cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $company = BusCompany::query()->create([
            'name' => 'Test Bus Co',
            'phone' => '01000000000',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->inventory = BusInventory::query()->create([
            'company_id' => $company->id,
            'route' => 'Cairo - Alexandria',
            'travel_date' => now()->addDay()->toDateString(),
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 500,
            'amount_paid' => 0,
            'remaining_debt' => 500,
            'created_by' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_can_create_booking_cancel_without_payment_and_cannot_cancel_after_payment(): void
    {
        $create = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => '01001112233',
            'quantity' => 1,
        ]);

        $create->assertCreated();
        $bookingId = $create->json('data.id');
        $this->assertNotNull($bookingId);

        $cancel = $this->postJson("/api/v1/bus/bookings/{$bookingId}/cancel");
        $cancel->assertOk();
        $cancel->assertJsonPath('data.status', 'cancelled');

        $booking2 = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل ثانٍ',
            'customer_phone' => '01002223344',
            'quantity' => 1,
        ]);
        $booking2->assertCreated();
        $id2 = $booking2->json('data.id');

        $pay = $this->postJson("/api/v1/bus/bookings/{$id2}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $pay->assertOk();

        $cancelPaid = $this->postJson("/api/v1/bus/bookings/{$id2}/cancel");
        $cancelPaid->assertStatus(422);
        $this->assertStringContainsString('payments', strtolower($cancelPaid->json('message')));
    }

    public function test_payment_amount_cannot_exceed_remaining(): void
    {
        $create = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل جزئي',
            'customer_phone' => '01003334455',
            'quantity' => 1,
        ]);
        $create->assertCreated();
        $bookingId = $create->json('data.id');

        $over = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $over->assertStatus(422);

        $partial = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 40,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $partial->assertOk();

        $rest = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 60,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $rest->assertOk();
        $rest->assertJsonPath('data.status', 'paid');

        $this->assertSame(100.0, (float) BusBooking::query()->findOrFail($bookingId)->paid_amount);
    }
}
