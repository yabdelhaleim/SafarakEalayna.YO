<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Enums\VisaType;

/**
 * Phase 9.5a — Regression test for the zero-purchase-price application defect
 * (Class-A financial defect discovered in Phase 9.0 baseline).
 *
 * Background:
 *   StoreVisaBookingRequest previously allowed `purchase_price = 0` and
 *   `selling_price < purchase_price`. This produced negative-profit bookings
 *   and distorted supplier-AP accounting. The Bus module had this validation
 *   applied; Visa was missed. Phase 9.5a fixes it in Visa\StoreVisaBookingRequest
 *   with rules: purchase_price > 0, selling_price >= purchase_price.
 *
 * These tests lock in the new validation behavior to prevent regression.
 */
class VisaPurchasePriceValidationTest extends VisaTestCase
{
    /* ============================================================
     *  PURCHASE PRICE > 0 (was: min:0 — accepted 0)
     * ============================================================ */

    public function test_purchase_price_zero_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 0,
        ]));
        $response->assertStatus(422);
        $this->assertArrayHasKey('purchase_price', $response->json('errors'),
            '422 must include purchase_price error key');
    }

    public function test_purchase_price_negative_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => -100,
        ]));
        $response->assertStatus(422);
        $this->assertArrayHasKey('purchase_price', $response->json('errors'));
    }

    public function test_purchase_price_one_piagtre_succeeds(): void
    {
        // Boundary: 0.01 (1 piastre) is the minimum valid value
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 0.01,
            'selling_price' => 0.01,  // must be >= purchase_price
            'service_fee' => 0,
        ]));
        $response->assertCreated();
    }

    /* ============================================================
     *  SELLING PRICE >= PURCHASE PRICE (cross-field)
     * ============================================================ */

    public function test_selling_price_below_purchase_price_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 1000.0,
            'selling_price' => 500.0,  // < 1000 → REJECT
        ]));
        $response->assertStatus(422);
        $this->assertArrayHasKey('selling_price', $response->json('errors'),
            '422 must include selling_price error key');
    }

    public function test_selling_price_equal_to_purchase_price_succeeds(): void
    {
        // Boundary: zero-profit booking (selling == purchase) is allowed
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 1000.0,
            'selling_price' => 1000.0,  // = 1000, zero profit but VALID
            'service_fee' => 0,
        ]));
        $response->assertCreated();
    }

    public function test_selling_price_above_purchase_price_succeeds(): void
    {
        // Happy path: positive profit
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
        ]));
        $response->assertCreated();
    }

    /* ============================================================
     *  SERVICE FEE (unchanged: min:0)
     * ============================================================ */

    public function test_service_fee_zero_is_allowed(): void
    {
        // service_fee = 0 is a legitimate "no service fee" case
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'service_fee' => 0,
        ]));
        $response->assertCreated();
    }

    public function test_service_fee_negative_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'service_fee' => -50,
        ]));
        $response->assertStatus(422);
        $this->assertArrayHasKey('service_fee', $response->json('errors'));
    }

    /* ============================================================
     *  COMBINED: zero purchase + valid selling still rejected
     * ============================================================ */

    public function test_zero_purchase_with_zero_selling_is_rejected_with_422(): void
    {
        // Even with both at 0, the gt:0 on purchase_price triggers first
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'purchase_price' => 0,
            'selling_price' => 0,
        ]));
        $response->assertStatus(422);
        $this->assertArrayHasKey('purchase_price', $response->json('errors'));
    }
}
