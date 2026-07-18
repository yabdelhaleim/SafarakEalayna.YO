<?php

namespace Tests\Feature\Flight;

use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for FlightSystemController — Phase 3 coverage.
 *
 * ⚠️ FlightSystemController (81 سطر) — صفر تغطية:
 *   - index: returns active systems (with SoftDeletes handling)
 *   - store: creates new system (validation + auto defaults)
 *   - show: returns system + carriers
 *   - update: updates fields
 *   - destroy: deletes system
 *
 * @see \App\Http\Controllers\Api\V1\Flight\FlightSystemController
 */
class FlightSystemControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'System Test Admin',
            'email' => 'system-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    /**
     * ✅ 1) index returns active systems sorted by name
     */
    public function test_index_returns_active_systems_sorted_by_name(): void
    {
        FlightSystem::create([
            'name' => 'Zeta System',
            'code' => 'ZTA-'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);
        FlightSystem::create([
            'name' => 'Alpha System',
            'code' => 'ALP-'.uniqid(),
            'type' => 'ndc',
            'is_active' => true,
            'currency' => 'SAR',
            'created_by' => $this->admin->id,
        ]);
        FlightSystem::create([
            'name' => 'Inactive System',
            'code' => 'INA-'.uniqid(),
            'type' => 'gds',
            'is_active' => false,  // ← فلتر
            'currency' => 'USD',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/flight/systems');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data');  // فقط الـ 2 النشط

        // الترتيب أبجدي بالـ name
        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertEquals(['Alpha System', 'Zeta System'], $names,
            'Systems must be sorted alphabetically by name');
    }

    /**
     * ✅ 2) store creates system with auto-defaults
     */
    public function test_store_creates_system_with_auto_defaults(): void
    {
        $payload = [
            'name' => 'New Test System',
            'code' => 'NTS-'.uniqid(),
            'type' => 'manual',
            'currency' => 'EGP',
        ];

        $response = $this->postJson('/api/v1/flight/systems', $payload);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'New Test System')
            ->assertJsonPath('data.is_active', true);

        // الـ balance مش في fillable → يفضل null أو ما يتبعتش في الـ response
        // المهم إن الـ system اتنشأ — نتأكد من الـ DB
        $this->assertDatabaseHas('flight_systems', [
            'name' => 'New Test System',
            'code' => $payload['code'],
            'is_active' => 1,
        ]);
    }

    /**
     * ✅ 3) store rejects duplicate code
     */
    public function test_store_rejects_duplicate_code(): void
    {
        $code = 'DUP'.uniqid();

        FlightSystem::create([
            'name' => 'First System',
            'code' => $code,
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson('/api/v1/flight/systems', [
            'name' => 'Second System',
            'code' => $code,  // ← مكرر
            'currency' => 'EGP',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /**
     * ✅ 4) show returns system + carriers relation
     */
    public function test_show_returns_system_with_carriers_loaded(): void
    {
        $system = FlightSystem::create([
            'name' => 'System With Carriers',
            'code' => 'SWC'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        \App\Models\Flight\FlightCarrier::create([
            'flight_system_id' => $system->id,
            'name' => 'Carrier A',
            'code' => 'CA'.uniqid(),
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/v1/flight/systems/{$system->id}");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $system->id)
            ->assertJsonStructure(['data' => ['carriers']]);

        $carrierNames = collect($response->json('data.carriers'))->pluck('name')->toArray();
        $this->assertContains('Carrier A', $carrierNames);
    }

    /**
     * ✅ 5) destroy soft-deletes the system
     */
    public function test_destroy_soft_deletes_system(): void
    {
        $system = FlightSystem::create([
            'name' => 'To Be Deleted',
            'code' => 'TBD'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->deleteJson("/api/v1/flight/systems/{$system->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        // الـ system لازم يتشال من الـ index (is_active doesn't matter — SoftDeletes)
        $idxResponse = $this->getJson('/api/v1/flight/systems');
        $this->assertEmpty(collect($idxResponse->json('data'))->where('id', $system->id)->all(),
            'Soft-deleted system must not appear in index');

        // لكن موجود in DB (with deleted_at)
        $this->assertSoftDeleted('flight_systems', ['id' => $system->id]);
    }
}
