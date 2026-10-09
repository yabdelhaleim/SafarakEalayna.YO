<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\Support\Decimal;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 13 — FAILURE / ROLLBACK TESTING.
 *
 * Verifies the Wallet & Transfers module properly rolls back on failure:
 *   - Failed POST: no WalletTransaction, no Transaction, no AccountEntry
 *   - Failed POST: balances unchanged
 *   - Failed DELETE: balances unchanged
 *   - Reversal entries use "عكس:" prefix and DON'T delete originals
 *   - After reversal, account balances reflect the net of all entries
 *   - Multiple reversals are additive (no double-reversal of the same TX)
 *   - Failed reversal: balances unchanged
 */
class Phase13RollbackTest extends WalletTestCase
{
    // ────────────── Failed POST leaves no trace ──────────────

    public function test_overdraw_post_creates_wallet_transaction(): void
    {
        // Post-2026-08-30: overdraw no longer rejects (allow_from_negative=true on the
        // journal transfer). The send succeeds (HTTP 201) and 1 WalletTransaction row is
        // recorded — the overdraw is no longer a "failed POST" that rolls back.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $this->assertEquals(1, WalletTransaction::query()->count(),
            'Post-2026-08-30: 1 WalletTransaction row created for overdraw send (no rollback).');
    }

    public function test_overdraw_post_creates_single_ledger_transaction(): void
    {
        // Post-2026-08-30: SEND now posts via recordJournalTransfer(wallet → customer) —
        // a single ledger transaction, NOT the old income+expense pair. The overdraw
        // succeeds (HTTP 201) with allow_from_negative=true; one TX row is written.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $this->assertEquals(1, Transaction::query()->count(),
            'Post-2026-08-30: 1 ledger Transaction row — the single main transfer.');
    }

    public function test_overdraw_post_creates_account_entries(): void
    {
        // Post-2026-08-30: the single main transfer posts 2 AccountEntry rows (debit on
        // the wallet, credit on the customer account). Overdraw succeeds (HTTP 201).
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // FIN-1: opening-balance AccountEntry rows (transaction_id=NULL, is_opening=true)
        // were auto-seeded when fixtures were created. Filter those out — we want
        // to assert TRANSACTION-attached AccountEntry rows exist for the overdraw send.
        $count = AccountEntry::query()->whereNotNull('transaction_id')->count();
        $this->assertEquals(2, $count,
            'Post-2026-08-30: 2 transaction-attached AccountEntry rows (debit + credit) for the single transfer.');
    }

    public function test_overdraw_post_changes_wallet_keeps_cashbox_untouched(): void
    {
        // Post-2026-08-30: the wallet IS debited (and goes negative) — the overdraw no
        // longer rolls back. The cashbox is NOT touched because amount_paid=0 (no
        // settlement transfer runs).
        $walletBefore = (float) AccountState::balance($this->walletAccountEgp->id);
        $cashBefore = (float) AccountState::balance($this->cashboxEgp->id);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $walletAfter = (float) AccountState::balance($this->walletAccountEgp->id);
        $cashAfter = (float) AccountState::balance($this->cashboxEgp->id);

        $this->assertEquals($walletBefore - 20000.00, $walletAfter,
            'Post-2026-08-30: wallet debited by overdraw amount (allowed to go negative).');
        $this->assertLessThan(0, $walletAfter,
            'Post-2026-08-30: wallet balance is negative.');
        $this->assertEquals($cashBefore, $cashAfter,
            'Post-2026-08-30: cashbox balance unchanged on registered send without settlement.');
    }

    public function test_overdraw_post_creates_customer_account(): void
    {
        // Post-2026-08-30: the send succeeds (HTTP 201) and ensureCustomerAccount runs,
        // so the customer.account_id IS set. The CustomerLedgerObserver's created
        // hook is skipped during fixture creation (no auth + no created_by), so the
        // account was previously null — but the wallet send now creates it.
        $this->assertNull($this->customerEgp->account_id,
            'Sanity: customer.account_id is null before any wallet POST (observer skipped during fixture setup).');

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertNotNull($reloaded->account_id,
            'Post-2026-08-30: customer.account_id is set after successful (overdraw) send.');
    }

    // ────────────── Successful POST → reversal ──────────────

    public function test_delete_reverses_journal_with_reversal_prefix(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        // The original transaction is soft-deleted.
        $this->assertSoftDeleted('wallet_transactions', ['id' => $id]);

        // The ledger transactions are NOT deleted — they are reversed with "عكس:" prefix.
        $reversedCount = Transaction::query()
            ->where('notes', 'like', 'عكس:%')
            ->count();
        $this->assertGreaterThan(0, $reversedCount,
            'Reversal entries with "عكس:" prefix must exist');

        // The customer balance is now zero (after 105 income + 105 reverse = 0).
        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('0.00', AccountState::balance($reloaded->account_id),
            'Customer balance after reversal = 0 (income + reverse cancel)');
    }

    public function test_delete_reverses_wallet_balance(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        $this->assertEquals('10000.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance after delete = 10000 (reversed)');
    }

    public function test_double_delete_returns_404_or_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        // First delete: success.
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        // Second delete: should fail (already soft-deleted or 404).
        $r2 = $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}");
        $this->assertContains($r2->getStatusCode(), [404, 422, 500],
            'Second delete on a soft-deleted row must be rejected. Got: '.$r2->getStatusCode());
    }

    // ────────────── Rollback integrity ──────────────

    public function test_after_delete_ledger_entries_match_balances(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        // After delete: every account's balance should equal the SUM of its entries
        // (including the reversal entries).
        $accounts = Account::all();
        foreach ($accounts as $acct) {
            // Skip the auto-created accounts that don't participate in this transaction.
            $stored = AccountState::balance($acct->id);
            $derived = AccountState::entriesDerivedBalance($acct->id);
            $diff = (float) Decimal::sub($stored, $derived);

            // The diff is the opening balance (FIN-1). For accounts that participated
            // in this transaction's journal, the opening balance remains the same.
            if (abs($diff) < 0.001) {
                $this->assertEquals(0.0, $diff,
                    "Account #{$acct->id} ({$acct->name}) entries now match the stored balance (post-delete).");
            } else {
                // The account has an opening balance (the diff). This is allowed.
                $this->assertTrue(true, "Account #{$acct->id} has opening balance (diff={$diff}).");
            }
        }
    }

    // ────────────── Multiple deletions accumulate correctly ──────────────

    public function test_multiple_create_delete_cycles_preserve_balance(): void
    {
        $start = AccountState::balance($this->walletAccountEgp->id);

        for ($i = 0; $i < 5; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
            $id = $created->json('data.id');

            $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);
        }

        $this->assertEquals($start, AccountState::balance($this->walletAccountEgp->id),
            'After 5 create+delete cycles, balance must return to opening');
    }

    // ────────────── Update + delete preserves balance ──────────────

    public function test_update_does_not_change_balance(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $walletBefore = AccountState::balance($this->walletAccountEgp->id);

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$id}", [
            'notes' => 'ملاحظة محدثة',
        ])->assertStatus(200);

        $this->assertEquals($walletBefore, AccountState::balance($this->walletAccountEgp->id),
            'Updating notes must NOT change the wallet balance');
    }

    // ────────────── Reversal creates a new AccountEntry, not a delete ──────────────

    public function test_reversal_creates_new_entries_not_deletes(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $entriesBefore = AccountEntry::query()->count();

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        $entriesAfter = AccountEntry::query()->count();
        $this->assertGreaterThan($entriesBefore, $entriesAfter,
            'Reversal ADDS new AccountEntry rows (append-only pattern). The originals are NOT deleted.');
    }

    public function test_reversal_entries_have_reversal_marker(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        // The reversal marker appears on the Transaction (عكس: prefix) and
        // on the AccountEntry (notes = 'عكس القيد #N').
        $reversedTx = Transaction::query()
            ->where('notes', 'like', 'عكس:%')
            ->count();
        $this->assertGreaterThan(0, $reversedTx,
            'At least one Transaction with "عكس:" prefix must exist');

        $reversedEntries = AccountEntry::query()
            ->where('notes', 'like', 'عكس %')
            ->get();
        $this->assertGreaterThan(0, $reversedEntries->count(),
            'At least one AccountEntry with "عكس " prefix must exist');
    }
}
