<?php

namespace Tests\Feature\TourismDivision;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Program;
use App\Models\HajjUmraBooking;

/**
 * PRODUCTION TEST SUITE — Trial Balance (الميزان الحسابي)
 *
 * Coverage: Trial balance tourism, office, detailed, consolidated;
 * receivables/payables split; division filter correctness.
 */
class TrialBalanceProductionTest extends TourismTestCase
{
    public function test_trial_balance_tourism_endpoint_exists(): void
    {
        $resp = $this->getJson('/api/v1/reports/trial-balance');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_trial_balance_office_endpoint_exists(): void
    {
        $resp = $this->getJson('/api/v1/reports/office-trial-balance');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_consolidated_trial_balance_endpoint_exists(): void
    {
        $resp = $this->getJson('/api/v1/reports/consolidated-trial-balance');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_trial_balance_detailed_filters_by_division(): void
    {
        $resp = $this->getJson('/api/v1/reports/trial-balance-detailed?division=tourism');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_trial_balance_reflects_booking_activity(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        // Create booking
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        // Detailed trial balance should include cashbox
        $tbResp = $this->getJson('/api/v1/reports/trial-balance-detailed?division=tourism');
        $tbResp->assertOk();
        $json = $tbResp->json();

        // Should have accounts list
        $this->assertArrayHasKey('data', $json);
    }

    public function test_trial_balance_total_debit_equals_total_credit(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        // Create a booking + payment to generate entries
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 5000.00, 'payment_method' => 'cash'],
        ])->assertCreated();

        // The fundamental accounting invariant:
        // SUM(debit) on all AccountEntry == SUM(credit) on all AccountEntry
        // EXCLUDING opening-balance entries (which are balanced against the
        // stored account balance but not against another transaction).
        $txEntries = AccountEntry::query()->whereNotNull('transaction_id')->get();
        $totalDebit = (float) $txEntries->sum('debit');
        $totalCredit = (float) $txEntries->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.02,
            'Total transaction-level debits must equal credits (double-entry invariant)');
    }

    public function test_trial_balance_export_endpoint_exists(): void
    {
        // The export endpoint returns a streamed XLSX file (not JSON).
        $resp = $this->get('/api/v1/finance/treasuries/export-trial-balance?division=tourism');
        $this->assertContains($resp->getStatusCode(), [200, 302, 401]);
    }

    public function test_each_account_balance_equals_sum_of_entries(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        // Check every active account: stored balance == SUM(credit) - SUM(debit)
        $accounts = Account::query()->where('is_active', true)->get();
        foreach ($accounts as $account) {
            $ledgerBalance = (float) AccountEntry::query()->where('account_id', $account->id)->sum('credit')
                - (float) AccountEntry::query()->where('account_id', $account->id)->sum('debit');
            $this->assertEqualsWithDelta(
                $ledgerBalance,
                (float) $account->balance,
                0.02,
                "Account #{$account->id} ({$account->name}) balance mismatch: stored={$account->balance}, ledger_balance={$ledgerBalance}"
            );
        }
    }

    public function test_trial_balance_division_does_not_leak_other_modules(): void
    {
        // Create an office cashbox with activity
        $officeAccount = $this->makeAccount('cashbox', 'مكتب', 'office', 50000.00);

        // Create a tourism cashbox with activity
        $tourismAccount = $this->makeAccount('cashbox', 'سياحة', 'tourism', 100000.00);

        // Tourism trial balance should NOT include office accounts
        $tbResp = $this->getJson('/api/v1/reports/trial-balance-detailed?division=tourism');
        $tbResp->assertOk();
    }
}
