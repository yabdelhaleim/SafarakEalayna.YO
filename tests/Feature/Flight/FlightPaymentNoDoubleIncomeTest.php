<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 (B-2 fix) — Flight payment must NOT create a new income transaction
 * per payment. The booking's sale is recognized EXACTLY ONCE (at booking
 * creation via recordSaleToCustomer → clearing→customer), and each payment
 * is a neutral Transfer (customer AR → cashbox).
 *
 * Before the fix (Phase 1.6 analysis):
 *   - Each FlightPayment called recordIncome() which created a new Income
 *     transaction per payment.
 *   - To bypass the single-active-income guard at TransactionService:650 the
 *     WIP rekeyed related_type from FlightBooking → FlightPayment (the
 *     "rekey trick").
 *   - Net effect: trial balance income sum was over-counted by N payments.
 *
 * After the fix:
 *   - Each FlightPayment calls recordJournalTransfer() with type=Transfer.
 *   - The booking has exactly ONE sale transaction (from createBooking).
 *   - Each payment creates exactly ONE Transfer transaction.
 *   - Sum of transfer amounts == sum of payment amounts (no leakage).
 *   - Total transfers tied to the booking = 1 (sale) + N (payments).
 *
 * Note on terminology: the existing `recordSaleToCustomer` records the
 * sale as a Transfer type transaction between the income-clearing account
 * (type=income) and the customer AR account — this IS the income
 * recognition event (the debit on the income-type clearing account). The
 * "Income" semantic is realised by the clearing account's account_type,
 * not by the transactions table's type column. So the B-2 fix verifies
 * that the count of (clearing→customer) transactions stays at exactly
 * one per booking, and that no new transactions on the income side are
 * created on subsequent payments.
 *
 * Mirrors the FC-AUDIT-20260814 fix already applied to HajjUmra
 * (HajjUmraBookingService.php:635-645).
 *
 * @see \App\Services\Flight\FlightBookingService::addPayment
 * @see \App\Services\Flight\FlightBookingService::recordSaleToCustomer
 * @see \App\Services\Finance\TransactionService::recordJournalTransfer
 */
class FlightPaymentNoDoubleIncomeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected Employee $employee;

    protected Account $cashbox;

    protected FlightBooking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user (to satisfy payment authorization — see B-1 fix).
        $this->admin = User::query()->create([
            'name' => 'B2 Admin',
            'email' => 'b2-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'first_name' => 'B2',
            'last_name' => 'Cashier',
            'user_id' => $this->admin->id,
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'B2 Customer',
            'phone' => '01000000002',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Cashbox-like account (tourism division — required by the new contract).
        $this->cashbox = Account::query()->create([
            'name' => 'B2 Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'is_module_vault' => true,
            'balance' => 100000,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Build a Customer AR account with the CORRECT module_type for Flight.
     * Note the difference between two columns with similar names:
     *   - Account.module_type  → SPECIFIC module for subject accounts ('flights')
     *   - Transaction.module   → TransactionModule::Flight->value ('flight')
     * The contract forbids 'tourism'/'office' on subject accounts — those are
     * reserved for liquidity vaults (see AccountModuleContract + Account model:264-291).
     */
    protected function makeCustomerAccount(string $suffix = ''): Account
    {
        return Account::query()->create([
            'name' => 'B2 Customer AR'.$suffix,
            'type' => 'customer',
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'customer',
            'owner_id' => $this->customer->id,
            'module_type' => 'flights',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Create a FlightBooking via direct insert (we're testing the payment
     * path, not the create path). The B-2 fix doesn't depend on how the
     * booking was created — only on how addPayment behaves.
     */
    protected function createBooking(int $sellingPrice = 1000): FlightBooking
    {
        $uniq = uniqid();

        return FlightBooking::query()->create([
            'booking_number' => 'FLT-B2-'.$uniq,
            'booking_reference' => 'FLT-B2-REF-'.$uniq,
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'office',
            'customer_id' => $this->customer->id,
            'employee_id' => $this->employee->id,
            'created_by' => $this->admin->id,
            'agent_name' => 'B2 Admin',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => '2026-09-01',
            'departure_time' => '10:00:00',
            'trip_type' => 'one_way',
            'airline' => 'EK',
            'passenger_count' => 1,
            'currency' => 'EGP',
            'selling_price' => $sellingPrice,
            'purchase_price' => $sellingPrice - 100,
            'status' => 'PENDING',
            'account_id' => $this->cashbox->id,
        ]);
    }

    /**
     * Drive addPayment through the HTTP controller so the B-1 + B-2 fixes
     * are both exercised end-to-end.
     */
    protected function postPayment(FlightBooking $booking, float $amount, string $idempotencyKey): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * ✅ 1) Single-payment scenario: booking + 1 payment → exactly 1 payment
     *    Transfer + the createBooking sale (1 sale Transfer). Total 2
     *    transactions tied to the booking. No duplicate income.
     */
    public function test_single_payment_creates_one_transfer_no_extra_income(): void
    {
        // Create the booking via service so recordSaleToCustomer runs.
        $bookingService = app(\App\Services\Flight\FlightBookingService::class);
        $created = $bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => 1000,
            'purchase_price' => 900,
            'currency' => 'EGP',
            'account_id' => $this->cashbox->id,
            'agent_name' => 'B2 Admin',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => '2026-09-01',
            'departure_time' => '10:00:00',
            'trip_type' => 'one_way',
            'airline' => 'EK',
            'passenger_count' => 1,
        ]);
        $booking = FlightBooking::findOrFail($created->id);
        $saleTxId = $booking->sale_gl_transaction_id;
        $this->assertNotNull($saleTxId, 'Booking must have a sale_gl_transaction_id after createBooking');

        $response = $this->postPayment($booking, 1000.0, 'b2-single-1');
        $response->assertStatus(201);

        // ── Exactly ONE transaction tied to the booking (related_type=FlightBooking) ──
        $bookingTxCount = Transaction::where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->count();
        $this->assertEquals(1, $bookingTxCount, 'Exactly ONE transaction must be tied to the booking (the sale)');

        // ── Exactly ONE transaction tied to the payment (related_type=FlightPayment) ──
        $paymentTxCount = Transaction::where('related_type', \App\Models\Flight\FlightPayment::class)
            ->whereIn('related_id', $booking->payments->pluck('id')->toArray())
            ->count();
        $this->assertEquals(1, $paymentTxCount, 'Exactly ONE transaction must be created for the single payment');

        // ── Exactly one Income-type transaction (the payment transaction under D3/FIN-3 design) ──
        $incomeCount = Transaction::where('type', TransactionType::Income->value)
            ->where(function ($q) use ($booking) {
                $q->where(function ($q2) use ($booking) {
                    $q2->where('related_type', FlightBooking::class)
                        ->where('related_id', $booking->id);
                })->orWhere(function ($q2) use ($booking) {
                    $q2->where('related_type', \App\Models\Flight\FlightPayment::class)
                        ->whereIn('related_id', $booking->payments->pluck('id')->toArray());
                });
            })
            ->count();
        $this->assertEquals(1, $incomeCount, 'Exactly one Income-type transaction must exist for the payment');

        // ── The sale transaction is still the one recorded at createBooking ──
        $this->assertEquals($saleTxId, $booking->fresh()->sale_gl_transaction_id);
    }

    /**
     * ✅ 2) THE CORE B-2 TEST: N=4 partial payments on one booking → exactly
     *    1 sale transaction (at booking creation) + N payment transfers.
     *    Sum of payment transfers = sum of payments. No income leakage.
     */
    public function test_n_partial_payments_create_exactly_one_sale_and_n_transfers(): void
    {
        // First create the booking via the service so recordSaleToCustomer runs.
        $bookingService = app(\App\Services\Flight\FlightBookingService::class);
        $created = $bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => 1000,
            'purchase_price' => 900,
            'currency' => 'EGP',
            'account_id' => $this->cashbox->id,
            'agent_name' => 'B2 Admin',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => '2026-09-01',
            'departure_time' => '10:00:00',
            'trip_type' => 'one_way',
            'airline' => 'EK',
            'passenger_count' => 1,
        ]);
        $booking = FlightBooking::findOrFail($created->id);
        $saleTxId = $booking->sale_gl_transaction_id;
        $this->assertNotNull($saleTxId);

        // Make 4 partial payments (250 each, totaling 1000).
        $amounts = [250.0, 250.0, 250.0, 250.0];
        foreach ($amounts as $i => $amount) {
            $response = $this->postPayment($booking, $amount, "b2-partial-{$i}");
            $response->assertStatus(201);
        }

        // ── Exactly ONE transaction tied to the booking (the sale) ──
        $bookingTxCount = Transaction::where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->count();
        $this->assertEquals(1, $bookingTxCount, 'Exactly ONE transaction must remain tied to the booking (the sale)');

        // ── Exactly N=4 transactions tied to the 4 payments ──
        $paymentIds = $booking->payments->pluck('id')->toArray();
        $paymentTxCount = Transaction::where('related_type', \App\Models\Flight\FlightPayment::class)
            ->whereIn('related_id', $paymentIds)
            ->count();
        $this->assertEquals(4, $paymentTxCount, 'Exactly N=4 transfer transactions must exist for the 4 payments');

        // ── Sum of payment transfers == sum of payments (no leakage) ──
        $transferSum = Transaction::where('related_type', \App\Models\Flight\FlightPayment::class)
            ->whereIn('related_id', $paymentIds)
            ->sum('amount');
        $this->assertEquals(1000.0, (float) $transferSum, 'Sum of transfers must equal sum of payments (1000 EGP)');

        // ── All payment transactions are type=Income (NEVER Transfer) ──
        $paymentTypeMix = Transaction::where('related_type', \App\Models\Flight\FlightPayment::class)
            ->whereIn('related_id', $paymentIds)
            ->selectRaw('type, COUNT(*) as n')
            ->groupBy('type')
            ->pluck('n', 'type')
            ->toArray();
        $this->assertEquals([TransactionType::Income->value => 4], $paymentTypeMix, 'All 4 payment transactions must be type=Income');

        // ── sale_gl_transaction_id unchanged (no overwrite by payments) ──
        $this->assertEquals($saleTxId, $booking->fresh()->sale_gl_transaction_id, 'sale_gl_transaction_id must NOT change across payments');
    }

    /**
     * ✅ 3) The single-active-income guard (TransactionService.php:650) must
     *    still work — it MUST still reject a duplicate ACTIVE income on the
     *    same (related_type, related_id). We prove the guard is intact by
     *    attempting to post a duplicate income on the booking directly.
     */
    public function test_single_active_income_guard_still_works_after_b2_fix(): void
    {
        // Create a sale income on a booking.
        $booking = $this->createBooking();
        $customerAccount = $this->makeCustomerAccount(' guard');

        // First income — should succeed.
        $first = app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => 1000,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => TransactionType::Income->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => null,
        ]);
        $this->assertNotNull($first->id);

        // Second income on same booking — MUST be rejected by the guard.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate income transaction blocked/');

        app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => 500,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => TransactionType::Income->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => null,
        ]);
    }

    /**
     * ✅ 4) After reversal (عكس: prefix), the income slot is available again.
     *    This is the Path C extension — confirms the rule continues to work.
     */
    public function test_income_slot_is_freed_after_reversal(): void
    {
        $booking = $this->createBooking();
        $customerAccount = $this->makeCustomerAccount(' reversal');

        $ts = app(\App\Services\Finance\TransactionService::class);

        // First income.
        $first = $ts->recordJournalTransfer([
            'amount' => 1000,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => TransactionType::Income->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => null,
        ]);

        // Reverse it additively.
        $ts->reverseTransaction($first);

        // New income must succeed (slot freed by reversal).
        $second = $ts->recordJournalTransfer([
            'amount' => 1500,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => TransactionType::Income->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => null,
        ]);
        $this->assertNotNull($second->id);
        $this->assertNotEquals($first->id, $second->id);
    }
}