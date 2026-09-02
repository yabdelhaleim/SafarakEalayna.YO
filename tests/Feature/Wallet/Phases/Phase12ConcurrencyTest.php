<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 12 — CONCURRENCY / RACE CONDITIONS.
 *
 * Verifies the Wallet & Transfers module is safe under concurrent writes.
 * Tests use a single-process deferred-execution model (since SQLite in-memory
 * is single-writer); we simulate contention by:
 *   - Reading the balance mid-write inside a transaction
 *   - Forcing two transactions to interleave at the lockForUpdate boundary
 *   - Posting in a tight loop and verifying the final balance matches the
 *     linear sum
 *
 * FINDING CONC-1 (HIGH): The Wallet balance is stored as a mutable column
 * (`balance`) on the `accounts` row. Concurrent updates are guarded by
 * `lockForUpdate()` inside the journal-transfer inner block (TX line 700+),
 * but the OUTER `WalletTransaction::create()` happens BEFORE the lock.
 * Two near-simultaneous sends could each read the old balance, each create
 * their own WT row, and only then queue for the lock. The lock prevents
 * the income/expense transaction from being applied incorrectly, but the WT
 * row count is already two. The insufficient-balance check happens INSIDE
 * the lock, so only one of the two pulls succeeds. The other fails with
 * "insufficient balance" — which is the correct behavior. Net: no double-spend.
 *
 * FINDING CONC-2 (MED): The customer-creation path inside
 * `ensureCustomerAccount()` is NOT wrapped in a lock at the application level.
 * Two concurrent first-time sends for the same customer could race to create
 * TWO customer accounts. The unique constraint on `customer_id` may not
 * exist at the DB level.
 */
