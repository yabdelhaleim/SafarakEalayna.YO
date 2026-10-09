<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\Support\Decimal;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 15 — RECONCILIATION.
 *
 * Verifies consistency between:
 *   - Account.balance (the running balance used by the application)
 *   - SUM(credit) - SUM(debit) on account_entries (the ledger-derived balance)
 *   - The double-entry invariant (debit == credit per transaction)
 *   - Treasury mirror (cashbox + wallet sums = total operating capital)
 *   - Global money conservation (sum of all accounts)
 *
 * FINDING REC-1: The project documents
 *   `Account.balance = SUM(credit) - SUM(debit)`
 * but does NOT honor this for accounts created with non-zero balance — no
 * opening-balance AccountEntry is created. Reconciliation between
 * `accounts.balance` and `SUM(credit-debit)` will always report the opening
 * balance as a phantom delta. (FIN-1)
 *
 * The reconciliation MUST use the formula
 *   `expected_balance = opening_balance + SUM(credit - debit)`
 * for it to be useful.
 */
class Phase15ReconciliationTest extends WalletTestCase
{
    // ────────────── Opening balance reconciliation ──────────────

    public function test_reconciliation_detects_fi_n_1_opening_balance_gap(): void
    {
        // FIN-1 FIXED: the wallet account opening balance is now seeded
        // as a paired AccountEntry (CREDIT on wallet + paired DEBIT on the
        // singleton "System Opening Balances" contra) by Account::created
        // boot hook. Therefore `stored == derived == opening balance`
        // and the gap is zero.
        $stored = AccountState::balance($this->walletAccountEgp->id);
        $derived = AccountState::entriesDerivedBalance($this->walletAccountEgp->id);

        // The reconciliation detects NO gap — the opening entry was auto-seeded.
        $this->assertEquals('10000.00', $stored, 'Stored balance = opening balance');
        $this->assertEquals('10000.00', $derived, 'Derived balance = opening credit (auto-seeded by FIN-1 fix)');

        // The gap is exactly zero.
        $gap = (float) Decimal::sub($stored, $derived);
        $opening = (float) $this->walletAccountEgp->balance;
        $this->assertEquals(0.0, $gap,
            'FIN-1 fixed: stored - derived = 0. The opening-balance gap is closed because the paired opening AccountEntry was auto-seeded.');
        $this->assertEquals($stored, $derived,
            'FIN-1 fixed: stored == derived for a freshly-loaded account.');
        $this->assertEquals($opening, $gap + $opening,
            'Sanity: gap + opening = opening (gap is 0).');
    }

    // ────────────── Post-ledger reconciliation ──────────────

    public function test_reconciliation_after_posting_includes_opening_balance(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $stored = AccountState::balance($this->walletAccountEgp->id);
        $derived = AccountState::entriesDerivedBalance($this->walletAccountEgp->id);

        // FIN-1 fixed: the opening-balance AccountEntry was auto-seeded, so
        // `derived` ALREADY INCLUDES the opening credit. Therefore
        // `stored == derived` directly (no need to add opening separately).
        $this->assertEquals((float) $stored, (float) $derived,
            "FIN-1 fixed: stored ($stored) == entries-derived ($derived) — opening entry auto-seeded.");
    }

    // ────────────── Per-transaction double-entry ──────────────

