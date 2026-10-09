<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 30 — Randomized Financial Dataset.
 *
 * Generates randomized Tourism-only scenarios using real application services,
 * then verifies all invariants hold:
 *  - balance = SUM(credit) - SUM(debit) per account
 *  - Tourism accounts only Tourism transactions
 *  - No unexplained variance
 */
class RandomizedFinancialDatasetTest extends TourismAuditTestCase
{
    protected HajjUmraBookingService $hajjService;

    protected HajjUmraRefundService $hajjRefund;

    protected VisaBookingService $visaService;

    protected VisaRefundService $visaRefund;

    protected Program $program;

    protected VisaDuration $visaDuration;

    protected VisaAgent $visaAgent;

    protected UmrahSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hajjService = app(HajjUmraBookingService::class);
        $this->hajjRefund = app(HajjUmraRefundService::class);
        $this->visaService = app(VisaBookingService::class);
        $this->visaRefund = app(VisaRefundService::class);

        $this->program = Program::query()->create([
            'program_name' => 'Randomized Test Program',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_nights' => 4,
            'medina_nights' => 3,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'Hotel',
            'medina_hotel_name' => 'Hotel',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(67)->toDateString(),
            'airline' => 'Air',
            'executing_company' => 'Co',
            'departure_point' => 'CAI',
            'default_selling_price' => 30000.0,
            'default_purchase_price' => 25000.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->visaDuration = VisaDuration::query()->create([
            'code' => 'RAND-30D',
            'label_ar' => '30 يوم',
            'label_en' => '30 days',
            'months' => 1,
            'entry_type' => 'single',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        LedgerBalanceMutationGuard::run(function () {
            $supplierAcc = Account::query()->create([
                'name' => 'Random Test Supplier',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'notes' => 'Random test',
                'created_by' => $this->admin->id,
            ]);

            $this->supplier = UmrahSupplier::query()->create([
                'name' => 'Random Test Supplier',
                'phone' => '01555555555',
                'account_id' => $supplierAcc->id,
                'default_cost_price' => 1500.0,
                'is_active' => true,
            ]);

            $agentAcc = Account::query()->create([
                'name' => 'Random Visa Agent',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'notes' => 'Random test',
                'created_by' => $this->admin->id,
            ]);

            $this->visaAgent = VisaAgent::query()->create([
                'company_name' => 'Random Visa Agent',
                'contact_person' => 'Contact',
                'phone' => '01555555556',
                'email' => 'rva@test.com',
                'country' => 'EG',
                'visa_type' => 'tourist',
                'default_cost_price' => 800.0,
                'account_id' => $agentAcc->id,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Randomized scenario: 4 customers (each used in Tourism bookings), mix of Hajj/Visa, payments, refunds.
     */
    public function test_randomized_dataset_invariants(): void
    {
        $rand = mt_rand(100000, 999999);
        $customerCount = 4;

        $customers = [];
        for ($i = 0; $i < $customerCount; $i++) {
            $customers[] = Customer::query()->create([
                'full_name' => "Random Customer {$i}",
                'phone' => "016{$rand}".str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'type' => 'individual',
                'status' => 'active',
                'currency' => 'EGP',
                'created_by' => $this->admin->id,
            ]);
        }

        $hajjBookings = [];
        $visaBookings = [];

        // Create 2 Hajj bookings and 2 Visa bookings (each customer used)
        for ($i = 0; $i < 2; $i++) {
            $hajjBookings[] = $this->createHajj($customers[$i]);
        }

        for ($i = 0; $i < 2; $i++) {
            $visaBookings[] = $this->createVisa($customers[$i + 2]);
        }

        // Add partial payments to each
        foreach ($hajjBookings as $b) {
            $this->hajjService->addPayment($b, [
                'amount' => mt_rand(5000, 15000),
                'account_id' => $this->vaultEgp->id,
                'payment_method' => 'cash',
                'currency' => 'EGP',
            ]);
        }

        foreach ($visaBookings as $b) {
            $this->visaService->addPayment($b, [
                'amount' => mt_rand(500, 1500),
                'account_id' => $this->vaultEgp->id,
                'payment_method' => 'cash',
                'currency' => 'EGP',
            ]);
        }

        // Cancel one Hajj booking
        if (! empty($hajjBookings)) {
            $this->hajjService->cancel($hajjBookings[0], 'Random cancel');
        }

        // Refund one Visa booking
        if (! empty($visaBookings)) {
            $this->visaRefund->refund($visaBookings[0], 'Random refund');
        }

        // Verify invariants
        $this->assertLedgerGloballyBalanced();

        // All Hajj bookings still exist
        $this->assertSame(2, HajjUmraBooking::query()->count());

        // All Visa bookings still exist
        $this->assertSame(2, VisaBooking::query()->count());

        // All customers were used in bookings → accounts MUST be Tourism-division
        foreach ($customers as $customer) {
            $acc = $customer->fresh()->ledgerAccount;
            if ($acc) {
                $division = \App\Support\Finance\AccountModuleContract::divisionFor($acc->module_type);
                $this->assertSame('tourism', $division, "Customer #{$customer->id} account has wrong division: {$acc->module_type}");
            }
        }
    }

    /**
     * Multiple-payments-per-booking randomized.
     */
    public function test_randomized_multiple_payments(): void
    {
        $rand = mt_rand(100000, 999999);
        $customer = Customer::query()->create([
            'full_name' => 'Multi-Payment Customer',
            'phone' => "016{$rand}01",
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $booking = $this->createHajj($customer);

        $totalPaid = 0.0;
        $paymentCount = mt_rand(3, 7);

        for ($i = 0; $i < $paymentCount; $i++) {
            $amount = mt_rand(1000, 5000);
            $this->hajjService->addPayment($booking, [
                'amount' => $amount,
                'account_id' => $this->vaultEgp->id,
                'payment_method' => 'cash',
                'currency' => 'EGP',
            ]);
            $totalPaid += $amount;
        }

        $this->assertEquals(round($totalPaid, 2), round((float) $booking->fresh()->paid_amount, 2));
        $this->assertSame($paymentCount, HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking->id)->count());
        $this->assertLedgerGloballyBalanced();
    }

    protected function createHajj(Customer $customer): HajjUmraBooking
    {
        return $this->hajjService->create([
            'customer_id' => $customer->id,
            'program_id' => $this->program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Random Test',
            'notes' => 'Random test',
        ]);
    }

    protected function createVisa(Customer $customer): VisaBooking
    {
        return $this->visaService->create([
            'customer_id' => $customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'agent_name' => 'Random Test',
            'notes' => 'Random test',
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'TEST',
                'duration' => '30',
                'visa_duration_id' => $this->visaDuration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'Test',
                'executing_agent' => 'Test',
                'executing_agent_contact' => '01000000000',
                'visa_agent_id' => $this->visaAgent->id,
            ],
        ]);
    }
}
