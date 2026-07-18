<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for FlightTreasuryController::overview — Phase 2 coverage.
 *
 * ⚠️ FlightTreasuryController::overview (228 سطر) — صفر تغطية:
 *    - لازم يرجّع systems + carriers + settlement accounts + recent flight transactions
 *    - liquidity_by_currency يقسّم الإجماليات حسب العملة
 *    - يستثني حسابات الـ "إقفال" / "رصيد مسبق" / "تسوية" / "(نظام)"
 *
 * Coverage areas:
 *   ① overview returns systems + carriers + accounts grouped by currency
 *   ② overview excludes accounts with special names (إقفال/رصيد مسبق/تسوية)
 *   ③ liquidity_by_currency aggregates balance per currency correctly
 *   ④ recent_transactions filters to flight module only
 *   ⑤ liquidity summary sorts EGP first then alphabetical
 *   ⑥ carrier_transactions returns paginated airline_transactions
 *
 * @see \App\Http\Controllers\Api\V1\Flight\FlightTreasuryController
 */
class FlightTreasuryOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightSystem $system;

    protected FlightCarrier $carrier;

    protected Account $cashboxEgp;

    protected Account $cashboxSar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Treasury Test Admin',
            'email' => 'treasury-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->system = FlightSystem::create([
            'name' => 'Treasury Test System',
            'code' => 'TSY'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            // balance intentionally NOT in fillable — نستخدم credit() بدل ما نمرره مباشرة
            'credit_limit' => 2000.00,
            'created_by' => $this->admin->id,
        ]);
        $this->system->refresh();
        // المسار المعتمد لرفع الرصيد
        $this->system->credit(5000.00, 'Treasury test setup', $this->admin->id, null);

        $this->carrier = FlightCarrier::create([
            'flight_system_id' => $this->system->id,
            'name' => 'Treasury Test Carrier',
            'code' => 'TTC'.uniqid(),
            'currency' => 'EGP',
            'credit_limit' => 1000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->carrier->refresh();
        $this->carrier->credit(3000.00, 'Treasury test setup', $this->admin->id, null);

        $this->cashboxEgp = Account::create([
            'name' => 'Treasury Test Cashbox EGP',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 10000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $this->cashboxSar = Account::create([
            'name' => 'Treasury Test Cashbox SAR',
            'type' => 'cashbox',
            'currency' => 'SAR',
            'balance' => 500.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // حسابات لازم تـ تـستبعد
        Account::create([
            'name' => 'إقفال تكاليف الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        Account::create([
            'name' => 'رصيد مسبق — ناقلو الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 200.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        Account::create([
            'name' => 'حساب تسوية عام',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ 1) overview returns systems, carriers, accounts grouped properly
     */
    public function test_overview_returns_systems_carriers_and_accounts(): void
    {
        $response = $this->getJson('/api/v1/flight/treasury/overview');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'systems',
                    'carriers',
                    'settlement_accounts',
                    'recent_flight_transactions',
                    'liquidity_by_currency',
                ],
            ]);

        // الـ systems لازم يحتوي على system
        $systems = $response->json('data.systems');
        $systemIds = collect($systems)->pluck('id')->toArray();
        $this->assertContains($this->system->id, $systemIds);

        // الـ carriers لازم يحتوي على carrier
        $carriers = $response->json('data.carriers');
        $carrierIds = collect($carriers)->pluck('id')->toArray();
        $this->assertContains($this->carrier->id, $carrierIds);

        // الـ settlement accounts لازم يحتوي على cashboxes — لكن مش اللي استُبعدوا
        $accountNames = collect($response->json('data.settlement_accounts'))->pluck('name')->toArray();
        $this->assertContains('Treasury Test Cashbox EGP', $accountNames,
            'Regular cashbox must be included');
        $this->assertContains('Treasury Test Cashbox SAR', $accountNames);
    }

    /**
     * ✅ 2) overview يستثني حسابات الـ "إقفال", "رصيد مسبق", "(نظام)", "تسوية"
     */
    public function test_overview_excludes_clearing_prepaid_settlement_accounts(): void
    {
        $response = $this->getJson('/api/v1/flight/treasury/overview');

        $response->assertOk();
        $accountNames = collect($response->json('data.settlement_accounts'))->pluck('name')->toArray();

        // ⚠️ الأربع حسابات الـ special-named لازم تكون مستبعدة
        $this->assertNotContains('إقفال تكاليف الطيران', $accountNames,
            'Clearing account must be excluded (not real cashbox)');
        $this->assertNotContains('رصيد مسبق — ناقلو الطيران', $accountNames,
            'Prepaid GL must be excluded (not real cashbox)');
        $this->assertNotContains('حساب تسوية عام', $accountNames,
            'Settlement account must be excluded');
    }

    /**
     * ✅ 3) liquidity_by_currency aggregates balance per currency correctly
     */
    public function test_liquidity_by_currency_aggregates_per_currency(): void
    {
        $response = $this->getJson('/api/v1/flight/treasury/overview');

        $response->assertOk();

        $systems = $response->json('data.systems');
        $carriers = $response->json('data.carriers');

        $liquidity = collect($response->json('data.liquidity_by_currency'));
        $egp = $liquidity->firstWhere('currency', 'EGP');
        $sar = $liquidity->firstWhere('currency', 'SAR');

        // sanity: نطبع عدد الـ systems/carriers عشان نعرف لو في مشكلة في الـ setUp
        $this->assertGreaterThan(0, count($systems),
            'At least 1 active system must appear in overview.systems');
        $this->assertGreaterThan(0, count($carriers),
            'At least 1 active carrier must appear in overview.carriers');

        $this->assertNotNull($egp, 'EGP row must exist in liquidity_by_currency');
        $this->assertNotNull($sar, 'SAR row must exist in liquidity_by_currency');

        // EGP: system 5000 + carrier 3000 + cashbox 10000 = 18000 actual
        $this->assertEquals(18000.0, (float) $egp['total_actual'],
            'EGP total_actual = 5000 (system) + 3000 (carrier) + 10000 (cashbox)');

        // SAR: cashbox 500 only
        $this->assertEquals(500.0, (float) $sar['total_actual'],
            'SAR total_actual = 500 (cashbox only)');

        // EGP credit limits: system 2000 + carrier 1000 = 3000
        $this->assertEquals(3000.0, (float) $egp['systems_credit_limit'] + (float) $egp['carriers_credit_limit']);

        // total_available = total_actual + credit_limits
        // EGP: 18000 + 2000 (system) + 1000 (carrier) = 21000
        $this->assertEquals(21000.0, (float) $egp['total_available']);
    }

    /**
     * ✅ 4) recent_flight_transactions filters to flight module only
     */
    public function test_overview_recent_transactions_filters_to_flight_module(): void
    {
        // أنشئ transactions في modules مختلفة
        Transaction::create([
            'type' => 'transfer',
            'amount' => 500,
            'from_account_id' => $this->cashboxEgp->id,
            'to_account_id' => $this->cashboxSar->id,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightBooking::class,
            'related_id' => 1,
            'created_by' => $this->admin->id,
            'notes' => 'Flight transaction',
        ]);

        Transaction::create([
            'type' => 'transfer',
            'amount' => 200,
            'from_account_id' => $this->cashboxEgp->id,
            'to_account_id' => $this->cashboxSar->id,
            'module' => 'bus',  // ← مش flight
            'related_type' => null,
            'related_id' => null,
            'created_by' => $this->admin->id,
            'notes' => 'Bus transaction',
        ]);

        $response = $this->getJson('/api/v1/flight/treasury/overview');

        $response->assertOk();

        $recent = $response->json('data.recent_flight_transactions');
        $this->assertIsArray($recent, 'recent_flight_transactions must be an array');

        // الـ recent لازم يكون فيه معاملة طيران (controller filter بـ whereModule=flight)
        // الـ size ممكن تكون 1 لو الصفقة الـ flight فقط
        $this->assertGreaterThanOrEqual(1, count($recent),
            'Recent transactions must include at least 1 flight transaction');

        // الـ bus transaction لازم ما يكون موجود (فلتر الـ controller)
        // نتأكد إن كل الـ recent من flight module — نمشي على الملاحظات
        $hasBusNote = collect($recent)->contains(fn ($tx) => isset($tx['notes']) && str_contains($tx['notes'], 'Bus transaction'));
        $this->assertFalse($hasBusNote,
            'Bus transaction must NOT appear in flight recent_flight_transactions');

        // نتأكد إن flight transaction موجود
        $hasFlightNote = collect($recent)->contains(fn ($tx) => isset($tx['notes']) && str_contains($tx['notes'], 'Flight transaction'));
        $this->assertTrue($hasFlightNote,
            'Flight transaction must appear in recent_flight_transactions');
    }

    /**
     * ✅ 5) liquidity_by_currency sorts EGP first then alphabetical
     */
    public function test_liquidity_summary_sorts_egp_first_then_alphabetical(): void
    {
        $response = $this->getJson('/api/v1/flight/treasury/overview');

        $liquidity = $response->json('data.liquidity_by_currency');
        $currencies = array_column($liquidity, 'currency');

        // EGP لازم يكون أولاً
        $this->assertEquals('EGP', $currencies[0],
            'EGP must always be first in liquidity_by_currency');

        // باقي الـ currencies مترتبة أبجدياً
        $rest = array_slice($currencies, 1);
        $sorted = $rest;
        sort($sorted);
        $this->assertEquals($sorted, $rest,
            'Remaining currencies must be sorted alphabetically');
    }

    /**
     * ✅ 6) carrier_transactions returns paginated airline_transactions
     */
    public function test_carrier_transactions_returns_paginated_airline_transactions(): void
    {
        // أنشئ airline_transaction مسجّل
        $at = \App\Models\Flight\AirlineTransaction::create([
            'flight_carrier_id' => $this->carrier->id,
            'flight_booking_id' => null,
            'type' => 'credit',
            'amount' => 1000.00,
            'balance_before' => 0,
            'balance_after' => 1000,
            'description' => 'Test airline transaction',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/v1/flight/treasury/carriers/{$this->carrier->id}/transactions");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'data' => [],
                    'current_page',
                    'per_page',
                ],
            ]);

        // لازم يحتوي على الـ transaction اللي أنشأناه
        $txIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($at->id, $txIds,
            'Carrier transactions response must include the airline_transaction we created');
    }
}
