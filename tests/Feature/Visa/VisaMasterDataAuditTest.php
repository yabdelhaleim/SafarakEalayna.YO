<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\VisaBooking;
use App\Models\VisaDetail;

/**
 * Phase 9.1 — Master Data Audit (Section 4 of the 30-section prompt).
 *
 * Audit target: GET /api/v1/visa/settings/* endpoints and the underlying
 * master-data models (VisaAgent, VisaDuration, VisaDetail).
 *
 * Coverage:
 *   1. Settings/statuses endpoint: all enum values reachable
 *   2. Settings/agents endpoint: active-only, ordered
 *   3. Settings/durations endpoint: active-only, ordered
 *   4. Master-data model invariants (soft-delete, scopes, FKs)
 *
 * Section 4 of the prompt requires verifying:
 *   - visa details
 *   - countries
 *   - visa types
 *   - suppliers (= VisaAgent)
 *   - prices (= default_cost_price)
 *   - availability (= is_active + sort_order)
 *   - active/inactive behavior
 *   - relationships / FKs
 *   - required fields
 */
class VisaMasterDataAuditTest extends VisaTestCase
{
    /* ============================================================
     *  1. /api/v1/visa/settings/statuses  —  enum coverage
     * ============================================================ */

    public function test_settings_statuses_returns_all_visa_status_enum_values(): void
    {
        $response = $this->getJson('/api/v1/visa/settings/statuses');
        $response->assertOk();

        $visaStatuses = $response->json('data.visa');
        $this->assertIsArray($visaStatuses, 'visa statuses payload must be an array');
        $this->assertCount(count(VisaStatus::cases()), $visaStatuses,
            'settings/statuses must include every VisaStatus case');

        // Every enum value must be present in the response
        $responseValues = array_column($visaStatuses, 'value');
        foreach (VisaStatus::cases() as $case) {
            $this->assertContains(
                $case->value,
                $responseValues,
                "VisaStatus::{$case->name} ({$case->value}) missing from settings/statuses"
            );
        }
    }

    public function test_settings_statuses_returns_all_visa_type_enum_values(): void
    {
        $response = $this->getJson('/api/v1/visa/settings/statuses');
        $response->assertOk();

        $visaTypes = $response->json('data.visa_types');
        $this->assertIsArray($visaTypes, 'visa_types payload must be an array');
        $this->assertCount(count(VisaType::cases()), $visaTypes);

        $responseValues = array_column($visaTypes, 'value');
        foreach (VisaType::cases() as $case) {
            $this->assertContains(
                $case->value,
                $responseValues,
                "VisaType::{$case->name} ({$case->value}) missing from settings/statuses"
            );
        }
    }

    public function test_settings_statuses_returns_all_visa_entry_type_enum_values(): void
    {
        $response = $this->getJson('/api/v1/visa/settings/statuses');
        $response->assertOk();

        $entryTypes = $response->json('data.visa_entry_types');
        $this->assertIsArray($entryTypes, 'visa_entry_types payload must be an array');
        $this->assertCount(count(VisaEntryType::cases()), $entryTypes);

        $responseValues = array_column($entryTypes, 'value');
        foreach (VisaEntryType::cases() as $case) {
            $this->assertContains(
                $case->value,
                $responseValues,
                "VisaEntryType::{$case->name} ({$case->value}) missing from settings/statuses"
            );
        }
    }

    public function test_settings_statuses_includes_color_label_for_visa_status(): void
    {
        $response = $this->getJson('/api/v1/visa/settings/statuses');
        $response->assertOk();

        $cancelled = collect($response->json('data.visa'))
            ->firstWhere('value', VisaStatus::Cancelled->value);

        $this->assertNotNull($cancelled, 'Cancelled status must be in /settings/statuses');
        $this->assertArrayHasKey('label', $cancelled);
        $this->assertArrayHasKey('color', $cancelled);
        $this->assertSame('ملغاة', $cancelled['label']);
        $this->assertNotEmpty($cancelled['color']);
    }