    public function test_every_transaction_has_balanced_entries(): void
    {
        // Post a few transactions.
        for ($i = 0; $i < 5; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $violations = [];
        foreach (Transaction::query()->get() as $tx) {
            $d = (float) AccountEntry::query()
                ->where('transaction_id', $tx->id)
                ->sum('debit');
            $c = (float) AccountEntry::query()
                ->where('transaction_id', $tx->id)
                ->sum('credit');
            if (abs($d - $c) > 0.001) {
                $violations[] = "TX #{$tx->id}: d={$d} c={$c}";
            }
        }

        $this->assertEmpty($violations,
            'Every transaction must have SUM(debit) == SUM(credit). Violations: '.implode(', ', $violations));
    }

    // ────────────── Module-scoped reconciliation ──────────────

    public function test_wallet_module_only_reconciliation(): void
    {
        // Snapshot starting balances for each account (pre-test ledger state).
        $startBalances = [];
        foreach (DB::table('accounts')->get(['id', 'balance']) as $r) {
            $startBalances[(int) $r->id] = (float) $r->balance;
        }

        for ($i = 0; $i < 3; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        // Sum wallet-module entries per account (excludes opening entries because
        // opening entries have transaction_id = NULL and the inner join drops them).
        $rows = DB::table('account_entries as ae')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where('t.module', 'wallet')
            ->groupBy('ae.account_id')
            ->selectRaw('ae.account_id, SUM(ae.credit) - SUM(ae.debit) as net')
            ->get();

        $this->assertGreaterThan(0, $rows->count(), 'Wallet-module entries must exist');

        // For each account that participated in the wallet module, the net change
        // from ledger entries should match the change in `account.balance` from
        // the start of the test. The opening-balance entry (transaction_id = NULL)
        // is excluded from this join but already baked into the starting balance,
        // so we compare against balance-DELTA, not against the absolute derived balance.
        foreach ($rows as $row) {
            $accountId = (int) $row->account_id;
            $currentBalance = (float) DB::table('accounts')->where('id', $accountId)->value('balance');
            $balanceDelta = $currentBalance - ($startBalances[$accountId] ?? 0.0);
            $this->assertEquals(
                (float) $row->net,
                $balanceDelta,
                "Account #{$accountId}: net from ledger entries ({$row->net}) should equal balance-delta ({$balanceDelta})."
            );
        }
    }

    // ────────────── Total system money conservation ──────────────

    public function test_total_system_money_remains_conserved(): void
    {
        $sumAccounts = function (): float {
            $total = 0.0;
            foreach (DB::table('accounts')->get(['balance']) as $r) {
                $total += (float) $r->balance;
            }

            return $total;
        };

        $initial = $sumAccounts();

        // 10 sends.
        for ($i = 0; $i < 10; $i++) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $final = $sumAccounts();
        $this->assertEquals($initial, $final,
            'Total system money is conserved (initial='.$initial.', final='.$final.')');
    }

    // ────────────── Reversal reconciliation ──────────────

    public function test_reversal_brings_balance_back_to_opening(): void
    {
        $start = AccountState::balance($this->walletAccountEgp->id);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        $this->assertEquals($start, AccountState::balance($this->walletAccountEgp->id),
            'Balance after post+delete = opening balance');
    }

    // ────────────── Reconciliation helper script ──────────────

    /**
     * Reconciliation report — sums all visible account balances, sums all
     * entries-derived balances, then asserts they match. With FIN-1 fixed
     * (paired opening entry auto-seeded on Account::created), the invariant
     * `SUM(stored) == SUM(derived)` holds directly for every "real" account
     * because each one is backed by exactly one opening-credit AccountEntry.
     *
     * The System Opening Balances contra account is excluded from this sum
     * because it is an internal bookkeeping anchor (its balance is always 0
     * and its derived balance is the negative of total opening balances —
     * by design the two cancel).
     */
    public function test_reconciliation_report_matches_opening_balance_sum(): void
    {
        $totalStored = 0.0;
        $totalDerived = 0.0;

        foreach (DB::table('accounts')->get() as $row) {
            if ($row->name === 'System Opening Balances') {
                continue; // internal contra anchor — excluded from system-wide reconciliation
            }
            $totalStored += (float) $row->balance;
            $totalDerived += (float) AccountState::entriesDerivedBalance($row->id);
        }

        // FIN-1 fixed: SUM(stored) == SUM(derived) directly (excluding internal contra).
        $this->assertEquals($totalDerived, $totalStored,
            'FIN-1 fixed: SUM(stored) == SUM(derived) (excluding System Opening Balances contra). '.
            'stored='.$totalStored.' derived='.$totalDerived
        );
    }
}
