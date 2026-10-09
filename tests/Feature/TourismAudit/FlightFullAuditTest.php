<?php

namespace Tests\Feature\TourismAudit;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Services\Flight\FlightBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 7 — Flight Full Audit.
 *
 * Tests:
 *  - Booking creation with carrier / system source
 *  - Payments (single, partial, multiple, full)
 *  - Cancellation (additive reversal — D2)
 *  - Negative price protection (D4)
 *  - Idempotency replay (D3)
 *  - PENDING → full payment → CONFIRMED (D1)
 *  - Zero payment rejected
 *  - Overpayment rejected
 *  - All Flight transactions tagged as Tourism
 */
class FlightFullAuditTest extends TourismAuditTestCase
{
    protected FlightBookingService $bookingService;

    protected FlightCarrier $carrier;

    protected FlightSystem $system;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(FlightBookingService::class);

        $this->customer = Customer::query()->create([
            'full_name' => 'Flight Audit Customer',
            'phone' => '01200000001',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        LedgerBalanceMutationGuard::run(function () {
            $systemAcc = Account::query()->create([
                'name' => 'Audit Flight System Account',
                'type' => 'supplier',
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'flights',
                'notes' => 'Tourism Audit 2026-08-17',
                'created_by' => $this->admin->id,
            ]);

            $this->system = FlightSystem::query()->create([
                'name' => 'Audit GDS',
                'code' => 'AUDGDS',
                'type' => 'manual',
                'account_id' => $systemAcc->id,
                'currency' => 'EGP',
                'balance' => 0,
                'credit_limit' => 0,
                'is_active' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->carrier = FlightCarrier::query()->create([
                'name' => 'Audit Carrier',
                'code' => 'AUDCR',
                'flight_system_id' => $this->system->id,
                'currency' => 'EGP',
                'balance' => 0,
                'credit_limit' => 0,
                'is_active' => true,
                'created_by' => $this->admin->id,
            ]);
        });

        // Recharge the flight system with sufficient balance for booking tests
        app(\App\Services\Flight\FlightSystemRechargeService::class)
            ->rechargeFromAccount($this->system, $this->vaultEgp, 100000.0, 'Audit setup');
    }

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Audit Air',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '10:00',
            'arrival_time' => '14:00',
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'selling_price' => 6000.0,
            'purchase_price' => 5000.0,
            'flight_system_id' => $this->system->id,
            'purchase_balance_source' => 'system',
            'pnr' => 'AUD'.random_int(100000, 999999),
            'passengers' => [
                ['first_name' => 'Audit', 'last_name' => 'Passenger', 'passenger_type' => 'adult'],
            ],
        ], $overrides);
    }

    /**
     * Recharge the flight system with enough balance for the booking.
     */
    protected function rechargeFlightSystem(float $amount = 100000.0): void
    {
        app(\App\Services\Flight\FlightSystemRechargeService::class)
            ->rechargeFromAccount($this->system, $this->vaultEgp, $amount, 'Audit setup');
    }

