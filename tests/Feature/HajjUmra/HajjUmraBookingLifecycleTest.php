<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 4.1 — Booking lifecycle (CREATE + PRICING + UPDATE).
 *
 * Scope:
 *   - POST /api/v1/hajj-umra/bookings  (store)
 *   - Pricing & accessor invariants (total_selling_price, paid_amount,
 *     remaining_amount, is_fully_paid, profit)
 *   - PUT /api/v1/hajj-umra/bookings/{id}  (update)
 *
 * Constraints respected (per Phase 4 protocol):
 *   - READ-ONLY: no production / migration / route / config changes.
 *   - Path C (`HajjUmraBookingService::repostIncomeTransaction()` line 327–350)
 *     is intentionally left untouched. Tests that exercise financial-update
 *     paths are written and will be reported as Known Deferred.
 *   - Only NEW tests in this file. Existing Hajj/Umrah tests NOT modified.
 *   - No Bus / Visa / Online files touched.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmraController
 * @see \App\Services\HajjUmra\HajjUmraBookingService
 * @see \App\Http\Requests\HajjUmra\StoreHajjUmraBookingRequest
 */
class HajjUmraBookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name'      => 'Booking Lifecycle Tester',
            'email'     => 'booking-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->treasury = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'خزينة الحج الرئيسية',
                'type'      => AccountType::Cashbox->value,
                'currency'  => 'EGP',
                'balance'   => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /* =========================================================
     *  Factories
     * ========================================================= */

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'full_name' => 'عميل حجز',
            'phone'     => '+20100' . random_int(1000000, 9999999),
            'email'     => 'booking-cust-' . uniqid('', true) . '@test.local',
            'national_id' => '299' . str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $overrides));
    }

    protected function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name'           => 'برنامج حج تجريبي',
            'program_type'           => 'hajj',
            'total_nights'           => 14,
            'mecca_nights'           => 8,
            'medina_nights'          => 6,
            'accommodation_type'     => 'DOUBLE',
            'mecca_hotel_name'       => 'فندق مكة',
            'medina_hotel_name'      => 'فندق المدينة',
            'departure_date'         => now()->addDays(60)->toDateString(),
            'return_date'            => now()->addDays(74)->toDateString(),
            'airline'                => 'Test Air',
            'executing_company'      => 'شركة تنفيذ',
            'departure_point'        => 'CAI',
            'default_selling_price'  => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active'              => true,
            'created_by'             => $this->admin->id,
        ], $overrides));
    }

    /**
     * Build a valid store payload; supports per-test overrides.
     */
    protected function payload(array $overrides = []): array
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();

        return array_merge([
            'customer_id'    => $customer->id,
            'program_id'     => $program->id,
            'purchase_price' => 40000,
            'selling_price'  => 50000,
            'currency'       => 'EGP',
            'per_person'     => true,
            'status'         => HajjUmraStatus::Confirmed->value,
            'agent_name'     => 'وكيل اختبار',
            'account_id'     => $this->treasury->id,
            'notes'          => 'تجربة حجز عادية',
        ], $overrides);
    }

    /* =========================================================
     *  4.1 CREATE — POST /api/v1/hajj-umra/bookings
     * ========================================================= */

    public function test_4_1_create_booking_with_valid_payload_returns_201(): void
    {
        $payload = $this->payload();

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertCreated();
        $bookingId = $response->json('data.id');
        $this->assertNotNull($bookingId);
        $this->assertGreaterThan(0, $bookingId);

        $booking = HajjUmraBooking::query()->find($bookingId);
        $this->assertNotNull($booking);
        $this->assertSame($payload['customer_id'], $booking->customer_id);
        $this->assertSame($payload['program_id'], $booking->program_id);
        $this->assertEqualsWithDelta((float) $payload['purchase_price'], (float) $booking->purchase_price, 0.01);
        $this->assertEqualsWithDelta((float) $payload['selling_price'], (float) $booking->selling_price, 0.01);
        $this->assertSame(HajjUmraStatus::Confirmed->value, $booking->status->value);
    }

    public function test_4_1_create_booking_with_inline_customer_creates_customer(): void
    {
        $program = $this->makeProgram();

        $payload = [
            'program_id'     => $program->id,
            'purchase_price' => 40000,
            'selling_price'  => 50000,
            'account_id'     => $this->treasury->id,
            'customer' => [
                'full_name' => 'عميل جديد',
                'phone'     => '+2011' . random_int(1000000, 9999999),
            ],
        ];

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        $this->assertDatabaseHas('customers', [
            'full_name' => 'عميل جديد',
        ]);

        $bookingId = $response->json('data.id');
        $booking = HajjUmraBooking::query()->find($bookingId);
        $this->assertNotNull($booking->customer_id);
    }

    public function test_4_1_create_missing_program_id_returns_422(): void
    {
        $payload = $this->payload();
        unset($payload['program_id']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_id']);
    }

    public function test_4_1_create_missing_purchase_price_returns_422(): void
    {
        $payload = $this->payload();
        unset($payload['purchase_price']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_price']);
    }

    public function test_4_1_create_missing_selling_price_returns_422(): void
    {
        $payload = $this->payload();
        unset($payload['selling_price']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['selling_price']);
    }

    public function test_4_1_create_with_non_existent_program_id_returns_422(): void
    {
        $payload = $this->payload(['program_id' => 99999]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_id']);
    }

    public function test_4_1_create_with_non_existent_account_id_returns_422(): void
    {
        $payload = $this->payload(['account_id' => 99999]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_4_1_create_with_negative_selling_price_returns_422(): void
    {
        $payload = $this->payload(['selling_price' => -1]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['selling_price']);
    }

    public function test_4_1_create_with_invalid_status_returns_422(): void
    {
        $payload = $this->payload(['status' => 'banana']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_4_1_create_with_companion_customer_succeeds(): void
    {
        $mainCustomer    = $this->makeCustomer(['full_name' => 'العميل الأساسي']);
        $companionCustomer = $this->makeCustomer(['full_name' => 'المرافق']);
        $program         = $this->makeProgram();

        $payload = [
            'customer_id'             => $mainCustomer->id,
            'companion_customer_id'   => $companionCustomer->id,
            'program_id'              => $program->id,
            'purchase_price'          => 40000,
            'selling_price'           => 50000,
            'companion_selling_price' => 30000,
            'account_id'              => $this->treasury->id,
        ];

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        $bookingId = $response->json('data.id');
        $booking = HajjUmraBooking::query()->find($bookingId);

        $this->assertSame($companionCustomer->id, $booking->companion_customer_id);
        $this->assertEqualsWithDelta(30000, (float) $booking->companion_selling_price, 0.01);
    }

    public function test_4_1_create_with_invalid_companion_customer_id_returns_422(): void
    {
        $payload = $this->payload(['companion_customer_id' => 99999]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['companion_customer_id']);
    }

    public function test_4_1_create_with_passengers_stores_passenger_rows(): void
    {
        $payload = $this->payload([
            'passengers' => [
                ['category' => 'adult', 'count' => 2, 'unit_price' => 25000, 'subtotal' => 50000],
                ['category' => 'child_with_bed', 'count' => 1, 'unit_price' => 15000, 'subtotal' => 15000],
            ],
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        $bookingId = $response->json('data.id');
        $passengerRows = DB::table('umrah_transaction_passengers')->where('transaction_id', $bookingId)->count();
        $this->assertSame(2, $passengerRows);
    }

    /* =========================================================
     *  4.2 PRICING — accessors + invariants
     * ========================================================= */

    public function test_4_2_profit_equals_selling_plus_companion_plus_accommodation_minus_purchase_minus_companion_purchase(): void
    {
        $payload = $this->payload([
            'purchase_price'           => 40000,
            'selling_price'            => 50000,
            'companion_purchase_price' => 5000,
            'companion_selling_price'  => 10000,
            'accommodation_extra_charge' => 2000,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        $booking = HajjUmraBooking::query()->find($response->json('data.id'));
        // total_selling = 50000 + 10000 + 2000 = 62000
        // total_purchase = 40000 + 5000 = 45000
        // profit = 62000 - 45000 = 17000
        $this->assertEqualsWithDelta(17000, (float) $booking->profit, 0.01);
    }

    public function test_4_2_negative_profit_accepted_no_validation(): void
    {
        // We document this as a (possibly) undesired behaviour: the system
        // accepts bookings where selling < purchase. The audit policy is to
        // surface it but NOT to change validation semantics in Phase 4
        // without explicit user approval.
        $payload = $this->payload([
            'purchase_price' => 80000,
            'selling_price'  => 50000,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        $response->assertCreated();
        $booking = HajjUmraBooking::query()->find($response->json('data.id'));
        $this->assertEqualsWithDelta(-30000, (float) $booking->profit, 0.01);
    }

    public function test_4_2_total_selling_price_accessor(): void
    {
        $payload = $this->payload([
            'selling_price'             => 50000,
            'companion_selling_price'   => 10000,
            'accommodation_extra_charge' => 3000,
        ]);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $bookingId = $response->json('data.id');

        $booking = HajjUmraBooking::query()->find($bookingId);
        $this->assertEqualsWithDelta(63000, $booking->total_selling_price, 0.01);
    }

    public function test_4_2_paid_amount_accessor_zero_when_no_payments(): void
    {
        $payload = $this->payload();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $bookingId = $response->json('data.id');

        $booking = HajjUmraBooking::query()->find($bookingId);
        $this->assertEqualsWithDelta(0, $booking->paid_amount, 0.01);
        $this->assertFalse($booking->is_fully_paid);
    }

    public function test_4_2_remaining_amount_accessor_clamped_to_zero(): void
    {
        $payload = $this->payload(['selling_price' => 50000]);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $booking = HajjUmraBooking::query()->find($response->json('data.id'));

        $this->assertEqualsWithDelta(50000, $booking->remaining_amount, 0.01);

        // Overpay pattern: negative remaining clamps to zero.
        DB::table('hajj_umra_payments')->insert([
            'hajj_umra_booking_id' => $booking->id,
            'amount'               => 999999,
            'payment_method'       => 'cash',
            'paid_by'              => 'cash',          // NOT NULL column (legacy)
            'treasury_account'     => 'office_drawer',   // NOT NULL column
            'payment_date'         => now(),             // NOT NULL column
            'account_id'           => $this->treasury->id,
            'currency'             => 'EGP',
            'created_by'           => $this->admin->id,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
        $booking->refresh();
        $this->assertEqualsWithDelta(0, $booking->remaining_amount, 0.01);
    }

    public function test_4_2_resource_includes_finance_paid_remaining_fully_paid_aliases(): void
    {
        $payload = $this->payload();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $bookingId = $response->json('data.id');

        $response = $this->getJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'status', 'status_label',
                    'pricing' => ['selling_price', 'purchase_price', 'profit'],
                    'finance' => ['paid_amount', 'remaining_amount', 'is_fully_paid'],
                ],
            ]);

        // The HajjUmraBookingResource nests these in `finance.*` (verified
        // in app/Http/Resources/HajjUmra/HajjUmraBookingResource.php lines 74–86).
        $this->assertEqualsWithDelta(50000, (float) $response->json('data.finance.paid_amount') + (float) $response->json('data.finance.remaining_amount'), 0.01);
        $this->assertEqualsWithDelta(0, (float) $response->json('data.finance.paid_amount'), 0.01);
        $this->assertFalse((bool) $response->json('data.finance.is_fully_paid'));
    }

    /* =========================================================
     *  NOTE — INCIDENT-2026-08-17 (Tourism No-Edit Contract):
     *  PUT/PATCH update tests have been REMOVED. The update route
     *  no longer exists (see `routes/api.php` and
     *  `TourismNoEditContractTest` for the contract enforcement tests).
     *  See `docs/TOURISM_NO_EDIT_CONTRACT.md`.
     * ========================================================= */

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $payload = $this->payload($overrides);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        return HajjUmraBooking::query()->find($response->json('data.id'));
    }
}
