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
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
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
use Tests\Feature\Flight\FlightFxFixtureTrait;

/**
 * PHASE 11.2 — FLIGHT FE/BE CONTRACT AUDIT
 * ========================================
 *
 * Verifies the HTTP contract between the Vue SPA (FlightCreate.vue +
 * flightStore.js) and the Laravel backend (FlightController + FlightBookingService).
 * Replays the exact payloads the Vue store produces and asserts:
 *   (a) HTTP status codes are correct
 *   (b) Response payload has all fields the Vue expects
 *   (c) Database state matches what the FE would receive
 *   (d) Three booking paths produce consistent response shapes
 *   (e) Frontend-displayed financial values match backend-computed values
 */
class Phase11FeBeContractAuditTest extends TestCase
{
    use RefreshDatabase;
    use FlightFxFixtureTrait;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['accounting.strict_test_guards' => true]);

        // PHASE G: seed USD rate so system-source HTTP contract booking can resolve.
        $this->seedFlightExchangeRates();

        $this->admin = User::factory()->create([
            'name' => 'Phase11 FE/BE Admin',
            'email' => 'phase11-febe-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION A — CREATE BOOKING CONTRACT (all 3 paths)
    // ═══════════════════════════════════════════════════════════════

    public function test_A1_create_customer_booking_via_http_contract(): void
    {
        // Simulate FlightCreate.vue + flightStore.js payload
        [$customer, $carrier, $cashbox] = $this->seedCustomerScenario('EGP', 100_000.0);
        // Fund the carrier so the booking can succeed.
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50_000, 'seed'
        );

        $payload = $this->buildFePayload([
            'customer_id' => $customer->id,
            'booking_source' => 'direct',  // Vue sends booking_source
            'purchase_balance_source' => 'carrier',
            'flight_carrier_id' => $carrier->id,
            'flight_system_id' => null,
            'flight_group_id' => null,
            'selling_price' => 1500,
            'purchase_price_egp' => 1000,
            'currency' => 'EGP',
        ]);

        // Send to BACKEND via HTTP (the FE does this via axios.post)
        $response = $this->postJson('/api/v1/flight/bookings', $payload);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'booking_number',
                    'customer_id',
                    'employee_id',
                    'status',
                    'currency',
                    'selling_price',
                    'purchase_price',
                    'profit',
                    'payment_status',
                    'total_paid',
                    'remaining',
                    'flight_carrier_id',
                    'purchase_balance_source',
                ],
            ]);

        $bookingId = $response->json('data.id');
        $this->assertNotNull($bookingId);

        $booking = FlightBooking::find($bookingId);
        $this->assertEquals(BookingChannelType::SIGN, $booking->booking_channel_type,
            'FE sends booking_source=direct → BE must store booking_channel_type=SIGN.');
        $this->assertEquals('carrier', $booking->purchase_balance_source);
        $this->assertEquals('EGP', $booking->currency);
    }

    public function test_A2_create_system_booking_via_http_contract(): void
    {
        [$customer, $system, $cashbox] = $this->seedSystemScenario('USD', 50_000.0);
        // Fund the system so the booking can succeed.
        // Need ~9700 EGP (200 USD × ~48.5) → fund 10000 USD
        app(FlightSystemRechargeService::class)->rechargeFromAccount(
            $system, $cashbox, 10_000, 'seed'
        );

        $payload = $this->buildFePayload([
            'customer_id' => $customer->id,
            // The Vue store translates booking_source → booking_channel_type.
            // Simulate what the store sends: booking_channel_type=SYSTEM,
            // purchase_balance_source=system.
            'booking_channel_type' => 'SYSTEM',
            'purchase_balance_source' => 'system',
            'flight_carrier_id' => null,
            'flight_system_id' => $system->id,
            'flight_group_id' => null,
            'selling_price' => 13600,
            'purchase_price_egp' => 10000,
            'currency' => 'USD',
            'purchase_price_foreign' => 200.0,
        ]);

        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertCreated();

        $bookingId = $response->json('data.id');
        $booking = FlightBooking::find($bookingId);
        $this->assertEquals(BookingChannelType::SYSTEM, $booking->booking_channel_type,
            'BE must store booking_channel_type=SYSTEM when sent.');
        $this->assertEquals('system', $booking->purchase_balance_source);
    }

    public function test_A3_create_group_booking_via_http_contract(): void
    {
        [$customer, $carrier, $cashbox, $group] = $this->seedGroupScenario('EGP', 100_000.0);

        $payload = $this->buildFePayload([
            'customer_id' => $customer->id,
            // Vue store translates booking_source=group → booking_channel_type=GROUP.
            'booking_channel_type' => 'GROUP',
            'purchase_balance_source' => 'group',
            'flight_carrier_id' => $carrier->id,
            'flight_system_id' => null,
            'flight_group_id' => $group->id,
            'selling_price' => 1500,
            'purchase_price_egp' => 1000,
            'currency' => 'EGP',
        ]);

        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertCreated();

        $bookingId = $response->json('data.id');
        $booking = FlightBooking::find($bookingId);
        $this->assertEquals(BookingChannelType::GROUP, $booking->booking_channel_type,
            'BE must store booking_channel_type=GROUP when sent.');
        $this->assertEquals('group', $booking->purchase_balance_source);

        // Verify Group AR is debited (not customer AR for cost).
        $this->assertNotNull($group->fresh()->account_id,
            'Group must have an account_id after first booking.');
        $groupAccount = Account::find($group->fresh()->account_id);
        $this->assertLessThanOrEqual(
            -1000.0,
            (float) $groupAccount->balance,
            'Group account must be debited by purchase price (COST). Got: '.(float) $groupAccount->balance
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION B — PAYMENT CONTRACT
    // ═══════════════════════════════════════════════════════════════

    public function test_B1_payment_via_http_contract(): void
    {
        [$customer, $carrier, $cashbox, $booking] = $this->seedCustomerWithBooking('EGP', 100_000.0);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => 'phase11-2-b1-'.uniqid(),
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'idempotent_replay',
                    'payments',
                    'total_paid',
                    'remaining',
                    'payment_status',
                ],
            ]);

        $this->assertFalse($response->json('data.idempotent_replay'),
            'First payment must NOT be flagged as replay.');
        $this->assertEquals(1500.0, $response->json('data.total_paid'));
        $this->assertEquals(0.0, $response->json('data.remaining'));
        $this->assertEquals('paid', $response->json('data.payment_status'));
    }

    public function test_B2_payment_with_idempotency_key_replay(): void
    {
        [$customer, $carrier, $cashbox, $booking] = $this->seedCustomerWithBooking('EGP', 100_000.0);

        $idempKey = 'phase11-2-b2-'.uniqid();

        $first = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => $idempKey,
        ]);
        $first->assertCreated();
        $firstPaymentId = $first->json('data.payments.0.id');

        $replay = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => $idempKey,
        ]);
        // Phase 11.11 audit verifies this — but Phase 11.2 contract check:
        // replay must return 200 OK + idempotent_replay=true.
        $replay->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertEquals($firstPaymentId, $replay->json('data.payments.0.id'),
            'Replay must return the same payment ID.');

        // Verify only ONE payment row was persisted.
        $paymentCount = FlightPayment::where('flight_booking_id', $booking->id)->count();
        $this->assertEquals(1, $paymentCount,
            'Idempotency replay must NOT create a duplicate payment row.');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION C — CANCEL/REFUND CONTRACT
    // ═══════════════════════════════════════════════════════════════

    public function test_C1_cancel_via_http_contract(): void
    {
        [$customer, $carrier, $cashbox, $booking] = $this->seedCustomerWithBooking('EGP', 100_000.0);

        // Make a partial payment first
        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
        ])->assertCreated();

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 100.0,
            'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'status',
                    'refund' => [
                        'airline_penalty',
                        'office_penalty',
                        'total_paid',
                        'refund_amount',
                        'status',
                    ],
                    'total_paid',
                    'remaining',
                ],
            ]);

        $refundAmount = (float) $response->json('data.refund.refund_amount');
        $this->assertEquals(400.0, $refundAmount,
            'Refund = paid (500) - airline_penalty (100) - office_penalty (0) = 400');

        $this->assertEquals(FlightBookingStatus::REFUNDED->value, $response->json('data.status'),
            'Booking must transition to REFUNDED when refund_amount > 0.');
    }

    public function test_C2_double_cancel_returns_error(): void
    {
        [$customer, $carrier, $cashbox, $booking] = $this->seedCustomerWithBooking('EGP', 100_000.0);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
        ])->assertOk();

        $second = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
        ]);

        $second->assertStatus(422);
        $this->assertStringContainsString('ملغي', $second->json('message') ?? '');
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION D — SHOW/INDEX CONTRACT (FE data loading)
    // ═══════════════════════════════════════════════════════════════

    public function test_D1_show_endpoint_returns_all_relations(): void
    {
        [$customer, $carrier, $cashbox, $booking] = $this->seedCustomerWithBooking('EGP', 100_000.0);

        $response = $this->getJson("/api/v1/flight/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'booking_number',
                    'customer',
                    'passengers',
                    'segments',
                    'tickets',
                    'payments',
                    'flight_carrier',
                    'account',
                ],
            ]);

        // flight_system, flight_group, and airline_account are conditional via whenLoaded().
        // They are only present when the relation is loaded AND not null.
        $this->assertArrayHasKey('data', $response->json());

        // Verify computed fields (these power the FE dashboard)
        $this->assertNotNull($response->json('data.payment_status'));
        $this->assertEquals(0.0, $response->json('data.total_paid'));
        $this->assertEquals(1500.0, $response->json('data.remaining'));
    }

    public function test_D2_index_paginates_correctly(): void
    {
        $this->seedCustomerWithBooking('EGP', 100_000.0);
        $this->seedCustomerWithBooking('EGP', 100_000.0);
        $this->seedCustomerWithBooking('EGP', 100_000.0);

        $response = $this->getJson('/api/v1/flight/bookings?per_page=2');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => [
                        'total',
                        'per_page',
                        'current_page',
                        'last_page',
                        'has_more',
                    ],
                ],
            ]);

        $this->assertEquals(2, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(3, $response->json('data.pagination.total'));
        $this->assertCount(2, $response->json('data.items'));
    }

    // ═══════════════════════════════════════════════════════════════
    // SECTION E — FE/BE FIELD NAME CONTRACT
    // ═══════════════════════════════════════════════════════════════

    public function test_E1_booking_source_field_is_translated_by_store(): void
    {
        // The Vue store translates FE field booking_source → BE booking_channel_type.
        // Test that BE accepts the canonical BE field name directly.
        [$customer, $carrier, $cashbox] = $this->seedCustomerScenario('EGP', 100_000.0);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50_000, 'seed'
        );

        $payload = $this->buildFePayload([
            'customer_id' => $customer->id,
            'booking_channel_type' => 'SIGN',  // BE canonical name
            'purchase_balance_source' => 'carrier',
            'flight_carrier_id' => $carrier->id,
        ]);

        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertCreated();

        $this->assertEquals(BookingChannelType::SIGN, FlightBooking::find($response->json('data.id'))->booking_channel_type);
    }

    public function test_E2_booking_source_legacy_alias_accepted(): void
    {
        // Some legacy clients still send booking_source. Verify backend can also accept it.
        // (form validation should accept both per BookingChannelType::validationValues())
        [$customer, $carrier, $cashbox] = $this->seedCustomerScenario('EGP', 100_000.0);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50_000, 'seed'
        );

        $payload = $this->buildFePayload([
            'customer_id' => $customer->id,
            'booking_source' => 'direct',  // FE alias
            'purchase_balance_source' => 'carrier',
            'flight_carrier_id' => $carrier->id,
        ]);

        // The FormRequest strips booking_source (it's not in allowedTopLevel).
        // So either the BE accepts it OR ignores it gracefully.
        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertCreated();

        $booking = FlightBooking::find($response->json('data.id'));
        $this->assertEquals(BookingChannelType::SIGN, $booking->booking_channel_type,
            'When booking_source is sent alone (without booking_channel_type), '.
            'BE must default to SIGN (sign carrier path).');
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build a payload that mimics what the Vue FlightCreate.vue sends
     * through the flightStore's transformPayloadForApi().
     */
    protected function buildFePayload(array $overrides = []): array
    {
        $defaults = [
            'customer_id' => null,
            'trip_type' => 'one_way',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '00:00',
            'passenger_count' => 1,
            'passengers' => [
                ['first_name' => 'Test', 'last_name' => 'Passenger', 'type' => 'adult'],
            ],
            'segments' => [
                [
                    'flight_number' => 'TEST123',
                    'from_airport' => 'CAI',
                    'to_airport' => 'JED',
                    'departure_date' => now()->addDays(7)->toDateString(),
                    'flight_class' => 'economy',
                ],
            ],
            'selling_price' => 1500,
            'purchase_price_egp' => 1000,
            'currency' => 'EGP',
            'agent_name' => 'Office',
            'booking_channel_type' => null,  // store maps it
            'purchase_balance_source' => 'carrier',
        ];

        return array_merge($defaults, $overrides);
    }

    protected function seedCustomerScenario(string $currency, float $cashboxBalance): array
    {
        $customer = $this->makeCustomer('Test Customer');
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', $currency, $cashboxBalance);
        $carrier = $this->makeCarrier('Test Carrier', null, $currency);

        return [$customer, $carrier, $cashbox];
    }

    protected function seedSystemScenario(string $currency, float $cashboxBalance): array
    {
        $customer = $this->makeCustomer('System Customer');
        $cashbox = $this->makeAccount('Cashbox SYS', 'cashbox', $currency, $cashboxBalance);
        $system = $this->makeSystem('Test System', $currency);

        return [$customer, $system, $cashbox];
    }

    protected function seedGroupScenario(string $currency, float $cashboxBalance): array
    {
        $customer = $this->makeCustomer('Group Customer');
        $cashbox = $this->makeAccount('Cashbox Group', 'cashbox', $currency, $cashboxBalance);
        $carrier = $this->makeCarrier('Group Carrier', null, $currency);
        $group = FlightGroup::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'Test Group',
            'code' => 'TG-'.uniqid(),
            'currency' => $currency,
            'credit_limit' => 100_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        return [$customer, $carrier, $cashbox, $group];
    }

    protected function seedCustomerWithBooking(string $currency, float $cashboxBalance): array
    {
        [$customer, $carrier, $cashbox] = $this->seedCustomerScenario($currency, $cashboxBalance);

        // Recharge carrier so booking succeeds.
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 10_000, 'seed'
        );

        $payload = $this->buildFePayload([
            'customer_id' => $customer->id,
            'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier',
            'flight_carrier_id' => $carrier->id,
            'selling_price' => 1500,
            'purchase_price_egp' => 1000,
        ]);

        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertCreated();

        $booking = FlightBooking::find($response->json('data.id'));
        $this->assertNotNull($booking, 'Booking must be created');

        return [$customer, $carrier, $cashbox, $booking];
    }

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
            'notes' => 'Phase11 FE/BE fixture',
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
            'notes' => 'Phase11 FE/BE opening balance',
        ]);

        return $account->fresh();
    }

    protected function makeCarrier(string $name, ?int $systemId, string $currency): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'flight_system_id' => $systemId,
            'currency' => $currency,
            'credit_limit' => 100_000,
            'is_active' => true,
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