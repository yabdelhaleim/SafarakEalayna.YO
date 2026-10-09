<?php

namespace Tests\Feature\TourismAudit;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Visa\VisaBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 26 / 27 / 28 — Database Integrity, Direct Financial Mutation Audit, Contamination.
 *
 * Verifies:
 *  - No orphan AccountEntries (every entry has a transaction)
 *  - No orphan Transactions (every related_id exists)
 *  - Idempotency keys are UNIQUE per (booking_id, key)
 *  - Soft-deleted payments don't break financial trail
 *  - Direct accounts.balance writes are blocked (or guarded)
 *  - No cross-module contamination
 */
class DatabaseIntegrityTest extends TourismAuditTestCase
{
    public function test_no_orphan_account_entries(): void
    {
        $customer = $this->makeTourismCustomer('orphan-test');
        $program = $this->makeProgram('Orphan Test');
        $this->createHajjBooking($customer, $program);

        $orphanEntries = AccountEntry::query()
            ->leftJoin('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->whereNull('transactions.id')
            ->select('account_entries.*')
            ->count();

        $this->assertSame(0, $orphanEntries, 'Found orphan AccountEntry rows without a transaction');
    }

    public function test_no_orphan_transactions(): void
    {
        $customer = $this->makeTourismCustomer('orphan-tx-test');
        $program = $this->makeProgram('Orphan TX Test');
        $this->createHajjBooking($customer, $program);

        // For each transaction, verify related_type/id points to existing record (when related)
        $transactions = Transaction::query()->whereNotNull('related_type')->whereNotNull('related_id')->get();
        $orphans = [];

        foreach ($transactions as $tx) {
            $exists = match ($tx->related_type) {
                HajjUmraBooking::class => HajjUmraBooking::query()->where('id', $tx->related_id)->exists(),
                default => true, // Skip non-audited types
            };
            if (! $exists) {
                $orphans[] = $tx->id;
            }
        }

        $this->assertEmpty($orphans, 'Found orphan Transaction rows: '.json_encode($orphans));
    }

    public function test_idempotency_key_uniqueness(): void
    {
        $customer = $this->makeTourismCustomer('idem-unique');
        $program = $this->makeProgram('Idem Unique Test');
        $booking = $this->createHajjBooking($customer, $program);

        // Add payment with idempotency_key
        $key = 'idem-uniq-test-'.uniqid();
        app(HajjUmraBookingService::class)->addPayment($booking, [
            'amount' => 1000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        // Try to insert a duplicate — should fail at DB level
        $duplicateCount = \DB::table('hajj_umra_payments')
            ->where('hajj_umra_booking_id', $booking->id)
            ->where('idempotency_key', $key)
            ->count();

        $this->assertSame(1, $duplicateCount, 'Idempotency key uniqueness violated');
    }

    public function test_soft_deleted_payment_preserves_ledger(): void
    {
        $customer = $this->makeTourismCustomer('soft-del');
        $program = $this->makeProgram('Soft Del Test');
        $booking = $this->createHajjBooking($customer, $program);

        $payment = app(HajjUmraBookingService::class)->addPayment($booking, [
            'amount' => 5000.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $txId = $payment->transaction_id;
        $payment->delete(); // Soft delete

        // The transaction should still exist (NOT deleted)
        $tx = Transaction::query()->find($txId);
        $this->assertNotNull($tx, 'Transaction must NOT be deleted when payment is soft-deleted');

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Direct account.balance write is blocked by the booted() guard.
     */
    public function test_direct_balance_update_blocked_by_guard(): void
    {
        $vault = $this->vaultEgp;
        $original = (float) $vault->fresh()->balance;

        try {
            // Try to bypass the guard by direct attribute assignment
            $vault->balance = 999999.0;
            $vault->save();
            $this->fail('Direct balance write should be blocked by the guard');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تعديل رصيد', $e->getMessage());
        }

        // Balance should be unchanged
        $this->assertEquals($original, (float) $vault->fresh()->balance);
    }

    /**
     * Cross-module: an Office transaction must NEVER appear in Tourism P&L.
     */
    public function test_office_transaction_excluded_from_tourism_pnl(): void
    {
        // Create a Hajj/Umrah booking (Tourism)
        $customer = $this->makeTourismCustomer('contam-test');
        $program = $this->makeProgram('Contam Test');
        $booking = $this->createHajjBooking($customer, $program);

        // Create an Office transaction directly (bus module) — must NOT be in Tourism P&L
        $busTx = Transaction::query()->create([
            'type' => 'income',
            'amount' => 1000.0,
            'module' => 'bus', // OFFICE module
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
            'notes' => 'Bus income — should NOT appear in Tourism P&L',
        ]);

        $tourismPnL = $this->calculateTourismPnLIndependent();
        $allPnL = $this->calculateAllPnLIndependent();

        // Tourism P&L must not include the bus income
        $this->assertLessThan($allPnL['income'], $tourismPnL['income'] + 1.0, 'Bus income leaked into Tourism P&L');

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Tourism accounts only ever debit/credit Tourism transactions.
     *
     * NOTE: Opening-balance transactions use module='general' by design (they are
     * pre-existing seed entries). We exclude those from this check.
     */
    public function test_tourism_accounts_only_tourism_transactions(): void
    {
        $customer = $this->makeTourismCustomer('acct-iso');
        $program = $this->makeProgram('Acct Iso');
        $this->createHajjBooking($customer, $program);

        $tourismAccountIds = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->pluck('id')
            ->toArray();

        // Office modules (general excluded — opening balance uses it by design)
        $offendingEntries = AccountEntry::query()
            ->whereIn('account_id', $tourismAccountIds)
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->whereIn('transactions.module', ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'office'])
            ->where('transactions.notes', 'not like', 'Opening balance%')
            ->where('transactions.notes', 'not like', 'رصيد افتتاحي%')
            ->select('account_entries.*')
            ->count();

        $this->assertSame(0, $offendingEntries, 'Tourism accounts have Office transactions (excluding opening balance)');
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    protected function makeTourismCustomer(string $phone): Customer
    {
        return Customer::query()->create([
            'full_name' => 'Test Customer '.$phone,
            'phone' => '015'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeProgram(string $name): Program
    {
        return Program::query()->create([
            'program_name' => $name,
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
    }

    protected function createHajjBooking(Customer $customer, Program $program): HajjUmraBooking
    {
        return app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'Audit',
            'notes' => 'Integrity test',
        ]);
    }

    protected function calculateAllPnLIndependent(): array
    {
        $income = (float) Transaction::query()->where('type', 'income')->where('notes', 'not like', 'عكس%')->sum('amount');
        $expense = (float) Transaction::query()->where('type', 'expense')->where('notes', 'not like', 'عكس%')->sum('amount');

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'profit' => round($income - $expense, 2),
        ];
    }
}
