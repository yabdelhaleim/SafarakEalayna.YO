<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for payment_method validation in BusBookingService::payBooking().
 *
 * Covers Bug #10 — previously any string was accepted as payment_method.
 * Downstream reports depend on the exact enum values; an unexpected value
 * like 'banana' would silently corrupt report groupings.
 */
class BusPaymentMethodValidationTest extends BusTestCase
{
    private BusBookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BusBookingService::class);
        $this->seedCashboxBalance(50000);
    }

    private function makeBooking(string $phone): \App\Models\Bus\BusBooking
    {
        $company   = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id'        => $company->id,
            'total_tickets'     => 10,
            'available_tickets' => 10,
            'cost_per_ticket'   => 50,
            'selling_price'     => 100,
            'payment_type'      => BusInventoryPaymentType::Deferred->value,
            'currency'          => 'EGP',
        ]);
        $customer = Customer::factory()->create(['phone' => $phone]);
        return $this->service->createBooking([
            'inventory_id'   => $inventory->id,
            'customer_id'    => $customer->id,
            'customer_name'  => 'Test',
            'customer_phone' => $phone,
            'quantity'       => 1,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 — Invalid payment_method throws InvalidArgumentException
    // ─────────────────────────────────────────────────────────────────────────

    public function test_invalid_payment_method_throws(): void
    {
        $booking = $this->makeBooking('01001000001');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/طريقة الدفع غير مدعومة|unsupported/i');

        $this->service->payBooking($booking, [
            'amount'         => 100.0,
            'payment_method' => 'banana',
            'account_id'     => $this->cashboxEgp->id,
        ]);
    }

    public function test_empty_payment_method_throws(): void
    {
        $booking = $this->makeBooking('01001000002');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->payBooking($booking, [
            'amount'         => 100.0,
            'payment_method' => '',
            'account_id'     => $this->cashboxEgp->id,
        ]);
    }

    public function test_numeric_payment_method_throws(): void
    {
        $booking = $this->makeBooking('01001000003');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->payBooking($booking, [
            'amount'         => 100.0,
            'payment_method' => '123',
            'account_id'     => $this->cashboxEgp->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — All valid payment methods are accepted
    // ─────────────────────────────────────────────────────────────────────────

    #[DataProvider('validPaymentMethodProvider')]
    public function test_valid_payment_method_is_accepted(string $method): void
    {
        static $phoneCounter = 9000;
        $phoneCounter++;
        $booking = $this->makeBooking("010009{$phoneCounter}");

        $result = $this->service->payBooking($booking, [
            'amount'         => 100.0,
            'payment_method' => $method,
            'account_id'     => $this->cashboxEgp->id,
        ]);

        $this->assertNotNull($result);
        $payment = $result->payments()->first();
        $this->assertEquals($method, $payment->payment_method,
            "payment_method '{$method}' should be persisted as-is");
    }

    public static function validPaymentMethodProvider(): array
    {
        return [
            'cash'            => ['cash'],
            'bank_transfer'   => ['bank_transfer'],
            'cash_wallet'     => ['cash_wallet'],
            'postal_transfer' => ['postal_transfer'],
            'office_safe'     => ['office_safe'],
            'office_drawer'   => ['office_drawer'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — Missing payment_method defaults to 'cash' (not rejected)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_payment_method_defaults_to_cash(): void
    {
        $booking = $this->makeBooking('01001999999');

        $result = $this->service->payBooking($booking, [
            'amount'     => 100.0,
            'account_id' => $this->cashboxEgp->id,
            // payment_method intentionally omitted
        ]);

        $payment = $result->payments()->first();
        $this->assertEquals('cash', $payment->payment_method,
            'Omitted payment_method should default to cash');
    }
}
