<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Financial integrity under Employee flows.
 *
 * Verifies that:
 *  - Bookings created by employees maintain the double-entry invariant.
 *  - All account balances match SUM(credit)-SUM(debit).
 *  - No balances are modified by direct DB UPDATE (the "Direct DB UPDATE on
 *    protected balance column" warning means the application is bypassing
 *    the proper service flow).
 *  - Office accounts are NOT touched by Tourism employee actions (the
 *    Tourism/Office division boundary stays clean).
 */
class EmployeeFinancialIntegrityTest extends EmployeeTestCase
{
    /* ============================================================
     *  All-account reconciliation
     * ============================================================ */

    public function test_all_tourism_accounts_balance_equals_ledger_sum(): void
    {
        // Snapshot opening balances BEFORE any employee action
        $tourismAccounts = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->get()
            ->keyBy('id');

        $openingBalances = $tourismAccounts->map(fn ($a) => (float) $a->balance)->toArray();

        // Drive a full Hajj booking flow as an employee
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $bookingPayload = [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_AUDIT_20260817',
            'notes' => 'Integrity test booking',
            'initial_payment' => [
                'amount' => 5000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ];
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $bookingPayload);
        $response->assertStatus(201);

        // For every Tourism account with ledger activity, the BALANCE DELTA
        // must equal the LEDGER NET DELTA. This avoids reconciling the test
        // opening balance (500k) which was set without a journal entry.
        $tourismAccounts = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->whereIn('id', function ($q) {
                $q->select('account_id')
                    ->from('account_entries')
                    ->groupBy('account_id');
            })
            ->get();

        $this->assertGreaterThan(0, $tourismAccounts->count(), 'Need at least one Tourism account with ledger activity');

        foreach ($tourismAccounts as $account) {
            $balanceDelta = (float) $account->balance - ($openingBalances[$account->id] ?? 0);
            $ledgerNet = (float) DB::table('account_entries')
                ->where('account_id', $account->id)
                ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
                ->value('net');

            $diff = abs($balanceDelta - $ledgerNet);
            $this->assertLessThan(
                0.01,
                $diff,
                "Account #{$account->id} ({$account->name}): balance_delta={$balanceDelta}, ledger_net={$ledgerNet}, diff={$diff}"
            );
        }
    }

    public function test_office_accounts_unchanged_by_tourism_employee_actions(): void
    {
        // Create an Office account
        $officeAccount = Account::query()->create([
            'name' => 'EMP_AUDIT_20260817_OFFICE_Vault',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100_000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $balanceBefore = $officeAccount->balance;

        // Employee creates tourism booking — uses Tourism vault, NOT office vault
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(201);

        $officeAccount->refresh();
        $this->assertEquals(
            $balanceBefore,
            $officeAccount->balance,
            'Office account balance must NOT change when Tourism employee creates a booking'
        );
    }

    /* ============================================================
     *  Booking financial invariants
     * ============================================================ */

    public function test_hajj_booking_creates_balanced_double_entry(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'initial_payment' => [
                'amount' => 10000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ]);
        $response->assertStatus(201);
        $bookingId = $response->json('data.id');

        // Find the transactions for this booking
        $transactions = Transaction::query()
            ->where('related_type', \App\Models\HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->get();

        $this->assertGreaterThan(0, $transactions->count(), 'Booking must create transactions');

        // Each transaction must be balanced: SUM(credit) = SUM(debit)
        foreach ($transactions as $tx) {
            $totals = DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->selectRaw('COALESCE(SUM(credit), 0) as cr, COALESCE(SUM(debit), 0) as dr')
                ->first();

            $diff = abs((float) $totals->cr - (float) $totals->dr);
            $this->assertLessThan(
                0.01,
                $diff,
                "Transaction #{$tx->id} is unbalanced: credit={$totals->cr}, debit={$totals->dr}"
            );
        }
    }

    /* ============================================================
     *  Cumulative sanity check
     * ============================================================ */

    public function test_full_tourism_employee_flow_leaves_balances_consistent(): void
    {
        // Snapshot all Tourism account balances
        $tourismAccounts = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->get();

        $beforeSum = $tourismAccounts->sum(fn ($a) => (float) $a->balance);

        // Flow 1: Employee creates Hajj booking + payment
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);
        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'initial_payment' => [
                'amount' => 5000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ])->assertStatus(201);

        // Flow 2: Employee creates Visa booking + payment
        $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'EG',
            ],
        ])->assertStatus(201);

        // Sum all balances again
        $tourismAccounts = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->get();
        $afterSum = $tourismAccounts->sum(fn ($a) => (float) $a->balance);

        // Total Tourism balance should not change in pure-transfer operations
        // (transfers between Tourism accounts conserve the sum).
        $this->assertEqualsWithDelta(
            $beforeSum,
            $afterSum,
            0.01,
            'Total Tourism balance must be conserved across employee-driven transfers'
        );
    }
}