    /* ============================================================
     *  2. /api/v1/visa/settings/agents  —  active filter + order
     * ============================================================ */

    public function test_settings_agents_excludes_inactive_agents(): void
    {
        $active = VisaAgent::create([
            'company_name' => 'PHASE91_ACTIVE_AGENT',
            'contact_person' => 'Active Person',
            'phone' => '01000000001',
            'email' => 'active@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);

        $inactive = VisaAgent::create([
            'company_name' => 'PHASE91_INACTIVE_AGENT',
            'contact_person' => 'Inactive Person',
            'phone' => '01000000002',
            'email' => 'inactive@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/visa/settings/agents');
        $response->assertOk();

        $names = array_column($response->json('data'), 'company_name');

        $this->assertContains($active->company_name, $names, 'active agent must be in response');
        $this->assertNotContains($inactive->company_name, $names, 'inactive agent must NOT be in response');
    }

    public function test_settings_agents_orders_results_by_company_name_ascending(): void
    {
        VisaAgent::create([
            'company_name' => 'PHASE91_ZULU_AGENT',
            'contact_person' => 'Z',
            'phone' => '01000000010',
            'email' => 'zulu@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);
        VisaAgent::create([
            'company_name' => 'PHASE91_ALPHA_AGENT',
            'contact_person' => 'A',
            'phone' => '01000000011',
            'email' => 'alpha@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);
        VisaAgent::create([
            'company_name' => 'PHASE91_MIKE_AGENT',
            'contact_person' => 'M',
            'phone' => '01000000012',
            'email' => 'mike@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/visa/settings/agents');
        $response->assertOk();

        $names = array_column($response->json('data'), 'company_name');
        $phaseNames = array_values(array_filter($names, fn ($n) => str_starts_with($n, 'PHASE91_')));

        $sorted = $phaseNames;
        sort($sorted);
        $this->assertSame($sorted, $phaseNames, 'agents must be ordered by company_name ASC');
    }

    /* ============================================================
     *  3. /api/v1/visa/settings/durations  —  active filter + order
     * ============================================================ */

    public function test_settings_durations_excludes_inactive_durations(): void
    {
        $active = VisaDuration::create([
            'code' => 'P91_30D',
            'label_ar' => '٣٠ يوم نشط',
            'label_en' => '30 days active',
            'months' => 1,
            'entry_type' => 'single',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $inactive = VisaDuration::create([
            'code' => 'P91_90D',
            'label_ar' => '٩٠ يوم معطل',
            'label_en' => '90 days inactive',
            'months' => 3,
            'entry_type' => 'single',
            'sort_order' => 2,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/visa/settings/durations');
        $response->assertOk();

        $codes = array_column($response->json('data'), 'code');

        $this->assertContains($active->code, $codes, 'active duration must be in response');
        $this->assertNotContains($inactive->code, $codes, 'inactive duration must NOT be in response');
    }

    public function test_settings_durations_orders_results_by_sort_order_ascending(): void
    {
        $a = VisaDuration::create([
            'code' => 'P91_SORT_A', 'label_ar' => 'A', 'label_en' => 'A',
            'months' => 1, 'entry_type' => 'single', 'sort_order' => 30, 'is_active' => true,
        ]);
        $b = VisaDuration::create([
            'code' => 'P91_SORT_B', 'label_ar' => 'B', 'label_en' => 'B',
            'months' => 1, 'entry_type' => 'single', 'sort_order' => 10, 'is_active' => true,
        ]);
        $c = VisaDuration::create([
            'code' => 'P91_SORT_C', 'label_ar' => 'C', 'label_en' => 'C',
            'months' => 1, 'entry_type' => 'single', 'sort_order' => 20, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/visa/settings/durations');
        $response->assertOk();

        $codes = array_column($response->json('data'), 'code');
        $phaseCodes = array_values(array_filter($codes, fn ($x) => str_starts_with($x, 'P91_SORT_')));

        // Expected: B (10), C (20), A (30)
        $this->assertSame(['P91_SORT_B', 'P91_SORT_C', 'P91_SORT_A'], $phaseCodes,
            'durations must be ordered by sort_order ASC');
    }

    /* ============================================================
     *  4. Master-data model invariants
     * ============================================================ */

    public function test_visa_agent_soft_delete_excludes_from_active_scope_but_preserves_row(): void
    {
        $agent = VisaAgent::create([
            'company_name' => 'PHASE91_SOFTDELETE_AGENT',
            'contact_person' => 'Soft Delete Test',
            'phone' => '01000000020',
            'email' => 'soft@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);

        // Sanity: visible in active scope initially
        $this->assertNotNull(VisaAgent::active()->find($agent->id),
            'agent must be in active scope before soft-delete');

        // Soft-delete
        $agent->delete();
        $this->assertNotNull($agent->fresh()->deleted_at, 'deleted_at must be set after soft-delete');

        // Excluded from active scope
        $this->assertNull(VisaAgent::active()->find($agent->id),
            'soft-deleted agent must be excluded from active() scope');

        // But still in withTrashed
        $this->assertNotNull(VisaAgent::withTrashed()->find($agent->id),
            'soft-deleted agent must still be queryable via withTrashed()');

        // And excluded from settings endpoint
        $response = $this->getJson('/api/v1/visa/settings/agents');
        $response->assertOk();
        $names = array_column($response->json('data'), 'company_name');
        $this->assertNotContains($agent->company_name, $names,
            'soft-deleted agent must NOT appear in /settings/agents');
    }

    public function test_visa_agent_fk_constraint_with_visa_detail(): void
    {
        $agent = VisaAgent::create([
            'company_name' => 'PHASE91_FK_AGENT',
            'contact_person' => 'FK Test',
            'phone' => '01000000030',
            'email' => 'fk@phase91.test',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);

        // bookingPayload uses array_merge (shallow) for overrides — passing
        // a partial nested override would wipe the rest of visa_details.
        // Build the full nested override in-place to keep all required fields.
        $payload = $this->bookingPayload();
        $payload['visa_details']['visa_agent_id'] = $agent->id;
        $booking = $this->makeBooking($payload);

        // VisaBooking has belongsTo(VisaDetail) — singular
        $detail = $booking->visaDetail;
        $this->assertNotNull($detail, 'visa_detail must be linked to booking');
        $this->assertSame($agent->id, $detail->visa_agent_id,
            'visa_detail.visa_agent_id must FK to the linked VisaAgent');

        // Reverse relationship: agent.visaDetails (HasMany on VisaAgent) must include this detail
        $this->assertTrue($agent->visaDetails->contains($detail->id),
            'agent.visaDetails must include the linked detail');
    }

    public function test_visa_duration_fk_constraint_with_visa_detail(): void
    {
        $duration = VisaDuration::create([
            'code' => 'P91_FK_DUR',
            'label_ar' => 'FK Duration',
            'label_en' => 'FK Duration EN',
            'months' => 6,
            'entry_type' => 'multiple',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        // Build full nested override (see comment in agent test above)
        $payload = $this->bookingPayload();
        $payload['visa_details']['visa_duration_id'] = $duration->id;
        $booking = $this->makeBooking($payload);

        $detail = $booking->visaDetail;
        $this->assertNotNull($detail, 'visa_detail must be linked to booking');
        $this->assertSame($duration->id, $detail->visa_duration_id,
            'visa_detail.visa_duration_id must FK to the linked VisaDuration');

        // Forward FK: detail.durationRow must resolve to VisaDuration
        $this->assertNotNull($detail->durationRow,
            'visa_detail.durationRow must resolve to VisaDuration');
        $this->assertSame($duration->id, $detail->durationRow->id);
    }
}
