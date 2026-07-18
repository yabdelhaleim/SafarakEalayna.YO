<?php

namespace Tests\Feature\Flight;

use App\Models\AuditLog;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1v2 protection tests for AirlineAccount.
 *
 * AirlineAccount.balance mutations are guarded by FOUR layers (per the AirlineAccount
 * observer):
 *   ① Mass Assignment (balance not in $fillable)
 *   ② Eloquent Observer (booted + static::updating)
 *   ③ Flag-based bypass (mutateBalanceInternal)
 *   ④ DB::listen() in AppServiceProvider
 *
 * These tests assert the Phase 1v2 contract is held — particularly that:
 *   - 'balance' field is NOT accepted via create/update API endpoints
 *   - Currency is restricted to the 5 listed currencies (EGP/KWD/SAR/USD/AED)
 *   - addCredit uses LedgerBalanceMutationGuard and creates audit log
 *   - destroy refuses if account has bookings/transactions
 *
 * ⚠️ These tests exercise the **production guard** by enabling
 *    `config('accounting.strict_test_guards') = true`, so:
 *   - `app()->runningUnitTests()` bypass is OFF
 *   - Direct $account->balance = X; $account->save() SHOULD throw RuntimeException
 *
 * @see \App\Models\Flight\AirlineAccount
 * @see \App\Http\Controllers\Api\V1\Flight\AirlineAccountController
 */
