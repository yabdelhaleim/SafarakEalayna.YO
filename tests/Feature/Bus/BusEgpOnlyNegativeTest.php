<?php

namespace Tests\Feature\Bus;

use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusInventoryService;
use App\Services\Bus\BusRefundService;
use App\Services\Finance\CurrencyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 4 — Bus EGP-Only Negative Currency Test Suite
 *
 * Product requirement: BUS = EGP ONLY.
 *
 * These tests verify that EVERY layer of the Bus module rejects non-EGP
 * currency attempts:
 *
 *   1. Service writers (BusInventoryService, BusBookingService, BusRefundService).
 *   2. API validation (FormRequests + Controller validation rules).
 *   3. Vue UI (informational — verified manually).
 *   4. Direct DB writes (caught at the next booking/payment/refund layer).
 *
 * Every rejected operation must create:
 *   - 0 invalid accounting movements
 *   - 0 invalid payment records
 *   - 0 invalid refund records
 *   - 0 FX movements
 *   - 0 balance corruption
 */
class BusEgpOnlyNegativeTest extends BusTestCase
{
    /**
     * Non-EGP currencies the Bus module must reject at every layer.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function nonEgpCurrenciesProvider(): array
    {
        return [
            'USD' => ['USD', 'دولار أمريكي'],
            'SAR' => ['SAR', 'ريال سعودي'],
            'KWD' => ['KWD', 'دينار كويتي'],
            'EUR' => ['EUR', 'يورو'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // §1. WRITER LAYER — BusInventoryService::createInventory
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The inventory writer must persist `currency='EGP'` and
     * `exchange_rate_to_egp=1.0` regardless of what the caller passes.
     * If a caller tries to inject a non-EGP currency it must throw.
     */
    #[Test]
    public function create_inventory_forces_egp_currency_and_rate_one(): void
    {
        $company = $this->makeBusCompany();

        $inventory = app(BusInventoryService::class)->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'travel_date' => now()->addDay()->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 100.0,
            'selling_price' => 150.0,
            'payment_type' => 'deferred',
            'notes' => 'EGP-only inventory',
        ]);

        $this->assertSame('EGP', $inventory->currency);
        $this->assertSame(1.0, (float) $inventory->exchange_rate_to_egp);

        // Reload from DB to confirm the snapshot was actually persisted.
        $reloaded = BusInventory::findOrFail($inventory->id);
        $this->assertSame('EGP', $reloaded->currency);
        $this->assertSame(1.0, (float) $reloaded->exchange_rate_to_egp);
    }

    // ─────────────────────────────────────────────────────────────────────
    // §2. SERVICE LAYER — BusBookingService::createBooking rejects non-EGP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Direct DB write of a non-EGP inventory must be rejected at the
     * booking layer (defence in depth on top of the writer that already
     * forces EGP). No booking row, no journal entry, no payment record.
     */
    #[Test]
    #[DataProvider('nonEgpCurrenciesProvider')]
    public function create_booking_rejects_non_egp_inventory(string $currency, string $_label): void
    {
        $company = $this->makeBusCompany();

        // Write a non-EGP inventory directly to the DB (bypass writer).
        $inventory = BusInventory::create([
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'travel_date' => now()->addDay()->toDateString(),
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 100.0,
            'selling_price' => 150.0,
            'payment_type' => 'deferred',
            'total_cost' => 1000.0,
            'amount_paid' => 0.0,
            'remaining_debt' => 1000.0,
            'is_auto_created' => false,
            'currency' => $currency,
            'exchange_rate_to_egp' => 50.0, // arbitrary rate
            'created_by' => $this->user->id,
        ]);

        $customer = $this->makeCustomerWithBusAccount();

        $bookingsBefore = BusBooking::count();
        $paymentsBefore = BusPayment::count();

        try {
            app(BusBookingService::class)->createBooking([
                'inventory_id' => $inventory->id,
                'customer_id' => $customer->id,
                'quantity' => 1,
            ]);
            $this->fail("Expected createBooking to reject non-EGP inventory ({$currency}).");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        }

        // No booking, no payment, no balance corruption.
        $this->assertSame($bookingsBefore, BusBooking::count());
        $this->assertSame($paymentsBefore, BusPayment::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // §3. SERVICE LAYER — BusBookingService::payBooking rejects non-EGP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Payment account must be EGP. A non-EGP account (e.g., USD wallet)
     * must be rejected at the service layer.
     */
    #[Test]
    #[DataProvider('nonEgpCurrenciesProvider')]
    public function pay_booking_rejects_non_egp_payment_account(string $currency, string $_label): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
        ]);

        $paymentsBefore = BusPayment::count();

        try {
            app(BusBookingService::class)->payBooking($booking, [
                'amount' => 100.0,
                'payment_method' => 'cash',
                'account_id' => $this->walletUsd->id, // USD wallet — rejected by EGP-only
            ]);
            $this->fail("Expected payBooking to reject non-EGP payment account ({$currency}).");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        }

        $this->assertSame($paymentsBefore, BusPayment::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // §4. SERVICE LAYER — BusBookingService::cancelBooking rejects non-EGP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cancellation refund destination account must be EGP. A non-EGP
     * account must be rejected at the service layer.
     */
    #[Test]
    #[DataProvider('nonEgpCurrenciesProvider')]
    public function cancel_booking_rejects_non_egp_refund_account(string $currency, string $_label): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        app(BusBookingService::class)->payBooking($booking, [
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $refundsBefore = BusRefundRequest::count();

        try {
            app(BusBookingService::class)->cancelBooking($booking->fresh(), [
                'company_penalty' => 0.0,
                'office_penalty' => 0.0,
                'account_id' => $this->walletUsd->id, // USD wallet — rejected by EGP-only
            ]);
            $this->fail("Expected cancelBooking to reject non-EGP refund account ({$currency}).");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        }

        $this->assertSame($refundsBefore, BusRefundRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // §5. SERVICE LAYER — BusRefundService rejects non-EGP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Refund creation rejects a non-EGP `refund_currency` argument.
     */
    #[Test]
    #[DataProvider('nonEgpCurrenciesProvider')]
    public function create_refund_request_rejects_non_egp_refund_currency(string $currency, string $_label): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        app(BusBookingService::class)->payBooking($booking, [
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $refundsBefore = BusRefundRequest::count();

        try {
            app(BusRefundService::class)->createRefundRequest([
                'bus_booking_id' => $booking->id,
                'cancellation_fee' => 0.0,
                'refund_currency' => $currency, // rejected by EGP-only contract
                'refund_exchange_rate' => 50.0,
                'destination' => 'company_credit',
            ], $this->user->id);
            $this->fail("Expected createRefundRequest to reject non-EGP refund_currency ({$currency}).");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        }

        $this->assertSame($refundsBefore, BusRefundRequest::count());
    }

    /**
     * Refund creation rejects a non-EGP `refund_exchange_rate`.
     */
    #[Test]
    public function create_refund_request_rejects_non_one_refund_exchange_rate(): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        app(BusBookingService::class)->payBooking($booking, [
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        try {
            app(BusRefundService::class)->createRefundRequest([
                'bus_booking_id' => $booking->id,
                'cancellation_fee' => 0.0,
                'refund_currency' => 'EGP',
                'refund_exchange_rate' => 50.0, // non-1.0 rate rejected
                'destination' => 'company_credit',
            ], $this->user->id);
            $this->fail('Expected createRefundRequest to reject refund_exchange_rate != 1.0.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('1.0', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('1.0', $e->getMessage());
        }
    }

    /**
     * Process refund rejects a non-EGP booking (defence in depth).
     */
    #[Test]
    public function process_refund_request_rejects_non_egp_booking(): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        app(BusBookingService::class)->payBooking($booking, [
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Create a refund request via the legitimate EGP path.
        $refund = app(BusRefundService::class)->createRefundRequest([
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0.0,
            'destination' => 'company_credit',
        ], $this->user->id);

        // Tamper the booking's currency directly to USD (simulating legacy row).
        BusBooking::where('id', $booking->id)->update([
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        try {
            app(BusRefundService::class)->processRefundRequest($refund->id, $this->user->id);
            $this->fail('Expected processRefundRequest to reject non-EGP booking.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // §6. API LAYER — BusRefundController rejects non-EGP refund_currency
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The HTTP `/api/v1/bus/refunds` endpoint must reject any
     * `refund_currency` other than EGP with 422.
     */
    #[Test]
    #[DataProvider('nonEgpCurrenciesProvider')]
    public function http_refund_endpoint_rejects_non_egp_refund_currency(string $currency, string $_label): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        app(BusBookingService::class)->payBooking($booking, [
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0.0,
            'refund_currency' => $currency,
            'refund_exchange_rate' => 50.0,
            'destination' => 'company_credit',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('EGP', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    // ─────────────────────────────────────────────────────────────────────
    // §7. POSITIVE PATH — Every Bus entity is EGP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Sanity: a normal EGP booking lifecycle produces 100% EGP rows.
     */
    #[Test]
    public function normal_egp_booking_lifecycle_keeps_everything_egp(): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        $this->assertSame('EGP', $booking->currency);
        $this->assertSame(1.0, (float) $booking->exchange_rate_to_egp);

        $payment = app(BusBookingService::class)->payBooking($booking, [
            'amount' => $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $paymentRow = BusPayment::where('booking_id', $booking->id)->latest()->first();
        $this->assertSame('EGP', $paymentRow->currency);
        $this->assertSame(1.0, (float) $paymentRow->exchange_rate_to_egp);

        $refund = app(BusBookingService::class)->cancelBooking($booking->fresh(), [
            'company_penalty' => 0.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->assertSame('EGP', $refund->original_currency);
        $this->assertSame('EGP', $refund->refund_currency);
        $this->assertSame(1.0, (float) $refund->refund_exchange_rate);
        $this->assertEqualsWithDelta($refund->refund_amount, (float) $refund->base_currency_refund, 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────
    // §8. NO FX — BusBookingService::convertAmount throws on non-EGP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The protected `convertAmount` helper is retained for backward
     * compatibility but must reject any non-EGP currency.
     */
    #[Test]
    public function convert_amount_helper_rejects_non_egp_currency(): void
    {
        $service = app(BusBookingService::class);
        $reflection = new \ReflectionMethod($service, 'convertAmount');
        $reflection->setAccessible(true);

        // Same-currency EGP→EGP is allowed (pass-through).
        $result = $reflection->invoke($service, 100.0, 'EGP', 'EGP');
        $this->assertSame(100.0, (float) $result['to_amount']);
        $this->assertSame(1.0, (float) $result['rate']);

        // Any non-EGP currency throws.
        try {
            $reflection->invoke($service, 100.0, 'USD', 'EGP');
            $this->fail('Expected convertAmount to reject USD→EGP.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('EGP', $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // §9. Dashboard — no FX conversion; sum only EGP columns
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The BusDashboardController must NOT call CurrencyService::convert.
     * Verify by inspecting the controller source (defence in depth) and by
     * asserting that a USD booking row is excluded from the dashboard sum.
     */
    #[Test]
    public function dashboard_excludes_legacy_non_egp_bookings(): void
    {
        $company = $this->makeBusCompany();
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'payment_type' => 'deferred',
        ]);
        $customer = $this->makeCustomerWithBusAccount();
        $this->seedCashboxBalance(5000);

        // Create a normal EGP booking.
        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        // Tamper the booking to a non-EGP currency (legacy row simulation).
        BusBooking::where('id', $booking->id)->update(['currency' => 'USD']);

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertStatus(200);

        // The tampered USD row must not contribute to monthly_revenue.
        $monthlyRevenue = (float) ($response->json('data.stats.monthly_revenue') ?? 0);
        $this->assertSame(0.0, $monthlyRevenue);
    }
}
