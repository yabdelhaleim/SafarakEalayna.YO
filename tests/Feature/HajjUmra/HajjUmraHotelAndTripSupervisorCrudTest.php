<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\TripSupervisor;
use App\Models\Program;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10.10 — Hotel & TripSupervisor CRUD + Reference Coverage.
 *
 * These two master-data entities are referenced by programs (and indirectly
 * by bookings) but their CRUD paths were not directly tested before. This
 * suite covers:
 *   - Hotel model creation/retrieval/relationship
 *   - TripSupervisor model creation/retrieval/relationship
 *   - Reference settings endpoints (already partially covered elsewhere but
 *     re-asserted here with new entities)
 *   - Defensive: bookings must still work when hotel is null
 *
 * The /api/v1/hajj-umra/settings/trip-supervisors endpoint is the canonical
 * read path; create/update/delete for TripSupervisor is via Filament (not
 * API), so we test the model directly + the read endpoint.
 *
 * There is no /api/v1/hajj-umra/settings/hotels endpoint — Hotels live in
 * the Hotel model but are wired via the Program's mecca_hotel_id /
 * medina_hotel_id FKs (set via Program create/update).
 */
class HajjUmraHotelAndTripSupervisorCrudTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Hotel TS Admin',
            'email' => 'hotel-ts-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    /* =========================================================
     *  Hotel — model CRUD
     * ========================================================= */

    public function test_create_hotel_then_retrieve_persists_all_fields(): void
    {
        $hotel = Hotel::query()->create([
            'name' => 'فندق مكة الكبير',
            'city' => 'مكة',
            'country' => 'SA',
            'stars' => 5,
            'price_per_night' => 850.00,
            'total_rooms' => 200,
            'available_rooms' => 200,
            'phone' => '+966125550000',
            'email' => 'mecca@hotel.test',
            'is_active' => true,
            'amenities' => ['wifi', 'breakfast'],
        ]);

        $this->assertDatabaseHas('hotels', [
            'id' => $hotel->id,
            'name' => 'فندق مكة الكبير',
            'city' => 'مكة',
            'stars' => 5,
        ]);

        $found = Hotel::findOrFail($hotel->id);
        $this->assertSame('فندق مكة الكبير', $found->name);
        $this->assertSame(5, $found->stars);
        $this->assertSame(['wifi', 'breakfast'], $found->amenities);
    }

    public function test_program_links_to_hotel_via_mecca_hotel_id_and_persists_relation(): void
    {
        $hotel = Hotel::query()->create([
            'name' => 'فندق المدينة',
            'city' => 'المدينة',
            'country' => 'SA',
            'stars' => 4,
            'is_active' => true,
        ]);

        $program = Program::query()->create([
            'program_name' => 'Hotel-Link Program',
            'program_type' => 'umra',
            'total_nights' => 7,
            'mecca_nights' => 4,
            'medina_nights' => 3,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_id' => $hotel->id,
            'mecca_hotel_name' => $hotel->name,
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(37)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'Test EC',
            'departure_point' => 'CAI',
            'default_selling_price' => 30000.00,
            'default_purchase_price' => 25000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $program->refresh();

        $this->assertSame($hotel->id, $program->mecca_hotel_id);
        $this->assertSame($hotel->id, (int) $program->meccaHotel->id);
        $this->assertSame('فندق المدينة', $program->meccaHotel->name);
    }

    /* =========================================================
     *  TripSupervisor — model CRUD + settings endpoint
     * ========================================================= */

    public function test_create_trip_supervisor_then_retrieve_persists_all_fields(): void
    {
        $sup = TripSupervisor::query()->create([
            'full_name' => 'أحمد المشرف',
            'phone' => '01012345678',
            'national_id' => '29012345678901',
            'is_active' => true,
            'notes' => 'مشرف رحلات مكة',
        ]);

        $this->assertDatabaseHas('trip_supervisors', [
            'id' => $sup->id,
            'full_name' => 'أحمد المشرف',
            'phone' => '01012345678',
        ]);

        $found = TripSupervisor::findOrFail($sup->id);
        $this->assertSame('أحمد المشرف', $found->full_name);
        $this->assertTrue($found->is_active);
    }

    public function test_trip_supervisor_appears_in_settings_endpoint_active_only(): void
    {
        // 2 active + 1 inactive
        TripSupervisor::query()->create(['full_name' => 'Active 1', 'is_active' => true]);
        TripSupervisor::query()->create(['full_name' => 'Active 2', 'is_active' => true]);
        TripSupervisor::query()->create(['full_name' => 'Inactive', 'is_active' => false]);

        $response = $this->getJson('/api/v1/hajj-umra/settings/trip-supervisors');
        $response->assertOk();

        $items = $response->json('data') ?? [];
        $names = array_column($items, 'full_name');

        $this->assertContains('Active 1', $names);
        $this->assertContains('Active 2', $names);
        $this->assertNotContains('Inactive', $names,
            'inactive trip supervisors must be excluded from settings endpoint');
    }

    public function test_program_links_to_trip_supervisor_via_id_and_persists_relation(): void
    {
        $sup = TripSupervisor::query()->create([
            'full_name' => 'محمد المشرف',
            'is_active' => true,
        ]);

        $program = Program::query()->create([
            'program_name' => 'TS-Link Program',
            'program_type' => 'hajj',
            'total_nights' => 14,
            'mecca_nights' => 8,
            'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة',
            'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'Test EC',
            'trip_supervisor_id' => $sup->id,
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $program->refresh();

        $this->assertSame($sup->id, $program->trip_supervisor_id);
        $this->assertSame($sup->id, (int) $program->tripSupervisor->id);
        $this->assertSame('محمد المشرف', $program->tripSupervisor->full_name);
    }

    public function test_settings_programs_endpoint_returns_nested_hotel_and_supervisor_labels(): void
    {
        $hotel = Hotel::query()->create([
            'name' => 'فندق الإعدادات',
            'city' => 'مكة',
            'is_active' => true,
        ]);
        $sup = TripSupervisor::query()->create([
            'full_name' => 'مشرف الإعدادات',
            'is_active' => true,
        ]);

        $program = Program::query()->create([
            'program_name' => 'Settings Program',
            'program_type' => 'umra',
            'total_nights' => 7,
            'mecca_nights' => 4,
            'medina_nights' => 3,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_id' => $hotel->id,
            'mecca_hotel_name' => $hotel->name,
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(37)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'Test EC',
            'trip_supervisor_id' => $sup->id,
            'departure_point' => 'CAI',
            'default_selling_price' => 30000.00,
            'default_purchase_price' => 25000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/hajj-umra/settings/programs');
        $response->assertOk();

        $items = $response->json('data') ?? [];
        $match = null;
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $program->id) {
                $match = $item;
                break;
            }
        }

        $this->assertNotNull($match, 'created program must appear in /settings/programs');
        $this->assertSame('فندق الإعدادات', $match['mecca_hotel_label']);
        $this->assertSame('مشرف الإعدادات', $match['trip_supervisor_label']);
    }

    /* =========================================================
     *  Defensive — booking without hotel must still succeed
     * ========================================================= */

    public function test_booking_create_without_hotel_still_succeeds(): void
    {
        // Create a program WITHOUT mecca_hotel_id — booking must not break.
        $program = Program::query()->create([
            'program_name' => 'No-Hotel Program',
            'program_type' => 'umra',
            'total_nights' => 7,
            'mecca_nights' => 4,
            'medina_nights' => 3,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق بدون ID',
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(37)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'Test EC',
            'departure_point' => 'CAI',
            'default_selling_price' => 30000.00,
            'default_purchase_price' => 25000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // We need a vault for booking creation to succeed.
        $vault = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'TS Test Vault',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 500_000.00,
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => [
                'full_name' => 'TS Test Customer',
                'phone' => '01098765432',
            ],
            'program_id' => $program->id,
            'purchase_price' => 25000.0,
            'selling_price' => 30000.0,
            'currency' => 'EGP',
            'account_id' => $vault->id,
        ]);
        $response->assertCreated();

        $bookingId = $response->json('data.id');
        $this->assertNotNull($bookingId);

        $show = $this->getJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $show->assertOk();
        $this->assertSame('فندق بدون ID', $show->json('data.program.mecca_hotel_name'));
        $this->assertNull($show->json('data.program.accommodation_label') === '' ? null : $show->json('data.program.accommodation_label'),
            'no accommodation_type_id set → label should be null');
    }
}
