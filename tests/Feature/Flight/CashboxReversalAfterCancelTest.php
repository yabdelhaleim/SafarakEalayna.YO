<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for DEFECT-005 + DEFECT-006 — cashbox loss during
 * cancel-then-delete lifecycle.
 *
 * Background:
 *   - The original `FlightSoftDeleteRealWorldTest::test_scenario2` and
 *     `::test_scenario3` assert only at the END of the lifecycle (after
 *     delete), using `assertBalancesUnchanged(snapshot)`. They prove the
 *     BUG EXISTS but they don't pin down WHERE in the flow the cashbox
 *     drifted.
 *
 *   - This file takes the opposite approach: capture the cashbox balance
 *     at every step (after createBooking, after each addPayment, after
 *     cancelBooking, after deleteBookingWithReversal) and assert the
 *     mathematical delta. The final assertion is the same
 *     `assertBalancesUnchanged`, but the intermediate assertions let a
 *     failing test pinpoint which step introduced the drift.
 *
 * Expected behavior (post-fix):
 *   - scenario2 (book + partial pay + cancel-with-refund + delete):
 *       cashbox returns to pre-booking baseline. The 14000 refund issued
 *       during cancel is walked back during delete (H1).
 *   - scenario3 (book + full pay + cancel-with-no-refund + delete):
 *       cashbox returns to pre-booking baseline. The pending_sales_receivable
 *       residual is cleared without debiting the cashbox (H2).
 *   - KWD + refund + delete:
 *       H1 throws BusinessLogicException (Option B decision — known
 *       limitation, see trace .zcode/plans/DEFECT_005_006_TRACE_20260824.md).
 *       The HTTP layer surfaces it as 409 Conflict.
 *
 * Status (today, before fix):
 *   - test_bug_a_defect_006_cancel_no_refund_delete → FAIL (cashbox drifts)
 *   - test_bug_b_defect_005_cancel_with_refund_delete → FAIL (cashbox drifts)
 *   - test_known_limitation_kwd_refund_throws → PASS (current code happens
 *     to throw — but the wrong way: silent 422 from the controller, not 409)
 */
