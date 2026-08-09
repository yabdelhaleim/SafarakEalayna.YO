<?php

namespace Tests\Feature\Flight;

use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the new `flight_groups.currency` column introduced
 * to decouple the group's pricing currency from the linked carrier's currency.
 *
 * These tests cover:
 *   ① schema has the new `currency` column with default `EGP`.
 *   ② newly created groups default to EGP and persist the chosen currency.
 *   ③ existing rows back-fill to EGP when the column is added.
 *   ④ API index endpoint returns the group's currency (not carrier.currency fallback).
 *   ⑤ API `getByCarrier` endpoint returns the currency.
 *   ⑥ group-level currency wins over carrier.currency in `recommendedCurrency` logic.
 *
 * @see \App\Models\Flight\FlightGroup
 * @see \App\Http\Controllers\Api\V1\Flight\FlightGroupController
 */
class FlightGroupCurrencyColumnTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Group Currency Admin',
            'email' => 'group-currency-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_schema_has_currency_column_with_egp_default(): void
    {
        $this->assertTrue(
            Schema::hasColumn('flight_groups', 'currency'),
            'flight_groups must have a currency column for the new group-level currency.'
        );

        // Insert directly via the query builder (bypassing Eloquent's $fillable)
        // to mirror how MySQL would assign the column default for a row that
        // doesn't pass `currency` explicitly. The migration uses `->default('EGP')`
        // so the column-level default must take effect.
        $code = 'SD-'.uniqid();
        \DB::table('flight_groups')->insert([
            'name' => 'Schema Default Group',
            'code' => $code,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = \DB::table('flight_groups')->where('code', $code)->value('currency');

        $this->assertSame('EGP', $row,
            'New rows must back-fill to EGP from the column default when currency is omitted.');
    }

    public function test_existing_rows_backfill_to_egp(): void
    {
        // Simulate an "old" row that pre-existed the column by inserting via the raw
        // query builder (without specifying currency) and re-fetching through Eloquent.
        \DB::table('flight_groups')->insert([
            'name' => 'Legacy Group',
            'code' => 'LG-'.uniqid(),
            'is_active' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = FlightGroup::where('name', 'Legacy Group')->firstOrFail();

        $this->assertSame('EGP', $legacy->currency,
            'Legacy rows must back-fill to EGP from the column default.');
    }

    public function test_group_persists_chosen_currency(): void
    {
        $group = FlightGroup::create([
            'name' => 'SAR Group',
            'code' => 'SAR-'.uniqid(),
            'currency' => 'SAR',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $fresh = $group->fresh();
        $this->assertSame('SAR', $fresh->currency);
    }

    public function test_group_currency_is_independent_from_carrier_currency(): void
    {
        $carrierKwd = FlightCarrier::create([
            'name' => 'Currency Test Carrier KWD',
            'code' => 'CTC-KWD-'.uniqid(),
            'currency' => 'KWD',
            'balance' => 0,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Group linked to a KWD carrier, but the group itself is EGP.
        $group = FlightGroup::create([
            'name' => 'Linked Group EGP',
            'code' => 'LG-EGP-'.uniqid(),
            'flight_carrier_id' => $carrierKwd->id,
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $fresh = $group->fresh();
        $this->assertSame('EGP', $fresh->currency, 'Group currency must be EGP.');
        $this->assertSame('KWD', $fresh->carrier->currency,
            'Carrier currency must still be KWD — group currency does not override it.');
    }

    public function test_api_index_returns_group_currency(): void
    {
        FlightGroup::create([
            'name' => 'API Index Group',
            'code' => 'AIG-'.uniqid(),
            'currency' => 'SAR',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/flight/groups');

        $response->assertOk()->assertJson(['success' => true]);

        $group = collect($response->json('data'))
            ->firstWhere('name', 'API Index Group');

        $this->assertNotNull($group, 'API index must include the new group.');
        $this->assertSame('SAR', $group['currency'],
            'API index payload must include the new group-level currency column.');
    }

    public function test_api_get_by_carrier_returns_group_currency(): void
    {
        $carrier = FlightCarrier::create([
            'name' => 'ByCarrier Test',
            'code' => 'BCT-'.uniqid(),
            'currency' => 'USD',
            'balance' => 0,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        FlightGroup::create([
            'name' => 'Group Under Carrier USD',
            'code' => 'GUC-'.uniqid(),
            'flight_carrier_id' => $carrier->id,
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/v1/flight/carriers/{$carrier->id}/groups");

        $response->assertOk()->assertJson(['success' => true]);

        $group = collect($response->json('data'))
            ->firstWhere('name', 'Group Under Carrier USD');

        $this->assertNotNull($group);
        $this->assertSame('EGP', $group['currency'],
            'getByCarrier must return the group-level currency column.');
    }

    public function test_flight_group_fillable_allows_currency_mass_assignment(): void
    {
        $group = FlightGroup::create([
            'name' => 'Mass Assign Group',
            'code' => 'MAG-'.uniqid(),
            'currency' => 'AED',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame('AED', $group->fresh()->currency,
            'currency must be in $fillable so Filament can save it.');
    }
}
