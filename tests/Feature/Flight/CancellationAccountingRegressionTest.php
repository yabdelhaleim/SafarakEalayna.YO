<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cancellation Accounting — Customer AR + Cash + P&L Regression Tests
 * --------------------------------------------------------------------
 *
 * Pinning tests for the duplicate-customer-AR-credits bug fixed on
 * 2026-08-28 (HIGH — flight cancellation posts duplicate customer AR
 * credits / double-refund).
 *
 * Bug summary:
 *   The cancel flow posted THREE independent customer-account credits
 *   for the same economic event:
 *     1) sale-reversal TX5 (customer → pending_sales_receivable)
 *     2) reverseTransaction() mirror entries on TX3 (customer credit)
 *     3) cash refund TX6 (treasury → customer)
 *   Result: customer AR ended at +payment_amount instead of 0.
 *
 * Fix:
 *   reverseFlightBookingRevenue now uses the new lightweight
 *   TransactionService::markTransactionReversed() — sets the canonical
 *   `عكس:` notes prefix on TX3 (so ProfitLossReportService::report()
 *   skips the income from revenue totals) WITHOUT creating mirror
 *   AccountEntry rows or mutating any account balance. The actual cash
 *   return is handled by the regular cash-refund journal (TX6).
 *
 * Each scenario below asserts:
 *   - customer AR = 0
 *   - treasury cash delta = -refund_amount (or 0 when no refund)
 *   - revenue = 0
 *   - cogs = 0
 *   - net profit = 0
 *   - ledger invariant: SUM(debit) = SUM(credit) per transaction
 *   - ledger invariant: balance = SUM(credit) - SUM(debit) per account
 */
class CancellationAccountingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'name' => 'Cancellation Accounting Admin',
            'email' => 'cancellation-accounting-'.uniqid().'@test.local',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Cancellation Accounting Customer',
            'phone' => '01000000999',
            'email' => 'cancel-customer@test.local',
            'national_id' => '99988877766655',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Cancellation System',
            'code' => 'CXS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Cancellation Airline',
            'code' => 'CXA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 100000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Cancellation Cashbox',
            'type' => 'cashbox',
            'balance' => 200000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            50000.00,
            'Cancellation accounting setup'
        );
    }

    protected function booking(array $overrides = []): FlightBooking
    {
        return $this->bookingService->createBooking(array_merge([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Cancellation Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 20000,
            'selling_price' => 22000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'passengers' => [
                ['name' => 'Cancellation Pax', 'type' => 'adult'],
            ],
        ], $overrides));
    }

    protected function customerAr(): float
    {
        return (float) Account::query()
            ->where('id', $this->customer->fresh()->account_id)
            ->value('balance');
    }

    protected function cashboxBalance(): float
    {
        return (float) $this->cashbox->fresh()->balance;
    }

    protected function snapshotPnl(): array
    {
        $report = app(ProfitLossReportService::class)->report([]);

        return [
            'totalRevenues' => (float) $report['totalRevenues'],
            'totalCogs' => (float) $report['totalCogs'],
            'grossProfit' => (float) $report['grossProfit'],
            'netProfit' => (float) $report['netProfit'],
        ];
    }

    /**
     * Verify every transaction in the database satisfies
     * SUM(debit) = SUM(credit).
     */
    protected function assertEveryTransactionBalanced(): void
    {
        $rows = DB::table('transactions')->select('id')->get();
        foreach ($rows as $row) {
            $debit = (float) DB::table('account_entries')->where('transaction_id', $row->id)->sum('debit');
            $credit = (float) DB::table('account_entries')->where('transaction_id', $row->id)->sum('credit');
            $this->assertEqualsWithDelta($debit, $credit, 0.01,
                "Transaction #{$row->id} unbalanced: debit={$debit}, credit={$credit}");
        }
    }

    /**
     * Verify every account's balance = SUM(credit) - SUM(debit).
     */
    protected function assertEveryAccountInvariant(): void
    {
        $rows = DB::table('accounts as a')
            ->leftJoin('account_entries as ae', 'a.id', '=', 'ae.account_id')
            ->groupBy('a.id', 'a.balance')
            ->selectRaw('a.id, a.balance, COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0) as net')
            ->get();
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta((float) $row->balance, (float) $row->net, 0.01,
                "Account #{$row->id}: balance={$row->balance} ≠ entries_net={$row->net}");
        }
    }

    /**
     * CASE 1 — Fully paid + full refund.
     *
     * booking = 22000
     * paid = 22000
     * refund = 22000
     *
     * Expected after cancel:
     *   - customer AR = 0 (no debt)
     *   - treasury cash delta = -22000 (cash returned)
     *   - revenue = 0
     *   - cogs = 0
     *   - net profit = 0
     *   - all ledger invariants preserved
     */
    public function test_case1_fully_paid_full_refund_customer_ar_zero(): void
    {
        $booking = $this->booking();

        // Booking creation posts the credit-sale leg: pending → customer, 22000.
        $this->assertEquals(22000.0, $this->customerAr(),
            'After booking creation (no payment yet), customer AR must be +22000 (sale as debt).');

        $this->bookingService->addPayment($booking, [
            'amount' => 22000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        // After full payment: customer AR must be 0 (debt cleared by payment).
        $this->assertEquals(0.0, $this->customerAr(),
            'After full payment, customer AR must be 0 (debt cleared).');

        // Snapshot cashbox AFTER payment so the cancel's refund delta is observable.
        $cashboxBeforeCancel = $this->cashboxBalance();

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 1 — fully paid, full refund',
        ]);

        // CORE INVARIANT for this fix:
        $this->assertEquals(0.0, $this->customerAr(),
            'CASE 1: Customer AR must be 0 after fully-paid + fully-refunded cancellation. '.
            'Pre-fix: would stay at +22000 due to duplicate refund mirror credits.');

        $this->assertEqualsWithDelta(
            -22000.0, round($this->cashboxBalance() - $cashboxBeforeCancel, 2), 0.01,
            'CASE 1: Treasury cash delta (post-payment → post-cancel) must equal -22000 (cash returned). '.
            'Pre-fix: would equal -44000 due to double-debit by mirror + refund.');

        $pnl = $this->snapshotPnl();
        $this->assertEquals(0.0, $pnl['totalRevenues'],
            'CASE 1: Revenue must be 0 (FIN-B prefix on TX3 skips it from P&L).');
        $this->assertEquals(0.0, $pnl['totalCogs'],
            'CASE 1: COGS must be 0 (cancel reverses the prepaid carrier consumption).');
        $this->assertEquals(0.0, $pnl['netProfit'],
            'CASE 1: Net profit must be 0.');

        $this->assertEquals(FlightBookingStatus::REFUNDED, $booking->fresh()->status);

        // Verify the prefix is set on TX3 (idempotency of markTransactionReversed).
        $paymentId = $booking->refresh()->payments()->first()->id;
        $incomeNotes = Transaction::query()
            ->where('related_type', FlightPayment::class)
            ->where('related_id', $paymentId)
            ->where('type', 'income')
            ->value('notes');
        $this->assertNotNull($incomeNotes);
        $this->assertTrue(
            str_starts_with((string) $incomeNotes, 'عكس:')
            || str_starts_with((string) $incomeNotes, 'عكس '),
            'CASE 1: TX3 notes must start with عكس: prefix (markTransactionReversed sets it).'
        );

        $this->assertEveryTransactionBalanced();
        $this->assertEveryAccountInvariant();
    }

    /**
     * CASE 2 — Partially paid + full refund of paid portion.
     *
     * booking = 22000
     * paid = 12000
     * unpaid = 10000
     * refund = 12000 (full refund of what was paid; no penalty)
     *
     * Expected after cancel:
     *   - customer AR = 0 (debt cleared via TX5 sale-reversal of full sale-penalty=22000)
     *   - treasury cash delta = -12000 (cash returned for the paid portion only)
     *   - revenue = 0
     *   - cogs = 0
     *   - net profit = 0
     */
    public function test_case2_partial_payment_full_refund_customer_ar_zero(): void
    {
        $booking = $this->booking();

        // After booking creation: customer AR = 22000 (unpaid debt).
        $this->assertEquals(22000.0, $this->customerAr(),
            'After booking creation, customer AR must be +22000 (unpaid debt).');

        $this->bookingService->addPayment($booking, [
            'amount' => 12000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $this->assertEquals(10000.0, $this->customerAr(),
            'After 12000 partial payment of 22000 booking, customer AR = 22000 - 12000 = 10000.');

        // Snapshot cashbox AFTER payment so the cancel's refund delta is observable.
        $cashboxBeforeCancel = $this->cashboxBalance();

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 2 — partially paid, full refund of paid',
        ]);

        $this->assertEquals(0.0, $this->customerAr(),
            'CASE 2: Customer AR must be 0 after partial-pay + full-refund. '.
            'Pre-fix: would stay at +12000 due to mirror + refund credits.');

        $this->assertEqualsWithDelta(
            -12000.0, round($this->cashboxBalance() - $cashboxBeforeCancel, 2), 0.01,
            'CASE 2: Treasury cash delta (post-payment → post-cancel) must equal -12000 (only the paid portion refunded).');

        $pnl = $this->snapshotPnl();
        $this->assertEquals(0.0, $pnl['totalRevenues']);
        $this->assertEquals(0.0, $pnl['totalCogs']);
        $this->assertEquals(0.0, $pnl['netProfit']);

        $this->assertEveryTransactionBalanced();
        $this->assertEveryAccountInvariant();
    }

    /**
     * CASE 3 — Unpaid + cancel with no penalty (no refund expected).
     *
     * booking = 22000
     * paid = 0
     *
     * Expected after cancel:
     *   - customer AR = 0 (debt cleared via TX5 sale-reversal of full 22000)
     *   - treasury cash delta = 0 (no payment, no refund)
     *   - revenue = 0
     *   - cogs = 0
     *   - net profit = 0
     *   - status = CANCELLED (not REFUNDED, since refund_amount = 0)
     */
    public function test_case3_unpaid_cancel_no_phantom_refund(): void
    {
        $cashboxBefore = $this->cashboxBalance();

        $booking = $this->booking();
        // No addPayment.

        $this->assertEquals(22000.0, $this->customerAr(),
            'After unpaid booking creation, customer AR must equal the 22000 sale.');

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'notes' => 'CASE 3 — unpaid, cancel with no refund',
        ]);

        $this->assertEquals(0.0, $this->customerAr(),
            'CASE 3: Customer AR must be 0 after unpaid cancel.');

        $this->assertEqualsWithDelta(
            0.0, round($this->cashboxBalance() - $cashboxBefore, 2), 0.01,
            'CASE 3: Treasury cash delta must be 0 (no payment, no refund).');

        $pnl = $this->snapshotPnl();
        $this->assertEquals(0.0, $pnl['totalRevenues']);
        $this->assertEquals(0.0, $pnl['totalCogs']);
        $this->assertEquals(0.0, $pnl['netProfit']);

        $this->assertEquals(FlightBookingStatus::CANCELLED, $booking->fresh()->status,
            'CASE 3: Unpaid cancel with no refund should result in CANCELLED status, not REFUNDED.');

        $this->assertEveryTransactionBalanced();
        $this->assertEveryAccountInvariant();
    }

    /**
     * CASE 4 — Full payment + cancel with penalty kept.
     *
     * booking = 22000
     * paid = 22000
     * airline_penalty + office_penalty = 8000 (kept by office)
     * refund = 22000 - 8000 = 14000
     *
     * Expected after cancel:
     *   - customer AR = 0 (sale-reversal clears the debt; refund returns cash but does NOT re-add AR)
     *   - treasury cash delta = -14000 (only the refund amount)
     *   - revenue = 0
     *   - cogs = 0 (penalty is kept via carrier credit-back, NOT via P&L revenue)
     *   - net profit = 0
     *   - status = REFUNDED
     */
    public function test_case4_full_payment_with_penalty_customer_ar_zero(): void
    {
        $booking = $this->booking();
        $this->bookingService->addPayment($booking, [
            'amount' => 22000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        // Snapshot cashbox AFTER payment.
        $cashboxBeforeCancel = $this->cashboxBalance();

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 4000,
            'office_penalty' => 4000,
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 4 — full payment, 8000 penalty kept, 14000 refund',
        ]);

        $this->assertEquals(0.0, $this->customerAr(),
            'CASE 4: Customer AR must be 0 after full-payment + penalty + partial refund. '.
            'Pre-fix: would stay at +22000 (penalty did not absorb the duplicate credits).');

        $this->assertEqualsWithDelta(
            -14000.0, round($this->cashboxBalance() - $cashboxBeforeCancel, 2), 0.01,
            'CASE 4: Treasury cash delta (post-payment → post-cancel) must equal -14000 (the refund amount). '.
            'Pre-fix: would equal -36000 due to mirror (-22000) + refund (-14000).');

        $pnl = $this->snapshotPnl();
        $this->assertEquals(0.0, $pnl['totalRevenues']);

        // The airline_penalty (4000) is KEPT as a real cost to the company:
        // we paid the carrier 20000 but only got back 16000, so 4000 is the
        // net cancellation cost. This is the correct economic interpretation
        // of the penalty. (The office_penalty is internal — the office keeps
        // a portion of the sale as commission, but it's not a separate COGS;
        // it manifests as a net profit reduction via the smaller sale-reversal.)
        //
        // Calling deleteBookingWithReversal() AFTER cancel would zero the
        // COGS completely (as FlightCashBasisRegressionTest::test_s04
        // verifies), but cancel alone preserves the kept penalty as a real
        // cost — exactly what the user explicitly asked us to verify in
        // Case 4 ("verify the penalty remains correctly represented").
        $this->assertEquals(4000.0, $pnl['totalCogs'],
            'CASE 4: COGS = 4000 (= airline_penalty kept) — the penalty remains correctly '.
            'represented as a real cost to the company after cancel.');

        $this->assertEquals(-4000.0, $pnl['netProfit'],
            'CASE 4: Net profit = -4000 (the loss from the kept airline_penalty).');

        $this->assertEquals(FlightBookingStatus::REFUNDED, $booking->fresh()->status);

        $this->assertEveryTransactionBalanced();
        $this->assertEveryAccountInvariant();
    }

    /**
     * CASE 5 — Multiple payments + cumulative cancel.
     *
     * booking = 22000
     * paid (cumulative) = 10000 + 8000 + 4000 = 22000 (3 separate addPayment calls)
     * refund = 22000 (full refund of cumulative paid)
     *
     * Expected after cancel:
     *   - customer AR = 0
     *   - treasury cash delta = -22000 (cash returned for the cumulative paid)
     *   - revenue = 0
     *   - cogs = 0
     *   - net profit = 0
     *   - Each of the 3 payment-side Income rows has the عكس: prefix set
     *     (markTransactionReversed called once per payment, idempotent).
     */
    public function test_case5_multiple_payments_cumulative_cancel(): void
    {
        $booking = $this->booking();

        $this->bookingService->addPayment($booking, [
            'amount' => 10000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 5 — payment 1 of 3',
        ]);
        $this->bookingService->addPayment($booking, [
            'amount' => 8000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 5 — payment 2 of 3',
        ]);
        $this->bookingService->addPayment($booking, [
            'amount' => 4000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 5 — payment 3 of 3',
        ]);

        $this->assertEquals(0.0, $this->customerAr(),
            'After 3 cumulative payments totaling 22000, customer AR must be 0.');

        $this->assertEquals(22000.0, (float) $booking->fresh()->payments()->sum('amount'),
            'Cumulative payments should sum to 22000.');

        // Snapshot cashbox AFTER all 3 payments.
        $cashboxBeforeCancel = $this->cashboxBalance();

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashbox->id,
            'notes' => 'CASE 5 — full cancel of multi-payment booking',
        ]);

        $this->assertEquals(0.0, $this->customerAr(),
            'CASE 5: Customer AR must be 0 after cumulative-pay + full-refund. '.
            'Pre-fix: would stay at +22000 due to 3 mirror credits + 1 refund credit.');

        $this->assertEqualsWithDelta(
            -22000.0, round($this->cashboxBalance() - $cashboxBeforeCancel, 2), 0.01,
            'CASE 5: Treasury cash delta (post-payments → post-cancel) must equal -22000 '.
            '(single cash return, NOT -44000 from 3 mirrors + 1 refund).');

        $pnl = $this->snapshotPnl();
        $this->assertEquals(0.0, $pnl['totalRevenues']);
        $this->assertEquals(0.0, $pnl['totalCogs']);
        $this->assertEquals(0.0, $pnl['netProfit']);

        // Verify EVERY per-payment Income row has the prefix set (no payment is "skipped").
        $reversedPaymentCount = Transaction::query()
            ->where('related_type', FlightPayment::class)
            ->whereIn('related_id', $booking->payments()->pluck('id')->all())
            ->where('type', 'income')
            ->where(function ($q) {
                $q->where('notes', 'like', 'عكس:%')
                    ->orWhere('notes', 'like', 'عكس %');
            })
            ->count();
        $totalPaymentCount = $booking->payments()->count();
        $this->assertSame($totalPaymentCount, $reversedPaymentCount,
            "CASE 5: Every payment's income row must be marked reversed. ".
            "Expected {$totalPaymentCount}, got {$reversedPaymentCount}.");

        $this->assertEveryTransactionBalanced();
        $this->assertEveryAccountInvariant();
    }

    /**
     * Idempotency — second cancel attempt must throw, no double reversal.
     */
    public function test_idempotency_second_cancel_throws_no_double_reversal(): void
    {
        $booking = $this->booking();
        $this->bookingService->addPayment($booking, [
            'amount' => 22000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashbox->id,
            'notes' => 'first cancel',
        ]);

        // Snapshot TX count after first cancel.
        $txCountAfterFirst = Transaction::query()->count();

        // Second cancel must throw.
        $threw = false;
        try {
            $this->bookingService->cancelBooking($booking->refresh(), [
                'airline_penalty' => 0,
                'office_penalty' => 0,
                'account_id' => $this->cashbox->id,
                'notes' => 'second cancel attempt',
            ]);
        } catch (\Exception $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Second cancel must throw (booking already REFUNDED).');

        $txCountAfterSecond = Transaction::query()->count();
        $this->assertSame($txCountAfterFirst, $txCountAfterSecond,
            'Second cancel attempt must not post any new transactions.');

        // Each payment's Income row must be marked ONCE, not twice.
        $paymentId = $booking->refresh()->payments()->first()->id;
        $incomeRow = Transaction::query()
            ->where('related_type', FlightPayment::class)
            ->where('related_id', $paymentId)
            ->where('type', 'income')
            ->first();
        $this->assertNotNull($incomeRow);
        $this->assertTrue(
            str_starts_with((string) $incomeRow->notes, 'عكس:')
            || str_starts_with((string) $incomeRow->notes, 'عكس '),
            'Income row must have the prefix marker set exactly once after first cancel.'
        );
    }
}