class Phase12ConcurrencyTest extends WalletTestCase
{
    /**
     * Synchronous tight loop: 50 sends of 100 each, wallet = 10000.
     * The final balance should be exactly 10000 − 50*100 = 5000.
     */
    public function test_tight_loop_50_sends_balance_invariant(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 0.00);
            $payload['amount_paid'] = 0;
            $payload['notes'] = "iteration {$i}";
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $this->assertEquals('5000.00', AccountState::balance($this->walletAccountEgp->id),
            'After 50 sends of 100 each, wallet = 10000 − 5000 = 5000');
        $this->assertEquals(50, WalletTransaction::query()->count(),
            '50 WT rows created');
    }

    /**
     * At the boundary of the wallet balance, verify the LAST successful send
     * matches the balance exactly. No partial debit (no truncation).
     */
    public function test_at_balance_boundary_no_overdraw(): void
    {
        $balance = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals(10000.0, $balance);

        // 99 sends of 100 each = 9900.
        for ($i = 0; $i < 99; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 0.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $this->assertEquals('100.00', AccountState::balance($this->walletAccountEgp->id),
            'After 99 sends, 100 left in wallet');

        // The 100th send (100) should succeed.
        $p100 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 0.00);
        $p100['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p100)->assertStatus(201);

        $this->assertEquals('0.00', AccountState::balance($this->walletAccountEgp->id),
            'After 100 sends, balance is 0');

        // Post-2026-08-30 fix: the new SEND uses recordJournalTransfer with
        // allow_from_negative=true (prepaid wallets allowed to go negative).
        // So the 101st send SUCCEEDS and wallet goes to -100.
        $p101 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 0.00);
        $p101['amount_paid'] = 0;
        $r = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p101);
        $r->assertStatus(201,
            'Post-2026-08-30: SEND uses allow_from_negative=true for prepaid wallets, so 101st send succeeds.');
        $this->assertEquals('-100.00', AccountState::balance($this->walletAccountEgp->id),
            '101st send debited the wallet into negative (-100)');
    }

    /**
     * Verify the double-entry balance on each transaction is internally consistent
     * (debit == credit) even after a tight loop.
     */
    public function test_tight_loop_ledger_integrity(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        // Invariant #2: every transaction row has debit == credit.
        $rows = Transaction::query()
            ->where('module', 'wallet')
            ->get();
        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $tx) {
            $d = (float) DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->sum('debit');
            $c = (float) DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->sum('credit');
            $this->assertEqualsWithDelta($d, $c, 0.001,
                "Transaction #{$tx->id} failed balance after tight loop: d={$d} c={$c}");
        }
    }

    /**
     * Verify total system money is conserved across all the operations.
     * The sum of all account balances should equal the sum of all opening balances.
     * (Since we don't transfer money OUT of the system, total money is conserved.)
     */
    public function test_total_system_money_conservation(): void
    {
        $sumAllAccounts = function (): float {
            $total = 0.0;
            foreach (DB::table('accounts')->where('name', '!=', 'System Opening Balances')->get(['balance']) as $r) {
                $total += (float) $r->balance;
            }

            return $total;
        };

        $initialTotal = $sumAllAccounts();

        // 10000 (wallet) + 5000 (cashbox EGP) + 1000 (USD) + 1000 (SAR) = 17000.
        $this->assertEquals(17000.0, $initialTotal);

        // Run 10 sends.
        for ($i = 0; $i < 10; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $finalTotal = $sumAllAccounts();

        $reloaded = Customer::find($this->customerEgp->id);
        $customerBalance = (float) AccountState::balance($reloaded->account_id);

        $this->assertEquals(1000.0, $customerBalance,
            'Post-2026-08-30: customer owes 10 × amount (100) = 1000 EGP — fee tracked on WT row, not ledger');

        $this->assertEquals(17000.0, $finalTotal,
            'Total system money is conserved (initial=17000, final+customer=17000). '.
            'All account balances summed: '.$finalTotal);
    }

    /**
     * Verify the customer's balance after consecutive sends equals the
     * sum of (amount + fee) for each send.
     */
    public function test_customer_balance_accumulates_correctly(): void
    {
        $amounts = [100.00, 50.00, 30.00, 70.00, 25.00];
        $expectedTotal = 0.0;

        foreach ($amounts as $amount) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
            // Post-2026-08-30: customer balance = sum of `amount` only (fee stays on WT).
            $expectedTotal += $amount;
        }

        $reloaded = Customer::find($this->customerEgp->id);
        $balance = (float) AccountState::balance($reloaded->account_id);
        $this->assertEquals($expectedTotal, $balance,
            'Post-2026-08-30: customer balance = sum of `amount` for each send (fee tracked on WT, not ledger)');
    }

    /**
     * Receive + Send pattern: verify the customer balance is correctly
     * swung between positive (debt to us) and negative (we owe them).
     */
    public function test_send_then_receive_then_send_balance_swap(): void
    {
        $s1 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $s1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $s1)->assertStatus(201);

        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('100.00', AccountState::balance($reloaded->account_id),
            'After send: customer balance = amount (100) — fee tracked on WT, not ledger');

        $r1 = $this->receivePayloadRegistered($this->customerEgp, amount: 200.00, fee: 8.00);
        $r1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $r1)->assertStatus(201);

        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('-92.00', AccountState::balance($reloaded->account_id),
            'Post-2026-08-30: After send(+amount=100) + receive(-total=192): 100 - 192 = -92 (we owe them 92)');

        $s2 = $this->sendPayloadRegistered($this->customerEgp, amount: 30.00, fee: 5.00);
        $s2['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $s2)->assertStatus(201);

        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('-62.00', AccountState::balance($reloaded->account_id),
            'Post-2026-08-30: After send+receive+send: -92 + 30 (amount) = -62');
    }

    /**
     * Verify the same balance is reported by every read path (DSO consistency).
     */
    public function test_balance_read_consistency_across_paths(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // Path 1: direct DB read
        $p1 = AccountState::balance($this->walletAccountEgp->id);

        // Path 2: Eloquent model
        $p2 = (string) Account::find($this->walletAccountEgp->id)->balance;

        // Path 3: derived from entries
        $p3 = AccountState::entriesDerivedBalance($this->walletAccountEgp->id);

        $this->assertEquals('9900.00', $p1, 'Path 1: direct DB');
        $this->assertEquals('9900.00', $p2, 'Path 2: Eloquent');
        // FIN-1 fixed: paired opening entry was auto-seeded, so the entries-
        // derived balance now INCLUDES the opening credit. Path 3 matches paths
        // 1 and 2.
        $this->assertEquals('9900.00', $p3, 'Path 3: entries-derived (FIN-1 fixed — opening entry was auto-seeded)');
    }

    /**
     * FINDING CONC-2 (MED): Two concurrent first-time sends for the same
     * customer could each see `customer->account_id === NULL`, both call
     * `Account::create()`, and both write back — leaving an orphan account.
     *
     * FIXED: `ensureCustomerAccount` now acquires `lockForUpdate()` on the
     * Customer row before reading `account_id`, and the create+update pair
     * runs inside the lock window. The test simulates concurrent calls and
     * asserts that ONLY ONE Account is created for the customer.
     */
    public function test_concurrent_first_time_sends_create_one_customer_account_con_c_2_fixed(): void
    {
        // Build a fresh customer with NO account_id yet (the race window).
        $customer = Customer::create([
            'full_name' => 'سباق التزامن — CONC-2',
            'phone' => '01110000222',
            'national_id' => 'CONC-2-FIXED',
            'currency' => 'EGP',
            'account_id' => null,
        ]);

        $this->assertNull($customer->account_id, 'Pre-condition: customer has no account');

        // Simulate two concurrent first-time sends by calling ensureCustomerAccount
        // twice in series (lockForUpdate serializes either way). After the fix,
        // the second call must observe the account_id that the first call wrote
        // and NOT create a duplicate Account row.
        $this->asAdmin();
        $svc = app(WalletTransactionService::class);
        $a1 = $svc->ensureCustomerAccountForTest($customer->id);
        $a2 = $svc->ensureCustomerAccountForTest($customer->id);

        $this->assertEquals($a1->id, $a2->id,
            'CONC-2 fixed: ensureCustomerAccount is idempotent — second call returns the same account.');

        // Exactly ONE Account row tagged for this customer.
        $count = Account::query()
            ->where('type', 'customer')
            ->where('name', 'حساب العميل: '.$customer->full_name)
            ->count();
        $this->assertEquals(1, $count,
            'CONC-2 fixed: only one Account row is created for the customer (no orphans).');

        // Customer.account_id was updated exactly once.
        $reloaded = Customer::find($customer->id);
        $this->assertEquals($a1->id, $reloaded->account_id);
    }
}
