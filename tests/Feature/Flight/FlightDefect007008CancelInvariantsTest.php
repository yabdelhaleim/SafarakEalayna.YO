<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Reports\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for DEFECT-007 + DEFECT-008 — customer_AR
 * over-statement + cashbox loss in cancel-with-refund lifecycle.
 *
 * Background (from .zcode/plans/DEFECT_005_006_TRACE_20260824.md):
 *   - DEFECT-007: customer_AR ended at +15000 (= total_paid) after
 *     cancel-with-refund, instead of the +1000 office penalty kept
 *     (or 0 in the no-penalty case).
 *   - DEFECT-008: cashbox lost 18000 (= total_paid) instead of just
 *     the 17300 refund on a paid-in-full cancel-with-partial-penalty.
 *
 * The fix (2026-08-26):
 *   - Cancel side: SKIP `reverseFlightBookingRevenue()` (FIN-B) when
 *     `refundAmount > 0.001`. Run `softReverseAddPaymentRevenues()`
 *     to set `'عкс:'` on the original income notes (so the P&L
 *     classifier skips the row) WITHOUT posting mirror entries that
 *     would over-debit the cashbox.
 *   - Delete side: `reverseAddPaymentsOnCancelThenDelete()` posts a
 *     2-leg transfer through `income_clearing` that brings the
 *     cashbox AND customer_AR back to baseline while leaving the
 *     original income tagged with `'عкс:'` (revenue stays at 0).
 *
 * This test pins down the FINAL invariants after cancel-with-refund
 * (no delete) for all four DEFECT-007/008 scenarios:
 *
 *   | Scenario | Setup              | Expected cashbox | Expected customer_AR | Expected pending | Expected revenue |
 *   |----------|--------------------|------------------|----------------------|------------------|------------------|
 *   | A        | full-pay, P=0      | baseline         | 0                    | 0                | 0                |
 *   | B        | full-pay, P>0      | baseline + P     | 0                    | -P               | 0                |
 *   | C        | partial, P=0       | baseline         | 0                    | 0                | 0                |
 *   | D        | partial, P>0       | baseline + P     | 0                    | -P               | 0                |
 *
 * Where `P = airline_penalty + office_penalty` (total kept penalty).
 *
 * @see app/Services/Flight/FlightBookingService.php
 *      (DEFECT-007/008 fixes — `softReverseAddPaymentRevenues()` and
 *      `reverseAddPaymentsOnCancelThenDelete()`)
 */
