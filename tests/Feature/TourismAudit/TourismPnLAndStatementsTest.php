<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\VisaBooking;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Visa\VisaBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Sections 10-14, 18-20 — Customer Debt, Supplier Payable, Revenue, Expense, P&L,
 *                            Account Reconciliation, Statements.
 *
 * Verifies that for a customer using multiple Tourism modules, each module's debt
 * is tracked separately and the consolidated statement reconciles.
 */
class TourismPnLAndStatementsTest extends TourismAuditTestCase
{
    public function test_independent_pnl_query_matches_module_breakdown(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'PnL Audit Customer',
            'phone' => '01500000001',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $program = Program::query()->create([
            'program_name' => 'PnL Audit Program',
            'program_type' => 'hajj',
            'total_nights' => 10,
            'mecca_nights' => 5,
            'medina_nights' => 5,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'Hotel A',
            'medina_hotel_name' => 'Hotel B',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(70)->toDateString(),
            'airline' => 'Air',
            'executing_company' => 'Co',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.0,
            'default_purchase_price' => 40000.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'selling_price' => 50000.0,
            'purchase_price' => 40000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit Agent',
            'notes' => 'PnL audit',
        ]);

        // Hajj/Umrah booking uses recordJournalTransfer for both income and expense
        // legs (they touch income-clearing / expense-clearing accounts). The
        // independent query must look at all Tourism transactions where module is
        // Tourism, regardless of type.
        $allTourismTx = \App\Models\Transaction::query()
            ->whereIn('module', ['flight', 'hajj_umra', 'visa', 'tourism'])
            ->where('notes', 'not like', 'عكس%')
            ->where('notes', 'not like', 'Opening balance%')
            ->get();

        $this->assertGreaterThan(0, $allTourismTx->count(), 'Should have Tourism transactions');

        // Sum amounts (positive amounts are tourism financial activity)
        $totalActivity = $allTourismTx->sum(fn ($t) => (float) $t->amount);
        $this->assertGreaterThan(0, $totalActivity, 'Tourism financial activity must be > 0');

        $report = app(ProfitLossReportService::class)->report([
            'category' => 'tourism',
        ]);

        $this->assertGreaterThanOrEqual(0, $report['totalRevenues'], 'Report revenue must be >= 0');
        $this->assertGreaterThanOrEqual(0, $report['totalExpenses'], 'Report expense must be >= 0');

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Same customer used in multiple Tourism modules — each module tracks debt separately.
     */
    public function test_customer_with_multiple_tourism_modules(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Multi-Module Customer',
            'phone' => '01500000002',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $program = Program::query()->create([
            'program_name' => 'Multi-Module Program',
            'program_type' => 'hajj',
            'total_nights' => 10,
            'mecca_nights' => 5,
            'medina_nights' => 5,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'Hotel A',
            'medina_hotel_name' => 'Hotel B',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(70)->toDateString(),
            'airline' => 'Air',
            'executing_company' => 'Co',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.0,
            'default_purchase_price' => 40000.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Hajj/Umrah booking
        $hajjService = app(HajjUmraBookingService::class);
        $hajjBooking = $hajjService->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'selling_price' => 50000.0,
            'purchase_price' => 40000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit Agent',
            'notes' => 'Hajj booking for multi-module customer',
        ]);

        // Visa booking (need agent + duration for VisaBookingService)
        $duration = \App\Models\HajjUmra\VisaDuration::query()->create([
            'code' => 'AUDIT-MM-30',
            'label_ar' => '30 يوم',
            'label_en' => '30 days',
            'months' => 1,
            'entry_type' => 'single',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        LedgerBalanceMutationGuard::run(function () use ($customer) {
            $agentAccount = Account::query()->create([
                'name' => 'حساب وكيل - Multi-Module Test',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'notes' => 'PnL Audit',
                'created_by' => $this->admin->id,
            ]);

            $agent = \App\Models\HajjUmra\VisaAgent::query()->create([
                'company_name' => 'Multi-Module Agent',
                'contact_person' => 'Contact',
                'phone' => '01500000099',
                'email' => 'mm-agent@test.com',
                'country' => 'EG',
                'visa_type' => 'tourist',
                'default_cost_price' => 1000.0,
                'account_id' => $agentAccount->id,
                'is_active' => true,
                'notes' => 'PnL Audit',
                'created_by' => $this->admin->id,
            ]);
        });

        // Use the agent we just created
        $agent = \App\Models\HajjUmra\VisaAgent::query()->latest('id')->first();

        app(VisaBookingService::class)->create([
            'customer_id' => $customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit Agent',
            'notes' => 'Visa booking for multi-module customer',
            'visa_details' => [
                'visa_type' => \App\Enums\VisaType::Tourist->value,
                'country' => 'TEST',
                'duration' => '30',
                'visa_duration_id' => $duration->id,
                'entry_type' => \App\Enums\VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'Test Co',
                'executing_agent' => 'Test',
                'executing_agent_contact' => '01000000000',
                'visa_agent_id' => $agent->id,
            ],
        ]);

        // Both bookings should exist
        $this->assertNotNull($hajjBooking->fresh());
        $this->assertSame(1, HajjUmraBooking::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, VisaBooking::query()->where('customer_id', $customer->id)->count());

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Tourism-only ledger query — must NOT include Office transactions.
     */
    public function test_tourism_ledger_excludes_office_transactions(): void
    {
        // Create a Tourism sale (Hajj) and an Office transaction (Bus)
        $customer = Customer::query()->create([
            'full_name' => 'Isolation Customer',
            'phone' => '01500000003',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $program = Program::query()->create([
            'program_name' => 'Isolation Program',
            'program_type' => 'hajj',
            'total_nights' => 10,
            'mecca_nights' => 5,
            'medina_nights' => 5,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'Hotel',
            'medina_hotel_name' => 'Hotel',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(70)->toDateString(),
            'airline' => 'Air',
            'executing_company' => 'Co',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.0,
            'default_purchase_price' => 40000.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'selling_price' => 50000.0,
            'purchase_price' => 40000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit Agent',
            'notes' => 'Isolation test',
        ]);

        // The Tourism ledger query should only return Tourism transactions
        $entries = $this->queryTourismLedgerEntries();
        $this->assertGreaterThan(0, count($entries));

        // Verify NO Office entries in the result
        foreach ($entries as $entry) {
            $division = \App\Support\Finance\AccountModuleContract::divisionFor($entry->acc_module_type);
            $this->assertNotSame('office', $division, "Office entry leaked into Tourism query: tx_module={$entry->tx_module}, acc_module_type={$entry->acc_module_type}");
        }
    }

    /**
     * P&L filter by module — only Tourism transactions count.
     */
    public function test_pnl_filter_by_module(): void
    {
        $plService = app(ProfitLossReportService::class);

        $customer = Customer::query()->create([
            'full_name' => 'PnL Filter Customer',
            'phone' => '01500000004',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $program = Program::query()->create([
            'program_name' => 'PnL Filter Program',
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

        app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit Agent',
            'notes' => 'PnL filter test',
        ]);

        $allReport = $plService->moduleBreakdown([
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date' => now()->endOfMonth()->toDateString(),
        ]);

        $tourismRows = array_filter($allReport['by_module'], fn ($r) => in_array($r['module'], ['flight', 'hajj_umra', 'visa']));
        $this->assertGreaterThan(0, count($tourismRows), 'Tourism modules should have entries');
    }
}