class AirlineAccountPhase1v2Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // ✅ تفعيل الـ production guard للـ tests دي
        config(['accounting.strict_test_guards' => true]);

        $this->admin = User::factory()->create([
            'name' => 'Phase1v2 Test Admin',
            'email' => 'phase1v2-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    /**
     * ✅ 1) 'balance' mass-assignment في الـ POST endpoint
     *    الـ controller::store لازم يتجاهله بصمت (الـ $fillable مش فيه balance)
     */
    public function test_create_account_ignores_balance_field_in_request(): void
    {
        $payload = [
            'name' => 'Test Phase 1v2',
            'code' => 'P1V2'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'balance' => 99999.99,  // ⚠️ يحاول يضخ رصيد ابتدائي عبر الـ API
        ];

        $response = $this->postJson('/api/v1/flight/airline-accounts', $payload);

        $response->assertOk()
            ->assertJson(['success' => true]);

        // الـ account اتنشأ برصيد 0 بغض النظر عن الـ payload
        $account = AirlineAccount::where('code', $payload['code'])->firstOrFail();
        $this->assertEquals(0.0, (float) $account->balance,
            'balance field must NOT be settable via POST endpoint (Phase 1v2 contract)');
    }

    /**
     * ✅ 2) update endpoint لازم يتجاهل balance حتى لو الـ client بعته
     */
    public function test_update_account_ignores_balance_field_in_request(): void
    {
        $account = AirlineAccount::create([
            'name' => 'Update Balance Test',
            'code' => 'UBT'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 1000,
            'is_active' => true,
        ]);

        $originalBalance = (float) $account->balance;

        $response = $this->putJson("/api/v1/flight/airline-accounts/{$account->id}", [
            'name' => 'Updated Name',
            'balance' => 99999.99,  // ⚠️ محاولة sneaky
        ]);

        $response->assertOk();

        $account->refresh();
        $this->assertEquals('Updated Name', $account->name);
        $this->assertEquals($originalBalance, (float) $account->balance,
            'balance must NOT change on update (Phase 1v2: forbid mass-assignment)');
    }

    /**
     * ✅ 3) Currency validation: whitelist EGP/KWD/SAR/USD/AED
     */
    public function test_create_rejects_currency_outside_whitelist(): void
    {
        $payload = [
            'name' => 'Bad Currency Test',
            'code' => 'BAD'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'JPY',  // ⚠️ مش في الـ whitelist
        ];

        $response = $this->postJson('/api/v1/flight/airline-accounts', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    /**
     * ✅ 4) All 5 whitelisted currencies are accepted
     */
    public function test_create_accepts_all_five_whitelisted_currencies(): void
    {
        $currencies = ['EGP', 'KWD', 'SAR', 'USD', 'AED'];

        foreach ($currencies as $currency) {
            $payload = [
                'name' => "Currency Test {$currency}",
                'code' => substr(md5($currency.microtime(true)), 0, 8),
                'system_type' => 'Amadeus',
                'currency' => $currency,
            ];
            $response = $this->postJson('/api/v1/flight/airline-accounts', $payload);
            $response->assertOk();
            $this->assertDatabaseHas('airline_accounts', [
                'code' => $payload['code'],
                'currency' => $currency,
            ]);
        }
    }

    /**
     * ✅ 5) Direct mutation via Eloquent must throw RuntimeException
     *    (strict_test_guards=true → الـ production guard شغال)
     */
    public function test_direct_balance_mutation_outside_guard_throws(): void
    {
        $account = AirlineAccount::create([
            'name' => 'Direct Mutation Test',
            'code' => 'DMT'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 1000,
            'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يمكن تعديل رصيد حساب/');

        // ⚠️ أي محاولة مباشرة بدون debit()/credit() لازم ترمي
        $account->balance = 5000.00;
        $account->save();
    }

    /**
     * ✅ 6) debit()/credit() model methods — المسار المعتمد — بيشتغلوا
     *    وفي نفس الوقت بيرفعوا الـ flag علشان الـ observer يسمح
     */
    public function test_debit_and_credit_methods_work_via_internal_guard(): void
    {
        $account = AirlineAccount::create([
            'name' => 'Debit/Credit Test',
            'code' => 'DCT'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 5000,
            'is_active' => true,
        ]);

        // ⚠️ نمط إنشاء مهم: balance مش في $fillable — لازم refresh بعد create()
        $account->refresh();

        // credit() عبر المسار المعتمد — لازم ينجح
        $tx = $account->credit(1000.00, 'اختبار credit', $this->admin->id, null);
        $account->refresh();
        $this->assertEquals(1000.0, (float) $account->balance,
            'credit() must work and create transaction record');

        // لازم يكون عندنا FlightBooking حقيقي لتفادي الـ FOREIGN KEY
        // (AirlineAccount.debit() بيكتب flight_booking_id إلزامي)
        $customer = \App\Models\Customer::create([
            'full_name' => 'Debit Test Cust',
            'phone' => '01000111222',
        ]);

        $booking = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'DBT'.uniqid(),
            'booking_number' => 'DBT'.uniqid(),
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
            'purchase_price' => 800,
            'selling_price' => 1000,
            'profit' => 200,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        // debit() — لازم ينجح
        $tx2 = $account->debit(300.00, $booking->id, $this->admin->id);
        $account->refresh();
        $this->assertEquals(700.0, (float) $account->balance,
            'debit() must work with available_balance and create transaction record');
    }

    /**
     * ✅ 7) Cannot delete AirlineAccount that has bookings
     */
    public function test_destroy_account_with_bookings_returns_422(): void
    {
        $customer = \App\Models\Customer::create([
            'full_name' => 'Del Test Cust',
            'phone' => '01000000001',
        ]);

        $account = AirlineAccount::create([
            'name' => 'Account With Booking',
            'code' => 'AWB'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 1000,
            'is_active' => true,
        ]);

        // أنشئ حجز مرتبط بالحساب
        FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'DELTEST',
            'booking_number' => 'DELTEST',
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
            'purchase_price' => 100,
            'selling_price' => 200,
            'profit' => 100,
            'currency' => 'EGP',
            'airline_account_id' => $account->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->deleteJson("/api/v1/flight/airline-accounts/{$account->id}");

        $response->assertStatus(422)
            ->assertJson(['success' => false]);

        // ⚠️ AirlineAccount model بيستخدم hard delete فقط (لا SoftDeletes) — check existence
        $this->assertDatabaseHas('airline_accounts', [
            'id' => $account->id,
        ]);

        // نتأكد إن الـ row موجود فعلياً — مش محذوف
        $count = AirlineAccount::where('id', $account->id)->count();
        $this->assertGreaterThan(0, $count, 'AirlineAccount must NOT be deleted when bookings exist');
    }

    /**
     * ✅ 8) addCredit via API يستخدم LedgerBalanceMutationGuard ويرفع الـ audit log
     */
    public function test_add_credit_api_uses_guard_and_creates_audit_log(): void
    {
        $account = AirlineAccount::create([
            'name' => 'Add Credit Audit',
            'code' => 'ACA'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 1000,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/flight/airline-accounts/add-credit', [
            'airline_account_id' => $account->id,
            'amount' => 5000.00,
            'description' => 'اختبار add credit للـ audit',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        // الـ balance زاد
        $account->refresh();
        $this->assertEquals(5000.0, (float) $account->balance);

        // الـ audit log تم تسجيله
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => AirlineAccount::class,
            'model_id' => $account->id,
            'action' => 'airline_account_credit_via_api',
        ]);

        // الـ transaction في airline_transactions
        $this->assertDatabaseHas('airline_transactions', [
            'type' => 'credit',
            'amount' => 5000.00,
        ]);
    }
}
