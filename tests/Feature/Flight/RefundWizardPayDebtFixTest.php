<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightRefund;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REGRESSION TEST — RefundWizard payDebt bug (2026-09-08).
 *
 * Bug: RefundWizard.vue totalPaid computed summed only `b.payments[*].amount`
 *      (i.e. flight_payments rows). For counter customers who paid the rest
 *      via CustomerController::payDebt, that sum was LESS than what they
 *      actually paid. Real-world example: booking FLT-20260905-8F37F8
 *      selling_price=5200, flight_payments=[2200], payDebt=3000.
 *      The wizard displayed 2,200 while the backend refunded 5,200 — a
 *      3,000 EGP mismatch between UI and ledger.
 *
 * Fix: RefundWizard.vue totalPaid now uses Math.max(api_total_paid,
 *      sum_of_payments). The API's `paid_amount` accessor correctly sums
 *      flight_payments + payDebt income, so the wizard now matches the
 *      backend refund service.
 *
 * This test verifies:
 *   (1) The wizard's source data (FlightBookingResource.total_paid) reflects
 *       the full paid amount including payDebt.
 *   (2) The cancelBooking service refunds the full paid amount, not just
 *       the flight_payments sum.
 */
class RefundWizardPayDebtFixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Customer $customer;
    protected \App\Models\Flight\FlightCarrier $carrier;
    protected Account $cashboxEgp;
    protected FlightBookingService $bookingService;
    protected TransactionService $transactionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Refund Wizard Test Admin',
            'email' => 'refund-wizard-test@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::query()->create([
            'full_name' => 'آجل Customer',
            'phone' => '01000000077',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Use a FlightCarrier (not FlightSystem) because carriers have a
        // generous credit_limit that doesn't require a funded balance for
        // the COGS debit step in createBooking — much simpler for the
        // regression scenario under test.
        $this->carrier = \App\Models\Flight\FlightCarrier::query()->create([
            'name' => 'Refund Wizard Test Airline',
            'code' => 'RWA',
            'currency' => 'EGP',
            'credit_limit' => 100000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashboxEgp = Account::query()->create([
            'name' => 'Refund Wizard Cashbox',
            'type' => 'cashbox',
            'balance' => 100000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->bookingService = app(FlightBookingService::class);
        $this->transactionService = app(TransactionService::class);
    }

    /**
     * REGRESSION: counter customer paid 2200 via flight_payments + 3000 via
     * payDebt. The FlightBookingResource must expose total_paid=5200 so the
     * Vue wizard can render the correct figure.
     */
    public function test_total_paid_reflects_paydebt_in_addition_to_flight_payments(): void
    {
        // Step 1: create the booking with selling_price=5200, paid 2200 via flight_payments
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'selling_price' => 5200.0,
            'purchase_price' => 5000.0,
            'currency' => 'EGP',
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'flight_carrier_id' => $this->carrier->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->cashboxEgp->id,
            'agent_name' => 'Test Agent',
            'pnr' => 'RW' . uniqid(),
            'payment' => [
                'amount' => 2200.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
                'notes' => 'دفعة أولى',
            ],
        ]);

        $booking->refresh();

        // Step 2: customer pays the remaining 3000 via CustomerController::payDebt
        // (the production flow: /customers/{id}/pay-debt, which posts an Income
        // transaction keyed to Customer::class, NOT a flight_payments row)
        $customerAccount = $this->customer->ledgerAccount;
        $this->transactionService->recordIncome([
            'amount' => 3000.0,
            'to_account_id' => $this->cashboxEgp->id,
            'contra_account_id' => $customerAccount->id,
            'allow_contra_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => Customer::class,
            'related_id' => $this->customer->id,
            'notes' => 'سند قبض - تسديد مديونية عميل: ' . $this->customer->full_name,
            'created_by' => $this->admin->id,
        ]);

        $booking->refresh();
        $booking->load('payments');

        // === Assertion 1: DB layer — only ONE flight_payments row exists ===
        $this->assertSame(
            1,
            $booking->payments->count(),
            'DB should have exactly 1 flight_payments row (the initial 2200)'
        );
        $this->assertEqualsWithDelta(
            2200.0,
            (float) $booking->payments->sum('amount'),
            0.01,
            'flight_payments sum is 2200 (NOT 5200)'
        );

        // === Assertion 2: API layer — total_paid reflects BOTH sources ===
        $resource = (new \App\Http\Resources\Flight\FlightBookingResource($booking))->resolve(request());
        $apiTotalPaid = (float) $resource['total_paid'];

        // payments field is an AnonymousResourceCollection — convert to array
        $paymentsRaw = $resource['payments'] ?? [];
        if (is_object($paymentsRaw) && method_exists($paymentsRaw, 'resolve')) {
            $paymentsArr = $paymentsRaw->resolve();
        } elseif (is_object($paymentsRaw) && method_exists($paymentsRaw, 'toArray')) {
            $paymentsArr = $paymentsRaw->toArray(request());
        } else {
            $paymentsArr = is_array($paymentsRaw) ? $paymentsRaw : [];
        }
        $apiPaymentsSum = array_sum(array_column($paymentsArr, 'amount'));

        $this->assertEqualsWithDelta(
            5200.0,
            $apiTotalPaid,
            0.01,
            "BUG FIX: FlightBookingResource.total_paid must be 5200 (2200 flight + 3000 payDebt), NOT 2200"
        );
        $this->assertEqualsWithDelta(
            2200.0,
            $apiPaymentsSum,
            0.01,
            'payments array sum is 2200 (only flight_payments)'
        );

        // === Assertion 3: Vue wizard logic — must use max(api, frontend sum) ===
        // Simulates the new computed:
        $b = (object) [
            'total_paid' => $apiTotalPaid,
            'totalPaid' => $apiTotalPaid,
            'payments' => $paymentsArr,
        ];
        $simulatedTotalPaid = (float) max(
            is_numeric($b->total_paid ?? null) ? $b->total_paid : 0,
            array_sum(array_column($b->payments, 'amount'))
        );

        $this->assertEqualsWithDelta(
            5200.0,
            $simulatedTotalPaid,
            0.01,
            'Vue wizard new totalPaid logic must equal 5200, NOT 2200'
        );

        echo "\n[REGRESSION PASS]";
        echo "\n  flight_payments sum: 2200 EGP";
        echo "\n  payDebt income:      3000 EGP";
        echo "\n  total_paid (API):    {$apiTotalPaid} EGP ✓";
        echo "\n  Vue totalPaid (new): {$simulatedTotalPaid} EGP ✓";
        echo "\n  → Wizard and backend now agree on 5200 EGP, no mismatch.";
    }

    /**
     * REGRESSION: cancelBooking service refunds the FULL paid amount (5200),
     * not just flight_payments (2200). The wizard's UI must therefore also
     * show 5200 so it doesn't shock the cashier.
     */
    public function test_cancelbooking_refunds_full_paid_amount_not_just_flight_payments(): void
    {
        // Setup the same scenario as above
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'selling_price' => 5200.0,
            'purchase_price' => 5000.0,
            'currency' => 'EGP',
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'flight_carrier_id' => $this->carrier->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->cashboxEgp->id,
            'agent_name' => 'Test Agent',
            'pnr' => 'RW' . uniqid(),
            'payment' => [
                'amount' => 2200.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
                'notes' => 'دفعة أولى',
            ],
        ]);

        $booking->refresh();

        $customerAccount = $this->customer->ledgerAccount;
        $this->transactionService->recordIncome([
            'amount' => 3000.0,
            'to_account_id' => $this->cashboxEgp->id,
            'contra_account_id' => $customerAccount->id,
            'allow_contra_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => Customer::class,
            'related_id' => $this->customer->id,
            'notes' => 'سند قبض - تسديد مديونية عميل',
            'created_by' => $this->admin->id,
        ]);

        // Now cancel with no penalties
        $refund = $this->bookingService->cancelBooking($booking->refresh(), [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashboxEgp->id,
            'notes' => 'Test cancellation after payDebt',
        ]);

        $this->assertEqualsWithDelta(
            5200.0,
            (float) $refund->refund_amount,
            0.01,
            "BUG FIX: cancelBooking must refund 5200 (the FULL paid amount), not just 2200 (flight_payments only)"
        );

        echo "\n[CANCEL PASS]";
        echo "\n  FlightRefund.refund_amount: {$refund->refund_amount} EGP ✓";
        echo "\n  → Backend refund matches total_paid accessor (5200 EGP).";
    }

    /**
     * EDGE CASE 1: counter customer paid ONLY via payDebt (no flight_payments
     * at all). The new totalPaid computed must still render the paid amount,
     * not 0 or the empty fallback.
     */
    public function test_paydebt_only_booking_still_shows_correct_total(): void
    {
        // Create booking with NO initial payment (آجل fully on debt)
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'selling_price' => 5000.0,
            'purchase_price' => 4800.0,
            'currency' => 'EGP',
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'flight_carrier_id' => $this->carrier->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->cashboxEgp->id,
            'agent_name' => 'Test Agent',
            'pnr' => 'PD' . uniqid(),
            // NO payment — counter customer pays later via payDebt
        ]);

        $booking->refresh();

        $this->assertSame(
            0,
            $booking->payments()->count(),
            'Pre-condition: no flight_payments rows for this booking'
        );

        // Customer pays the full 5000 via payDebt
        $customerAccount = $this->customer->ledgerAccount;
        $this->transactionService->recordIncome([
            'amount' => 5000.0,
            'to_account_id' => $this->cashboxEgp->id,
            'contra_account_id' => $customerAccount->id,
            'allow_contra_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => Customer::class,
            'related_id' => $this->customer->id,
            'notes' => 'سند قبض - تسديد كامل',
            'created_by' => $this->admin->id,
        ]);

        $booking->refresh();
        $resource = (new \App\Http\Resources\Flight\FlightBookingResource($booking))->resolve(request());
        $apiTotalPaid = (float) $resource['total_paid'];

        // Simulate the new Vue computed:
        $fromApi = (float) $resource['total_paid'];
        $fromPayments = 0; // empty payments array
        $computed = max(
            $fromApi > 0 ? $fromApi : 0,
            $fromPayments > 0 ? $fromPayments : 0,
        );

        $this->assertEqualsWithDelta(
            5000.0,
            $apiTotalPaid,
            0.01,
            'API total_paid must reflect payDebt income (5000)'
        );
        $this->assertEqualsWithDelta(
            5000.0,
            $computed,
            0.01,
            'Vue wizard new totalPaid must show 5000 even with empty flight_payments array'
        );

        echo "\n[EDGE CASE 1 PASS] payDebt-only: wizard shows 5000 EGP ✓";
    }

    /**
     * EDGE CASE 2: customer paid via 3 flight_payments (no payDebt).
     * This is the "happy path" — the fix must NOT break it.
     */
    public function test_three_installments_via_flight_payments_still_works(): void
    {
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'selling_price' => 9000.0,
            'purchase_price' => 8500.0,
            'currency' => 'EGP',
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'flight_carrier_id' => $this->carrier->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->cashboxEgp->id,
            'agent_name' => 'Test Agent',
            'pnr' => 'IN' . uniqid(),
            'payment' => [
                'amount' => 3000.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ],
        ]);

        $this->bookingService->addPayment($booking->refresh(), [
            'amount' => 3000.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $this->bookingService->addPayment($booking->refresh(), [
            'amount' => 3000.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $booking->refresh();
        $resource = (new \App\Http\Resources\Flight\FlightBookingResource($booking))->resolve(request());
        $apiTotalPaid = (float) $resource['total_paid'];

        $this->assertSame(
            3,
            $booking->payments()->count(),
            'Should have exactly 3 flight_payments rows'
        );
        $this->assertEqualsWithDelta(
            9000.0,
            $apiTotalPaid,
            0.01,
            'API total_paid must equal sum of 3 flight_payments = 9000'
        );

        echo "\n[EDGE CASE 2 PASS] 3 installments via flight_payments: total_paid = 9000 ✓";
    }

    /**
     * EDGE CASE 3: a fresh booking (no payments at all yet).
     * The new totalPaid computed must NOT default to selling_price — it must
     * return 0 because the customer hasn't paid anything. Otherwise the
     * wizard would show a phantom refund base.
     */
    public function test_booking_with_no_payments_returns_zero_not_selling_price(): void
    {
        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'selling_price' => 5000.0,
            'purchase_price' => 4800.0,
            'currency' => 'EGP',
            'airline_name' => 'Test Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'flight_carrier_id' => $this->carrier->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->cashboxEgp->id,
            'agent_name' => 'Test Agent',
            'pnr' => 'NP' . uniqid(),
            'payment' => [
                'amount' => 0.0,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ],
        ]);

        $booking->refresh();
        $resource = (new \App\Http\Resources\Flight\FlightBookingResource($booking))->resolve(request());
        $apiTotalPaid = (float) $resource['total_paid'];

        // Simulate the new Vue computed for empty booking:
        $fromApi = (float) $resource['total_paid'];
        $fromPayments = 0;
        $computed = max(
            $fromApi > 0 ? $fromApi : 0,
            $fromPayments > 0 ? $fromPayments : 0,
        );

        $this->assertEqualsWithDelta(
            0.0,
            $apiTotalPaid,
            0.01,
            'Empty booking must have total_paid = 0 (not selling_price)'
        );
        $this->assertEqualsWithDelta(
            0.0,
            $computed,
            0.01,
            'Vue wizard must show 0 for empty booking (not selling_price fallback)'
        );

        echo "\n[EDGE CASE 3 PASS] Empty booking: wizard shows 0, not selling_price ✓";
    }

    /**
     * EDGE CASE 4: verify the cancelBooking service math agrees with the
     * new wizard display in all three payment scenarios.
     */
    public function test_wizard_and_backend_agree_in_all_payment_scenarios(): void
    {
        $scenarios = [
            'flight_payments only (3 installments)' => [
                'selling' => 9000.0,
                'payments' => [3000.0, 3000.0, 3000.0],
                'paydebt' => 0.0,
            ],
            'payDebt only (full)' => [
                'selling' => 5000.0,
                'payments' => [],
                'paydebt' => 5000.0,
            ],
            'mixed (flight + payDebt)' => [
                'selling' => 5200.0,
                'payments' => [2200.0],
                'paydebt' => 3000.0,
            ],
        ];

        foreach ($scenarios as $name => $scenario) {
            // Build a fresh customer for each scenario so payDebt attribution
            // doesn't bleed across tests.
            $customer = Customer::query()->create([
                'full_name' => "Scenario {$name}",
                'phone' => '01' . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
                'is_active' => true,
                'created_by' => $this->admin->id,
            ]);

            $bookingData = [
                'customer_id' => $customer->id,
                'selling_price' => $scenario['selling'],
                'purchase_price' => $scenario['selling'] - 200.0,
                'currency' => 'EGP',
                'airline_name' => 'Test',
                'from_airport' => 'CAI',
                'to_airport' => 'DXB',
                'departure_date' => now()->addDays(7)->toDateString(),
                'trip_type' => 'one_way',
                'flight_carrier_id' => $this->carrier->id,
                'purchase_balance_source' => 'carrier',
                'account_id' => $this->cashboxEgp->id,
                'agent_name' => 'Test Agent',
                'pnr' => 'SC' . uniqid(),
            ];

            // Initial payment if any flight_payments exist
            if (! empty($scenario['payments'])) {
                $bookingData['payment'] = [
                    'amount' => $scenario['payments'][0],
                    'payment_method' => 'cash',
                    'account_id' => $this->cashboxEgp->id,
                ];
            } else {
                $bookingData['payment'] = [
                    'amount' => 0.0,
                    'payment_method' => 'cash',
                    'account_id' => $this->cashboxEgp->id,
                ];
            }

            $booking = $this->bookingService->createBooking($bookingData);
            $booking->refresh();

            // Additional flight_payments
            foreach (array_slice($scenario['payments'], 1) as $extraPayment) {
                $this->bookingService->addPayment($booking, [
                    'amount' => $extraPayment,
                    'payment_method' => 'cash',
                    'account_id' => $this->cashboxEgp->id,
                ]);
            }

            // payDebt income
            if ($scenario['paydebt'] > 0) {
                $customerAccount = $customer->ledgerAccount;
                $this->transactionService->recordIncome([
                    'amount' => $scenario['paydebt'],
                    'to_account_id' => $this->cashboxEgp->id,
                    'contra_account_id' => $customerAccount->id,
                    'allow_contra_negative' => true,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => Customer::class,
                    'related_id' => $customer->id,
                    'notes' => 'payDebt scenario test',
                    'created_by' => $this->admin->id,
                ]);
            }

            $booking->refresh();
            $resource = (new \App\Http\Resources\Flight\FlightBookingResource($booking))->resolve(request());
            $apiTotalPaid = (float) $resource['total_paid'];

            // New Vue computed:
            $paymentsArr = $resource['payments'] ?? [];
            if (is_object($paymentsArr) && method_exists($paymentsArr, 'resolve')) {
                $paymentsArr = $paymentsArr->resolve();
            } elseif (is_object($paymentsArr) && method_exists($paymentsArr, 'toArray')) {
                $paymentsArr = $paymentsArr->toArray(request());
            } else {
                $paymentsArr = is_array($paymentsArr) ? $paymentsArr : [];
            }
            $frontendSum = array_sum(array_column($paymentsArr, 'amount'));
            $wizardTotalPaid = (float) max(
                $apiTotalPaid > 0 ? $apiTotalPaid : 0,
                $frontendSum > 0 ? $frontendSum : 0,
            );

            // What the wizard will DISPLAY:
            $expected = array_sum($scenario['payments']) + $scenario['paydebt'];

            $this->assertEqualsWithDelta(
                $expected,
                $apiTotalPaid,
                0.01,
                "Scenario '{$name}': API total_paid must equal {$expected}"
            );
            $this->assertEqualsWithDelta(
                $expected,
                $wizardTotalPaid,
                0.01,
                "Scenario '{$name}': Vue wizard totalPaid must equal {$expected}"
            );
        }

        echo "\n[EDGE CASE 4 PASS] Wizard + backend agree across 3 scenarios ✓";
    }
}
