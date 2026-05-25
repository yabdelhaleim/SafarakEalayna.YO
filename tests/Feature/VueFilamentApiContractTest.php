<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract checks for endpoints consumed by Vue (flightStore / finance) — no Vue code changes.
 * Filament writes to the same Eloquent models / tables that these APIs read.
 */
class VueFilamentApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Contract Tester',
            'email' => 'contract-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_trip_types_endpoint_returns_ok_payload(): void
    {
        $response = $this->getJson('/api/v1/settings/trip-types');

        $response->assertOk();
        $this->assertTrue($response->json('success') === true);
    }

    public function test_currencies_endpoint_returns_ok_payload(): void
    {
        $response = $this->getJson('/api/v1/settings/currencies');

        $response->assertOk();
        $this->assertNotNull($response->json('data'));
    }

    public function test_carriers_endpoint_returns_ok_payload(): void
    {
        $response = $this->getJson('/api/v1/flight/carriers');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    public function test_airport_search_returns_created_airport(): void
    {
        Airport::query()->create([
            'iata_code' => 'TST',
            'icao_code' => 'OTST',
            'city_name_ar' => 'اختبار',
            'city_name_en' => 'Test City',
            'airport_name_ar' => 'مطار اختبار',
            'airport_name_en' => 'Test Airport',
            'country_code' => 'EG',
            'country_name_ar' => 'مصر',
            'country_name_en' => 'Egypt',
            'latitude' => 30.0,
            'longitude' => 31.0,
            'timezone' => 'Africa/Cairo',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/flight/airports/search?q=TS');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('TST', $data[0]['iata_code'] ?? null);
    }

    public function test_payment_methods_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/settings/payment-methods');

        $response->assertOk();
    }

    public function test_finance_accounts_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/finance/accounts');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    public function test_online_settings_bundle_matches_vue_online_store(): void
    {
        $response = $this->getJson('/api/v1/online/settings/all');

        $response->assertOk();
        $this->assertTrue($response->json('success') === true);
        $data = $response->json('data');
        $this->assertIsArray($data['service_types'] ?? null);
        $this->assertIsArray($data['providers'] ?? null);
        $this->assertIsArray($data['payment_methods'] ?? null);
        $this->assertIsArray($data['accounts'] ?? null);
        $this->assertIsArray($data['statuses'] ?? null);
    }
}
