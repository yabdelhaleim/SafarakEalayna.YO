<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\User;
use App\Models\VisaBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\Visa\VisaBookingController.
 *
 * @see \App\Http\Controllers\Api\V1\Visa\VisaBookingController
 */
class VisaBookingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected VisaDuration $duration;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Booking Tester',
            'email' => 'visa-booking@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Visa Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'visas',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => '6m_single',
            'label_ar' => '6 أشهر',
            'label_en' => '6 months',
            'months' => 6,
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Visa Test Customer',
            'phone' => '01000000099',
        ]);
    }

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 5000,
            'selling_price' => 7000,
            'service_fee' => 0,
            'currency' => 'EGP',
            'account_id' => $this->treasury->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Work->value,
                'country' => 'USA',
                'duration' => '6 months',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
            ],
        ], $overrides);
    }

    /* =========================================================
     * INDEX
     * ========================================================= */

    public function test_index_returns_paginated_list(): void
    {
        $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());

        $response = $this->getJson('/api/v1/visa/bookings');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_index_filters_by_country(): void
    {
        $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());

        $response = $this->getJson('/api/v1/visa/bookings?country=USA');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('data.pagination.total'));
    }

    /* =========================================================
     * STORE
     * ========================================================= */

    public function test_store_creates_visa_booking_and_returns_201(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'customer', 'pricing'],
            ]);

        $this->assertDatabaseHas('visa_bookings', [
            'id' => $response->json('data.id'),
            'customer_id' => $this->customer->id,
            'currency' => 'EGP',
        ]);
    }

    public function test_store_requires_purchase_and_selling_prices(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['purchase_price']);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_price']);
    }

    public function test_store_requires_visa_details_visa_type(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['visa_details']['visa_type']);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['visa_details.visa_type']);
    }

    public function test_store_requires_visa_details_country(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['visa_details']['country']);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['visa_details.country']);
    }

    public function test_store_rejects_currency_mismatch_account(): void
    {
        // Create a USD treasury — booking is EGP, so this should fail
        $usdTreasury = Account::query()->create([
            'name' => 'Visa Treasury USD',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'visas',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'account_id' => $usdTreasury->id,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    /* =========================================================
     * SHOW
     * ========================================================= */

    public function test_show_returns_visa_booking_details(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->getJson("/api/v1/visa/bookings/{$id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    /* =========================================================
     * UPDATE
     * ========================================================= */

    public function test_update_modifies_selling_price(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->putJson("/api/v1/visa/bookings/{$id}", [
            'selling_price' => 8000,
        ]);

        $response->assertOk();
        $booking = VisaBooking::query()->findOrFail($id);
        $this->assertEqualsWithDelta(8000.0, (float) $booking->selling_price, 0.01);
    }

    /* =========================================================
     * ADD PAYMENT
     * ========================================================= */

    public function test_add_payment_creates_payment_record(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->postJson("/api/v1/visa/bookings/{$id}/payments", [
            'amount' => 3000,
            'payment_method' => 'cash',
            'account_id' => $this->treasury->id,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'payment' => ['id', 'amount'],
                    'booking' => ['id'],
                ],
            ]);

        $this->assertEquals(3000, $response->json('data.payment.amount'));
    }

    public function test_add_payment_validates_amount_gt_zero(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->postJson("/api/v1/visa/bookings/{$id}/payments", [
            'amount' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->treasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /* =========================================================
     * CANCEL
     * ========================================================= */

    public function test_cancel_flips_status_to_cancelled(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->postJson("/api/v1/visa/bookings/{$id}/cancel", [
            'reason' => 'test cancel',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('visa_bookings', [
            'id' => $id,
            'status' => 'cancelled',
        ]);
    }

    /* =========================================================
     * REFUND
     * ========================================================= */

    public function test_refund_flips_status_to_refunded(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->postJson("/api/v1/visa/bookings/{$id}/refund", [
            'reason' => 'test refund',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('visa_bookings', [
            'id' => $id,
            'status' => 'refunded',
        ]);
    }

    /* =========================================================
     * MODIFICATIONS HISTORY
     * ========================================================= */

    public function test_modifications_returns_history(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->getJson("/api/v1/visa/bookings/{$id}/modifications");

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    /* =========================================================
     * DESTROY (soft delete with reversal)
     * ========================================================= */

    public function test_destroy_soft_deletes_booking(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $response = $this->deleteJson("/api/v1/visa/bookings/{$id}");

        $response->assertOk();
        $booking = VisaBooking::withTrashed()->findOrFail($id);
        $this->assertNotNull($booking->deleted_at);
    }

    public function test_destroy_returns_404_for_unknown_id(): void
    {
        $response = $this->deleteJson('/api/v1/visa/bookings/999999');

        $response->assertStatus(404);
    }

    public function test_destroy_returns_422_when_already_trashed(): void
    {
        $created = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload());
        $id = $created->json('data.id');

        $this->deleteJson("/api/v1/visa/bookings/{$id}");

        $response = $this->deleteJson("/api/v1/visa/bookings/{$id}");

        $response->assertStatus(422);
    }
}