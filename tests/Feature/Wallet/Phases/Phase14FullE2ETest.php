<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 14 — FULL E2E.
 *
 * End-to-end scenarios covering the complete lifecycle of wallet operations:
 *   - Customer registration → first transaction → statement → reversal
 *   - Multi-customer / multi-wallet flow
 *   - Daily summary aggregation across multiple transactions
 *   - Cross-account balance verification
 *   - Audit trail integrity
 *
 * These tests simulate real-world production usage patterns.
 */
class Phase14FullE2ETest extends WalletTestCase
{
    // ────────────── E2E-1: Customer journey ──────────────

    public function test_e2e_customer_journey_send_receive_delete(): void
    {
        // 1. Customer sends 100 EGP via wallet (registered customer, no settlement).
        $send = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $send['amount_paid'] = 0;
        $sendResp = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $send);
        $sendResp->assertStatus(201);
        $sendId = $sendResp->json('data.id');

        // 2. Show the transaction.
        $show = $this->asAdmin()->getJson("/api/v1/wallet/transactions/{$sendId}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $sendId)
            ->assertJsonPath('data.type', 'send');
        $this->assertEquals(100.0, (float) $show->json('data.amount'),
            'amount field must be 100.00');

        // 3. Daily summary should reflect the send.
        $today = now()->toDateString();
        $summary = $this->asAdmin()->getJson("/api/v1/wallet/transactions/daily-summary?date={$today}");
        $summary->assertStatus(200);
        $this->assertEquals(1, $summary->json('data.total_transactions'));

        // 4. Customer receives 50 EGP.
        $recv = $this->receivePayloadRegistered($this->customerEgp, amount: 50.00, fee: 2.00);
        $recv['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $recv)->assertStatus(201);

        // 5. Customer balance after send+receive.
        // Post-2026-08-30: SEND now credits the customer only `amount` (NOT amount+fee).
        // Pre-change:  +105 (amount+fee) - 48 (receive net amount-fee) = 57.
        // Post-change: +100 (amount only) - 48 (receive net amount-fee) = 52.
        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('52.00', AccountState::balance($reloaded->account_id),
            'Customer balance: 100 (send debt, amount only) + (-48) (we owe them) = 52');

        // 6. Wallet balance: 10000 - 100 + 50 = 9950.
        $this->assertEquals('9950.00', AccountState::balance($this->walletAccountEgp->id));

        // 7. Admin deletes the send.
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$sendId}")->assertStatus(200);

        // 8. After delete: wallet = 10000 - 100 + 50 + 100 = 10050 (send is reversed, receive still active).
        $this->assertEquals('10050.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet restored after delete reversal (10000 - 100 send + 50 receive + 100 reversal = 10050)');
        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('-48.00', AccountState::balance($reloaded->account_id),
            'Customer balance after deleting the send: only the receive remains = -48');
    }

    // ────────────── E2E-2: Multi-customer / multi-wallet flow ──────────────

    public function test_e2e_two_customers_independent_lifecycles(): void
    {
        // Customer 1 does 2 sends.
        $p1 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $p1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p1)->assertStatus(201);

        $p2 = $this->sendPayloadRegistered($this->customerEgp, amount: 50.00, fee: 2.00);
        $p2['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p2)->assertStatus(201);

        // Customer 2 does 1 receive.
        $r1 = $this->receivePayloadRegistered($this->customer2, amount: 200.00, fee: 8.00);
        $r1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $r1)->assertStatus(201);

        // Verify the balances.
        $c1 = Customer::find($this->customerEgp->id);
        $c2 = Customer::find($this->customer2->id);
        // Post-2026-08-30: SEND now credits the customer only `amount` per send
        // (pre-change credited amount+fee). C1 received 2 sends: 100 + 50 = 150
        // (was 105 + 52 = 157). C2 received 1 receive (unchanged behavior).
        $this->assertEquals('150.00', AccountState::balance($c1->account_id), 'C1: 100 + 50 = 150');
        $this->assertEquals('-192.00', AccountState::balance($c2->account_id), 'C2: -(200-8) = -192');

        // Wallet: 10000 - 100 - 50 + 200 = 10050.
        $this->assertEquals('10050.00', AccountState::balance($this->walletAccountEgp->id));