class FlightDefect007008CancelInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(FlightBookingService::class);
        $this->seedExchangeRates();

        $this->admin = User::factory()->create([
            'name' => 'DEFECT-007/008 Admin',
            'email' => 'defect-007-008-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    // ════════════════════════════════════════════════════════════════
    // Scenario A — full-pay + zero penalty (full refund)
    // ════════════════════════════════════════════════════════════════

    /**
     * Book 18000 EGP, pay 18000 (full), cancel with 0 penalty,
     * refund 18000 (full). Assert the post-cancel invariants:
     *   - cashbox returns to baseline (no money kept).
     *   - customer_AR returns to 0 (no customer relationship left).
     *   - pending_sales_receivable returns to 0 (sale fully cancelled).
     *   - revenue returns to 0 (the 18000 income was soft-reversed).
     */
    public function test_scenario_A_full_pay_zero_penalty_full_refund(): void
    {
        $baseline = 50000.0;
        $cashbox = $this->buildFixtureCashbox('EGP', $baseline);
        $customer = $this->buildFixtureCustomer();
        $carrier = $this->buildFixtureCarrier(FlightSystem::first());

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Scenario A Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $carrier->id,
            'account_id' => $cashbox->id,
            'passengers' => [
                ['name' => 'Scenario A Passenger', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount' => 18000,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'notes' => 'Scenario A — full payment',
        ]);

        $cashbox->refresh();
        $this->assertEqualsWithDelta(
            $baseline + 18000.0,
            (float) $cashbox->balance,
            0.01,
            'After createBooking + addPayment(18000): cashbox should be 68000.'
        );

        // Cancel with zero penalty → full refund
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $cashbox->id,
            'notes' => 'Scenario A — full refund cancel',
        ]);

        $this->assertEquals(18000.0, (float) $refund->refund_amount,
            'Cancel with 0 penalty must produce refund_amount = 18000.');

        $cashbox->refresh();
        $this->assertEqualsWithDelta(
            $baseline,
            (float) $cashbox->balance,
            0.01,
            'DEFECT-008 FIX (Scenario A): after full-refund cancel the cashbox must be back at baseline.'
        );

        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAccount->balance,
            0.01,
            'DEFECT-007 FIX (Scenario A): customer_AR must be 0 after full-refund cancel, not over-stated.'
        );

        $pendingPlaceholder = \App\Models\Account::query()
            ->where('name', 'ذمم عملاء طيران معلق')
            ->first();
        $this->assertNotNull($pendingPlaceholder, 'pending_sales_receivable placeholder must exist.');
        $this->assertEqualsWithDelta(
            0.0,
            (float) $pendingPlaceholder->balance,
            0.01,
            'pending_sales_receivable must net to 0 after full-refund cancel.'
        );

        // Revenue check — requires a small P&L helper since
        // FinancialReportService::build() reads many other rows.
        $revenue = $this->getRevenueForBooking($booking);
        $this->assertEqualsWithDelta(
            0.0,
            $revenue,
            0.01,
            'Revenue must be 0 after full-refund cancel (DEFECT-008 soft-reversal).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Scenario B — full-pay + positive penalty (partial refund)
    // ════════════════════════════════════════════════════════════════

    /**
     * Book 18000 EGP, pay 18000 (full), cancel with 700 total penalty
     * (500 airline + 200 office), refund 17300. Assert the post-c invariants:
     *   - cashbox = baseline + 700 (kept penalty, no over-debit).
     *   - customer_AR = 0 (the office-penalty as "kept revenue" is in cashbox).
     *   - pending_sales_receivable = -700 (residual cleared by H2 on delete).
     *   - revenue = 0 (the 18000 income was soft-reversed).
     *
     * Pre-DEFECT-008 behaviour: cashbox = 32700 (lost full 18000 instead of
     * just the 17300 refund) — verified in
     * `tests/Feature/FlightBookingFlowTest.php::test_cancels_booking_with_complete_accounting_rollback`.
     */
    public function test_scenario_B_full_pay_partial_penalty_partial_refund(): void
    {
        $baseline = 50000.0;
        $totalPenalty = 700.0;
        $refundExpected = 18000.0 - $totalPenalty;

        $cashbox = $this->buildFixtureCashbox('EGP', $baseline);
        $customer = $this->buildFixtureCustomer();
        $carrier = $this->buildFixtureCarrier(FlightSystem::first());

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Scenario B Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $carrier->id,
            'account_id' => $cashbox->id,
            'passengers' => [
                ['name' => 'Scenario B Passenger', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount' => 18000,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'notes' => 'Scenario B — full payment',
        ]);

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 500,
            'office_penalty' => 200,
            'account_id' => $cashbox->id,
            'notes' => 'Scenario B — partial refund cancel',
        ]);

        $this->assertEquals($refundExpected, (float) $refund->refund_amount,
            'Scenario B: refund must be total_paid - total_penalty = 17300.');

        $cashbox->refresh();
        $this->assertEqualsWithDelta(
            $baseline + $totalPenalty,
            (float) $cashbox->balance,
            0.01,
            'DEFECT-008 FIX (Scenario B): after partial-refund cancel the cashbox must be baseline + kept_penalty (50700), '.
            'NOT baseline - total_paid (32700).'
        );

        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAccount->balance,
            0.01,
            'DEFECT-007 FIX (Scenario B): customer_AR must be 0 after partial-refund cancel, not over-stated.'
        );

        $pendingPlaceholder = \App\Models\Account::query()
            ->where('name', 'ذمم عملاء طيران معلق')
            ->first();
        $this->assertNotNull($pendingPlaceholder, 'pending_sales_receivable placeholder must exist.');
        $this->assertEqualsWithDelta(
            -1.0 * $totalPenalty,
            (float) $pendingPlaceholder->balance,
            0.01,
            'pending_sales_receivable must equal -kept_penalty (-700) after partial-refund cancel. '.
            'Residual cleared by H2 in deleteBookingWithReversal.'
        );

        $revenue = $this->getRevenueForBooking($booking);
        $this->assertEqualsWithDelta(
            0.0,
            $revenue,
            0.01,
            'Revenue must be 0 after partial-refund cancel (DEFECT-008 soft-reversal).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Scenario C — partial pay + zero penalty (full refund of paid amount)
    // ════════════════════════════════════════════════════════════════

    /**
     * Book 20000 EGP, pay 8000 (partial), cancel with 0 penalty,
     * refund 8000 (full of paid). Assert the post-c invariants:
     *   - cashbox returns to baseline (no money kept).
     *   - customer_AR returns to 0 (no customer relationship left).
     *   - pending_sales_receivable returns to 0 (sale fully cancelled).
     *   - revenue returns to 0 (the 8000 income was soft-reversed).
     */
    public function test_scenario_C_partial_pay_zero_penalty_full_refund(): void
    {
        $baseline = 50000.0;
        $paidAmount = 8000.0;

        $cashbox = $this->buildFixtureCashbox('EGP', $baseline);
        $customer = $this->buildFixtureCustomer();
        $carrier = $this->buildFixtureCarrier(FlightSystem::first());

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Scenario C Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 20000,
            'flight_carrier_id' => $carrier->id,
            'account_id' => $cashbox->id,
            'passengers' => [
                ['name' => 'Scenario C Passenger', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount' => $paidAmount,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'notes' => 'Scenario C — partial payment',
        ]);

        $cashbox->refresh();
        $this->assertEqualsWithDelta(
            $baseline + $paidAmount,
            (float) $cashbox->balance,
            0.01,
            'After createBooking + addPayment(8000): cashbox should be 58000.'
        );

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $cashbox->id,
            'notes' => 'Scenario C — full refund cancel',
        ]);

        $this->assertEquals($paidAmount, (float) $refund->refund_amount,
            'Scenario C: refund must equal the paid amount (8000).');

        $cashbox->refresh();
        $this->assertEqualsWithDelta(
            $baseline,
            (float) $cashbox->balance,
            0.01,
            'DEFECT-008 FIX (Scenario C): after partial-pay + full-refund cancel the cashbox must be back at baseline.'
        );

        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAccount->balance,
            0.01,
            'DEFECT-007 FIX (Scenario C): customer_AR must be 0 after full-refund cancel.'
        );

        $pendingPlaceholder = \App\Models\Account::query()
            ->where('name', 'ذمم عملاء طيران معلق')
            ->first();
        $this->assertNotNull($pendingPlaceholder);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $pendingPlaceholder->balance,
            0.01,
            'pending_sales_receivable must net to 0 after partial-pay full-refund cancel.'
        );

        $revenue = $this->getRevenueForBooking($booking);
        $this->assertEqualsWithDelta(
            0.0,
            $revenue,
            0.01,
            'Revenue must be 0 after partial-pay full-refund cancel (DEFECT-008 soft-reversal).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Scenario D — partial pay + positive penalty (partial refund)
    // ════════════════════════════════════════════════════════════════

    /**
     * Book 25000 EGP, pay 15000 (partial), cancel with 1000 total penalty
     * (600 airline + 400 office), refund 14000. Assert the post-c invariants:
     *   - cashbox = baseline + 1000 (kept penalty).
     *   - customer_AR = 0.
     *   - pending_sales_receivable = -1000 (residual cleared by H2 on delete).
     *   - revenue = 0 (the 15000 income was soft-reversed).
     */
    public function test_scenario_D_partial_pay_partial_penalty_partial_refund(): void
    {
        $baseline = 50000.0;
        $sellingPrice = 25000.0;
        $paidAmount = 15000.0;
        $totalPenalty = 1000.0;
        $refundExpected = $paidAmount - $totalPenalty;

        $cashbox = $this->buildFixtureCashbox('EGP', $baseline);
        $customer = $this->buildFixtureCustomer();
        $carrier = $this->buildFixtureCarrier(FlightSystem::first());

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Scenario D Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 20000,
            'selling_price' => $sellingPrice,
            'flight_carrier_id' => $carrier->id,
            'account_id' => $cashbox->id,
            'passengers' => [
                ['name' => 'Scenario D Passenger', 'type' => 'adult'],
            ],
        ]);

        // Two-installment partial pay
        $this->bookingService->addPayment($booking, [
            'amount' => 10000,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'notes' => 'Scenario D — first installment',
        ]);
        $this->bookingService->addPayment($booking, [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'notes' => 'Scenario D — second installment',
        ]);

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 600,
            'office_penalty' => 400,
            'account_id' => $cashbox->id,
            'notes' => 'Scenario D — partial refund cancel',
        ]);

        $this->assertEquals($refundExpected, (float) $refund->refund_amount,
            'Scenario D: refund must be paid - total_penalty = 14000.');

        $cashbox->refresh();
        $this->assertEqualsWithDelta(
            $baseline + $totalPenalty,
            (float) $cashbox->balance,
            0.01,
            'DEFECT-008 FIX (Scenario D): after partial-pay + partial-refund cancel the cashbox must be baseline + kept_penalty (51000).'
        );

        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAccount->balance,
            0.01,
            'DEFECT-007 FIX (Scenario D): customer_AR must be 0 after partial-refund cancel.'
        );

        $pendingPlaceholder = \App\Models\Account::query()
            ->where('name', 'ذمم عملاء طيران معلق')
            ->first();
        $this->assertNotNull($pendingPlaceholder);
        $this->assertEqualsWithDelta(
            -1.0 * $totalPenalty,
            (float) $pendingPlaceholder->balance,
            0.01,
            'pending_sales_receivable must equal -kept_penalty (-1000). Residual cleared by H2 in deleteBookingWithReversal.'
        );

        $revenue = $this->getRevenueForBooking($booking);
        $this->assertEqualsWithDelta(
            0.0,
            $revenue,
            0.01,
            'Revenue must be 0 after partial-refund cancel (DEFECT-008 soft-reversal).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Fixtures (copied from CashboxReversalAfterCancelTest.php pattern)
    // ════════════════════════════════════════════════════════════════

    protected function buildFixtureCashbox(string $currency, float $balance = 50000.0): Account
    {
        return Account::create([
            'name' => 'Scenario Cashbox '.$currency,
            'type' => \App\Enums\AccountType::Cashbox,
            'balance' => $balance,
            'currency' => $currency,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'tourism', // Cashbox = liquidity = division (tourism per AccountModuleContract)
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function buildFixtureCustomer(): Customer
    {
        $customer = Customer::create([
            'full_name' => 'Scenario Customer',
            'phone' => '0100'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);

        return $customer;
    }

    protected function buildFixtureCarrier(?FlightSystem $system = null): FlightCarrier
    {
        if ($system === null) {
            $system = FlightSystem::create([
                'name' => 'Scenario System',
                'code' => 'SCS'.uniqid(),
                'type' => 'gds',
                'is_active' => true,
                'currency' => 'EGP',
                'credit_limit' => 5000,
                'created_by' => $this->admin->id,
            ]);
        }

        return FlightCarrier::create([
            'name' => 'Scenario Carrier',
            'code' => 'SC'.uniqid(),
            'iata_code' => 'SC',
            'flight_system_id' => $system->id,
            'currency' => $system->currency,
            'credit_limit' => 200000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function seedExchangeRates(): void
    {
        $rates = [
            ['code' => 'EGP', 'name_ar' => 'جنيه مصري', 'name_en' => 'Egyptian Pound', 'symbol' => 'E£', 'exchange_rate' => 1.0,    'is_active' => true, 'order' => 1],
            ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar',     'symbol' => '$',   'exchange_rate' => 48.5,  'is_active' => true, 'order' => 2],
            ['code' => 'KWD', 'name_ar' => 'دينار كويتي',   'name_en' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'exchange_rate' => 157.5, 'is_active' => true, 'order' => 4],
        ];
        foreach ($rates as $row) {
            \App\Models\Setting\Currency::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    /**
     * Compute revenue for the given booking by summing the `amount` of
     * every income transaction whose notes do NOT start with `'عكس:'`
     * and whose notes do NOT start with `'عكس '`. This mirrors the
     * classifier logic in FinancialReportService.
     */
    protected function getRevenueForBooking(FlightBooking $booking): float
    {
        $paymentIds = $booking->payments()->pluck('id')->all();

        $rows = \App\Models\Transaction::query()
            ->where('related_type', \App\Models\Flight\FlightPayment::class)
            ->whereIn('related_id', $paymentIds)
            ->where('type', 'income')
            ->get();

        $revenue = 0.0;
        foreach ($rows as $row) {
            $notes = (string) ($row->notes ?? '');
            if (str_starts_with($notes, 'عكس:') || str_starts_with($notes, 'عكس ')) {
                continue;
            }
            $revenue += (float) $row->amount;
        }

        return $revenue;
    }
}