<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for FlightPayment reversal — Priority 3.2.
 *
 * Original `addPayment` posts:  income_clearing (debit) → cash account (credit)
 * Reversal posts the mirror:     cash account (debit)    → income_clearing (credit)
 *
 * Tested via the booking-level `deleteBookingWithReversal` (which iterates
 * each payment and calls `reverseSinglePayment` internally).
 *
 * Invariants:
 *   1. Per-payment reversal: net delta on income_clearing AND cashbox == 0.
 *   2. Original transaction remains in DB (not deleted).
 *   3. Reversal transaction exists with opposite from/to accounts.
 *   4. Multiple payments: each reversed independently.
 */
class FlightPaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected int $incomeClearingAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'name' => 'Payment Test Admin',
            'email' => 'payment-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Payment Test Customer',
            'phone' => '0123456789',
            'email' => 'payment-customer@test.com',
            'national_id' => '98765432109876',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Payment Test System',
            'code' => 'PTS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Payment Test Airline',
            'code' => 'PTA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Payment Test Cashbox',
            'type' => 'cashbox',
            'balance' => 100000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            100000.00,
            'Payment test setup'
        );

        $this->carrier->refresh();
        $this->cashbox->refresh();

        // The income contra (clearing) account is auto-created by LedgerClearingAccounts.
        $this->incomeClearingAccountId = app(LedgerClearingAccounts::class)
            ->incomeContraIdForFlightBooking();

        Log::info('FlightPaymentReversalTest setUp complete', [
            'cashbox_id' => $this->cashbox->id,
            'cashbox_balance' => (float) $this->cashbox->balance,
            'income_clearing_id' => $this->incomeClearingAccountId,
        ]);
    }

    public function test_single_payment_reversal_restores_cashbox_and_clearing_balances(): void
    {
        Log::info('Starting: test_single_payment_reversal_restores_cashbox_and_clearing_balances');

        $sellingPrice = 18000.0;
        $purchasePrice = 15000.0;

        // Snapshot BEFORE
        $before = [
            'cashbox'         => (float) $this->cashbox->balance,
            'income_clearing' => (float) Account::find($this->incomeClearingAccountId)->balance,
        ];

        // Create booking
        $booking = $this->bookingService->createBooking([
            'customer_id'      => $this->customer->id,
            'airline_name'     => 'Payment Test Airline',
            'from_airport'     => 'CAI',
            'to_airport'       => 'JED',
            'departure_date'   => now()->addDays(7)->toDateString(),
            'trip_type'        => 'one_way',
            'currency'         => 'EGP',
            'purchase_price'   => $purchasePrice,
            'selling_price'    => $sellingPrice,
            'flight_carrier_id'=> $this->carrier->id,
            'account_id'       => $this->cashbox->id,
            'passengers'       => [
                ['name' => 'Single Pay Pax', 'type' => 'adult'],
            ],
        ]);

        // Single payment
        $payment = $this->bookingService->addPayment($booking, [
            'amount'         => $sellingPrice,
            'payment_method' => 'cash',
            'account_id'     => $this->cashbox->id,
            'notes'          => 'Single payment test',
        ]);

        $this->assertNotNull($payment->transaction_id, 'Payment must have a transaction_id after posting');

        // Cashbox should have INCREASED by payment amount
        // (The carrier purchase debit goes through the prepaid GL, NOT through the cashbox)
        $this->assertEquals(
            $before['cashbox'] + $sellingPrice,
            (float) $this->cashbox->fresh()->balance,
            'Cashbox should reflect: starting + payment amount'
        );

        // ── ACT: delete booking with reversal ─────────────────────
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // ── ASSERT 1: net balance delta == 0 ──────────────────────
        $after = [
            'cashbox'         => (float) $this->cashbox->fresh()->balance,
            'income_clearing' => (float) Account::find($this->incomeClearingAccountId)->fresh()->balance,
        ];

        $this->assertEquals(0.0, round($after['cashbox'] - $before['cashbox'], 2),
            'Cashbox balance delta must be zero after reversal');
        $this->assertEquals(0.0, round($after['income_clearing'] - $before['income_clearing'], 2),
            'Income clearing balance delta must be zero after reversal');

        // ── ASSERT 2: original payment transaction preserved ──────
        // Note: the original addPayment creates a transaction with related_type=FlightBooking
        // (per line 1614 in FlightBookingService). The REVERSAL transaction gets
        // related_type=FlightPayment (line 2178). The original must remain unchanged.
        $this->assertDatabaseHas('transactions', [
            'id' => $payment->transaction_id,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
        ]);

        // ── ASSERT 3: reversal transaction exists with opposite legs ─
        $reversalTx = Transaction::query()
            ->where('related_type', FlightPayment::class)
            ->where('related_id', $payment->id)
            ->where('id', '!=', $payment->transaction_id)
            ->first();

        $this->assertNotNull($reversalTx, 'Reversal transaction must exist for this payment');

        $originalTx = Transaction::find($payment->transaction_id);
        $this->assertEquals(
            (int) $originalTx->to_account_id,
            (int) $reversalTx->from_account_id,
            'Reversal from_account_id must equal original to_account_id (cashbox)'
        );
        $this->assertEquals(
            (int) $originalTx->from_account_id,
            (int) $reversalTx->to_account_id,
            'Reversal to_account_id must equal original from_account_id (income clearing)'
        );

        Log::info('PASSED: test_single_payment_reversal_restores_cashbox_and_clearing_balances', [
            'before' => $before,
            'after' => $after,
            'original_tx_id' => $originalTx->id,
            'reversal_tx_id' => $reversalTx->id,
        ]);
    }

    public function test_multiple_payments_each_reversed_independently(): void
    {
        Log::info('Starting: test_multiple_payments_each_reversed_independently');

        $sellingPrice = 18000.0;
        $purchasePrice = 15000.0;

        $booking = $this->bookingService->createBooking([
            'customer_id'      => $this->customer->id,
            'airline_name'     => 'Payment Test Airline',
            'from_airport'     => 'CAI',
            'to_airport'       => 'JED',
            'departure_date'   => now()->addDays(7)->toDateString(),
            'trip_type'        => 'one_way',
            'currency'         => 'EGP',
            'purchase_price'   => $purchasePrice,
            'selling_price'    => $sellingPrice,
            'flight_carrier_id'=> $this->carrier->id,
            'account_id'       => $this->cashbox->id,
            'passengers'       => [
                ['name' => 'Multi Pay Pax', 'type' => 'adult'],
            ],
        ]);

        $before = (float) $this->cashbox->fresh()->balance;

        // Add 3 partial payments
        $payment1 = $this->bookingService->addPayment($booking, [
            'amount' => 6000, 'payment_method' => 'cash',
            'account_id' => $this->cashbox->id, 'notes' => '1st partial',
        ]);
        $payment2 = $this->bookingService->addPayment($booking, [
            'amount' => 6000, 'payment_method' => 'cash',
            'account_id' => $this->cashbox->id, 'notes' => '2nd partial',
        ]);
        $payment3 = $this->bookingService->addPayment($booking, [
            'amount' => 6000, 'payment_method' => 'cash',
            'account_id' => $this->cashbox->id, 'notes' => '3rd partial',
        ]);

        // Cashbox should have +18000 (3 payments) — carrier debit goes through prepaid GL
        $this->assertEquals(
            $before + 18000,
            (float) $this->cashbox->fresh()->balance,
            'Cashbox should reflect 3 payments in (carrier debit goes through prepaid GL)'
        );

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // Cashbox should be back to $before (everything reversed)
        $this->assertEquals(
            $before,
            (float) $this->cashbox->fresh()->balance,
            'Cashbox should be fully restored after reversing 3 payments + carrier debit'
        );

        // Each payment should have a reversal transaction
        foreach ([$payment1, $payment2, $payment3] as $payment) {
            $reversalCount = Transaction::query()
                ->where('related_type', FlightPayment::class)
                ->where('related_id', $payment->id)
                ->where('id', '!=', $payment->transaction_id)
                ->count();

            $this->assertEquals(1, $reversalCount,
                "Payment #{$payment->id} must have exactly 1 reversal transaction");
        }

        Log::info('PASSED: test_multiple_payments_each_reversed_independently', [
            'payments_count' => 3,
            'final_cashbox' => (float) $this->cashbox->fresh()->balance,
        ]);
    }
}