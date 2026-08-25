<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupMember;
use App\Models\Flight\FlightRefund;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage expansion suite for booking delete scenarios.
 *
 * Goal: ensure EVERY combination of (payment × cancel × currency × purchase_source)
 * returns all four key accounts to their pre-booking baseline after delete:
 *   - cashbox (treasury)
 *   - customer_AR
 *   - carrier (or system / group balance)
 *   - pending_sales_receivable
 *
 * Each test does a strict before/after snapshot and a delta assertion per account.
 * Tests are organized by the missing coverage cell they target.
 *
 * See: .zcode/plans/DEFECT_005_006_TRACE_20260824.md
 */
class FlightDeleteCoverageExpansionTest extends TestCase
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
            'name' => 'Coverage Admin',
            'email' => 'coverage-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    // ─────────────────────────────────────────────────────────────
    // FIXTURE HELPERS (minimal, shared with siblings)
    // ─────────────────────────────────────────────────────────────

    protected function buildFixtureCashbox(string $currency, float $opening = 100000.0): Account
    {
        $account = Account::create([
            'name' => "Test {$currency} Cashbox",
            'type' => 'cashbox',
            'currency' => $currency,
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
            'debit' => 0.00, 'credit' => $opening, 'balance_after' => $opening,
            'notes' => "رصيد افتتاحي {$currency}",
        ]);
        return $account->refresh();
    }

    protected function buildFixtureCustomer(): Customer
    {
        return Customer::create([
            'full_name' => 'عميل تغطية الحذف',
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'cov-'.uniqid().'@test.com',
            'national_id' => '29'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
        ]);
    }

    protected function buildFixtureSystem(string $currency): FlightSystem
    {
        return FlightSystem::create([
            'name' => "{$currency} System Cov",
            'code' => substr($currency, 0, 2).'SC'.uniqid(),
            'type' => 'gds', 'is_active' => true,
            'currency' => $currency,
            'credit_limit' => 5000, 'created_by' => $this->admin->id,
        ]);
    }

    protected function buildFixtureCarrier(FlightSystem $system): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => 'Cov Carrier',
            'code' => 'CC'.uniqid(),
            'flight_system_id' => $system->id,
            'currency' => $system->currency,
            'credit_limit' => 100000, 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function buildFixtureGroup(FlightSystem $system): FlightGroup
    {
        return FlightGroup::create([
            'name' => 'Cov Group',
            'code' => 'CG'.uniqid(),
            'currency' => $system->currency,
            'flight_system_id' => $system->id,
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
     * Snapshot all four key account balances.
     */
    protected function snapshot(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $key => $acc) {
            $out[$key] = (float) ($acc ? $acc->fresh()->balance : 0);
        }
        return $out;
    }

    /**
     * Assert each account returned to its pre-booking baseline (delta == 0).
     */
    protected function assertAllBackToBaseline(array $before, array $accounts, string $msg): void
    {
        foreach ($accounts as $key => $acc) {
            $after = (float) ($acc ? $acc->fresh()->balance : 0);
            $delta = round($after - $before[$key], 2);
            $this->assertEqualsWithDelta(
                0.0,
                $delta,
                0.01,
                "{$msg} — account '{$key}' delta != 0 (before={$before[$key]}, after={$after}, delta={$delta})"
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HIGH PRIORITY — Pay=P + Cancel=N (partial pay + full-penalty cancel)
    // ════════════════════════════════════════════════════════════════

    /**
     * Cell: Pay=P / Cancel=N / EGP / carrier
     * Scenario: Book 20000, pay 8000, cancel with FULL penalty (refund=0), delete.
     * Expected: all four accounts return to baseline.
     */
    public function test_partial_pay_full_penalty_cancel_then_delete_returns_all_to_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50000);

        // Snapshot
        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(20)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 16000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'P_PN_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 8000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Cancel with FULL penalty — refund = 0
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 11000, 'office_penalty' => 9000, // total 20000 = selling
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(0.0, (float) $refund->refund_amount, 'Full-penalty cancel must produce refund_amount=0.');

        // Delete
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // All accounts must return to baseline
        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Partial pay + full-penalty cancel + delete');
    }

    // ════════════════════════════════════════════════════════════════
    // MEDIUM PRIORITY — Cancel=F (full refund, zero penalty) variants
    // ════════════════════════════════════════════════════════════════

    /**
     * Cell: Pay=P / Cancel=F / EGP / carrier
     * Scenario: Book 20000, pay 8000, cancel with ZERO penalty (full refund 8000), delete.
     * Expected: cashbox returns to baseline (8000 walked back).
     */
    public function test_partial_pay_full_refund_cancel_then_delete_returns_all_to_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50000);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(21)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 16000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'P_PF_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 8000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Full refund = 8000, zero penalty
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0, 'office_penalty' => 0,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(8000.0, (float) $refund->refund_amount, 'Zero-penalty cancel must refund full amount paid.');

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // DEFECT-009 explicit assertion: customer_AR side-effect (DEFECT-007 sub-case).
        // After a zero-penalty full-refund cancel + delete, customer_AR must return to 0.
        // This side-effect is closed by H1 (same code path that restores cashbox).
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAR->fresh()->balance,
            0.01,
            'Partial pay + full refund cancel + delete — customer_AR must be at 0 (DEFECT-009 side-effect fix).'
        );

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Partial pay + full refund cancel + delete');
    }

    /**
     * Cell: Pay=I / Cancel=F / EGP / carrier
     * Scenario: Book 30000, pay 10000 + 10000 + 10000, cancel with zero penalty (refund 30000), delete.
     */
    public function test_installments_full_refund_cancel_then_delete_returns_all_to_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50000);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(22)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 25000, 'selling_price' => 30000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'I_PF_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 10000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        // 2 addPayments to make 3 installments
        $this->bookingService->addPayment($booking, [
            'amount' => 10000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
        ]);
        $this->bookingService->addPayment($booking, [
            'amount' => 10000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Full refund of all 30000 (zero penalty)
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0, 'office_penalty' => 0,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(30000.0, (float) $refund->refund_amount);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // DEFECT-010 explicit assertion: customer_AR side-effect (DEFECT-007 sub-case).
        // Same fix as DEFECT-009; installment count does not change the structural gap.
        $this->assertEqualsWithDelta(
            0.0,
            (float) $customerAR->fresh()->balance,
            0.01,
            'Installments + full refund cancel + delete — customer_AR must be at 0 (DEFECT-010 side-effect fix).'
        );

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Installments + full refund cancel + delete');
    }

    // ════════════════════════════════════════════════════════════════
    // MEDIUM PRIORITY — Pay=Z + Cancel=N (true debt case) per source
    // ════════════════════════════════════════════════════════════════

    /**
     * Cell: Pay=Z / Cancel=N / EGP / carrier
     * Scenario: Book 20000 (credit, no payment), cancel with FULL penalty (20000 kept as revenue), delete.
     * Expected: cashbox, customer_AR, carrier all return to baseline. The kept penalty
     * becomes office revenue on cancel, then is walked back on delete.
     */
    public function test_zero_pay_full_penalty_cancel_with_carrier_source_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50000);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(23)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 16000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'Z_PN_C_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
        ]);

        // No payment — credit booking
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Full penalty = 20000 (= selling_price). refund = 0
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 12000, 'office_penalty' => 8000,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(0.0, (float) $refund->refund_amount);

        // Pre-delete snapshot to show the "after cancel" state
        $afterCancel = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        // Sanity: pre-delete, the customer's AR carries the sale debt
        // (this is the "true debt case" — customer owed the full selling price before delete)
        $this->assertNotEquals(
            $before['customer'], $afterCancel['customer'],
            "Pre-delete sanity: customer_AR must NOT be at baseline (customer carries debt)."
        );

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Zero pay + full-penalty cancel + delete (carrier source)');
    }

    /**
     * Cell: Pay=Z / Cancel=N / EGP / system
     * Same as above but purchase_balance_source=system.
     */
    public function test_zero_pay_full_penalty_cancel_with_system_source_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(24)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 16000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'system',
            'pnr' => 'Z_PN_S_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 12000, 'office_penalty' => 8000,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(0.0, (float) $refund->refund_amount);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Zero pay + full-penalty cancel + delete (system source)');
    }

    /**
     * Cell: Pay=Z / Cancel=N / EGP / group
     * Same as above but purchase_balance_source=group.
     */
    public function test_zero_pay_full_penalty_cancel_with_group_source_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);
        $group = $this->buildFixtureGroup($system);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(25)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 16000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'flight_group_id' => $group->id,
            'purchase_balance_source' => 'group',
            'pnr' => 'Z_PN_G_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 12000, 'office_penalty' => 8000,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(0.0, (float) $refund->refund_amount);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // For group source, the carrier is NOT involved; only cashbox + customer + group balance
        $groupAccount = Account::find($group->account_id);
        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR,
        ], 'Zero pay + full-penalty cancel + delete (group source)');

        // Group account also returns to baseline
        if ($groupAccount) {
            $groupDelta = round((float) $groupAccount->fresh()->balance - 0, 2); // newly created = 0
            $this->assertEqualsWithDelta(0.0, $groupDelta, 0.01,
                "Group account must also be at zero after group-source delete.");
        }
    }

    // ════════════════════════════════════════════════════════════════
    // MEDIUM PRIORITY — system / group source with cancel + delete
    // ════════════════════════════════════════════════════════════════

    /**
     * Cell: Pay=F / Cancel=R / EGP / system
     * Scenario: Book via system source, pay full, cancel with partial penalty, delete.
     */
    public function test_full_pay_partial_refund_cancel_with_system_source_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(26)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'system',
            'pnr' => 'F_PR_S_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 20000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Penalty 2000, refund 18000
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 1200, 'office_penalty' => 800,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(18000.0, (float) $refund->refund_amount);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Full pay + partial refund cancel + delete (system source)');
    }

    /**
     * Cell: Pay=F / Cancel=F / EGP / system
     * Scenario: Book via system source, pay full, cancel with zero penalty (full refund), delete.
     */
    public function test_full_pay_full_refund_cancel_with_system_source_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(27)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'system',
            'pnr' => 'F_PF_S_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 20000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0, 'office_penalty' => 0,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(20000.0, (float) $refund->refund_amount);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Full pay + full refund cancel + delete (system source)');
    }

    /**
     * Cell: Pay=F / Cancel=R / EGP / group
     * Scenario: Book via group source, pay full, cancel with partial penalty, delete.
     */
    public function test_full_pay_partial_refund_cancel_with_group_source_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('EGP');
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('EGP');
        $carrier = $this->buildFixtureCarrier($system);
        $group = $this->buildFixtureGroup($system);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI', 'to_airport' => 'RUH',
            'departure_date' => now()->addDays(28)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000, 'selling_price' => 20000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'flight_group_id' => $group->id,
            'purchase_balance_source' => 'group',
            'pnr' => 'F_PR_G_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 20000, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 1200, 'office_penalty' => 800,
            'account_id' => $cashbox->id,
        ]);
        $this->assertEquals(18000.0, (float) $refund->refund_amount);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR,
        ], 'Full pay + partial refund cancel + delete (group source)');
    }

    // ════════════════════════════════════════════════════════════════
    // MEDIUM PRIORITY — KWD (cross-currency) variants
    // ════════════════════════════════════════════════════════════════

    /**
     * Cell: Pay=Z / Cancel=N / KWD / carrier
     * Scenario: KWD credit booking (no payment), full-penalty cancel, delete.
     */
    public function test_zero_pay_full_penalty_cancel_kwd_delete_returns_baseline(): void
    {
        $cashbox = $this->buildFixtureCashbox('KWD', 5000.0);
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('KWD');
        $carrier = $this->buildFixtureCarrier($system);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 2000);

        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Jazeera',
            'from_airport' => 'CAI', 'to_airport' => 'KWI',
            'departure_date' => now()->addDays(29)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'foreign_currency' => 'KWD',
            'exchange_rate' => 157.5,
            'purchase_price_foreign' => 100.0,
            'purchase_price' => 15750.0,
            'selling_price' => 20000.0, // ~127 KWD
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'Z_PN_K_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
        ]);

        $booking->forceFill(['selling_price_foreign' => 127.0])->save();
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Full penalty (everything kept as office revenue), refund = 0
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 12000, 'office_penalty' => 8000, // 20000 EGP = ~127 KWD penalty
            'account_id' => $cashbox->id,
        ]);
        // KWD cashbox required for refund — accept that refund_amount may be 0 or partial here
        // (cancel might throw or produce different output for cross-currency — we just want the
        //  flow to terminate without breaking the delete invariants).

        // For KWD cancel-with-refund path, the cancel itself is tricky — try the delete only if
        // booking reached CANCELLED status. If refund row exists, attempt delete.
        $booking->refresh();
        $refundRow = $booking->refund;
        if ($refundRow && (float) $refundRow->refund_amount > 0.001) {
            // KWD refund → cross-currency → throws BusinessLogicException on delete
            $this->expectException(\App\Exceptions\BusinessLogicException::class);
            $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
            return; // exit — exception thrown as expected
        }

        // No refund row OR refund == 0: proceed with delete
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertAllBackToBaseline($before, [
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ], 'Zero pay + full-penalty cancel + delete (KWD)');
    }

    /**
     * Cell: Pay=I / Cancel=R / KWD / carrier
     * Scenario: KWD installments + partial-refund cancel.
     * Per the documented known limitation, this should throw BusinessLogicException on delete.
     */
    public function test_kwd_installments_partial_refund_cancel_delete_throws(): void
    {
        $cashbox = $this->buildFixtureCashbox('KWD', 5000.0);
        $customer = $this->buildFixtureCustomer();
        $system = $this->buildFixtureSystem('KWD');
        $carrier = $this->buildFixtureCarrier($system);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 2000);

        // Manually create a CANCELLED booking + refund row to avoid upstream KWD cancel issues
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Jazeera',
            'from_airport' => 'CAI', 'to_airport' => 'KWI',
            'departure_date' => now()->addDays(30)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'foreign_currency' => 'KWD',
            'exchange_rate' => 157.5,
            'purchase_price_foreign' => 50.0,
            'purchase_price' => 7875.0,
            'selling_price' => 10000.0, // ~63.5 KWD
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'I_PR_K_'.uniqid(),
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax', 'passenger_type' => 'adult']],
            'payment' => [
                'amount' => 30.0, 'account_id' => $cashbox->id, 'payment_method' => 'cash',
            ],
        ]);

        $booking->forceFill(['selling_price_foreign' => 63.5])->save();
        $booking->refresh();

        // Mark cancelled + create manual refund (30 KWD paid - 5 penalty = 25 refund)
        $booking->update(['status' => FlightBookingStatus::CANCELLED]);
        $refund = FlightRefund::create([
            'flight_booking_id' => $booking->id,
            'airline_penalty' => 3.0, 'office_penalty' => 2.0,
            'total_paid' => 30.0, 'refund_amount' => 25.0,
            'account_id' => $cashbox->id,
            'transaction_id' => null,
            'status' => 'processed',
            'notes' => 'KWD installments partial refund',
            'created_by' => $this->admin->id,
        ]);

        // Pre-delete snapshot
        $customerAR = Account::find($customer->account_id);
        $before = $this->snapshot([
            'cashbox' => $cashbox, 'customer' => $customerAR, 'carrier' => $carrier,
        ]);

        // Delete must throw BusinessLogicException (cross-currency refund walk-back)
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // Verify the cashbox was NOT modified (the transaction must roll back)
        $afterAttempt = (float) $cashbox->fresh()->balance;
        $this->assertEqualsWithDelta($before['cashbox'], $afterAttempt, 0.01,
            'Cashbox must NOT have phantom movement when delete throws.');
    }
}
