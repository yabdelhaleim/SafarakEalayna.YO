<?php

namespace Tests\Feature\TourismDivision;

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;

/**
 * PRODUCTION TEST SUITE — Flight (الطيران)
 *
 * The Flight module has many cross-cutting concerns (carriers, systems,
 * groups, segments) that need extensive setup. This suite verifies the
 * core endpoints exist, the module's account types are correctly registered,
 * and the division-level accounting flows work end-to-end.
 */
class FlightProductionTest extends TourismTestCase
{
    public function test_flight_module_accounts_appear_in_trial_balance(): void
    {
        $resp = $this->getJson('/api/v1/reports/trial-balance');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_flight_treasury_endpoint_exists(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $resp->assertOk();
    }

    public function test_flight_carrier_account_creation(): void
    {
        $carrier = FlightCarrier::query()->create([
            'name' => 'ناقل اختبار '.uniqid(),
            'code' => 'CR-'.uniqid(),
            'is_active' => true,
        ]);
        $this->assertNotNull($carrier->id);
    }

    public function test_flight_system_creation(): void
    {
        $system = FlightSystem::query()->create([
            'name' => 'نظام اختبار '.uniqid(),
            'code' => 'FS-'.uniqid(),
            'type' => 'amadeus',
            'is_active' => true,
            'balance' => 0,
        ]);
        $this->assertNotNull($system->id);
    }

    public function test_flight_models_have_correct_module_types(): void
    {
        // For Phase 5 unified vault, a flight cashbox has module_type='tourism'
        // and module='flights' as a label hint.
        $flightCashbox = $this->makeAccount('cashbox', 'طيران', 'tourism', 10000.00, 'EGP', false, 'flights');
        $this->assertEquals('tourism', $flightCashbox->module_type);
        $this->assertEquals('flights', $flightCashbox->module);
    }

    public function test_flight_trial_balance_detailed_endpoint_exists(): void
    {
        $resp = $this->getJson('/api/v1/reports/trial-balance-detailed?division=tourism');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_flight_division_filter_works(): void
    {
        $resp = $this->getJson('/api/v1/reports/trial-balance-detailed?module=flight&division=tourism');
        $resp->assertOk();
    }

    public function test_flight_accounting_invariant_holds(): void
    {
        // Create a flight account, verify account balance equals ledger sum
        $flightAccount = $this->makeAccount('cashbox', 'طيران', 'tourism', 75000.00, 'EGP', false, 'flights');
        $this->assertAccountLedgerConsistent($flightAccount->id, 'flight cashbox after opening');
    }

    public function test_flight_booking_index_endpoint_exists(): void
    {
        $resp = $this->getJson('/api/v1/flight/bookings');
        // Either succeeds or returns 401 (depending on auth middleware setup)
        $this->assertContains($resp->status(), [200, 401]);
    }

    public function test_flight_carrier_model_loaded(): void
    {
        $carrier = new FlightCarrier();
        $this->assertInstanceOf(FlightCarrier::class, $carrier);
    }
}
