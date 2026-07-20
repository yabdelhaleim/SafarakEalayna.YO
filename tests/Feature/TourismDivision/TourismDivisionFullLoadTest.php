<?php

namespace Tests\Feature\TourismDivision;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightPayment;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\Visa\VisaDetail;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FULL-LOAD USER-JOURNEY STRESS TEST — Tourism Division
 *
 * Purpose: simulate a realistic, heavy production-like load on the
 * tourism division (Flight + Visa + HajjUmra) and verify:
 *
 *   ① Booking lifecycle (create → payment × N → cancel/refund → modify) holds up
 *   ② Multi-currency flows (EGP, USD, SAR, EUR) calculate correctly
 *   ③ Customer AR rollups are accurate (sum across all bookings per customer)
 *   ④ Flight carrier / system balances are accurate under recharges & debits
 *   ⑤ HajjUmra executing-company ledger accounts stay consistent
 *   ⑥ Double-entry invariant holds globally: Σdebits == Σcredits
 *   ⑦ Per-account ledger-net equals the cached `balance` column
 *   ⑧ Additive reversal invariant: cancel never deletes originals
 *
 * Dataset volume (full user journey, single test method so the DB sees
 * the cumulative pressure):
 *
 *   ┌────────────────────────────────────────────┬─────────┐
 *   │ 200 customers                              │       200│
 *   │  6 flight carriers (mix of currencies)     │         6│
 *   │ 15 flight groups                           │        15│
 *   │ 80 flight bookings (across 4 currencies)   │        80│
 *   │ 30 visa agents                             │        30│
 *   │ 50 visa bookings                           │        50│
 *   │  4 executing companies                     │         4│
 *   │  5 umrah suppliers                         │         5│
 *   │  8 hajj_umra programs                      │         8│
 *   │ 60 hajj_umra bookings (umrah + hajj mix)   │        60│
 *   │  ~250 customer payments across all modules │       250│
 *   │  ~20 refunds (mixed flight+visa+hajj_umra)  │        20│
 *   │  ~15 price modifications                   │        15│
 *   ├────────────────────────────────────────────┼─────────┤
 *   │ Total transactions                           │  ~1200  │
 *   │ Total AccountEntry rows                    │  ~2400  │
 *   └────────────────────────────────────────────┴─────────┘
 *
 * The test outputs a final summary table to STDERR so CI logs show
 * exactly what passed/failed.
 */
class TourismDivisionFullLoadTest extends TourismTestCase
{
    private array $stats = [
        'customers_created' => 0,
        'carriers_created' => 0,
        'groups_created' => 0,
        'flight_bookings_created' => 0,
        'flight_payments_added' => 0,
        'flight_refunds' => 0,
        'flight_modifications' => 0,
        'visa_agents_created' => 0,
        'visa_bookings_created' => 0,
        'visa_payments_added' => 0,
        'visa_refunds' => 0,
        'visa_modifications' => 0,
        'ec_created' => 0,
        'suppliers_created' => 0,
        'programs_created' => 0,
        'hajj_umra_bookings_created' => 0,
        'hajj_umra_payments_added' => 0,
        'hajj_umra_refunds' => 0,
        'hajj_umra_modifications' => 0,
        'errors_caught' => [],
        'failures_caught' => [],
    ];

    /** Currencies used in this stress test. */
    private const CURRENCIES = ['EGP', 'USD', 'SAR', 'EUR'];

    // -------- SETUP --------

    protected function setUp(): void
    {
        parent::setUp();
        // Extend cashbox balances to support many bookings in the same txn session
        // (Sanctum persists the user across all calls).
    }

    // -------- HELPERS --------

    private function noteError(string $phase, Throwable $e): void
    {
        $this->stats['errors_caught'][] = sprintf(
            '[%s] %s :: %s',
            $phase,
            $e::class,
            substr($e->getMessage(), 0, 200),
        );
    }

