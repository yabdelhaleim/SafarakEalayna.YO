<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Database integrity scan — checks for orphaned records, missing ledger
 * entries, and other structural invariants AFTER employee-driven flows.
 *
 * Note: This is an IN-FLOW integrity check (the test creates its own data).
 * The AuditRunner aggregates per-module results across all Employee E2E tests.
 */
class EmployeeDatabaseIntegrityTest extends EmployeeTestCase
{
    public function test_no_orphan_account_entries(): void
    {
        // Run a full flow that creates many entries
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

        // Every account_entries row must reference an existing account
        $orphans = DB::table('account_entries as ae')
            ->leftJoin('accounts as a', 'a.id', '=', 'ae.account_id')
            ->whereNull('a.id')
            ->select('ae.id', 'ae.account_id')
            ->limit(10)
            ->get();

        $this->assertSame(0, $orphans->count(),
            'No orphan account_entries rows allowed. Found: '.json_encode($orphans->toArray()));

        // Every account_entries row must reference an existing transaction
        $orphans = DB::table('account_entries as ae')
            ->leftJoin('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->whereNull('t.id')
            ->select('ae.id', 'ae.transaction_id')
            ->limit(10)
            ->get();

        $this->assertSame(0, $orphans->count(),
            'No orphan account_entries (missing transaction). Found: '.json_encode($orphans->toArray()));
    }

    public function test_no_transactions_without_balanced_entries(): void
    {
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

        // Every transaction must have balanced entries: SUM(credit) == SUM(debit)
        $unbalanced = Transaction::query()
            ->selectRaw('transactions.id, COALESCE(SUM(account_entries.credit), 0) as cr, COALESCE(SUM(account_entries.debit), 0) as dr')
            ->leftJoin('account_entries', 'account_entries.transaction_id', '=', 'transactions.id')
            ->groupBy('transactions.id')
            ->havingRaw('ABS(COALESCE(SUM(account_entries.credit), 0) - COALESCE(SUM(account_entries.debit), 0)) > 0.01')
            ->limit(10)
            ->get();

        $this->assertSame(0, $unbalanced->count(),
            'No unbalanced transactions allowed. Found: '.json_encode($unbalanced->toArray()));
    }

    public function test_no_orphan_flight_payments(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_DB_'.uniqid(),
        ])->assertStatus(201);

        $orphans = DB::table('flight_payments as fp')
            ->leftJoin('flight_bookings as fb', 'fb.id', '=', 'fp.flight_booking_id')
            ->whereNull('fb.id')
            ->select('fp.id', 'fp.flight_booking_id')
            ->limit(10)
            ->get();

        $this->assertSame(0, $orphans->count());
    }

    public function test_no_orphan_hajj_payments(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_DB_'.uniqid(),
        ])->assertStatus(201);

        $orphans = DB::table('hajj_umra_payments as hp')
            ->leftJoin('hajj_umra_bookings as hb', 'hb.id', '=', 'hp.hajj_umra_booking_id')
            ->whereNull('hb.id')
            ->select('hp.id', 'hp.hajj_umra_booking_id')
            ->limit(10)
            ->get();

        $this->assertSame(0, $orphans->count());
    }

    public function test_no_orphan_visa_payments(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_DB_'.uniqid(),
        ])->assertStatus(201);

        $orphans = DB::table('visa_payments as vp')
            ->leftJoin('visa_bookings as vb', 'vb.id', '=', 'vp.visa_booking_id')
            ->whereNull('vb.id')
            ->select('vp.id', 'vp.visa_booking_id')
            ->limit(10)
            ->get();

        $this->assertSame(0, $orphans->count());
    }

    public function test_no_accounts_with_negative_balance_unless_allowed(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ])->assertStatus(201);

        // Customer AR accounts are allowed to go negative (they represent debt)
        // but liquidity accounts (vaults, banks) must never go negative
        $bad = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas', 'office'])
            ->whereIn('type', ['cashbox', 'bank'])
            ->where('balance', '<', 0)
            ->get();

        $this->assertSame(0, $bad->count(),
            'No liquidity account may go negative. Found: '.json_encode($bad->toArray()));
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function createHajjBooking(\App\Models\Program $program): \App\Models\HajjUmraBooking
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_AUDIT_20260817',
        ];
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        return \App\Models\HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    protected function createVisaBooking(): \App\Models\VisaBooking
    {
        $payload = [
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
        ];
        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        return \App\Models\VisaBooking::findOrFail($response->json('data.id'));
    }

    protected function createFlightBooking(\App\Models\Flight\FlightCarrier $carrier): \App\Models\Flight\FlightBooking
    {
        $payload = $this->flightBookingPayload($carrier);
        $response = $this->postJson('/api/v1/flight/bookings', $payload);

        return \App\Models\Flight\FlightBooking::findOrFail($response->json('data.id'));
    }
}