        // Daily summary: 3 transactions, 2 sends, 1 receive.
        $today = now()->toDateString();
        $summary = $this->asAdmin()->getJson("/api/v1/wallet/transactions/daily-summary?date={$today}");
        $summary->assertStatus(200);
        $this->assertEquals(3, $summary->json('data.total_transactions'));
        $this->assertEquals(2, $summary->json('data.send_count'));
        $this->assertEquals(1, $summary->json('data.receive_count'));
    }

    // ────────────── E2E-3: Walk-in customer flow ──────────────

    public function test_e2e_walk_in_customer_cash_collected(): void
    {
        $payload = $this->sendPayloadWalkIn(amount: 1000.00, fee: 25.00);
        $payload['amount_paid'] = 0;  // walk-in path doesn't use amount_paid

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // Walk-in: cashbox receives `amount` (not total_amount) under the new behavior.
        // Post-2026-08-30: walk-in SEND posts a single journal transfer wallet → cashbox
        // for `amount` only (1000). The fee (25) stays on the WT row and surfaces
        // to P&L via settlement — not as an immediate cashbox credit at SEND time.
        // Pre-change cashbox balance: 5000 + (1000 + 25) = 6025.00.
        // Post-change cashbox balance: 5000 + 1000 = 6000.00.
        $this->assertEquals('6000.00', AccountState::balance($this->cashboxEgp->id),
            'Walk-in send: cashbox receives 1000 (amount only, fee stays on WT row)');
        $this->assertEquals('9000.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet -1000');
    }

    // ────────────── E2E-4: Multi-currency E2E ──────────────

    public function test_e2e_three_currencies_independent(): void
    {
        // EGP send.
        $egp = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $egp['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $egp)->assertStatus(201);

        // After 1 send: wallet=9900, cashbox=5000, USD=1000, SAR=1000.
        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id));
        $this->assertEquals('5000.00', AccountState::balance($this->cashboxEgp->id));
        $this->assertEquals('1000.00', AccountState::balance($this->cashboxUsd->id));
        $this->assertEquals('1000.00', AccountState::balance($this->cashboxSar->id));

        // Customer balance: 100 (amount only).
        // Post-2026-08-30: registered SEND credits customer only `amount`.
        // Pre-change: customer received amount+fee = 100 + 5 = 105.
        // Post-change: customer receives amount only = 100 (fee stays on WT row).
        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('100.00', AccountState::balance($reloaded->account_id));
    }

    // ────────────── E2E-5: Reconciliation ──────────────

    public function test_e2e_ledger_entries_match_wallet_balance(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // The wallet account has entries tied to its transactions.
        $walletEntries = AccountEntry::query()
            ->where('account_id', $this->walletAccountEgp->id)
            ->get();

        $this->assertGreaterThan(0, $walletEntries->count(),
            'Wallet account must have ledger entries');

        // Each entry has a balance_after (cumulative running balance).
        $lastEntry = $walletEntries->sortByDesc('id')->first();
        $this->assertNotNull($lastEntry->balance_after,
            'Every AccountEntry must have a balance_after');

        // The last entry's balance_after should match the current account balance.
        $this->assertEquals(
            $lastEntry->balance_after,
            AccountState::balance($this->walletAccountEgp->id),
            'balance_after in the last entry must match the current account balance'
        );
    }

    // ────────────── E2E-6: Audit trail completeness ──────────────

    public function test_e2e_audit_logs_complete_lifecycle(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        $auditLogs = DB::table('audit_logs')
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $auditLogs->count(),
            'Audit trail must have at least 2 entries (created + deleted)');

        $actions = $auditLogs->pluck('action')->toArray();
        $this->assertContains('wallet_transaction.created', $actions,
            'Audit log must record a created action');
        $this->assertContains('wallet_transaction.deleted', $actions,
            'Audit log must record a deleted action');
    }

    // ────────────── E2E-7: Full ledger reproducibility ──────────────

    public function test_e2e_replay_all_ledger_transactions_reproduces_balance(): void
    {
        // Run 5 sends.
        for ($i = 0; $i < 5; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        // Read each AccountEntry and validate the journal ledger still tracks it.
        $entries = AccountEntry::query()
            ->where('account_id', $this->walletAccountEgp->id)
            ->where('transaction_id', '!=', null)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThan(0, $entries->count());

        // Sum all credits and debits on the wallet.
        $totalCredit = $entries->sum('credit');
        $totalDebit = $entries->sum('debit');
        $netChange = $totalCredit - $totalDebit;

        // Wallet had 5 sends of 100 = 500 net debit.
        $this->assertEquals(0.0, (float) $totalCredit,
            'Wallet has 0 credits (no incoming funds via wallet-to-self)');
        $this->assertEquals(500.0, (float) $totalDebit,
            'Wallet has 500 debits from 5 sends of 100');
        $this->assertEquals(-500.0, (float) $netChange,
            'Net change on wallet is -500');
    }

    // ────────────── E2E-8: Statement endpoint ──────────────

    public function test_e2e_customer_statement_returns_transactions(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $reloaded = Customer::find($this->customerEgp->id);
        $response = $this->asAdmin()->getJson("/api/v1/wallet/customer-statement?client_id={$reloaded->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data['transactions'] ?? [],
            'Statement must return the recent transaction');
        // Post-2026-08-30: SEND now credits the customer only `amount` (100), not
        // amount+fee (105). The fee stays on the WT row and surfaces later via
        // settlement — not as an immediate customer-account credit at SEND time.
        $this->assertEquals(100.0, $data['running_balance'] ?? 0.0,
            'Statement running balance = 100 (amount only, fee stays on WT row)');
    }

    // ────────────── E2E-9: Customer balances endpoint ──────────────

    public function test_e2e_customer_balances_returns_aggregated(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $response = $this->asAdmin()->getJson('/api/v1/wallet/customer-balances');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data, 'customer-balances must return an array');
        $this->assertGreaterThan(0, count($data),
            'customer-balances must include the customer who has a transaction');
    }
}