    /**
     * D4 — Negative price rejected at service layer.
     */
    public function test_service_create_rejects_negative_purchase_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->createBooking($this->bookingPayload([
            'purchase_price' => -100.0,
        ]));
    }

    public function test_service_create_rejects_negative_selling_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->createBooking($this->bookingPayload([
            'selling_price' => -100.0,
        ]));
    }

    /**
     * Booking creation happy path with system source.
     */
    public function test_create_booking_with_system_source(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());
        $this->assertNotNull($booking);
        $this->assertSame(FlightBooking::class, get_class($booking));
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * D1 — PENDING → full payment → CONFIRMED.
     */
    public function test_full_payment_moves_booking_to_confirmed(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());
        $this->assertSame('pending', strtolower($booking->fresh()->status->value ?? (string) $booking->fresh()->status));

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 6000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->assertSame('confirmed', strtolower($booking->fresh()->status->value ?? (string) $booking->fresh()->status));
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * D3 — Partial payment + idempotency.
     */
    public function test_partial_payment_keeps_booking_pending(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());

        $payment = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 2000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->assertNotNull($payment);
        $this->assertSame('pending', strtolower($booking->fresh()->status->value ?? (string) $booking->fresh()->status));
        $this->assertLedgerGloballyBalanced();
    }

    public function test_idempotency_key_replay_returns_existing_payment(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());
        $key = 'audit-flight-idem-'.uniqid();

        $first = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 2000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        $this->assertFalse($first->idempotent_replay ?? false);

        $second = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 2000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        $this->assertTrue($second->idempotent_replay ?? false);
        $this->assertSame($first->id, $second->id, 'Replay should return the same payment row');

        $payments = FlightPayment::query()->where('flight_booking_id', $booking->id)->count();
        $this->assertSame(1, $payments);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_different_idempotency_keys_create_distinct_payments(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());

        $first = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 1000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => 'audit-key-A',
        ]);

        $second = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 2000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => 'audit-key-B',
        ]);

        $this->assertNotSame($first->id, $second->id);

        $payments = FlightPayment::query()->where('flight_booking_id', $booking->id)->get();
        $total = $payments->sum(fn ($p) => (float) $p->amount);
        $this->assertEquals(3000.0, round($total, 2));
    }

    /**
     * D2 — Cancel preserves original sale transaction reference (additive reversal).
     */
    public function test_cancellation_preserves_original_sale_transaction(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());
        $originalSaleTxId = $booking->fresh()->sale_gl_transaction_id;
        $this->assertNotNull($originalSaleTxId, 'Booking should have sale_gl_transaction_id after creation');

        $originalTxCount = Transaction::query()->count();

        $this->bookingService->cancelBooking($booking->fresh(), [
            'reason' => 'Audit cancellation',
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
        ]);

        // Original transaction must still exist (NOT deleted)
        $originalStillExists = Transaction::query()->find($originalSaleTxId);
        $this->assertNotNull($originalStillExists, 'Original sale transaction should be PRESERVED (additive reversal)');

        // Original transaction's amount must NOT be modified
        $this->assertEquals(
            6000.0,
            (float) $originalStillExists->amount,
            'Original sale transaction amount must remain unchanged'
        );

        // New reversal transactions should have been created (additive — NOT modifying original)
        $reversalTxs = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('notes', 'like', '%عكس%')
            ->count();
        $this->assertGreaterThan(0, $reversalTxs, 'Reversal transactions should be created');

        // Net financial effect: original + reversals should net to zero on sale leg
        $this->assertLedgerGloballyBalanced();
    }

    public function test_cancellation_blocks_double_cancel(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());

        $this->bookingService->cancelBooking($booking->fresh(), [
            'reason' => 'First cancel',
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
        ]);

        $this->expectException(\Exception::class);
        $this->bookingService->cancelBooking($booking->fresh(), [
            'reason' => 'Second cancel (should fail)',
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
        ]);
    }

    /**
     * Zero payment rejected.
     */
    public function test_zero_payment_rejected(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());

        $this->expectException(\Exception::class);
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 0.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);
    }

    /**
     * Overpayment rejected.
     */
    public function test_overpayment_rejected(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());

        $this->expectException(\Exception::class);
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 99999.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);
    }

    /**
     * All Flight transactions must be Tourism-tagged.
     */
    public function test_all_flight_transactions_tagged_as_tourism(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 2000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $paymentId = FlightPayment::query()->where('flight_booking_id', $booking->id)->first()?->id ?? 0;

        $transactions = Transaction::query()
            ->where(function ($q) use ($booking, $paymentId) {
                $q->where(function ($q2) use ($booking) {
                    $q2->where('related_type', FlightBooking::class)->where('related_id', $booking->id);
                });
                if ($paymentId) {
                    $q->orWhere(function ($q2) use ($paymentId) {
                        $q2->where('related_type', FlightPayment::class)->where('related_id', $paymentId);
                    });
                }
            })
            ->get();

        $this->assertGreaterThan(0, $transactions->count(), 'Should have transactions related to the booking');
        foreach ($transactions as $tx) {
            $this->assertTransactionIsTourism($tx);
            $this->assertTransactionAccountsAreTourism($tx);
        }
    }

    /**
     * Customer account remains Tourism (flights) after operations.
     */
    public function test_customer_account_uses_tourism_module_type(): void
    {
        $booking = $this->bookingService->createBooking($this->bookingPayload());
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 2000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $customerAccount = $this->customer->fresh()->ledgerAccount;
        $this->assertNotNull($customerAccount, 'Customer must have an AR ledger account');
        // Booking service should re-tag customer account to 'flights'
        $this->assertSame('flights', $customerAccount->fresh()->module_type);
    }
}
