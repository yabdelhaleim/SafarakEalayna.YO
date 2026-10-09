<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * PHASE 16: Edge cases — extreme inputs, unicode, empty values.
 *
 * @group visa
 * @group visa-edge
 */
class VisaEdgeCasesTest extends VisaTestCase
{
    public function test_zero_egp_booking_rejected(): void
    {
        $payload = $this->bookingPayload();
        $payload['purchase_price'] = 0;
        $payload['selling_price'] = 0;

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertStatus(422);
    }

    public function test_one_piastre_booking_succeeds(): void
    {
        $payload = $this->bookingPayload();
        $payload['purchase_price'] = 0.01;
        $payload['selling_price'] = 0.01;
        $payload['service_fee'] = 0.0;

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
    }

    public function test_one_piastre_payment_succeeds(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 0.01,
            'selling_price' => 1.00,
            'service_fee' => 0,
        ]);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 0.01,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $response->assertCreated();
    }

    public function test_very_large_booking_handled(): void
    {
        $payload = $this->bookingPayload();
        $payload['purchase_price'] = 9999999.99;
        $payload['selling_price'] = 14999999.99;
        $payload['service_fee'] = 100000.0;

        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        // Must not overflow / must not crash
        $this->assertContains($response->status(), [201, 422]);

        if ($response->status() === 201) {
            $id = $response->json('data.id');
            $booking = VisaBooking::find($id);
            $this->assertEqualsWithDelta(14999999.99, (float) $booking->selling_price, 0.01);
        }
    }

    public function test_decimal_precision_three_places_truncated_or_rounded(): void
    {
        $payload = $this->bookingPayload();
        $payload['purchase_price'] = 100.123;
        $payload['selling_price'] = 200.456;

        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        if ($response->status() === 201) {
            $id = $response->json('data.id');
            $booking = VisaBooking::find($id);
            // decimal:2 cast — only 2 decimal places stored
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $booking->selling_price);
        }
    }

    public function test_arabic_customer_name_in_details(): void
    {
        $payload = $this->bookingPayload();
        $payload['visa_details']['executing_company'] = 'شركة التأشيرات المصرية';
        $payload['visa_details']['executing_agent'] = 'محمد أحمد علي';
        $payload['visa_details']['visa_number'] = 'VSA-2026-001-مصر';

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();

        $id = $response->json('data.id');
        $booking = VisaBooking::with('visaDetail')->find($id);
        $this->assertSame('شركة التأشيرات المصرية', $booking->visaDetail->executing_company);
        $this->assertSame('محمد أحمد علي', $booking->visaDetail->executing_agent);
    }

    public function test_special_characters_in_notes(): void
    {
        $specials = "!@#$%^&*()_+-=[]{}|;':\",./<>?`~";
        $payload = $this->bookingPayload();
        $payload['notes'] = $specials;

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();

        $id = $response->json('data.id');
        $booking = VisaBooking::find($id);
        $this->assertSame($specials, $booking->notes);
    }

    public function test_booking_with_minimal_valid_data(): void
    {
        // Strip all optional fields
        $minimal = [
            'customer_id' => $this->customer->id,
            'purchase_price' => 100.0,
            'selling_price' => 200.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'visa_details' => [
                'visa_type' => \App\Enums\VisaType::Tourist->value,
                'country' => 'XX',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => \App\Enums\VisaEntryType::Single->value,
            ],
        ];

        $response = $this->postJson('/api/v1/visa/bookings', $minimal);
        $response->assertCreated();
    }

    public function test_zero_value_visa_details_optional(): void
    {
        $payload = $this->bookingPayload();
        $payload['service_fee'] = 0;
        $payload['visa_details']['executing_agent_contact'] = null;

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
    }

    public function test_extreme_country_code_length(): void
    {
        $payload = $this->bookingPayload();
        $payload['visa_details']['country'] = str_repeat('A', 100);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        // DB column is varchar(100) — may accept or reject
        $this->assertContains($response->status(), [201, 422]);
    }

    public function test_payment_with_currency_mismatch_rejected(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 1000.0,
        ]);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'currency' => 'USD',  // mismatch with booking's EGP
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_very_long_payment_reference_accepted_or_rejected(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 500.0,
        ]);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => str_repeat('R', 500),
        ]);

        $this->assertContains($response->status(), [201, 422]);
    }

    public function test_booking_with_future_validity_to(): void
    {
        $payload = $this->bookingPayload();
        $payload['visa_details']['validity_from'] = '2030-01-01';
        $payload['visa_details']['validity_to'] = '2031-01-01';

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
    }

    public function test_booking_with_past_validity(): void
    {
        $payload = $this->bookingPayload();
        $payload['visa_details']['validity_from'] = '2000-01-01';
        $payload['visa_details']['validity_to'] = '2000-12-31';

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        // May accept (no business rule against it) or reject — must not crash
        $this->assertContains($response->status(), [201, 422]);
    }

    public function test_payment_with_no_reference_succeeds(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 1000.0,
        ]);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            // no reference
        ]);

        $response->assertCreated();
    }

    public function test_payment_with_unknown_payment_method(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 1000.0,
        ]);

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'unknown_method_xyz',
            'account_id' => $this->vaultEgp->id,
        ]);

        // payment_method is usually a free string column — accept or reject
        $this->assertContains($response->status(), [201, 422]);
    }
}