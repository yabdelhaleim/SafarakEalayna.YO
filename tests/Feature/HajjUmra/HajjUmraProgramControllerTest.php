<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\Employee;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-controller tests for HajjUmraProgramController.
 *
 * Extracted from the monolithic HajjUmraProductionE2ETest to give
 * per-action coverage with clear, fast-running tests.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraProgramController
 */
class HajjUmraProgramControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'HajjUmra Program Tester',
            'email' => 'hajjumra-program@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        // Treasury account required by Phase 6 NOT NULL on hajj_umra_bookings.account_id
        $this->treasury = Account::query()->create([
            'name' => 'Hajj Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    protected function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name' => 'برنامج حج تجريبي',
            'program_type' => 'HAJJ',
            'total_nights' => 14,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة',
            'mecca_nights' => 7,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDays(14)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'شركة تنفيذ',
            'departure_point' => 'CAI',
            'selling_price' => 50000,
            'purchase_price' => 42000,
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /**
     * GET /v1/hajj-umra/programs — paginated index
     */
    public function test_programs_index_returns_paginated_list(): void
    {
        $this->makeProgram(['program_name' => 'برنامج 1']);
        $this->makeProgram(['program_name' => 'برنامج 2']);
        $this->makeProgram(['program_name' => 'برنامج 3']);

        $response = $this->getJson('/api/v1/hajj-umra/programs');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(3, count($data['items'] ?? $data));
    }

    /**
     * POST /v1/hajj-umra/programs — create new program
     */
    public function test_store_program_creates_new_record(): void
    {
        $payload = [
            'program_name' => 'برنامج جديد',
            'program_type' => 'UMRA',
            'total_nights' => 7,
            'accommodation_type' => 'QUAD',
            'mecca_hotel_name' => 'فندق جديد',
            'mecca_nights' => 5,
            'medina_hotel_name' => 'فندق المدينة',
            'medina_nights' => 2,
            'departure_date' => now()->addMonths(2)->toDateString(),
            'return_date' => now()->addMonths(2)->addDays(7)->toDateString(),
            'airline' => 'Saudi Airlines',
            'executing_company' => 'الشركة المنفذة',
            'departure_point' => 'CAI',
            'selling_price' => 30000,
            'purchase_price' => 25000,
            'currency' => 'EGP',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);

        $response->assertCreated();

        $programId = $response->json('data.id');
        $program = Program::query()->find($programId);

        $this->assertNotNull($program);
        $this->assertSame('برنامج جديد', $program->program_name);
    }

    /**
     * GET /v1/hajj-umra/programs/{id} — show program
     */
    public function test_show_program_returns_record(): void
    {
        $program = $this->makeProgram();

        $response = $this->getJson("/api/v1/hajj-umra/programs/{$program->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $program->id)
            ->assertJsonPath('data.program_name', 'برنامج حج تجريبي');
    }

    /**
     * PUT /v1/hajj-umra/programs/{id} — update program
     */
    public function test_update_program_modifies_record(): void
    {
        $program = $this->makeProgram();

        $response = $this->putJson("/api/v1/hajj-umra/programs/{$program->id}", [
            'program_name' => 'برنامج محدّث',
            'selling_price' => 55000,
        ]);

        $response->assertOk();

        $program->refresh();
        $this->assertSame('برنامج محدّث', $program->program_name);
        $this->assertEqualsWithDelta(55000.0, (float) $program->selling_price, 0.01);
    }

    /**
     * Cannot delete a program that has bookings attached.
     */
    public function test_cannot_delete_program_with_active_bookings(): void
    {
        $program = $this->makeProgram();

        // Need a customer first
        $customer = \App\Models\Customer::query()->create([
            'full_name' => 'عميل تجربة',
            'phone' => '01000000099',
            'national_id' => '99999999999999',
            'created_by' => $this->admin->id,
        ]);

        // Create a booking attached to this program
        HajjUmraBooking::query()->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'module' => \App\Enums\TransactionModule::HajjUmra->value,
            'selling_price' => 50000,
            'purchase_price' => 42000,
            'profit' => 8000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => \App\Enums\HajjUmraStatus::Pending->value,
            'agent_name' => $customer->full_name,
            'created_by' => $this->admin->id,
            'account_id' => $this->treasury->id,
        ]);

        // Try to delete — must fail because the program has bookings
        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");

        // Expect 422 / 409 / 400 — controller-level rejection
        $this->assertContains($response->status(), [400, 409, 422]);
    }

    /**
     * DELETE /v1/hajj-umra/programs/{id} — soft-delete a program without bookings
     */
    public function test_delete_program_without_bookings_succeeds(): void
    {
        $program = $this->makeProgram();

        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");

        // Expect 200 or 204 (success) — no bookings to protect
        $this->assertContains($response->status(), [200, 204]);
    }
}