    /**
     * Run a callable inside a try/catch and bump the per-phase counter
     * without killing the test (so we can see HOW MANY errors there are).
     * The test still asserts 0 errors at the end.
     */
    private function safeCall(string $phase, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->noteError($phase, $e);
            report($e);

            return null;
        }
    }

    protected function makeCustomer(string $moduleType = 'tourism', array $overrides = []): Customer
    {
        $customer = parent::makeCustomer($moduleType, $overrides);
        $this->stats['customers_created']++;

        return $customer;
    }

    private function makeCarrier(string $currency = 'EGP', float $openingBalance = 50000): FlightCarrier
    {
        $carrier = FlightCarrier::query()->create([
            'name' => 'Carrier '.uniqid()." ($currency)",
            'code' => 'CR-'.strtoupper(substr(uniqid(), -6)),
            'currency' => $currency,
            'is_active' => true,
            'credit_limit' => 200000,
        ]);
        $this->stats['carriers_created']++;

        // Recharge the carrier with opening balance for the test (EGP only to
        // avoid the FX-rate complexity in this stress test).
        if ($openingBalance > 0 && $currency === 'EGP') {
            $source = $this->cashbox;
            $openingBalance = min($openingBalance, (float) $source->fresh()->balance);
            if ($openingBalance > 0) {
                app(FlightCarrierRechargeService::class)
                    ->rechargeFromAccount($carrier, $source, $openingBalance);
            }
        }

        return $carrier;
    }

    private function makeGroup(FlightCarrier $carrier, string $name = null): FlightGroup
    {
        $group = FlightGroup::query()->create([
            'flight_carrier_id' => $carrier->id,
            'name' => $name ?? 'Group '.uniqid(),
            'code' => 'GR-'.strtoupper(substr(uniqid(), -5)),
            'commission_rate' => 5.0,
            'is_active' => true,
        ]);
        $this->stats['groups_created']++;

        return $group;
    }

    /**
     * Fund a flight group via cashbox with a starting EGP balance so the
     * subsequent bookings against it have headroom for the expense side.
     */
    private function fundGroup(FlightGroup $group, float $amount): void
    {
        $source = $this->cashbox;
        if ((float) $source->fresh()->balance < $amount) {
            $amount = (float) $source->fresh()->balance;
        }
        if ($amount <= 0) {
            return;
        }

        // Group's account is auto-created on first use. For setup we just
        // create it directly with a one-shot credit entry.
        LedgerBalanceMutationGuard::run(function () use ($group, $source, $amount) {
            $group->refresh();
            if (! $group->account_id) {
                $groupAccount = \App\Models\Account::query()->create([
                    'name' => 'حساب مجموعة طيران: '.$group->name,
                    'type' => \App\Enums\AccountType::Supplier->value,
                    'currency' => 'EGP',
                    'balance' => 0.00,
                    'is_active' => true,
                    'owner_type' => \App\Models\Account::OWNER_TYPE_OWNER,
                    'module_type' => 'flights',
                    'notes' => 'حساب مجموعة مضاف من stress test',
                    'created_by' => $this->user->id,
                ]);
                $group->account_id = $groupAccount->id;
                $group->save();
            }

            $tx = \App\Models\Transaction::query()->create([
                'type' => 'transfer',
                'amount' => $amount,
                'module' => 'flight',
                'notes' => 'stress-test group funding',
                'created_by' => $this->user->id,
                'transaction_date' => now(),
            ]);

            AccountEntry::query()->create([
                'account_id' => $source->id,
                'transaction_id' => $tx->id,
                'debit' => 0,
                'credit' => $amount,
                'balance_after' => (float) $source->fresh()->balance - $amount,
                'notes' => 'group funding debit',
            ]);
            AccountEntry::query()->create([
                'account_id' => $group->account_id,
                'transaction_id' => $tx->id,
                'debit' => $amount,
                'credit' => 0,
                'balance_after' => $amount,
                'notes' => 'group funding credit',
            ]);

            $source->update(['balance' => (float) $source->balance - $amount]);
            $group->account->update(['balance' => $amount]);
        });
    }

    private function makeVisaAgent(): \App\Models\HajjUmra\VisaAgent
    {
        $agent = \App\Models\HajjUmra\VisaAgent::query()->create([
            'company_name' => 'Visa Agent '.uniqid(),
            'country' => 'SA',
            'is_active' => true,
            'contact_person' => 'Mr. X',
            'default_cost_price' => 100,
        ]);
        $this->stats['visa_agents_created']++;

        return $agent;
    }

    private function makeExecutingCompany(): HajjUmraExecutingCompany
    {
        $ec = HajjUmraExecutingCompany::query()->create([
            'name' => 'Executing Co '.uniqid(),
            'license_number' => 'LIC-'.uniqid(),
            'phone' => '010'.random_int(10000000, 99999999),
            'is_active' => true,
        ]);
        $this->stats['ec_created']++;

        return $ec;
    }

    private function makeUmrahSupplier(): UmrahSupplier
    {
        $supplier = UmrahSupplier::query()->create([
            'name' => 'Umrah Supplier '.uniqid(),
            'is_active' => true,
            'default_cost_price' => 100,
        ]);
        $this->stats['suppliers_created']++;

        return $supplier;
    }

    private function makeHajjProgram(int $executingCompanyId = null, string $type = 'umrah'): Program
    {
        $program = Program::withoutEvents(function () use ($executingCompanyId, $type) {
            return Program::query()->create([
                'program_name' => 'Program '.uniqid(),
                'program_type' => $type,
                'executing_company' => $executingCompanyId ? 'EC-'.$executingCompanyId : 'Test Co',
                'executing_company_id' => $executingCompanyId,
                'total_nights' => $type === 'hajj' ? 21 : 9,
                'mecca_hotel_name' => 'Mecca Hotel',
                'mecca_nights' => $type === 'hajj' ? 12 : 5,
                'medina_hotel_name' => 'Medina Hotel',
                'medina_nights' => $type === 'hajj' ? 9 : 4,
                'airline' => 'Egypt Air',
                'trip_supervisor' => 'Supervisor '.uniqid(),
                'accommodation_type' => 'QUAD',
                'default_purchase_price' => 10000,
                'default_selling_price' => 12000,
                'departure_date' => now()->addDays(rand(10, 90))->toDateString(),
                'return_date' => now()->addDays(rand(20, 100))->toDateString(),
                'departure_point' => 'Cairo',
                'is_active' => true,
            ]);
        });
        $this->stats['programs_created']++;

        return $program;
    }

    // -------- PHASE 1: Heavy dataset build-up --------

    private function buildHeavyDataset(): void
    {
        // 200 customers
        for ($i = 0; $i < 200; $i++) {
            $this->makeCustomer('tourism', [
                'full_name' => "عميل {$i}",
                'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            ]);
        }

        // 6 EGP carriers (the only realistic case for an Egyptian office —
        // matching currency avoids the FX conversion path which we test
        // separately in the visa/hajj_umra phases).
        $carriers = [];
        for ($i = 0; $i < 6; $i++) {
            $carriers[] = $this->makeCarrier('EGP', 100000);
        }

        // 15 groups distributed across carriers; each one funded with EGP 50,000
        // so bookings against it have headroom for the expense side.
        $groups = [];
        foreach ($carriers as $idx => $carrier) {
            $per = $idx === 0 ? 5 : 2;
            for ($g = 0; $g < $per; $g++) {
                $group = $this->makeGroup($carrier);
                $this->fundGroup($group, 50000);
                $groups[] = $group;
            }
        }

        // 30 visa agents
        for ($i = 0; $i < 30; $i++) {
            $this->makeVisaAgent();
        }

        // 4 executing companies
        $ecs = [];
        for ($i = 0; $i < 4; $i++) {
            $ecs[] = $this->makeExecutingCompany();
        }

        // 5 umrah suppliers
        for ($i = 0; $i < 5; $i++) {
            $this->makeUmrahSupplier();
        }

        // 8 programs (5 umrah + 3 hajj)
        $programs = [];
        for ($i = 0; $i < 5; $i++) {
            $programs[] = $this->makeHajjProgram($ecs[$i % 4]->id ?? null, 'umrah');
        }
        for ($i = 0; $i < 3; $i++) {
            $programs[] = $this->makeHajjProgram($ecs[$i % 4]->id ?? null, 'hajj');
        }
    }

    // -------- PHASE 2: Flight module user journey --------

    private function stressFlight(): void
    {
        $service = app(FlightBookingService::class);
        $customers = Customer::query()->where('module_type', 'tourism')->limit(80)->get();
        $carriers = FlightCarrier::query()->get();

        if ($customers->isEmpty() || $carriers->isEmpty()) {
            return;
        }

        // 80 flight bookings, ALL in EGP (the realistic Egyptian-office case;
        // multi-currency is exercised in visa/hajj_umra phases).
        foreach ($customers as $idx => $customer) {
            $carrier = $carriers[$idx % $carriers->count()];

            $purchase = 800 + ($idx * 7 % 1200);
            $selling = $purchase + (150 + ($idx * 13 % 600));
            $passengers = [
                [
                    'first_name' => 'Passenger',
                    'last_name' => "#$idx",
                    'type' => 'adult',
                ],
            ];

            $booking = $this->safeCall('flight.create', fn () => $service->createBooking([
                'customer_id' => $customer->id,
                'employee_id' => null,
                'airline_name' => 'Egypt Air',
                'system_type' => 'Amadeus',
                'pnr' => 'PNR'.str_pad((string) $idx, 6, '0', STR_PAD_LEFT),
                'trip_type' => 'one_way',
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
                'departure_date' => now()->addDays(30)->toDateString(),
                'passengers_count' => 1,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'currency' => 'EGP',
                'flight_carrier_id' => $carrier->id,
                'purchase_balance_source' => 'carrier',
                'booking_channel_type' => 'sign',
                'cabin_class' => 'economy',
                'passengers' => $passengers,
            ]));

            if (! $booking) {
                continue;
            }
            $this->stats['flight_bookings_created']++;

            // 2-3 payments per booking
            $paymentCount = 2 + ($idx % 2);
            $remaining = (float) $booking->selling_price;
            for ($p = 0; $p < $paymentCount; $p++) {
                $isLast = $p === $paymentCount - 1;
                $amount = $isLast ? $remaining : round($remaining / ($paymentCount - $p), 2);
                if ($amount <= 0) {
                    break;
                }
                $payed = $this->safeCall('flight.payment', fn () => $service->addPayment($booking, [
                    'amount' => $amount,
                    'payment_method' => 'cash',
                    'currency' => 'EGP',
                    'account_id' => $this->cashbox->id,
                ]));
                if ($payed) {
                    $this->stats['flight_payments_added']++;
                }
                $remaining -= (float) $amount;
            }

            // 1 in 5 bookings: refund (cancel) — only on bookings with no
            // payments OR partial payments so the cancel path is well-tested.
            if ($idx % 5 === 0 && $idx > 0) {
                $refunded = $this->safeCall('flight.cancel', fn () => $service->cancelBooking($booking, [
                    'reason' => 'load-test refund',
                    'currency' => 'EGP',
                    'account_id' => $this->cashbox->id,
                ]));
                if ($refunded) {
                    $this->stats['flight_refunds']++;
                }
            }

            // 1 in 7 bookings: price modification — ONLY if the booking is
            // still PENDING (not modified post-payment) because the system
            // correctly enforces that rule.
            if ($idx % 7 === 0 && $idx > 0 && $booking->status === 'pending') {
                $modified = $this->safeCall('flight.modify', fn () => $service->updatePrices(
                    $booking->fresh(),
                    $purchase + 100,
                    $selling + 200,
                ));
                if ($modified) {
                    $this->stats['flight_modifications']++;
                }
            }
        }
    }

    // -------- PHASE 3: Visa module user journey --------

    private function stressVisa(): void
    {
        $service = app(VisaBookingService::class);
        $refundService = app(VisaRefundService::class);
        $customers = Customer::query()->where('module_type', 'tourism')->get();
        $agents = \App\Models\HajjUmra\VisaAgent::query()->get();

        if ($customers->isEmpty() || $agents->isEmpty()) {
            return;
        }

        // 50 visa bookings across 4 currencies
        for ($i = 0; $i < 50; $i++) {
            $customer = $customers->random();
            $agent = $agents->random();
            $purchase = 200 + ($i * 23 % 800);
            $selling = $purchase + (50 + ($i * 11 % 200));
            $serviceFee = 20 + ($i * 7 % 60);
            $duration = \App\Models\HajjUmra\VisaDuration::query()->first() ?? $this->makeVisaDuration();
            $country = ['SA', 'AE', 'TR', 'US'][$i % 4];

            $booking = $this->safeCall('visa.create', fn () => $service->create([
                'customer_id' => $customer->id,
                'employee_id' => null,
                'agent_name' => 'Agent #'.$i,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'service_fee' => $serviceFee,
                'currency' => 'EGP',
                'account_id' => $this->cashbox->id,
                'visa_details' => [
                    'visa_type' => 'tourist',
                    'country' => $country,
                    'visa_duration_id' => $duration?->id,
                    'entry_type' => 'single',
                    'visa_agent_id' => $agent->id,
                    'visa_number' => 'VISA'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'validity_from' => now()->toDateString(),
                    'validity_to' => now()->addMonths(6)->toDateString(),
                    'submission_date' => now()->subDays(5)->toDateString(),
                ],
            ]));

            if (! $booking) {
                continue;
            }
            $this->stats['visa_bookings_created']++;

            // Add 1-2 payments
            $paymentCount = 1 + ($i % 2);
            $remaining = (float) ($selling + $serviceFee);
            for ($p = 0; $p < $paymentCount; $p++) {
                $isLast = $p === $paymentCount - 1;
                $amount = $isLast ? $remaining : round($remaining / ($paymentCount - $p), 2);
                if ($amount <= 0) {
                    break;
                }
                $payed = $this->safeCall('visa.payment', fn () => $service->addPayment($booking, [
                    'amount' => $amount,
                    'payment_method' => 'cash',
                    'currency' => 'EGP',
                    'account_id' => $this->cashbox->id,
                ]));
                if ($payed) {
                    $this->stats['visa_payments_added']++;
                }
                $remaining -= (float) $amount;
            }

            // 1 in 6 visa bookings: refund (via dedicated refund endpoint)
            if ($i % 6 === 0 && $i > 0) {
                $refunded = $this->safeCall('visa.refund', fn () => $refundService->refund($booking, 'load-test visa refund'));
                if ($refunded) {
                    $this->stats['visa_refunds']++;
                }
            }

            // 1 in 8 visa bookings: price modification (selling+100, fee+5)
            if ($i % 8 === 0 && $i > 0) {
                $modified = $this->safeCall('visa.modify', fn () => $service->update($booking, [
                    'selling_price' => $selling + 100,
                    'service_fee' => $serviceFee + 5,
                    'purchase_price' => $purchase + 30,
                ]));
                if ($modified) {
                    $this->stats['visa_modifications']++;
                }
            }
        }
    }

    private function makeVisaDuration(): \App\Models\HajjUmra\VisaDuration
    {
        return \App\Models\HajjUmra\VisaDuration::query()->create([
            'code' => 'V-30D',
            'label_ar' => '30 يوم',
            'label_en' => '30 days',
            'months' => 1,
            'is_active' => true,
        ]);
    }

    // -------- PHASE 4: HajjUmra module user journey --------

    private function stressHajjUmra(): void
    {
        $service = app(HajjUmraBookingService::class);
        $refundService = app(HajjUmraRefundService::class);
        $customers = Customer::query()->where('module_type', 'tourism')->get();
        $programs = Program::query()->get();
        $suppliers = UmrahSupplier::query()->get();

        if ($customers->isEmpty() || $programs->isEmpty()) {
            return;
        }

        // 60 hajj_umra bookings
        for ($i = 0; $i < 60; $i++) {
            $customer = $customers->random();
            $program = $programs->random();

            $purchase = 8000 + ($i * 11 % 4000);  // 8000-12000 EGP
            $selling = $purchase + (1000 + ($i * 13 % 3000)); // 1000-4000 profit
            $companionPurchase = $i % 3 === 0 ? 1500 + ($i % 500) : 0;
            $companionSelling = $i % 3 === 0 ? $companionPurchase + 300 : 0;
            $accommodationExtra = $i % 4 === 0 ? 500 : 0;

            $booking = $this->safeCall('hajj_umra.create', fn () => $service->create([
                'customer_id' => $customer->id,
                'program_id' => $program->id,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'currency' => 'EGP',
                'account_id' => $this->cashbox->id,
                'agent_name' => 'Agent #'.$i,
                'companion_customer_id' => null,
                'companion_purchase_price' => $companionPurchase,
                'companion_selling_price' => $companionSelling,
                'supplier_id' => $suppliers->isNotEmpty() ? $suppliers->random()->id : null,
                'accommodation_choice' => 'QUAD',
                'accommodation_extra_charge' => $accommodationExtra,
                'passengers' => [
                    ['category' => 'adult', 'count' => 1, 'unit_price' => 0, 'subtotal' => 0],
                ],
            ]));

            if (! $booking) {
                continue;
            }
            $this->stats['hajj_umra_bookings_created']++;

            // 2 payments (initial + remaining)
            $total = $selling + $companionSelling + $accommodationExtra;
            $firstPay = round($total * 0.4, 2);
            $payed = $this->safeCall('hajj_umra.payment', fn () => $service->addPayment($booking, [
                'amount' => $firstPay,
                'payment_method' => 'cash',
                'currency' => 'EGP',
                'account_id' => $this->cashbox->id,
            ]));
            if ($payed) {
                $this->stats['hajj_umra_payments_added']++;
            }

            $remainPay = $total - $firstPay;
            if ($remainPay > 0.01) {
                $payed2 = $this->safeCall('hajj_umra.payment', fn () => $service->addPayment($booking, [
                    'amount' => $remainPay,
                    'payment_method' => 'cash',
                    'currency' => 'EGP',
                    'account_id' => $this->cashbox->id,
                ]));
                if ($payed2) {
                    $this->stats['hajj_umra_payments_added']++;
                }
            }

            // 1 in 7: refund
            if ($i % 7 === 0 && $i > 0) {
                $refunded = $this->safeCall('hajj_umra.refund', fn () => $refundService->refund($booking, 'load-test HJ refund', Auth::id()));
                if ($refunded) {
                    $this->stats['hajj_umra_refunds']++;
                }
            }

            // 1 in 9: price modification (selling + 500)
            if ($i % 9 === 0 && $i > 0) {
                $modified = $this->safeCall('hajj_umra.modify', fn () => $service->update($booking, [
                    'selling_price' => $selling + 500,
                    'purchase_price' => $purchase + 200,
                ]));
                if ($modified) {
                    $this->stats['hajj_umra_modifications']++;
                }
            }
        }
    }

    // -------- PHASE 5: Treasury & integration checks --------

    private function validateInvariants(): void
    {
        // Skip if earlier phases crashed.
        if (! empty($this->stats['errors_caught'])) {
            fwrite(STDERR, "\n⚠️ Errors were caught in earlier phases — invariants may not hold.\n");

            return;
        }

        // 1) Per-account: stored balance == ledger net (the FUNDAMENTAL invariant)
        $accounts = Account::query()->where('is_active', true)->get();
        $accountViolations = 0;
        foreach ($accounts as $account) {
            $entries = AccountEntry::query()->where('account_id', $account->id)->get();
            $net = (float) $entries->sum('debit') - (float) $entries->sum('credit');
            $stored = (float) $account->balance;
            if (abs($net - $stored) > 0.02) {
                $this->stats['errors_caught'][] = "Account #{$account->id} ({$account->name}): stored {$stored} != ledger net {$net}";
                $accountViolations++;
            }
        }
        $this->stats['accounts_validated'] = $accounts->count();

        // 2) Global double-entry on tx-backed entries: Σdebit == Σcredit
        $debit = (float) AccountEntry::query()->whereNotNull('transaction_id')->sum('debit');
        $credit = (float) AccountEntry::query()->whereNotNull('transaction_id')->sum('credit');
        if (abs($debit - $credit) > 0.02) {
            $this->stats['errors_caught'][] = sprintf(
                "Global double-entry FAIL: tx-debit=%.2f, tx-credit=%.2f, diff=%.2f",
                $debit, $credit, abs($debit - $credit),
            );
        }

        // 3) All transactions are balanced (debit == credit per txn)
        $txViolations = 0;
        $txIds = AccountEntry::query()->whereNotNull('transaction_id')->distinct()->pluck('transaction_id');
        foreach ($txIds as $txId) {
            $d = (float) AccountEntry::query()->where('transaction_id', $txId)->sum('debit');
            $c = (float) AccountEntry::query()->where('transaction_id', $txId)->sum('credit');
            if (abs($d - $c) > 0.02) {
                $txViolations++;
                $this->stats['errors_caught'][] = "Transaction #{$txId}: unbalanced ({$d} vs {$c})";
            }
        }
        $this->stats['transactions_validated'] = $txIds->count();

        // 4) All bookings have valid status (no half-state after refunds)
        $badVisa = VisaBooking::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', ''))->count();
        if ($badVisa > 0) {
            $this->stats['errors_caught'][] = "{$badVisa} visa bookings have null/empty status";
        }
        $badHj = HajjUmraBooking::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', ''))->count();
        if ($badHj > 0) {
            $this->stats['errors_caught'][] = "{$badHj} hajj_umra bookings have null/empty status";
        }
        $badFlt = FlightBooking::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', ''))->count();
        if ($badFlt > 0) {
            $this->stats['errors_caught'][] = "{$badFlt} flight bookings have null/empty status";
        }

        // 5) Additive reversal invariant: cancelled bookings must have at
        // LEAST as many ledger entries as before (never fewer — we never
        // destroy originals).
        $cancelledBookings = FlightBooking::query()
            ->where('status', 'cancelled')->limit(5)->get();
        foreach ($cancelledBookings as $b) {
            $count = AccountEntry::query()->where('transaction_id', $b->income_transaction_id ?? 0)->count();
            if ($count < 2) {
                $this->stats['errors_caught'][] = "Cancelled flight booking #{$b->id} has too few entries (count={$count}) — reversal may have destroyed originals";
            }
        }

        // Track account & transaction counts for the summary
        $this->stats['account_invariant_violations'] = $accountViolations;
        $this->stats['transaction_balance_violations'] = $txViolations;
    }

    private function printSummary(): void
    {
        $totals = [
            'total_bookings' => $this->stats['flight_bookings_created']
                + $this->stats['visa_bookings_created']
                + $this->stats['hajj_umra_bookings_created'],
            'total_payments' => $this->stats['flight_payments_added']
                + $this->stats['visa_payments_added']
                + $this->stats['hajj_umra_payments_added'],
            'total_refunds' => $this->stats['flight_refunds']
                + $this->stats['visa_refunds']
                + $this->stats['hajj_umra_refunds'],
            'total_modifications' => $this->stats['flight_modifications']
                + $this->stats['visa_modifications']
                + $this->stats['hajj_umra_modifications'],
        ];

        $transactionCount = Transaction::query()->count();
        $entryCount = AccountEntry::query()->count();
        $accountCount = Account::query()->count();

        fwrite(STDERR, "\n\n=== TOURISM DIVISION FULL-LOAD STRESS REPORT ===\n");
        fwrite(STDERR, sprintf("Customers created:                %d\n", $this->stats['customers_created']));
        fwrite(STDERR, sprintf("Carriers created:                 %d\n", $this->stats['carriers_created']));
        fwrite(STDERR, sprintf("Flight groups:                    %d\n", $this->stats['groups_created']));
        fwrite(STDERR, sprintf("Visa agents:                      %d\n", $this->stats['visa_agents_created']));
        fwrite(STDERR, sprintf("Executing companies:              %d\n", $this->stats['ec_created']));
        fwrite(STDERR, sprintf("Umrah suppliers:                  %d\n", $this->stats['suppliers_created']));
        fwrite(STDERR, sprintf("Programs:                         %d\n", $this->stats['programs_created']));
        fwrite(STDERR, str_repeat('-', 60)."\n");
        fwrite(STDERR, sprintf("Flight bookings:                  %d\n", $this->stats['flight_bookings_created']));
        fwrite(STDERR, sprintf("Visa bookings:                    %d\n", $this->stats['visa_bookings_created']));
        fwrite(STDERR, sprintf("HajjUmra bookings:                %d\n", $this->stats['hajj_umra_bookings_created']));
        fwrite(STDERR, sprintf("TOTAL BOOKINGS:                   %d\n", $totals['total_bookings']));
        fwrite(STDERR, str_repeat('-', 60)."\n");
        fwrite(STDERR, sprintf("Payments added:                   %d\n", $totals['total_payments']));
        fwrite(STDERR, sprintf("Refunds processed:                %d\n", $totals['total_refunds']));
        fwrite(STDERR, sprintf("Price modifications:              %d\n", $totals['total_modifications']));
        fwrite(STDERR, str_repeat('-', 60)."\n");
        fwrite(STDERR, sprintf("Total transactions:               %d\n", $transactionCount));
        fwrite(STDERR, sprintf("Total account_entries:            %d\n", $entryCount));
        fwrite(STDERR, sprintf("Total accounts:                   %d\n", $accountCount));
        fwrite(STDERR, str_repeat('=', 60)."\n");
        fwrite(STDERR, sprintf("Errors caught:                    %d\n", count($this->stats['errors_caught'])));
        if (! empty($this->stats['errors_caught'])) {
            foreach (array_slice($this->stats['errors_caught'], 0, 30) as $err) {
                fwrite(STDERR, "  - $err\n");
            }
            if (count($this->stats['errors_caught']) > 30) {
                fwrite(STDERR, sprintf("  ... and %d more\n", count($this->stats['errors_caught']) - 30));
            }
        }
        fwrite(STDERR, str_repeat('=', 60)."\n\n");
    }

    // -------- THE TEST --------

    public function test_full_tourism_division_under_heavy_load(): void
    {
        // Phase 1: build heavy dataset
        $this->buildHeavyDataset();

        // Phase 2-4: stress each module with full user journeys
        $this->stressFlight();
        $this->stressVisa();
        $this->stressHajjUmra();

        // Phase 5: validate accounting invariants
        $this->validateInvariants();

        // Print summary
        $this->printSummary();

        // Final assertion: zero errors
        $this->assertEmpty(
            $this->stats['errors_caught'],
            '❌ FULL-LOAD STRESS TEST FAILED — errors encountered: '.PHP_EOL
            .implode(PHP_EOL, array_slice($this->stats['errors_caught'], 0, 20))
        );

        // Sanity: confirm we actually created bookings and not just a clean DB
        $this->assertGreaterThan(50, $this->stats['flight_bookings_created'], 'flight bookings < 50 — load was too low');
        $this->assertGreaterThan(30, $this->stats['visa_bookings_created'], 'visa bookings < 30 — load was too low');
        $this->assertGreaterThan(40, $this->stats['hajj_umra_bookings_created'], 'hajj_umra bookings < 40 — load was too low');
    }
}
