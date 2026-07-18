<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for FlightCarrierController — Phase 3 coverage.
 *
 * The FlightCarrier recharge API was tested in Phase 1 (FlightCarrierRechargeServiceTest).
 * This file focuses on the rest of the controller endpoints:
 *   - CRUD (store, update, destroy)
 *   - balance endpoint (already covered in Phase 1 — covered again for clarity)
 *   - show endpoint (with relations)
 *
 * @see \App\Http\Controllers\Api\V1\Flight\FlightCarrierController
 */
class FlightCarrierControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Carrier Test Admin',
            'email' => 'carrier-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->cashbox = Account::create([
            'name' => 'Carrier Test Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // Prepaid GL للناقل
        Account::create([
            'name' => 'رصيد مسبق — ناقلو الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ 1) store creates new carrier with defaults
     */
    public function test_store_creates_carrier_with_defaults(): void
    {
        $payload = [
            'name' => 'Test Airline',
            'code' => 'TST'.uniqid(),
            'iata_code' => 'TA',
            'currency' => 'EGP',
            'credit_limit' => 5000,
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/flight/carriers', $payload);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'Test Airline')
            ->assertJsonPath('data.code', $payload['code'])
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('flight_carriers', [
            'code' => $payload['code'],
            'name' => 'Test Airline',
            'currency' => 'EGP',
        ]);
    }

    /**
     * ✅ 2) store rejects duplicate code (Phase 1 already covered service-level; this covers controller)
     */
    public function test_store_rejects_duplicate_carrier_code(): void
    {
        $code = 'DUP'.uniqid();

        FlightCarrier::create([
            'name' => 'First',
            'code' => $code,
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson('/api/v1/flight/carriers', [
            'name' => 'Duplicate',
            'code' => $code,
            'currency' => 'EGP',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * ✅ 3) show returns carrier with system + recent transactions
     */
    public function test_show_returns_carrier_with_relations(): void
    {
        $system = FlightSystem::create([
            'name' => 'Show Test System',
            'code' => 'STS'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $carrier = FlightCarrier::create([
            'flight_system_id' => $system->id,
            'name' => 'Show Test Carrier',
            'code' => 'SHC'.uniqid(),
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/v1/flight/carriers/{$carrier->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $carrier->id)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'system',
                    'groups',
                    'transactions',
                    'available_balance',
                ],
            ]);

        // الـ system لازم يكون موجود في الـ response
        $this->assertEquals('Show Test System', $response->json('data.system.name'));
    }

    /**
     * ✅ 4) update updates carrier fields
     */
    public function test_update_updates_carrier_fields(): void
    {
        $carrier = FlightCarrier::create([
            'name' => 'Before Update',
            'code' => 'BUU'.uniqid(),
            'currency' => 'EGP',
            'credit_limit' => 1000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson("/api/v1/flight/carriers/{$carrier->id}", [
            'name' => 'After Update',
            'credit_limit' => 5000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'After Update');

        $this->assertEquals(5000.0, (float) $response->json('data.credit_limit'));

        $carrier->refresh();
        $this->assertEquals('After Update', $carrier->name);
        $this->assertEquals(5000.0, (float) $carrier->credit_limit);
    }

    /**
     * ✅ 5) destroy soft-deletes the carrier
     */
    public function test_destroy_soft_deletes_carrier(): void
    {
        $carrier = FlightCarrier::create([
            'name' => 'To Delete',
            'code' => 'DEL'.uniqid(),
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->deleteJson("/api/v1/flight/carriers/{$carrier->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        // الـ carrier لازم يتشال من index
        $idxResponse = $this->getJson('/api/v1/flight/carriers');
        $this->assertEmpty(collect($idxResponse->json('data'))->where('id', $carrier->id)->all());

        // الـ row موجود with deleted_at
        $this->assertSoftDeleted('flight_carriers', ['id' => $carrier->id]);
    }
}
