<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * RC-002 regression: Phantom COGS on Unpaid Credit Bookings (FIN-3 cash-basis).
 *
 * الـ invariant:
 *   1. A booking created on credit with NO payment must record zero COGS
 *      in the P&L (revenue is also zero — that's FIN-2).
 *   2. The actual COGS is recognised PROPORTIONALLY as customer payments
 *      arrive. Rule: recognised_cogs = purchase_price × (cumulative_paid /
 *      selling_price). This must work for a single full payment (B), a
 *      single partial payment (C), and multiple partial payments (D).
 *
 * Scenarios:
 *   A — booking, no payment                      → totalCogs = 0
 *   B — booking, full payment                    → totalCogs = purchase_price
 *   C — booking, single 50% partial payment      → totalCogs = purchase_price / 2
 *   D — booking, 30% then 20% partial payments   → totalCogs = purchase_price / 2
 *
 * These scenarios exercise the FIN-3 cash-basis invariant for COGS.
 * The companion FIN-2 revenue invariant is covered by
 * FlightCashBasisRegressionTest S01–S08.
 */
class FlightRCCogsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected int $pendingCogsId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'name' => 'RC-002 Admin',
            'email' => 'rc002-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'RC-002 Customer',
            'phone' => '0112233445',
            'email' => 'rc002-customer@test.com',
            'national_id' => '99887766554433',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'RC-002 System',
            'code' => 'RC2'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'RC-002 Airline',
            'code' => 'RC2',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'RC-002 Cashbox',
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
            50000.00,
            'RC-002 regression setup'
        );

        $this->pendingCogsId = (int) app(LedgerClearingAccounts::class)->pendingCogsIdForFlight();
    }

    protected function booking(array $overrides = []): FlightBooking
    {
        return $this->bookingService->createBooking(array_merge([
            'customer_id' => $this->customer->id,
            'airline_name' => 'RC-002 Airline',
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
                ['name' => 'RC-002 Pax', 'type' => 'adult'],
            ],
        ], $overrides));
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

    protected function pendingCogsBalance(): float
    {
        return (float) Account::query()->whereKey($this->pendingCogsId)->value('balance');
    }

    /**
     * Scenario A — booking on credit, NO payment.
     *
     * Total COGS must be ZERO. The creation-time posting routes the COGS
     * to `pending_cogs` (not `expense_clearing`), so the P&L stays clean
     * until cash arrives.
     */
    public function test_rc002_A_no_payment_zero_cogs(): void
    {
        $booking = $this->booking();

        $pnl = $this->snapshotPnl();

        $this->assertSame(0.0, $pnl['totalCogs'],
            'Scenario A: totalCogs must be 0 for an unpaid credit booking.');
        $this->assertSame(0.0, $pnl['totalRevenues'],
            'Scenario A: totalRevenues must also be 0 (FIN-2 cash-basis).');
        $this->assertSame(0.0, $pnl['netProfit'],
            'Scenario A: netProfit must be 0 (no revenue, no cogs).');

        // The full purchase price sits in pending_cogs, awaiting recognition.
        $this->assertSame(20000.0, $this->pendingCogsBalance(),
            'Scenario A: pending_cogs must hold the full purchase price as deferred COGS.');

        Log::info('RC-002 A PASSED: unpaid credit booking records zero P&L COGS', [
            'pnl' => $pnl,
            'pending_cogs_balance' => $this->pendingCogsBalance(),
        ]);
    }

    /**
     * Scenario B — booking + full payment.
     *
     * After a single full payment, the COGS recogniser moves the full
     * purchase price from pending_cogs to expense_clearing. P&L must show
     * totalCogs = purchase_price.
     */
    public function test_rc002_B_full_payment_full_cogs(): void
    {
        $booking = $this->booking();
        $this->bookingService->addPayment($booking, [
            'amount' => 22000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $pnl = $this->snapshotPnl();

        $this->assertSame(22000.0, $pnl['totalRevenues'],
            'Scenario B: full payment must recognise full selling as revenue.');
        $this->assertSame(20000.0, $pnl['totalCogs'],
            'Scenario B: full payment must recognise full purchase as COGS.');
        $this->assertSame(2000.0, $pnl['grossProfit'],
            'Scenario B: grossProfit must equal selling - purchase.');

        // pending_cogs must be empty after full recognition.
        $this->assertSame(0.0, $this->pendingCogsBalance(),
            'Scenario B: pending_cogs must drain to 0 after full recognition.');

        Log::info('RC-002 B PASSED: full payment recognises full COGS', [
            'pnl' => $pnl,
            'pending_cogs_balance' => $this->pendingCogsBalance(),
        ]);
    }

    /**
     * Scenario C — booking + single 50% partial payment.
     *
     * Proportional rule: recognised_cogs = purchase_price × (paid / selling).
     * After a 11000 EGP payment on a 22000 selling-price / 20000 purchase-price
     * booking, recognised COGS must be 10000 EGP (50%).
     */
    public function test_rc002_C_single_partial_payment_proportional_cogs(): void
    {
        $booking = $this->booking();
        $this->bookingService->addPayment($booking, [
            'amount' => 11000,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $pnl = $this->snapshotPnl();

        $this->assertSame(11000.0, $pnl['totalRevenues'],
            'Scenario C: 50% payment must recognise 50% revenue.');
        $this->assertSame(10000.0, $pnl['totalCogs'],
            'Scenario C: 50% payment must recognise 50% COGS (proportional rule).');
        $this->assertSame(1000.0, $pnl['grossProfit'],
            'Scenario C: grossProfit must equal 50% of selling - 50% of purchase.');

        // pending_cogs must still hold the unrecognised half.
        $this->assertSame(10000.0, $this->pendingCogsBalance(),
            'Scenario C: pending_cogs must hold the remaining 50% as deferred COGS.');

        Log::info('RC-002 C PASSED: 50% payment recognises 50% COGS', [
            'pnl' => $pnl,
            'pending_cogs_balance' => $this->pendingCogsBalance(),
        ]);
    }

    /**
     * Scenario D — booking + multiple partial payments (30% then 20%).
     *
     * Cumulative recognition must follow the proportional rule, regardless
     * of how many partial-payment calls are made. After 30% + 20% = 50%
     * paid, recognised COGS must equal 50% of the purchase price.
     *
     * Also asserts that an intermediate snapshot (after the first 30%
     * payment) recognises 30% of the COGS — the recogniser handles each
     * payment incrementally, not only on full payment.
     */
    public function test_rc002_D_multiple_partial_payments_proportional_cogs(): void
    {
        $booking = $this->booking();

        // First partial payment — 30% of selling = 6600 EGP.
        $this->bookingService->addPayment($booking, [
            'amount' => 6600,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $pnlAfterFirst = $this->snapshotPnl();
        $this->assertSame(6600.0, $pnlAfterFirst['totalRevenues'],
            'Scenario D (first 30%): revenue must equal 30% of selling.');
        $this->assertSame(6000.0, $pnlAfterFirst['totalCogs'],
            'Scenario D (first 30%): COGS must equal 30% of purchase (proportional).');
        $this->assertSame(14000.0, $this->pendingCogsBalance(),
            'Scenario D (first 30%): pending_cogs must hold 70% of purchase.');

        // Second partial payment — another 20% of selling = 4400 EGP, cumulative 50%.
        $this->bookingService->addPayment($booking, [
            'amount' => 4400,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $pnlAfterSecond = $this->snapshotPnl();
        $this->assertSame(11000.0, $pnlAfterSecond['totalRevenues'],
            'Scenario D (cumulative 50%): revenue must equal 50% of selling.');
        $this->assertSame(10000.0, $pnlAfterSecond['totalCogs'],
            'Scenario D (cumulative 50%): COGS must equal 50% of purchase (proportional).');
        $this->assertSame(10000.0, $this->pendingCogsBalance(),
            'Scenario D (cumulative 50%): pending_cogs must hold remaining 50%.');

        Log::info('RC-002 D PASSED: multiple partial payments follow proportional rule', [
            'after_first_30pct' => $pnlAfterFirst,
            'after_second_50pct_cumulative' => $pnlAfterSecond,
            'pending_cogs_balance' => $this->pendingCogsBalance(),
        ]);
    }
}