class CashboxReversalAfterCancelTest extends TestCase
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
            'name' => 'Cashbox Reversal Admin',
            'email' => 'cashbox-reversal-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    // ════════════════════════════════════════════════════════════════
    // BUG A — DEFECT-006: cancel-with-no-refund + delete
    // ════════════════════════════════════════════════════════════════

    /**
     * Book 12000 EGP, pay 12000 (full), cancel with FULL penalty (12000),
     * delete. Expected: cashbox back to pre-booking 50000. Bug: cashbox
     * drops to 38000 (lost 12000).
     *
     * Numbers explained:
     *   - Initial cashbox: 50000 (fixture)
     *   - After createBooking + pay 12000: 50000 + 12000 = 62000
     *   - After cancel (full penalty, refund=0): 62000 - 12000 (FIN-B revenue reversal) = 50000
     *   - After delete (post-fix): 50000 (H2 clears residual without touching cashbox)
     *   - After delete (current buggy): 50000 - 12000 (FIN-A phantom debit) = 38000
     */
    public function test_bug_a_defect_006_cancel_no_refund_delete(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50000);

        $baseline = (float) $cashbox->fresh()->balance;
        $this->assertEquals(50000.0, $baseline, 'Fixture sanity: cashbox starts at 50000 (after 100000 - 50000 recharge).');

        // Step 1: createBooking with payment 12000
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Qatar Airways',
            'from_airport' => 'CAI',
            'to_airport' => 'DOH',
            'departure_date' => now()->addDays(15)->toDateString(),
            'trip_type' => 'round_trip',
            'currency' => 'EGP',
            'purchase_price' => 10000,
            'selling_price' => 12000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'BUG_A_'.uniqid(),
            'passengers' => [
                ['first_name' => 'سامي', 'last_name' => 'خالد', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 12000,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
            ],
        ]);

        $afterCreate = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline + 12000.0,
            $afterCreate,
            0.01,
            'After createBooking(paid 12000): cashbox should be 62000 (= 50000 + 12000).'
        );

        // Step 2: cancel with FULL penalty (refund = 0)
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 7000,
            'office_penalty' => 5000,  // 12000 total penalty, refund = 0
            'account_id' => $cashbox->id,
        ]);

        $this->assertEquals(0.0, (float) $refund->refund_amount,
            'Full-penalty cancel must produce refund_amount = 0.');

        $afterCancel = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline,
            $afterCancel,
            0.01,
            'After cancel (full penalty, refund=0): cashbox should be back to 50000 (FIN-B reversed the income).'
        );

        // Step 3: delete the cancelled booking
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $afterDelete = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline,
            $afterDelete,
            0.01,
            'DEFECT-006: After delete, cashbox should still be 50000 (H2 must NOT debit cashbox). '.
            'Current buggy behavior: 38000 (lost 12000).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // BUG B — DEFECT-005: cancel-with-refund + delete
    // ════════════════════════════════════════════════════════════════

    /**
     * Book 25000 EGP, pay 10000, pay 5000, cancel with 1000 penalty
     * (refund = 14000), delete. Expected: cashbox back to pre-booking 50000.
     * Bug: cashbox drops to 35000 (lost 15000 = 14000 refund + 1000 phantom).
     *
     * Numbers explained:
     *   - Initial cashbox: 50000 (fixture)
     *   - After createBooking + pay 10000: 50000 + 10000 = 60000
     *   - After addPayment 5000: 60000 + 5000 = 65000
     *   - After cancel (penalty=1000, refund=14000): 65000 - 14000 = 51000
     *   - After delete (post-fix H1): 51000 + 14000 = 50000
     *   - After delete (current buggy): 51000 - 1000 (FIN-A phantom) = 50000
     *     -- but the test asserts final == baseline. The 1000 phantom IS the bug.
     */
    public function test_bug_b_defect_005_cancel_with_refund_delete(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50000);

        $baseline = (float) $cashbox->fresh()->balance;
        $this->assertEquals(50000.0, $baseline, 'Fixture sanity: cashbox starts at 50000 (after 100000 - 50000 recharge).');

        // Step 1: createBooking with partial payment 10000
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Emirates',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(10)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 20000,
            'selling_price' => 25000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'BUG_B_'.uniqid(),
            'passengers' => [
                ['first_name' => 'فاطمة', 'last_name' => 'حسن', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 10000,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع نص',
            ],
        ]);

        $afterCreate = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline + 10000.0,
            $afterCreate,
            0.01,
            'After createBooking(paid 10000): cashbox should be 60000.'
        );

        // Step 2: addPayment 5000 (second installment)
        $this->bookingService->addPayment($booking, [
            'amount' => 5000,
            'account_id' => $cashbox->id,
            'payment_method' => 'cash',
            'notes' => 'دفعة ثانية',
        ]);

        $afterSecondPayment = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline + 15000.0,
            $afterSecondPayment,
            0.01,
            'After addPayment(5000): cashbox should be 65000.'
        );

        // Step 3: cancel with 1000 penalty (refund = 14000)
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 600,
            'office_penalty' => 400,
            'account_id' => $cashbox->id,
            'notes' => 'إلغاء العميل',
        ]);

        $this->assertEquals(14000.0, (float) $refund->refund_amount,
            'Cancel with 1000 penalty must produce refund_amount = 14000.');

        $afterCancel = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline + 1000.0,
            $afterCancel,
            0.01,
            'DEFECT-008 FIX (2026-08-26): after cancel-with-refund the cashbox should be baseline + kept_penalty '.
            '(= 50000 + 1000 = 51000). Pre-fix: 36000 because FIN-B wrongly reversed 15000 income on top of the 14000 refund.'
        );

        // Step 4: delete — H1 must walk back the 14000 refund
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $afterDelete = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $baseline,
            $afterDelete,
            0.01,
            'DEFECT-005: After delete, cashbox should be 50000 (H1 walks back the 14000 refund). '.
            'Current buggy behavior: 35000 (lost 15000).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // KNOWN LIMITATION — KWD + cancel-with-refund + delete
    // ════════════════════════════════════════════════════════════════

    /**
     * KNOWN LIMITATION (2026-08-24) — see
     * .zcode/plans/DEFECT_005_006_TRACE_20260824.md for the decision.
     *
     * A KWD booking + cancel-with-refund + delete cycle will throw
     * BusinessLogicException (HTTP 409) because H1 cannot walk back a
     * cross-currency refund walk-back without explicit FX conversion.
     *
     * This test pins down the EXPECTED behavior:
     *   1. Booking + cancel-with-refund succeeds (in KWD cashbox).
     *   2. deleteBookingWithReversal throws BusinessLogicException.
     *   3. The booking is NOT soft-deleted (transaction rolled back).
     *   4. The exception message is in Arabic and identifies the limitation.
     *
     * Post-fix (DEFECT-005/006 commit), this test should PASS.
     * Currently it may or may not — depends on whether the buggy FIN-A
     * branch "successfully" mangles the cross-currency transfer. If it
     * fails today, it's actually documenting another instance of the bug.
     */
    public function test_known_limitation_kwd_refund_throws(): void
    {
        // Two-currency fixture: KWD system/carrier + KWD cashbox
        $system = FlightSystem::create([
            'name' => 'KWD System KL',
            'code' => 'KL'.uniqid(),
            'type' => 'gds', 'is_active' => true,
            'currency' => 'KWD',
            'credit_limit' => 0, 'created_by' => $this->admin->id,
        ]);

        $carrier = FlightCarrier::create([
            'name' => 'KWD Carrier KL',
            'code' => 'KL'.uniqid(),
            'flight_system_id' => $system->id,
            'currency' => 'KWD',
            'credit_limit' => 100000, 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $kwdCashbox = $this->createKwdCashbox(5000);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $kwdCashbox, 2000);

        $customer = $this->buildFixtureCustomer();

        $exchangeRate = 160.0;

        // 100 KWD booking with selling_price_foreign populated so the cancel
        // math works (without it the cancel computes refund=0 for KWD).
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Jazeera Airways',
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(12)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'foreign_currency' => 'KWD',
            'exchange_rate' => $exchangeRate,
            'purchase_price_foreign' => 50.0,
            'purchase_price' => 8000.0,
            'selling_price' => 100.0 * $exchangeRate,        // 16000 EGP
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'KL_'.uniqid(),
            'passengers' => [
                ['first_name' => 'خالد', 'last_name' => 'الجابر', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 100.0,  // 100 KWD raw
                'account_id' => $kwdCashbox->id,
                'payment_method' => 'cash',
            ],
        ]);

        // Set selling_price_foreign directly (column exists but not in fillable).
        // The cancel math needs this to compute the KWD refund correctly.
        $booking->forceFill(['selling_price_foreign' => 100.0])->save();
        $booking->refresh();

        // Capture KWD cashbox baseline AFTER the recharge+booking+pay for
        // precise delta assertions (the recharge debits 2000 KWD).
        $kwdBaseline = (float) $kwdCashbox->fresh()->balance;

        // Skip the cancelBooking flow — it has its own pre-existing KWD+cross-currency
        // bugs (refundTreasuryAccount requires converted_amount for cross-currency
        // transfers). For THIS test, manually create the FlightRefund row to
        // simulate "the booking was cancelled with a refund" state, then
        // verify that deleteBookingWithReversal correctly throws H1.

        // Manually mark the booking as CANCELLED and create a refund row.
        $booking->update(['status' => FlightBookingStatus::CANCELLED]);
        $refund = \App\Models\Flight\FlightRefund::create([
            'flight_booking_id' => $booking->id,
            'airline_penalty' => 20.0,
            'office_penalty' => 10.0,
            'total_paid' => 100.0,
            'refund_amount' => 70.0,  // 100 paid - 30 penalty = 70 refund
            'account_id' => $kwdCashbox->id,
            'transaction_id' => null,
            'status' => 'processed',
            'notes' => 'Manual refund for KWD known-limitation test',
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals(70.0, (float) $refund->refund_amount,
            'Sanity: refund row must record 70 KWD refund amount.'
        );

        // ACT: delete the KWD booking — H1 must throw BusinessLogicException
        // because the cross-currency walk-back (customer_AR[EGP] → cashbox[KWD])
        // cannot complete without explicit converted_amount/exchange_rate.
        $thrown = null;
        try {
            $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        } catch (\App\Exceptions\BusinessLogicException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown,
            'KNOWN LIMITATION (DEFECT-005/006 Option B): deleteBookingWithReversal must throw '.
            'BusinessLogicException for KWD+refund cross-currency.'
        );

        // The booking is NOT soft-deleted (transaction rolled back)
        $booking->refresh();
        $this->assertNull($booking->deleted_at,
            'Booking must NOT be soft-deleted when H1 throws — the whole transaction must roll back.'
        );

        // The exception message must be self-explanatory in Arabic
        $message = $thrown->getMessage();
        $this->assertStringContainsString(
            'KWD',
            $message,
            'Exception message must identify the currency (KWD) for operator clarity.'
        );

        // The KWD cashbox must NOT have phantom movement from the failed delete
        $afterFailedDelete = (float) $kwdCashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $kwdBaseline,
            $afterFailedDelete,
            0.01,
            'Cashbox must be unchanged after failed delete — no phantom movement.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // FIXTURE HELPERS (minimal — separate from FlightSoftDeleteRealWorldTest)
    // ════════════════════════════════════════════════════════════════

    protected function buildFixtureCashbox(string $currency): Account
    {
        $account = Account::create([
            'name' => "Test {$currency} Cashbox",
            'type' => 'cashbox',
            'currency' => $currency,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',  // 'tourism' is the DIVISION for flight cashboxes
            'created_by' => $this->admin->id,
        ]);

        // Force-set the balance via the ledger guard (defence-in-depth).
        // EGP starts at 100000 so the fixture leaves 50000 after a 50000 recharge
        // — matches the existing FlightSoftDeleteRealWorldTest baseline shape.
        LedgerBalanceMutationGuard::run(function () use ($account, $currency) {
            $account->balance = $currency === 'KWD' ? 5000.0 : 100000.0;
            $account->save();
        });
        // Stamp an opening entry so the invariant is preserved.
        AccountEntry::create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0.00,
            'credit' => $currency === 'KWD' ? 5000.0 : 100000.0,
            'balance_after' => $currency === 'KWD' ? 5000.0 : 100000.0,
            'notes' => "رصيد افتتاحي {$currency}",
        ]);

        return $account->refresh();
    }

    protected function createKwdCashbox(float $opening): Account
    {
        $account = Account::create([
            'name' => 'KWD Cashbox KL',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);
        LedgerBalanceMutationGuard::run(function () use ($account, $opening) {
            $account->balance = $opening;
            $account->save();
        });
        AccountEntry::create([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => 0.00,
            'credit' => $opening,
            'balance_after' => $opening,
            'notes' => 'رصيد افتتاحي KWD',
        ]);

        return $account->refresh();
    }

    protected function buildFixtureCustomer(): Customer
    {
        return Customer::create([
            'full_name' => 'عميل اختبار الكاش بوكس',
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'cashbox-test-'.uniqid().'@test.com',
            'national_id' => '29'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'travel_country' => 'EGY',
        ]);
    }

    protected function buildFixtureSystem(string $currency): FlightSystem
    {
        return FlightSystem::create([
            'name' => "{$currency} System",
            'code' => substr($currency, 0, 2).'S'.uniqid(),
            'type' => 'gds', 'is_active' => true,
            'currency' => $currency,
            'credit_limit' => 5000, 'created_by' => $this->admin->id,
        ]);
    }

    protected function buildFixtureCarrier(FlightSystem $system): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TC'.uniqid(),
            'flight_system_id' => $system->id,
            'currency' => $system->currency,
            'credit_limit' => 100000, 'is_active' => true,
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
}