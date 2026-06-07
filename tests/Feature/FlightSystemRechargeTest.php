<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightSystemRechargeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected FlightSystem $system;
    protected Account $flightAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->system = FlightSystem::create([
            'name' => 'Amadeus Test System',
            'code' => 'AMATESTA',
            'type' => 'gds',
            'currency' => 'EGP',
            'balance' => 0.00,
            'credit_limit' => 0.00,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->flightAccount = Account::create([
            'name' => 'Flight Test Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 5000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_can_recharge_flight_system_successfully(): void
    {
        $response = $this->postJson("/api/v1/flight/treasury/systems/{$this->system->id}/recharge", [
            'from_account_id' => $this->flightAccount->id,
            'amount' => 1000.00,
            'notes' => 'Test recharge note',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم شحن رصيد نظام الحجز بنجاح.');

        $this->system->refresh();
        $this->flightAccount->refresh();

        $this->assertEquals(1000.00, (float) $this->system->balance);
        $this->assertEquals(4000.00, (float) $this->flightAccount->balance);

        $this->assertDatabaseHas('flight_system_transactions', [
            'flight_system_id' => $this->system->id,
            'amount' => 1000.00,
            'type' => 'credit',
        ]);
    }

    public function test_fails_recharge_if_account_belongs_to_other_module(): void
    {
        $officeAccount = Account::create([
            'name' => 'Office Test Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 5000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/flight/treasury/systems/{$this->system->id}/recharge", [
            'from_account_id' => $officeAccount->id,
            'amount' => 1000.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from_account_id']);
    }

    public function test_fails_recharge_if_currency_mismatch(): void
    {
        $usdFlightAccount = Account::create([
            'name' => 'USD Flight Cashbox',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 5000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/flight/treasury/systems/{$this->system->id}/recharge", [
            'from_account_id' => $usdFlightAccount->id,
            'amount' => 1000.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from_account_id']);
    }

    public function test_fails_recharge_if_insufficient_balance(): void
    {
        $lowBalanceAccount = Account::create([
            'name' => 'Low Balance Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/flight/treasury/systems/{$this->system->id}/recharge", [
            'from_account_id' => $lowBalanceAccount->id,
            'amount' => 500.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }
}
