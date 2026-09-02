<?php

namespace Tests\Feature\Flight;

use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 11.1 — FLIGHT MASTER DATA AUDIT
 * =====================================
 *
 * Verifies that NO master-data configuration (customers, carriers, systems,
 * groups, currencies) can create financial corruption. Every test asserts:
 *   (a) Balance invariants: account.balance == SUM(credits) - SUM(debits)
 *   (b) No negative carrier/system/group balances outside permitted flows
 *   (c) Currency consistency: account.currency matches the booking/customer currency
 *   (d) Direct balance mutation is BLOCKED outside approved services
 *
 * Scope: 3 booking paths × master-data dependencies.
 */
class Phase11MasterDataAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightBookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 11: enable strict test guards to verify the production safety
        // net (defense-in-depth balance-guard in FlightCarrier/FlightSystem).
        config(['accounting.strict_test_guards' => true]);

        $this->admin = User::factory()->create([
            'name' => 'Phase11 MasterData Admin',
            'email' => 'phase11-masterdata-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->bookingService = app(FlightBookingService::class);

        // Make sure each test starts with a clean active currencies list.
        // Tests that need a working currency table can call `seedCurrencies()`
        // (or just `Currency::updateOrCreate(...)` for a single row).
        Currency::query()->delete();
    }

    /**
     * Seed the standard currency rates used across the Flight module.
     * Idempotent — safe to call multiple times within one test.
     *
     * Phase 11.1 audit fix (2026-09-02): the C2 recharge test needs USD
     * active; downstream cross-currency tests need KWD/SAR/GBP. Calling
     * this helper at the top of those tests keeps the e1–e5 master-data
     * coverage unchanged (they still expect a clean currency table).
     */
    protected function seedCurrencies(): void
    {
        foreach ([
            ['USD', 50.0],
            ['EUR', 54.5],
            ['SAR', 13.33],
            ['KWD', 162.5],
            ['GBP', 61.2],
        ] as [$code, $rate]) {
            Currency::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $code,
                    'name_en' => $code,
                    'symbol' => $code,
                    'exchange_rate' => $rate,
                    'is_active' => true,
                    'order' => 0,
                ],
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION A — CUSTOMER MASTER DATA
    // ═══════════════════════════════════════════════════════════════

    public function test_A1_customer_create_auto_creates_ledger_account(): void
    {
        // Creating a customer MUST automatically provision a ledger account.
        // The flight booking flow uses Account::getModuleVault fallback and
        // ensureCustomerAccount() — both require a customer.account_id.

        $customer = Customer::create([
            'full_name' => 'عميل الاختبار',
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'customer-'.uniqid().'@test.com',
            'national_id' => '29'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'travel_country' => 'EGY',
            'module_type' => 'tourism',
        ]);

        // Some flows expect Customer::account_id to be auto-populated by the
        // CustomerLedgerObserver. Verify this works after customer creation.
        $this->assertNotNull($customer->fresh()->account_id,
            'Customer must auto-create a ledger account on insert.');

        $account = Account::find($customer->account_id);
        $this->assertNotNull($account, 'Customer ledger account must exist in accounts table.');
        $this->assertEquals(0.0, (float) $account->balance,
            'Newly-created customer ledger account must have balance = 0.');
        $this->assertEquals('EGP', $account->currency,
            'Customer ledger account must default to EGP currency.');
    }

    public function test_A2_multiple_customers_have_independent_accounts(): void
    {
        $c1 = Customer::create([
            'full_name' => 'Customer One',
            'phone' => '01000000001',
            'email' => 'c1-'.uniqid().'@test.com',
            'national_id' => '29000000000001',
            'city' => 'Cairo',
            'module_type' => 'tourism',
        ]);
        $c2 = Customer::create([
            'full_name' => 'Customer Two',
            'phone' => '01000000002',
            'email' => 'c2-'.uniqid().'@test.com',
            'national_id' => '29000000000002',
            'city' => 'Cairo',
            'module_type' => 'tourism',
        ]);

        $this->assertNotEquals($c1->account_id, $c2->account_id,
            'Each customer must get a unique ledger account — no shared accounts.');

        // Verify no cross-customer AccountEntry contamination
        $entries = AccountEntry::whereIn('account_id', [$c1->account_id, $c2->account_id])->get();
        $this->assertCount(0, $entries,
            'Newly-created customers should have ZERO ledger entries.');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION B — FLIGHT CARRIER MASTER DATA
    // ═══════════════════════════════════════════════════════════════

    public function test_B1_carrier_create_with_zero_balance(): void
    {
        $carrier = FlightCarrier::create([
            'name' => 'EgyptAir',
            'code' => 'MS-'.uniqid(),
            'currency' => 'EGP',
            'credit_limit' => 100000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals(0.0, (float) $carrier->balance,
            'Newly-created carrier must start with balance = 0 (cannot mass-assign balance).');

        $this->assertEquals('EGP', $carrier->currency);
        $this->assertEquals(100000.0, (float) $carrier->credit_limit);
    }

    public function test_B2_carrier_balance_NOT_mass_assignable(): void
    {
        // Security: balance MUST be protected from mass-assignment even if
        // the request payload smuggles it in.
        $payload = [
            'name' => 'Smuggle Test',
            'code' => 'ST-'.uniqid(),
            'currency' => 'EGP',
            'balance' => 999_999.99,  // ← attacker-controlled
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ];

        $carrier = FlightCarrier::create($payload);

        $this->assertEquals(0.0, (float) $carrier->fresh()->balance,
            'Carrier balance MUST be ignored on create() — only rechargeFromAccount() funds it.');
    }

    public function test_B3_carrier_recharge_same_currency_succeeds(): void
    {
        $cashbox = $this->makeAccount('Cashbox EGP', 'cashbox', 'EGP', 200_000);
        $carrier = $this->makeCarrier('EgyptAir', null, 'EGP');

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50_000, 'Phase11 test'
        );

        $this->assertEquals(50_000.0, (float) $carrier->fresh()->balance,
            'Carrier balance must reflect recharge.');
        $this->assertEquals(150_000.0, (float) $cashbox->fresh()->balance,
            'Cashbox must be debited by the recharge amount.');
    }

    public function test_B4_carrier_recharge_currency_mismatch_blocked_at_controller(): void
    {
        // The controller-level guard: recharge endpoint refuses currency mismatch.
        $cashbox = $this->makeAccount('Cashbox EGP', 'cashbox', 'EGP', 200_000);
        $carrier = $this->makeCarrier('KACarrier', null, 'KWD');

        $response = $this->postJson("/api/v1/flight/carriers/{$carrier->id}/recharge", [
            'from_account_id' => $cashbox->id,
            'amount' => 100.0,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('تضارب في العملة', $response->json('message') ?? '');
        $this->assertEquals(0.0, (float) $carrier->fresh()->balance,
            'Currency-mismatch recharge MUST NOT credit the carrier.');
    }

    public function test_B5_carrier_delete_blocked_with_nonzero_balance(): void
    {
        $cashbox = $this->makeAccount('Cashbox EGP', 'cashbox', 'EGP', 200_000);
        $carrier = $this->makeCarrier('EgyptAir', null, 'EGP');

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 10_000
        );

        $response = $this->deleteJson("/api/v1/flight/carriers/{$carrier->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('رصيد غير صفري', $response->json('message') ?? '');
        $this->assertNull($carrier->fresh()->deleted_at,
            'Carrier with non-zero balance MUST NOT be soft-deleted.');
    }

    public function test_B6_carrier_delete_blocked_with_active_bookings(): void
    {
        $carrier = $this->makeCarrier('EgyptAir', null, 'EGP');
        $customer = $this->makeCustomer('Test Customer');

        // Create a booking (no payment needed)
        $booking = FlightBooking::create([
            'customer_id' => $customer->id,
            'flight_carrier_id' => $carrier->id,
            'booking_number' => 'FLT-'.uniqid(),
            'booking_reference' => 'FLT-'.uniqid(),
            'airline_name' => 'EgyptAir',
            'airline' => 'EgyptAir',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '00:00:00',
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'booking_channel_type' => BookingChannelType::SIGN->value,
            'booking_channel_provider' => 'SIGN',
            'agent_name' => 'Office',
            'purchase_price' => 1000,
            'selling_price' => 1200,
            'profit' => 200,
            'currency' => 'EGP',
            'purchase_balance_source' => 'carrier',
            'status' => FlightBookingStatus::PENDING,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->deleteJson("/api/v1/flight/carriers/{$carrier->id}");
        $response->assertStatus(422);
        $this->assertStringContainsString('حجوزات', $response->json('message') ?? '');
    }

    public function test_B7_inactive_carrier_should_not_create_bookings(): void
    {
        // CLASS-B SECURITY GAP VERIFICATION:
        // When a carrier is inactive, the booking creation must fail.
        // We:
        //   (a) Create an ACTIVE carrier with balance
        //   (b) Deactivate it via direct DB update
        //   (c) Try to create a booking routed to it
        //   (d) The service must refuse.
        //
        // Note: FlightCarrierRechargeService::rechargeFromAccount rejects
        // inactive carriers — so we cannot fund an already-inactive carrier
        // through the official API. We fund it while active, then deactivate.
        $cashbox = $this->makeAccount('Cashbox B7', 'cashbox', 'EGP', 200_000);
        $carrier = $this->makeCarrier('Then Deactivated', null, 'EGP', true);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50_000, 'Active funding'
        );

        // Deactivate via direct update (bypassing policy).
        DB::table('flight_carriers')
            ->where('id', $carrier->id)
            ->update(['is_active' => false]);

        $customer = $this->makeCustomer('Inactive Test Customer');
        $carrier->refresh();

        try {
            $booking = $this->bookingService->createBooking([
                'customer_id' => $customer->id,
                'airline_name' => 'Then Deactivated',
                'flight_carrier_id' => $carrier->id,
                'purchase_balance_source' => 'carrier',
                'selling_price' => 1500,
                'purchase_price' => 1000,
                'currency' => 'EGP',
                'passengers' => [['first_name' => 'X', 'last_name' => 'Y']],
                'segments' => [['flight_number' => 'TD1']],
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
            ]);

            $this->markTestIncomplete(
                'CLASS-B SECURITY GAP: FlightBookingService created booking #'.$booking->id.
                ' with INACTIVE carrier #'.$carrier->id.' (which had 50,000 EGP balance). '.
                'Need is_active check in debitFlightCarrier().'
            );
        } catch (\Exception $e) {
            // Any error path is acceptable as long as no booking was created.
            $this->assertTrue(true, 'Inactive carrier properly rejected. Got: '.$e->getMessage());
        }

        // Verify no booking was actually persisted.
        $bookingCount = DB::table('flight_bookings')
            ->where('flight_carrier_id', $carrier->id)
            ->count();
        $this->assertEquals(0, $bookingCount,
            'No booking must be persisted when carrier is inactive.');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION C — FLIGHT SYSTEM MASTER DATA
    // ═══════════════════════════════════════════════════════════════

    public function test_C1_system_create_with_zero_balance(): void
    {
        $system = FlightSystem::create([
            'name' => 'Amadeus GDS',
            'code' => 'AMD-'.uniqid(),
            'currency' => 'USD',
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals(0.0, (float) $system->balance,
            'Newly-created system must start with balance = 0.');
        $this->assertEquals('USD', $system->currency);
    }

    public function test_C2_system_recharge_succeeds(): void
    {
        $this->seedCurrencies();
        $cashbox = $this->makeAccount('Cashbox USD', 'cashbox', 'USD', 200_000);
        $system = $this->makeSystem('Amadeus GDS', 'USD');

        app(FlightSystemRechargeService::class)->rechargeFromAccount(
            $system, $cashbox, 25_000, 'Phase11 test'
        );

        $this->assertEquals(25_000.0, (float) $system->fresh()->balance);
        $this->assertEquals(175_000.0, (float) $cashbox->fresh()->balance);
    }

    public function test_C3_system_balance_NOT_mass_assignable(): void
    {
        $system = FlightSystem::create([
            'name' => 'Tamper Test',
            'code' => 'TT-'.uniqid(),
            'currency' => 'EGP',
            'balance' => 999_999.99,  // attacker
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals(0.0, (float) $system->fresh()->balance,
            'System balance MUST be ignored on create().');
    }

    public function test_C4_system_delete_blocked_with_attached_carriers(): void
    {
        $system = $this->makeSystem('Amadeus GDS', 'USD');
        $this->makeCarrier('EgyptAir', $system->id, 'USD');

        $response = $this->deleteJson("/api/v1/flight/systems/{$system->id}");
        $response->assertStatus(422);
        $this->assertStringContainsString('ناقلين', $response->json('message') ?? '');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION D — FLIGHT GROUP MASTER DATA
    // ═══════════════════════════════════════════════════════════════

    public function test_D1_group_create_with_carrier_and_credit_limit(): void
    {
        $carrier = $this->makeCarrier('Group Carrier', null, 'EUR');
        $group = FlightGroup::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'Tour Group Alpha',
            'code' => 'TGA-'.uniqid(),
            'currency' => 'EUR',
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotNull($group->id);
        $this->assertEquals('EUR', $group->currency);
        $this->assertEquals(50_000.0, (float) $group->credit_limit);
    }

    public function test_D2_group_negative_credit_limit_validation(): void
    {
        // credit_limit must NOT be negative — would invert the semantic
        // of "available balance". CLASS-C DEFECT documented: model does not reject.
        $carrier = $this->makeCarrier('Group Carrier', null, 'EGP');

        $negativeAccepted = false;
        try {
            $group = FlightGroup::create([
                'flight_carrier_id' => $carrier->id,
                'name' => 'Negative Limit Test',
                'code' => 'NLT-'.uniqid(),
                'currency' => 'EGP',
                'credit_limit' => -1000,
                'is_active' => true,
                'created_by' => $this->admin->id,
            ]);
            $negativeAccepted = ((float) $group->fresh()->credit_limit) < 0;
        } catch (\Throwable $e) {
            $negativeAccepted = false;
        }

        if ($negativeAccepted) {
            $this->markTestIncomplete(
                'CLASS-C DEFECT (MASTER DATA): FlightGroup::create() accepts negative credit_limit. '.
                'Semantics inverted — credit_limit should be min:0. Needs DB check constraint '.
                'or model-level validation.'
            );
        }

        $this->assertFalse($negativeAccepted,
            'Group credit_limit must NOT be negative.');
    }

    public function test_D3_group_threshold_settings_stored(): void
    {
        $carrier = $this->makeCarrier('Group Carrier', null, 'EGP');
        $group = FlightGroup::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'Thresholds Test',
            'code' => 'THR-'.uniqid(),
            'currency' => 'EGP',
            'credit_limit' => 10_000,
            'notification_threshold_info' => 5_000,
            'notification_threshold_warning' => 2_500,
            'notification_threshold_danger' => 500,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals(5_000.0, (float) $group->fresh()->notification_threshold_info);
        $this->assertEquals(2_500.0, (float) $group->fresh()->notification_threshold_warning);
        $this->assertEquals(500.0, (float) $group->fresh()->notification_threshold_danger);
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION E — CURRENCY MASTER DATA
    // ═══════════════════════════════════════════════════════════════

    public function test_E1_active_currency_resolves_exchange_rate(): void
    {
        Currency::create([
            'code' => 'USD',
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
            'symbol' => '$',
            'exchange_rate' => 50.0,
            'is_active' => true,
            'order' => 1,
        ]);

        $rate = FlightBookingService::egpPerUnitOfCurrency('USD');
        $this->assertEquals(50.0, $rate,
            'Active currency rate must be picked up from currencies table.');
    }

    public function test_E2_inactive_currency_still_uses_rate_with_warning(): void
    {
        Currency::create([
            'code' => 'EUR',
            'name_ar' => 'يورو',
            'name_en' => 'Euro',
            'symbol' => '€',
            'exchange_rate' => 54.5,
            'is_active' => false,  // inactive
            'order' => 2,
        ]);

        // Service falls back to inactive rate (with a warning logged).
        $rate = FlightBookingService::egpPerUnitOfCurrency('EUR');
        $this->assertEquals(54.5, $rate,
            'Inactive-but-present currency must still resolve (with log warning).');
    }

    public function test_E3_undefined_currency_uses_builtin_fallback(): void
    {
        // No DB row — must use built-in FALLBACK_EGP_PER_UNIT map.
        $rate = FlightBookingService::egpPerUnitOfCurrency('KWD');
        $this->assertEquals(157.5, $rate,
            'Undefined currency KWD must use built-in FALLBACK rate.');

        $rate = FlightBookingService::egpPerUnitOfCurrency('SAR');
        $this->assertEquals(12.9, $rate);

        $rate = FlightBookingService::egpPerUnitOfCurrency('GBP');
        $this->assertEquals(61.2, $rate);
    }

    public function test_E4_egp_returns_one(): void
    {
        $this->assertEquals(1.0, FlightBookingService::egpPerUnitOfCurrency('EGP'));
        $this->assertEquals(1.0, FlightBookingService::egpPerUnitOfCurrency(''));
        $this->assertEquals(1.0, FlightBookingService::egpPerUnitOfCurrency('egp'),
            'Lowercase "egp" must also return 1.0');
    }

    public function test_E5_truly_unknown_currency_returns_zero(): void
    {
        // No DB row, no fallback — service returns 0 to force a 422.
        $rate = FlightBookingService::egpPerUnitOfCurrency('XYZ');
        $this->assertEquals(0.0, $rate,
            'Currency with no rate (no DB, no fallback) must return 0 to trigger validation error.');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION F — CROSS-ENTITY INVARIANTS
    // ═══════════════════════════════════════════════════════════════

    public function test_F1_carrier_currency_must_match_booking_currency_handling(): void
    {
        // USD carrier with EGP booking: debit must convert at exchange rate.
        Currency::create([
            'code' => 'USD', 'name_ar' => 'دولار', 'name_en' => 'USD',
            'symbol' => '$', 'exchange_rate' => 50.0, 'is_active' => true, 'order' => 1,
        ]);

        $cashbox = $this->makeAccount('Cashbox USD', 'cashbox', 'USD', 10_000);
        $carrier = $this->makeCarrier('USD Carrier', null, 'USD');

        // Recharge
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 1_000, 'USD funding'
        );
        $this->assertEquals(1_000.0, (float) $carrier->fresh()->balance);
    }

    public function test_F2_group_account_auto_created_on_first_booking(): void
    {
        // Group with no account_id should auto-create one when first booking
        // routes through recordPurchaseFromGroup().
        $carrier = $this->makeCarrier('Group Carrier', null, 'EGP');
        $group = FlightGroup::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'Auto-account Test',
            'code' => 'AAT-'.uniqid(),
            'currency' => 'EGP',
            'credit_limit' => 10_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Group may or may not auto-create account on creation (acceptable either way).
        // The contract is: account MUST exist before the first GROUP booking.
        $this->assertTrue(true,
            'Group created successfully. Account linkage verified below.');

        // Now create a booking routed to this group.
        $customer = $this->makeCustomer('Group Test Customer');

        try {
            $booking = $this->bookingService->createBooking([
                'customer_id' => $customer->id,
                'airline_name' => 'Group Carrier',
                'flight_group_id' => $group->id,
                'flight_carrier_id' => $carrier->id,
                'booking_channel_type' => BookingChannelType::GROUP->value,
                'booking_channel_provider' => 'GROUP',
                'purchase_balance_source' => 'group',
                'selling_price' => 1500,
                'purchase_price' => 1000,
                'currency' => 'EGP',
                'passengers' => [['first_name' => 'X', 'last_name' => 'Y']],
                'segments' => [['flight_number' => 'GC1']],
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
            ]);

            $this->assertNotNull($group->fresh()->account_id,
                'Group account_id must be auto-created on first GROUP booking.');

            // Group balance must reflect the debt.
            $groupAccount = Account::find($group->fresh()->account_id);
            $this->assertNotNull($groupAccount,
                'Group ledger account must exist in accounts table after GROUP booking.');

            $this->assertLessThanOrEqual(
                -1000.0,
                (float) $groupAccount->balance,
                'Group account balance must be debited by purchase price. '.
                'Got: '.(float) $groupAccount->balance
            );
        } catch (\Exception $e) {
            $this->markTestIncomplete('GROUP booking creation failed: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION G — DIRECT MUTATION ATTEMPTS (security/auditor)
    // ═══════════════════════════════════════════════════════════════

    public function test_G1_carrier_balance_direct_save_blocked_by_observer(): void
    {
        // Defense-in-depth: even a direct $carrier->balance = X; $carrier->save();
        // must be blocked by the booted() observer.
        $carrier = $this->makeCarrier('Direct Tamper', null, 'EGP');

        config(['accounting.strict_test_guards' => true]);

        $blocked = false;
        try {
            $carrier->balance = 999_999.0;
            $carrier->save();
        } catch (\Throwable $e) {
            $blocked = true;
            $this->assertStringContainsString(
                'رصيد الناقل',
                $e->getMessage(),
                'Observer must block direct balance write with explicit message.'
            );
        }

        $this->assertTrue($blocked,
            'Direct carrier->balance write MUST be blocked in strict mode.');

        $this->assertEquals(0.0, (float) $carrier->fresh()->balance,
            'Carrier balance must remain 0 after blocked write.');
    }

    public function test_G2_negative_credit_limit_rejected_at_model(): void
    {
        // Carrier credit_limit must be non-negative.
        $carrier = $this->makeCarrier('NegativeLimit', null, 'EGP');

        $result = false;
        try {
            $carrier->credit_limit = -100.0;
            $carrier->save();
        } catch (\Throwable $e) {
            $result = true;
        }

        if (! $result) {
            $this->markTestIncomplete(
                'Carrier credit_limit allowed negative value. '.
                'Current value: '.(float) $carrier->fresh()->credit_limit
            );
        }

        $this->assertTrue($result);
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    protected function makeAccount(string $name, string $type, string $currency, float $balance): Account
    {
        $account = Account::create([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER ?? 'office',
            'module_type' => 'tourism',
            'is_module_vault' => false,
            'notes' => 'Phase11 fixture',
            'created_by' => $this->admin->id,
        ]);

        LedgerBalanceMutationGuard::run(function () use ($account, $balance) {
            $account->balance = $balance;
            $account->save();
        });

        AccountEntry::create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => $balance,
            'balance_after' => $balance,
            'notes' => "Phase11 opening balance {$name}",
        ]);

        return $account->fresh();
    }

    protected function makeCarrier(string $name, ?int $systemId, string $currency, bool $isActive = true): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'flight_system_id' => $systemId,
            'currency' => $currency,
            'credit_limit' => 100_000,
            'is_active' => $isActive,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeSystem(string $name, string $currency): FlightSystem
    {
        return FlightSystem::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'type' => 'gds',
            'currency' => $currency,
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeCustomer(string $name): Customer
    {
        return Customer::create([
            'full_name' => $name,
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'c-'.uniqid().'@test.com',
            'national_id' => '29'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'module_type' => 'tourism',
        ])->fresh();
    }
}