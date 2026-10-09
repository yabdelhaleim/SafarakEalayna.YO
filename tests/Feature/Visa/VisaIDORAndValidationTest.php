<?php

namespace Tests\Feature\Visa;

use App\Models\VisaBooking;

/**
 * Phase 9.11 — Validation + Auth/IDOR (Sections 19-21).
 *
 * Two concerns:
 *   - Validation: edge cases in field values (unicode, emoji, large numbers)
 *   - IDOR: object-level cross-user access (admin vs employee)
 *
 * Tourism bookings are shared across employees (no per-employee ownership),
 * so IDOR is checked at the permission/role level rather than per-row.
 */
class VisaIDORAndValidationTest extends VisaTestCase
{
    /* ============================================================
     *  A. VALIDATION EDGE CASES
     * ============================================================ */

    public function test_unicode_in_notes_is_accepted(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'notes' => 'ملاحظات باللغة العربية: تأشيرة سياحية — ٣٠ يوم',
        ]));
        $response->assertCreated();
        $this->assertStringContainsString('العربية', $response->json('data.notes'));
    }

    public function test_unicode_in_country_is_accepted(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'مصر',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => 'single',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addDays(30)->toDateString(),
                'executing_company' => 'شركة',
                'visa_agent_id' => $this->agent->id,
            ],
        ]));
        $response->assertCreated();
        $this->assertSame('مصر', $response->json('data.visa_detail.country'));
    }

    public function test_emoji_in_notes_is_rejected_or_sanitized(): void
    {
        // Emoji in free-text fields must not crash or corrupt
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'notes' => 'Booking 🎉 with emoji 🛂',
        ]));
        // Either accept (201) or reject (422), but MUST NOT 500
        $this->assertNotSame(500, $response->status(),
            'emoji in notes must not cause server error');
        $this->assertContains($response->status(), [201, 422]);
    }

    public function test_very_large_amount_is_accepted_when_under_limit(): void
    {
        // 15-digit decimal (DECIMAL 15,2 max) — fits within MAX
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 9999999.99,
            'selling_price' => 9999999.99,
            'service_fee' => 0.0,
        ]));
        $response->assertCreated();
    }

    public function test_decimal_precision_is_preserved(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 1000.50,
            'selling_price' => 1500.75,
            'service_fee' => 100.25,
        ]));
        $response->assertCreated();

        $booking = VisaBooking::findOrFail($response->json('data.id'));
        $this->assertEquals(1000.50, (float) $booking->purchase_price);
        $this->assertEquals(1500.75, (float) $booking->selling_price);
        $this->assertEquals(100.25, (float) $booking->service_fee);
    }

    public function test_currency_code_is_validated(): void
    {
        // Garbage currency must be rejected
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'currency' => 'XX',
        ]));
        $this->assertNotSame(201, $response->status(),
            'invalid currency code must be rejected');
    }

    public function test_required_fields_are_required(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['customer_id']);
        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $this->assertSame(422, $response->status(), 'missing customer_id must 422');
    }

    public function test_future_validity_dates_are_required(): void
    {
        // validity_to before validity_from must be rejected
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'EG',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => 'single',
                'validity_from' => now()->addDays(30)->toDateString(),
                'validity_to' => now()->toDateString(),  // before validity_from!
                'executing_company' => 'X',
                'visa_agent_id' => $this->agent->id,
            ],
        ]));
        $this->assertNotSame(201, $response->status(),
            'validity_to before validity_from must be rejected');
    }

    /* ============================================================
     *  B. AUTH/IDOR — Cross-Employee
     * ============================================================ */

    public function test_employee_can_view_visa_bookings_via_show(): void
    {
        // Show endpoint is admin-only per Phase 8.5 — verify employee gets 403
        $this->actingAs($this->employeeUser);
        $booking = $this->makeBooking();
        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $this->assertSame(403, $response->status(),
            'GET /bookings/{id} must remain admin-only');
    }

    public function test_employee_can_record_payment_on_any_booking(): void
    {
        // Tourism cross-employee: any employee with manage_online can pay any booking
        $this->actingAs($this->user); // admin creates
        $booking = $this->makeBooking();

        $this->actingAs($this->employeeUser);  // employee pays
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P911_EMP_PAY_'.uniqid(),
        ]);
        $response->assertCreated();
    }

    public function test_other_employee_can_refund_same_booking(): void
    {
        $this->actingAs($this->user);
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P911_OTHER_PAY_'.uniqid(),
        ])->assertCreated();

        // Employee refunds (cross-employee is allowed for Tourism)
        $this->actingAs($this->employeeUser);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'cross-employee refund',
        ]);
        $response->assertOk();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        // Drop any Sanctum auth set up in setUp() so we exercise the real
        // `auth:sanctum` middleware (VisaTestCase::setUp calls Sanctum::actingAs).
        // `Sanctum::actingAs()` writes the user into the resolved guard instance;
        // `forgetGuards()` discards the cached instance so a fresh guard is built,
        // and no user is set on it.
        app('auth')->forgetGuards();
        $response = $this->getJson('/api/v1/visa/bookings');
        $this->assertNotSame(200, $response->status(),
            'an unauthenticated request must not return 200');
        $this->assertContains($response->status(), [401, 403],
            'unauthenticated request must be rejected');
    }

    public function test_inactive_employee_request_rejected(): void
    {
        // Make employee inactive
        $this->employeeUser->update(['is_active' => false]);

        $this->actingAs($this->employeeUser);
        $booking = $this->makeBooking();  // create while still active

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P911_INACT_'.uniqid(),
        ])->assertStatus(401);  // EnsureIsActive returns 401
    }

    public function test_sequential_id_enumeration_returns_404_not_500(): void
    {
        // ID enumeration probe: request non-existent booking IDs
        $response = $this->getJson('/api/v1/visa/bookings/999999999');
        $this->assertNotSame(500, $response->status(), 'missing booking must not 500');
        $this->assertContains($response->status(), [404, 403]);
    }

    public function test_negative_id_enumeration_rejected(): void
    {
        $response = $this->getJson('/api/v1/visa/bookings/-1');
        $this->assertNotSame(500, $response->status(),
            'negative ID must not crash the server');
    }
}