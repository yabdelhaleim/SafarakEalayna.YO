<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 12 follow-up regression tests (added 2026-08-20).
 *
 * Pins down the delete-reversal paths verified in P0.1 / P0.1b:
 *
 *   - test_p0_1_egp_partial_cancel_delete_balances_return: EGP full-pay
 *     → partial-cancel (refund > 0, penalty kept > 0) → delete. The
 *     `$hasPartialResidual` branch must run, leaving the cashbox +
 *     income-clearing balanced.
 *
 *   - test_p0_1b_full_penalty_cancel_then_delete: EGP full-pay → full-penalty
 *     cancel (refund_amount = 0) → delete. The `if` (full sale reverse)
 *     branch must run, NOT the residual-clearing branch. Without this
 *     guard the cashbox loses the office-kept penalty twice (once in the
 *     residual-clearing debit, once via the missing sale-reverse offset).
 *
 * @group flight
 * @group flight-delete
 * @group phase-12
 */
class F12Phase12FlightDeleteRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(FlightBookingService::class);
        $this->admin = User::query()->create([
            'name' => 'P12 Admin',
            'email' => 'p12-admin-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    public function test_p0_1_egp_partial_cancel_delete_balances_return(): void
    {
        [$customer, $system, $carrier, $cashbox] = $this->buildEgpFixture();

        // Create booking 1 (cancel target)
        $booking1 = $this->createEgpBooking($customer, $system, $carrier, $cashbox, 10000, 12000, 'P12-1');
        $booking1->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Partial cancel: 1000 airline + 1000 office penalty, refund = 10000.
        // After cancel the cashbox sits at fixture-initial + 12000 (pay) − 10000 (refund)
        // = fixture-initial + 2000 (the office/airline penalty kept as revenue).
        $this->bookingService->cancelBooking($booking1, [
            'airline_penalty' => 1000,
            'office_penalty' => 1000,
            'account_id' => $cashbox->id,
        ]);

        // Snapshot AFTER cancel — this is the state the delete of booking 2
        // must NOT alter. (Booking 1's kept penalty is preserved; booking 2
        // is fully reversed.)
        $snapshotBeforeDelete = $this->snapshotBalance($cashbox->id);

        // Create + delete booking 2 (the actual reversal under test)
        $booking2 = $this->createEgpBooking($customer, $system, $carrier, $cashbox, 10000, 12000, 'P12-2');
        $this->bookingService->deleteBookingWithReversal($booking2->id, $this->admin->id);

        // After delete: cashbox must equal snapshot (no drift)
        $after = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $snapshotBeforeDelete,
            $after,
            0.01,
            'Cashbox must return to post-cancel baseline after booking-2 delete (P0.1 path: partial-residual branch)'
        );
    }

    public function test_p0_1b_full_penalty_cancel_then_delete_no_residual_drift(): void
    {
        [$customer, $system, $carrier, $cashbox] = $this->buildEgpFixture();

        $snapshot = $this->snapshotBalance($cashbox->id);

        $booking = $this->createEgpBooking($customer, $system, $carrier, $cashbox, 10000, 12000, 'P12FP');
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Full-penalty cancel: total 12000 EGP = full sale. refund_amount = 0.
        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 7000,
            'office_penalty' => 5000,
            'account_id' => $cashbox->id,
        ]);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $after = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $snapshot,
            $after,
            0.01,
            'Cashbox must return to pre-booking baseline after full-penalty-cancel + delete (P0.1b path — no residual-clearing drift)'
        );
    }

    /**
     * Build a minimal EGP fixture: customer + system + carrier + cashbox.
     *
     * @return array{0: Customer, 1: FlightSystem, 2: FlightCarrier, 3: Account}
     */
    protected function buildEgpFixture(): array
    {
        $customer = Customer::create([
            'full_name' => 'P12 Fixture Cust',
            'phone' => '01'.substr(md5(uniqid()), 0, 8),
            'email' => 'p12-cust-'.uniqid().'@test.com',
            'national_id' => '29'.substr(md5(uniqid()), 0, 12),
            'city' => 'Cairo',
        ]);

        $system = FlightSystem::create([
            'name' => 'P12 System',
            'code' => 'P12S'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'credit_limit' => 100000,
            'created_by' => $this->admin->id,
        ]);

        $carrier = FlightCarrier::create([
            'name' => 'P12 Carrier',
            'code' => 'P12C'.uniqid(),
            'flight_system_id' => $system->id,
            'currency' => 'EGP',
            'credit_limit' => 100000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $cashbox = Account::create([
            'name' => 'P12 Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        LedgerBalanceMutationGuard::run(function () use ($cashbox) {
            $cashbox->balance = 100000.0;
            $cashbox->save();
        });
        AccountEntry::create([
            'account_id' => $cashbox->id,
            'transaction_id' => null,
            'debit' => 0.0,
            'credit' => 100000.0,
            'balance_after' => 100000.0,
            'notes' => 'رصيد افتتاحي',
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50000.0, 'P12 fixture'
        );

        return [$customer, $system->fresh(), $carrier->fresh(), $cashbox->fresh()];
    }

    protected function createEgpBooking(
        Customer $customer,
        FlightSystem $system,
        FlightCarrier $carrier,
        Account $cashbox,
        int $purchasePrice,
        int $sellingPrice,
        string $pnrPrefix,
    ) {
        return $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'P12 Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(10)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => $pnrPrefix.uniqid(),
            'passengers' => [
                ['first_name' => 'P12', 'last_name' => 'Test', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $sellingPrice,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
            ],
        ]);
    }

    protected function snapshotBalance(int $accountId): float
    {
        return (float) Account::find($accountId)->fresh()->balance;
    }
}
