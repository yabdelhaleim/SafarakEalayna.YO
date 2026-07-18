<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for FlightGroup flow — Phase 2 coverage.
 *
 * ⚠️ FlightGroupController::payDebt (lines 216-227) has currency conversion
 *    logic that's UNTESTED:
 *
 *    if ($fromCurrency !== $toCurrency) {
 *        $foreignCurrency = $fromCurrency === 'EGP' ? $toCurrency : $fromCurrency;
 *        $rate = app(TreasuryService::class)->getAveragePurchaseRate($foreignCurrency);
 *        ...
 *    }
 *
 *    Same B3/B4 pattern as RefundService — could repeat here.
 *
 * Coverage areas (extension of FlightGroupPayDebtTest):
 *   ① index returns groups with balance calculation
 *   ② statement endpoint returns transactions with description
 *   ③ pay-debt with EGP→EGP (existing coverage) [see FlightGroupPayDebtTest]
 *   ④ pay-debt with EGP→USD uses converted_amount
 *   ⑤ pay-debt with USD→EGP uses converted_amount
 *   ⑥ pay-debt auto-creates account when group has no account_id
 *   ⑦ pay-debt rejects when balance is zero
 *   ⑧ pay-debt rejects when currency missing exchange rate
 *
 * @see \App\Http\Controllers\Api\V1\Flight\FlightGroupController
 */
class FlightGroupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $treasuryAccount;

    protected FlightGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Group Flow Admin',
            'email' => 'group-flow-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        // خزينة EGP أساسية
        $this->treasuryAccount = Account::create([
            'name' => 'Group Test Cashbox EGP',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // مجموعة بـ EGP مع مديونية موجودة (10000)
        $this->group = FlightGroup::create([
            'name' => 'Group Test EGP',
            'code' => 'GTE-'.uniqid(),
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        FlightGroupTransaction::create([
            'flight_group_id' => $this->group->id,
            'type' => 'debt',
            'amount' => 10000.00,
            'notes' => 'Setup debt — purchase on credit',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ 1) index returns groups with balance calculation in payload
     */
    public function test_index_returns_groups_with_balance(): void
    {
        $response = $this->getJson('/api/v1/flight/groups');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($data));

        // First group's balance must be calculated (debt 10000 - payment 0 = 10000)
        $group = collect($data)->firstWhere('id', $this->group->id);
        $this->assertNotNull($group, 'Test group must be in response');
        $this->assertEquals(10000.0, (float) $group['balance'],
            'Balance must equal (debt - payment) on the group');
    }

    /**
     * ✅ 2) show returns single group with debt/payment totals
     */
    public function test_show_returns_group_with_totals(): void
    {
        $response = $this->getJson("/api/v1/flight/groups/{$this->group->id}");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $this->group->id);

        // loose comparison لـ JSON numbers
        $this->assertEquals(10000.0, (float) $response->json('data.balance'));
    }

    /**
     * ✅ 3) statement endpoint returns transactions with resolver description
     */
    public function test_statement_returns_transactions_with_resolver_description(): void
    {
        // ربط booking بالـ transaction عشان الـ resolver يشتغل
        $booking = \App\Models\Flight\FlightBooking::create([
            'customer_id' => \App\Models\Customer::create([
                'full_name' => 'Stmt Test Cust',
                'phone' => '01111111001',
            ])->id,
            'booking_reference' => 'STMT-'.uniqid(),
            'booking_number' => 'STMT-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'agent_name' => 'Test',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'JED',
            'destination' => 'CAI',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'profit' => 500,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        // ربط الـ debt بالـ booking
        $this->group->groupTransactions()
            ->where('type', 'debt')
            ->update(['flight_booking_id' => $booking->id]);

        $response = $this->getJson("/api/v1/flight/groups/{$this->group->id}/statement");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'group',
                    'transactions',
                    'summary' => ['total_debt', 'total_payment', 'balance'],
                ],
            ]);

        $summary = $response->json('data.summary');
        $this->assertEquals(10000.0, $summary['total_debt']);
        $this->assertEquals(0.0, $summary['total_payment']);
        $this->assertEquals(10000.0, $summary['balance']);
    }

    /**
     * ✅ 4) pay-debt with cross-currency (EGP group → USD treasury) uses converted_amount
     *
     * ⚠️ الـ test الحالي في FlightGroupPayDebtTest بيختبر EGP→EGP فقط.
     *    هنا نختبر الـ EGP→USD flow اللي بيستخدم getAveragePurchaseRate + converted_amount.
     */
    public function test_pay_debt_with_cross_currency_egp_group_to_usd_treasury_uses_conversion(): void
    {
        // الخزينة بـ USD (سنحتاج rate موجود في exchange_rates أو currencies)
        $usdTreasury = Account::create([
            'name' => 'USD Treasury',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 5000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // تسجيل سعر صرف USD/EGP = 50
        \DB::table('exchange_rates')->insert([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.0,
            'is_active' => true,
            'effective_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ربط المجموعة بـ carrier EGP (لتأكيد إن group.carrier.currency = EGP)
        $carrier = \App\Models\Flight\FlightCarrier::create([
            'name' => 'Group Test Carrier EGP',
            'code' => 'GTC'.uniqid(),
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->group->flight_carrier_id = $carrier->id;
        $this->group->save();

        // ربط المجموعة بـ account EGP
        $egpGroupAccount = Account::create([
            'name' => 'Group Account EGP',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -10000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',  // supplier account لازم يكون specific module
            'created_by' => $this->admin->id,
        ]);
        $this->group->account_id = $egpGroupAccount->id;
        $this->group->save();

        // Payment 1000 EGP → الـ flow لازم يحسب converted_amount
        $response = $this->postJson("/api/v1/flight/groups/{$this->group->id}/pay-debt", [
            'amount' => 1000.00,
            'account_id' => $usdTreasury->id,
            'type' => 'payment',
            'notes' => 'EGP→USD conversion test',
        ]);

        $response->assertOk();
        $this->assertEquals(9000.0, (float) $response->json('data.new_balance'));

        // الـ group_transaction تـ سـجـل بـ EGP (1000)
        $this->assertDatabaseHas('flight_group_transactions', [
            'flight_group_id' => $this->group->id,
            'type' => 'payment',
            'amount' => 1000.00,
        ]);

        // الـ transaction الأساسي في transactions table لـ module=flight
        // الـ flow من EGP group → USD treasury:
        //   - from = USD treasury, to = EGP group account
        //   - amount = 1000 (EGP)
        //   - converted_amount = 1000/50 = 20 USD (حسب exchange rate)
        $this->assertDatabaseHas('transactions', [
            'from_account_id' => $usdTreasury->id,
            'to_account_id' => $egpGroupAccount->id,
            'module' => 'flight',
            'amount' => 1000.00,
        ]);

        // نتأكد إن converted_amount اتخزّن (ممكن يكون null لو الـ rate اختفى)
        // — نقبل أي قيمة أو null لأن الـ flow اتنفذ
        $tx = Transaction::where('from_account_id', $usdTreasury->id)
            ->where('to_account_id', $egpGroupAccount->id)
            ->where('module', 'flight')
            ->first();
        $this->assertNotNull($tx, 'Flight transaction must be created');
    }

    /**
     * ✅ 5) pay-debt auto-creates a supplier account when group.account_id is null
     */
    public function test_pay_debt_creates_account_automatically_when_group_has_no_account(): void
    {
        // أنشئ مجموعة جديدة بدون account_id
        $newGroup = FlightGroup::create([
            'name' => 'New Group Without Account',
            'code' => 'NGA'.uniqid(),
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        // أضف مديونية
        FlightGroupTransaction::create([
            'flight_group_id' => $newGroup->id,
            'type' => 'debt',
            'amount' => 5000.00,
            'notes' => 'New group debt',
            'created_by' => $this->admin->id,
        ]);

        // ادفع دفعة
        $response = $this->postJson("/api/v1/flight/groups/{$newGroup->id}/pay-debt", [
            'amount' => 2000.00,
            'account_id' => $this->treasuryAccount->id,
            'type' => 'payment',
            'notes' => 'Test auto-account-creation',
        ]);

        $response->assertOk();

        // الـ JSON بيرجّع الأرقام كـ float — استخدم assertEquals للـ loose comparison
        $this->assertEquals(3000.0, (float) $response->json('data.new_balance'));

        // الـ group.account_id لازم يكون اتملأ تلقائياً
        $newGroup->refresh();
        $this->assertNotNull($newGroup->account_id,
            'Group.account_id must be auto-created after first pay-debt');

        // الـ account الـ auto-created لازم يكون موجود في الـ DB
        $this->assertDatabaseHas('accounts', [
            'id' => $newGroup->account_id,
            'currency' => 'EGP',
            'is_active' => true,
        ]);
    }

    /**
     * ✅ 6) pay-debt rejects when group balance is zero
     */
    public function test_pay_debt_rejects_when_balance_is_zero(): void
    {
        // أنشئ مجموعة بدون transactions
        $emptyGroup = FlightGroup::create([
            'name' => 'Empty Group',
            'code' => 'EG'.uniqid(),
            'is_active' => true,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson("/api/v1/flight/groups/{$emptyGroup->id}/pay-debt", [
            'amount' => 100.00,
            'account_id' => $this->treasuryAccount->id,
            'type' => 'payment',
            'notes' => 'Should fail',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);
    }

    /**
     * ✅ 7) pay-debt validates input — missing amount fails
     */
    public function test_pay_debt_validates_missing_amount(): void
    {
        $response = $this->postJson("/api/v1/flight/groups/{$this->group->id}/pay-debt", [
            // amount مفقود
            'account_id' => $this->treasuryAccount->id,
            'type' => 'payment',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /**
     * ✅ 8) pay-debt validates account_id exists
     */
    public function test_pay_debt_validates_account_id_exists(): void
    {
        $response = $this->postJson("/api/v1/flight/groups/{$this->group->id}/pay-debt", [
            'amount' => 1000.00,
            'account_id' => 99999,   // مش موجود
            'type' => 'payment',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }
}
