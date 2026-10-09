<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\TripSupervisor;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\Program;

/**
 * Phase 10.1 — Master Data Audit (Section 4 of the 30-section prompt, applied
 * independently to the Hajj/Umra module).
 *
 * Audit targets:
 *   - HajjUmraStatus enum completeness (6 statuses: Pending, Confirmed,
 *     InProgress, Completed, Cancelled, Refunded)
 *   - program_type enum coverage (hajj/umra) and case-insensitive normalization
 *   - accommodation_type string normalization (UPPERCASE)
 *   - Reference endpoints expose all required data
 *   - Master data: UmrahSupplier, HajjUmraExecutingCompany, Hotel,
 *     TripSupervisor, AccommodationType
 *
 * Per the user's Phase 10 directive: "do not assume Visa findings or
 * behavior automatically apply to Hajj/Umra". So this audit is built
 * from scratch.
 */
class HajjUmraMasterDataAuditTest extends HajjUmraTestCase
{
    /* ============================================================
     *  HAJJUMRASTATUS ENUM COMPLETENESS
     * ============================================================ */

    public function test_hajjumra_status_enum_has_six_cases(): void
    {
        $cases = HajjUmraStatus::cases();
        $this->assertCount(6, $cases, 'HajjUmraStatus must have exactly 6 cases');

        $values = array_map(fn ($c) => $c->value, $cases);
        $this->assertEqualsCanonicalizing(
            ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'refunded'],
            $values,
            'HajjUmraStatus values must match the expected 6-state set',
        );
    }

    public function test_hajjumra_status_for_dropdown_includes_all_six(): void
    {
        $dropdown = HajjUmraStatus::forDropdown();
        $this->assertCount(6, $dropdown);
        foreach (['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'refunded'] as $value) {
            $this->assertArrayHasKey($value, $dropdown,
                "forDropdown must include $value");
        }
    }

    public function test_hajjumra_status_label_and_color_defined_for_all_cases(): void
    {
        foreach (HajjUmraStatus::cases() as $case) {
            $this->assertNotEmpty($case->label(), "label() must be non-empty for {$case->value}");
            $this->assertNotEmpty($case->color(), "color() must be non-empty for {$case->value}");
        }
    }

    /* ============================================================
     *  PROGRAM TYPE — case-insensitive normalization
     * ============================================================ */

    public function test_program_type_lowercase_hajj_accepted(): void
    {
        $this->assertProgramTypeAccepted('hajj');
    }

    public function test_program_type_uppercase_hajj_normalized_and_accepted(): void
    {
        $program = $this->assertProgramTypeAccepted('HAJJ');
        $this->assertSame('hajj', $program->program_type,
            'uppercase HAJJ must be normalized to lowercase hajj');
    }

    public function test_program_type_mixed_case_hajj_accepted(): void
    {
        $this->assertProgramTypeAccepted('Hajj');
    }

    public function test_program_type_lowercase_umra_accepted(): void
    {
        $this->assertProgramTypeAccepted('umra');
    }

    public function test_program_type_lowercase_umrah_normalized_to_umra(): void
    {
        $program = $this->assertProgramTypeAccepted('umrah');
        $this->assertSame('umra', $program->program_type,
            'umrah must be normalized to umra');
    }

    public function test_program_type_uppercase_umra_normalized_and_accepted(): void
    {
        // Phase 10.1 FIX — uppercase UMRA was previously 422.
        $program = $this->assertProgramTypeAccepted('UMRA');
        $this->assertSame('umra', $program->program_type,
            'uppercase UMRA must be normalized to umra');
    }

    public function test_program_type_uppercase_umrah_accepted(): void
    {
        $program = $this->assertProgramTypeAccepted('UMRAH');
        $this->assertSame('umra', $program->program_type,
            'uppercase UMRAH must be normalized to umra');
    }

    public function test_program_type_invalid_value_rejected(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/programs', $this->validProgramPayload([
            'program_type' => 'invalid_type',
        ]));
        $response->assertStatus(422)->assertJsonValidationErrors(['program_type']);
    }

    protected function assertProgramTypeAccepted(string $programType): Program
    {
        $response = $this->postJson('/api/v1/hajj-umra/programs', $this->validProgramPayload([
            'program_type' => $programType,
        ]));
        $response->assertCreated();
        $program = Program::query()->find($response->json('data.id'));
        $this->assertNotNull($program);
        return $program;
    }

    /* ============================================================
     *  ACCOMMODATION_TYPE — case normalization
     * ============================================================ */

    public function test_accommodation_type_normalized_to_uppercase(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/programs', $this->validProgramPayload([
            'accommodation_type' => 'double',
        ]));
        $response->assertCreated();
        $program = Program::query()->find($response->json('data.id'));
        $this->assertSame('DOUBLE', $program->accommodation_type,
            'accommodation_type must be uppercased on save');
    }

    public function test_accommodation_type_id_links_to_row(): void
    {
        $type = AccommodationType::query()->create([
            'code' => 'KING',
            'name_ar' => 'جناح ملكي',
            'name_en' => 'King Suite',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/programs', $this->validProgramPayload([
            'accommodation_type_id' => $type->id,
            'accommodation_type' => null,
        ]));
        $response->assertCreated();
        $program = Program::query()->find($response->json('data.id'));
        $this->assertSame($type->id, $program->accommodation_type_id);
        $this->assertSame('KING', $program->accommodation_type,
            'accommodation_type string must mirror the linked AccommodationType code');
    }

    /* ============================================================
     *  SUPPLIER + EXECUTING COMPANY
     * ============================================================ */

    public function test_umrah_supplier_factory_creates_with_account(): void
    {
        $supplier = $this->makeSupplier();
        $this->assertNotNull($supplier->account_id, 'supplier must have a linked account');
        $this->assertSame('hajj_umra', $supplier->account->module_type);
    }

    public function test_executing_company_observer_auto_creates_account(): void
    {
        // Phase 10.1 FINDING: HajjUmraExecutingCompanyObserver::saving auto-creates
        // a Supplier Account when account_id is null (mirrors VisaAgentObserver).
        // This is by-design — every executing company MUST have an account to
        // record AP. We lock this in so any future regression is caught.
        $company = $this->makeExecutingCompany();
        $this->assertNotNull($company->id);
        $this->assertTrue($company->is_active);
        $this->assertNotNull($company->account_id,
            'HajjUmraExecutingCompanyObserver must auto-create supplier account on save');
        $this->assertSame('hajj_umra', $company->account->module_type);
        $this->assertNotNull($company->account->name);
    }

    /* ============================================================
     *  REFERENCE ENDPOINTS
     * ============================================================ */

    public function test_settings_statuses_endpoint_returns_six(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/settings/statuses')->assertOk();
        // The endpoint returns a structured object: { hajj_umra: [...], visa: [...], ... }
        $hajjUmraStatuses = $response->json('data.hajj_umra');
        $this->assertIsArray($hajjUmraStatuses);
        $this->assertCount(6, $hajjUmraStatuses,
            'statuses endpoint must return 6 hajj_umra statuses');

        $values = array_column($hajjUmraStatuses, 'value');
        $this->assertEqualsCanonicalizing(
            ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'refunded'],
            $values,
        );
    }

    public function test_settings_programs_endpoint_lists_active_programs(): void
    {
        $this->makeProgram(['program_name' => 'A1', 'program_type' => 'hajj', 'is_active' => true]);
        $this->makeProgram(['program_name' => 'A2', 'program_type' => 'umra', 'is_active' => true]);
        $this->makeProgram(['program_name' => 'A3', 'program_type' => 'umra', 'is_active' => false]);

        $response = $this->getJson('/api/v1/hajj-umra/settings/programs')->assertOk();
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $names = array_column($items, 'program_name');
        $this->assertContains('A1', $names);
        $this->assertContains('A2', $names);
    }

    public function test_settings_executing_companies_endpoint(): void
    {
        $this->makeExecutingCompany(['name' => 'Exc Co Z']);
        $response = $this->getJson('/api/v1/hajj-umra/settings/executing-companies')->assertOk();
        $this->assertNotNull($response->json('data'));
    }

    /* ============================================================
     *  PROGRAMS INDEX/SHOW contract
     * ============================================================ */

    public function test_programs_index_returns_paginated(): void
    {
        $this->makeProgram(['program_name' => 'P1']);
        $this->makeProgram(['program_name' => 'P2']);
        $this->makeProgram(['program_name' => 'P3']);

        $response = $this->getJson('/api/v1/hajj-umra/programs')->assertOk();
        $data = $response->json('data');
        $items = $data['items'] ?? $data ?? [];
        $this->assertGreaterThanOrEqual(3, count($items));
    }

    public function test_program_show_returns_record(): void
    {
        $program = $this->makeProgram();
        $response = $this->getJson("/api/v1/hajj-umra/programs/{$program->id}")
            ->assertOk();
        $this->assertSame($program->id, $response->json('data.id'));
        $this->assertSame('برنامج حج تجريبي', $response->json('data.program_name'));
    }

    /* ============================================================
     *  HELPER
     * ============================================================ */

    protected function validProgramPayload(array $overrides = []): array
    {
        return array_merge([
            'program_name' => 'برنامج ' . uniqid(),
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
            'executing_company' => 'شركة تنفيذ',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000,
            'default_purchase_price' => 42000,
            'currency' => 'EGP',
            'is_active' => true,
        ], $overrides);
    }
}
