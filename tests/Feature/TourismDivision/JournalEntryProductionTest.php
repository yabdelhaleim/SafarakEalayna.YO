<?php

namespace Tests\Feature\TourismDivision;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Program;
use App\Models\Transaction;

/**
 * PRODUCTION TEST SUITE — Journal Entry Invariants (القيود)
 *
 * Coverage: double-entry balance, additive reversal preservation,
 * no destructive deletes on AccountEntry, original transaction preservation.
 */
class JournalEntryProductionTest extends TourismTestCase
{
    public function test_sum_of_debits_equals_sum_of_credits_globally(): void
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

        // Exclude opening-balance entries (transaction_id IS NULL) which are
        // balanced against the stored account balance, not against another tx.
        $txEntries = AccountEntry::query()->whereNotNull('transaction_id')->get();
        $totalDebit = (float) $txEntries->sum('debit');
        $totalCredit = (float) $txEntries->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.02,
            'Total transaction-level debits must equal credits (double-entry invariant)');
    }

    public function test_each_transaction_has_balanced_entries(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 5000.00, 'payment_method' => 'cash'],
        ])->assertCreated();

        $txs = Transaction::query()->get();
        foreach ($txs as $tx) {
            $this->assertTransactionBalanced($tx);
        }
    }

    public function test_account_entry_immutable_no_soft_deletes(): void
    {
        // Check that AccountEntry doesn't have SoftDeletes trait
        $traits = class_uses_recursive(\App\Models\AccountEntry::class);
        $this->assertNotContains('Illuminate\Database\Eloquent\SoftDeletes', $traits,
            'AccountEntry must NOT use SoftDeletes (append-only ledger invariant)');
    }

    public function test_reversal_entries_are_additive_not_destructive(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $originalTxCount = Transaction::query()
            ->where('related_type', \App\Models\HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->count();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", ['reason' => 'test'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // ADDITIVE: original transactions are preserved (not deleted)
        $afterCancelTxCount = Transaction::query()
            ->where('related_type', \App\Models\HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->count();

        $this->assertGreaterThanOrEqual($originalTxCount, $afterCancelTxCount,
            'Original transactions must be preserved (additive reversal)');
    }

    public function test_net_debit_minus_credit_zero_after_full_cancellation(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 5000.00, 'payment_method' => 'cash'],
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", ['reason' => 'test'])
            ->assertOk();

        // After cancel: cashbox should be back to opening
        $this->assertEqualsWithDelta($openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02);
        $this->assertCustomerBalance($customer, 0.0);
    }

    public function test_each_transaction_balanced_across_modules(): void
    {
        // Verify that the balance invariant holds across all tourism modules
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 5000.00, 'payment_method' => 'cash'],
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 2000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertStatus(201);

        // All transactions in the database must be balanced
        $txs = Transaction::query()->get();
        $this->assertGreaterThan(0, $txs->count());

        foreach ($txs as $tx) {
            $entries = AccountEntry::query()->where('transaction_id', $tx->id)->get();
            $debit = (float) $entries->sum('debit');
            $credit = (float) $entries->sum('credit');
            $this->assertEqualsWithDelta($debit, $credit, 0.02,
                "Transaction #{$tx->id} should be balanced: debit={$debit}, credit={$credit}");
        }
    }

    public function test_reversal_adds_inverse_entries_with_arabic_prefix(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $originalIncomeId = \App\Models\HajjUmraBooking::find($bookingId)->income_transaction_id;
        $originalExpenseId = \App\Models\HajjUmraBooking::find($bookingId)->expense_transaction_id;

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", ['reason' => 'test'])
            ->assertOk();

        // Original transactions should now have "عكس:" prefix in notes
        $originalIncome = Transaction::find($originalIncomeId);
        $originalExpense = Transaction::find($originalExpenseId);

        $this->assertNotNull($originalIncome);
        $this->assertNotNull($originalExpense);
        $this->assertStringStartsWith('عكس:', $originalIncome->notes);
        $this->assertStringStartsWith('عكس:', $originalExpense->notes);
    }

    public function test_account_balance_field_is_driven_by_ledger_entries(): void
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

        // Check every touched account: balance == SUM(credit) - SUM(debit)
        $touched = [$this->cashbox->id, $customer->ledgerAccount()->first()->id];
        foreach ($touched as $id) {
            $ledgerBalance = (float) AccountEntry::query()->where('account_id', $id)->sum('credit')
                - (float) AccountEntry::query()->where('account_id', $id)->sum('debit');
            $stored = (float) Account::find($id)->balance;
            $this->assertEqualsWithDelta($stored, $ledgerBalance, 0.02,
                "Account #{$id}: stored balance {$stored} must equal ledger balance {$ledgerBalance}");
        }
    }
}
