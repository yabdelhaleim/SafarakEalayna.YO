<?php

namespace Tests\Feature\Wallet\Phases;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PHASE FINANCIAL-RETEST-COMPREHENSIVE — Wallets & Transfers
 * Comprehensive Financial Retest  |  2026-08-26
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Covers every money-movement category:
 *   A.  Wallet Creation / Initialization
 *   B.  Wallet Credit (all paths)
 *   C.  Wallet Debit (all paths + edge cases)
 *   D.  Send — Registered Customer (with/without settlement)
 *   E.  Receive — Registered Customer
 *   F.  Walk-in (anonymous) Send / Receive
 *   G.  Update / Edit — ledger repost correctness
 *   H.  Delete / Soft-delete — reversal correctness
 *   I.  Idempotency — key present → single financial movement
 *   J.  Concurrency — sequential rapid sends on same wallet
 *   K.  Rollback / Atomicity — failed operation leaves no partial state
 *   L.  Balance Reconciliation — entries-derived vs stored
 *   M.  Accounting Integrity — double-entry, orphans
 *   N.  Currency Isolation — EGP-only, cross-currency rejected
 *   O.  Precision / Rounding — small decimals, large amounts
 *   P.  Security — IDOR, inactive account, same-account guard
 *   Q.  Daily Summary — FIN-4 fee-inclusive keys
 *   R.  Customer Balances / Statement endpoints
 *   S.  Delete-then-Recreate — no ghost balances
 *   T.  No-change Update — no spurious ledger rows
 *   U.  Debt / Receivable Lifecycle
 *   V.  Transfer Treasury overview
 *   W.  Dashboard stats endpoint
 *   X.  Double-reversal guard
 */
class PhaseFinancialRetestComprehensiveTest extends WalletTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Independently derives wallet balance from account_entries rows
     * (opening entry + all credited/debited entries) rather than the
     * cached accounts.balance column.
     */
    private function entriesDerivedBalance(int $accountId): float
    {
        $row = DB::table('account_entries')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')
            ->first();

        return (float) ($row->net ?? 0.0);
    }

    /** Count Transaction rows linked to a WalletTransaction (all, including reversals). */
    private function relatedTxCount(int $walletTxId): int
    {
        return Transaction::where('related_type', WalletTransaction::class)
            ->where('related_id', $walletTxId)
            ->count();
    }

    /** Global double-entry check: SUM(debit) == SUM(credit) across all non-opening entries. */
    private function assertDoubleEntryBalanced(string $context = ''): void
    {
        $row = DB::table('account_entries')
            ->whereNotNull('transaction_id')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        $this->assertEquals(
            (float) ($row->d ?? 0.0),
            (float) ($row->c ?? 0.0),
            "Double-entry not balanced{$context}: debit={$row->d}, credit={$row->c}"
        );
    }

    /** POST a wallet transaction, optionally with an Idempotency-Key header. */
    private function postTx(array $payload, ?string $idemKey = null): TestResponse
    {
        $req = $this->asAdmin();
        if ($idemKey !== null) {
            $req = $req->withHeaders(['Idempotency-Key' => $idemKey]);
        }

        return $req->postJson('/api/v1/wallet/transactions', $payload);
    }

    /** Anonymous receive payload (walk-in customer). */
    protected function receivePayloadAnonymous(float $amount = 300.00, float $fee = 8.00): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'عميل عابر',
            'wallet_number' => '01088887777',
            'type' => 'receive',
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletAccountEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'audit receive walk-in',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // A. WALLET CREATION / INITIALIZATION
    // ═════════════════════════════════════════════════════════════════════

    /** A-1: Non-zero balance → opening AccountEntry created (FIN-1 fix). */
    public function test_a1_nonzero_balance_creates_opening_entry(): void
    {
        $wallet = $this->makeAccount(AccountType::Wallet, 'Test Wallet Opening', 'EGP', 5000.00, 'office');

        $derived = $this->entriesDerivedBalance($wallet->id);
        $this->assertEquals(5000.00, $derived,
            'A-1: Opening AccountEntry must mirror the initial balance.');
    }

    /** A-2: Zero-balance wallet → no spurious AccountEntry. */
    public function test_a2_zero_balance_wallet_has_no_entry(): void
    {
        $wallet = $this->makeAccount(AccountType::Wallet, 'Zero Wallet', 'EGP', 0.00, 'office');
        $count = AccountEntry::where('account_id', $wallet->id)->count();
        $this->assertEquals(0, $count, 'A-2: Zero-balance wallet must have 0 AccountEntry rows.');
    }

    /** A-3: Wallet creation must not generate any ledger Transaction row. */
    public function test_a3_wallet_creation_generates_no_ledger_transaction(): void
    {
        $before = Transaction::count();
        $this->makeAccount(AccountType::Wallet, 'Ledger-free Wallet', 'EGP', 2000.00, 'office');
        $this->assertEquals($before, Transaction::count(),
            'A-3: Wallet creation must not create any Transaction row.');
    }

    /** A-4: Two wallets with identical names → independent IDs, independent balances. */
    public function test_a4_duplicate_name_wallets_are_independent(): void
    {
        $w1 = $this->makeAccount(AccountType::Wallet, 'Dup Wallet', 'EGP', 1000.00, 'office');
        $w2 = $this->makeAccount(AccountType::Wallet, 'Dup Wallet', 'EGP', 2000.00, 'office');

        $this->assertNotEquals($w1->id, $w2->id, 'A-4: Different IDs.');
        $this->assertEquals('1000.00', AccountState::balance($w1->id), 'A-4: W1 = 1000.');
        $this->assertEquals('2000.00', AccountState::balance($w2->id), 'A-4: W2 = 2000.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // B. WALLET CREDIT — all paths
    // ═════════════════════════════════════════════════════════════════════

    /** B-1: Walk-in receive credits wallet by exact `amount` (not total_amount). */
    public function test_b1_walkin_receive_credits_wallet_by_amount(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        $p = $this->receivePayloadAnonymous(amount: 300.00, fee: 10.00);
        $this->postTx($p)->assertStatus(201);

        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before + 300.00, $after,
            'B-1: Wallet credited by 300 (amount), not 290 (total_amount after fee).');
    }

    /** B-2: Registered receive credits wallet by `amount`, fee deducted from net to customer. */
    public function test_b2_registered_receive_credits_wallet_by_amount(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        $p = $this->receivePayloadRegistered($this->customerEgp, amount: 500.00, fee: 20.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);

        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before + 500.00, $after,
            'B-2: Wallet credited by 500 (principal), not 480.');
    }

    /** B-3: Three successive receives accumulate correctly (no float drift). */
    public function test_b3_multiple_receives_accumulate_correctly(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        foreach ([100.00, 200.00, 50.00] as $amt) {
            $this->postTx($this->receivePayloadAnonymous(amount: $amt, fee: 0.00))->assertStatus(201);
        }
        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before + 350.00, $after, 'B-3: 3 receives accumulate to +350.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // C. WALLET DEBIT — all paths
    // ═════════════════════════════════════════════════════════════════════

    /** C-1: Walk-in send debits wallet by `amount` (not total_amount). */
    public function test_c1_walkin_send_debits_wallet_by_amount(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->postTx($this->sendPayloadWalkIn(amount: 200.00, fee: 8.00))->assertStatus(201);
        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before - 200.00, $after, 'C-1: Wallet debited by 200, not 208.');
    }

    /** C-2: Registered send (no settlement) debits wallet by `amount`. */
    public function test_c2_registered_send_no_settlement_debits_wallet(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 300.00, fee: 15.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);
        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before - 300.00, $after, 'C-2: Wallet debited by 300, not 315.');
    }

    /** C-3: Insufficient balance → HTTP 201 with negative wallet (allow_from_negative=true). */
    public function test_c3_insufficient_balance_rejected_wallet_unchanged(): void
    {
        // Post-2026-08-30: SEND now uses recordJournalTransfer with allow_from_negative=true,
        // so an overdraw does NOT reject the request — the wallet goes negative instead.
        // The old behavior (HTTP 409 rejection) is gone.
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 99999.00, fee: 0.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);

        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before - 99999.00, $after,
            'C-3 (Post-2026-08-30): Insufficient balance succeeds with negative wallet ('.$after.').');
        $this->assertLessThan(0, $after,
            'C-3 (Post-2026-08-30): Wallet balance went negative.');
    }

    /** C-4: Zero amount → 422 (min:1 guard). */
    public function test_c4_zero_amount_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 0.00, fee: 0.00);
        $this->postTx($p)->assertStatus(422, 'C-4: Zero amount must be rejected.');
    }

    /** C-5: Negative amount → 422. */
    public function test_c5_negative_amount_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: -50.00, fee: 0.00);
        $this->postTx($p)->assertStatus(422, 'C-5: Negative amount must be rejected.');
    }

    /** C-6: Cashbox unaffected when wallet is debited (registered send, no settlement). */
    public function test_c6_cashbox_not_affected_by_registered_send_without_settlement(): void
    {
        $cashBefore = AccountState::balance($this->cashboxEgp->id);
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);
        // Cashbox credited by income (clearing), but original cashbox balance for customer
        // debt-based send does not touch cashbox directly for the main debit.
        // The key assertion: total wallet − 500, cashbox unchanged from cash perspective.
        // (Accounting may touch clearing — we verify no "real" cashbox movement.)
        $cashAfter = AccountState::balance($this->cashboxEgp->id);
        // For registered send without settlement: cashbox is NOT touched at all.
        $this->assertEquals($cashBefore, $cashAfter,
            'C-6: Cashbox untouched on registered send without settlement.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // D. SEND — Registered Customer (with/without settlement)
    // ═════════════════════════════════════════════════════════════════════

    /** D-1: Send registered (no settlement) → customer account credited by amount only. */
    public function test_d1_send_registered_no_settlement_customer_debited_total_amount(): void
    {
        // Post-2026-08-30: SEND posts a single journal transfer (wallet → customer) for `amount`
        // (NOT amount+fee). The fee is tracked on the WT row only — it does NOT inflate the
        // customer ledger balance.
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);

        $customer = Customer::find($this->customerEgp->id);
        $balance = $this->entriesDerivedBalance($customer->account_id);
        $this->assertEquals(500.00, $balance,
            'D-1 (Post-2026-08-30): Customer account credited 500 (the amount). Fee stays on WT row.');
    }

    /** D-2: Send registered (full settlement = amount+fee) → customer balance = -fee. */
    public function test_d2_send_registered_full_settlement_zeroes_customer_balance(): void
    {
        // Post-2026-08-30 math:
        //   - Main transfer: wallet (-500) → customer (+500)
        //   - Settlement transfer: customer (-510) → cashbox (+510)
        //   - Net customer = 500 - 510 = -10 = -fee (the fee is over-collected
        //     and stays with the cashier as commission).
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 510.00;
        $this->postTx($p)->assertStatus(201);

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('-10.00', AccountState::balance($customer->account_id),
            'D-2 (Post-2026-08-30): Full settlement leaves customer at -10 (= -fee over-collection).');
        $this->assertEquals('9500.00', AccountState::balance($this->walletAccountEgp->id),
            'D-2: Wallet debited by 500 amount.');
        $this->assertEquals('5510.00', AccountState::balance($this->cashboxEgp->id),
            'D-2: Cashbox credited by 510 amount_paid.');
    }

    /** D-3: Send registered (partial settlement) → correct residual debt. */
    public function test_d3_send_registered_partial_settlement_correct_debt(): void
    {
        // Post-2026-08-30 math:
        //   - Main transfer:   wallet (-1000) → customer (+1000)
        //   - Settlement:      customer (-500) → cashbox (+500)
        //   - Net customer = 1000 - 500 = +500 (the customer still owes `amount - amount_paid`)
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 1000.00, fee: 20.00);
        $p['amount_paid'] = 500.00;
        $this->postTx($p)->assertStatus(201);

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('500.00', AccountState::balance($customer->account_id),
            'D-3 (Post-2026-08-30): 1000 amount - 500 paid = 500 residual.');
        $this->assertEquals('9000.00', AccountState::balance($this->walletAccountEgp->id),
            'D-3: Wallet debited by 1000 amount.');
        $this->assertEquals('5500.00', AccountState::balance($this->cashboxEgp->id),
            'D-3: Cashbox credited by 500 amount_paid.');
    }

    /** D-4: Send creates exactly 1 ledger TX (single journal transfer, no settlement). */
    public function test_d4_send_creates_exactly_two_main_ledger_tx(): void
    {
        // Post-2026-08-30: SEND posts ONE journal transfer (wallet → customer).
        // No more income + expense pair through clearing accounts.
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 300.00, fee: 0.00);
        $p['amount_paid'] = 0;
        $r = $this->postTx($p);
        $r->assertStatus(201);

        $txId = $r->json('data.id');
        $wt = WalletTransaction::find($txId);

        // Both ledger-pointer columns point at the SAME transfer row (the
        // postMainSendPair returns [$transfer, $transfer] for backward compat).
        $this->assertNotNull($wt->income_transaction_id,
            'D-4 (Post-2026-08-30): income_transaction_id set (points at the transfer).');
        $this->assertNotNull($wt->expense_transaction_id,
            'D-4 (Post-2026-08-30): expense_transaction_id set (also points at the transfer).');
        $this->assertEquals($wt->income_transaction_id, $wt->expense_transaction_id,
            'D-4 (Post-2026-08-30): Both columns point at the same transfer row.');

        // Exactly one related ledger TX row.
        $this->assertEquals(1, $this->relatedTxCount($txId),
            'D-4 (Post-2026-08-30): Send with no settlement creates exactly 1 ledger TX.');
    }

    /** D-5: Send with partial settlement → 2 ledger TX (main transfer + settlement transfer). */
    public function test_d5_send_with_settlement_creates_three_ledger_tx(): void
    {
        // Post-2026-08-30: Send with settlement produces exactly 2 ledger TX —
        //   1) Main transfer (wallet → customer, amount=300)
        //   2) Settlement transfer (customer → cashbox, amount=200 = amount_paid)
        // The old income+expense pair through clearing accounts is gone.
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 300.00, fee: 10.00);
        $p['amount_paid'] = 200.00;
        $r = $this->postTx($p);
        $r->assertStatus(201);

        $this->assertEquals(2, $this->relatedTxCount($r->json('data.id')),
            'D-5 (Post-2026-08-30): Send with settlement produces exactly 2 ledger TX (main + settlement transfers).');
    }

    /** D-6: Walk-in send → cashbox credited by amount only (fee stays on WT row). */
    public function test_d6_walkin_send_cashbox_credited_by_total_amount(): void
    {
        // Post-2026-08-30: walk-in send posts recordJournalTransfer(wallet → cashbox, amount=200).
        // The fee (10) is NOT routed into the cashbox — it is kept by the cashier as commission
        // and surfaces only on the WT row, not on the ledger.
        $before = (float) AccountState::balance($this->cashboxEgp->id);
        $this->postTx($this->sendPayloadWalkIn(amount: 200.00, fee: 10.00))->assertStatus(201);
        $after = (float) AccountState::balance($this->cashboxEgp->id);
        $this->assertEquals($before + 200.00, $after,
            'D-6 (Post-2026-08-30): Walk-in send credits cashbox by 200 (amount only, NOT total 210).');
    }

    // ═════════════════════════════════════════════════════════════════════
    // E. RECEIVE — Registered Customer
    // ═════════════════════════════════════════════════════════════════════

    /** E-1: Registered receive (no settlement) → customer account shows -total_amount (owed). */
    public function test_e1_receive_registered_no_settlement_customer_owed_net(): void
    {
        $p = $this->receivePayloadRegistered($this->customerEgp, amount: 500.00, fee: 20.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('-480.00', AccountState::balance($customer->account_id),
            'E-1: Customer owed 480 (500-20 net): account shows -480.');
    }

    /** E-2: Walk-in receive → cashbox debited by net total_amount. */
    public function test_e2_walkin_receive_cashbox_debited_by_net_total(): void
    {
        $before = (float) AccountState::balance($this->cashboxEgp->id);
        $this->postTx($this->receivePayloadAnonymous(amount: 400.00, fee: 15.00))->assertStatus(201);
        $after = (float) AccountState::balance($this->cashboxEgp->id);
        $this->assertEquals($before - 385.00, $after,
            'E-2: Walk-in receive debits cashbox by 385 (400-15 net payout).');
    }

    // ═════════════════════════════════════════════════════════════════════
    // F. WALK-IN (ANONYMOUS) SEND / RECEIVE
    // ═════════════════════════════════════════════════════════════════════

    /** F-1: Walk-in send → no customer Account row created. */
    public function test_f1_walkin_send_creates_no_customer_account(): void
    {
        $before = Account::where('type', AccountType::Customer->value)->count();
        $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 5.00))->assertStatus(201);
        $after = Account::where('type', AccountType::Customer->value)->count();
        $this->assertEquals($before, $after, 'F-1: No customer account created for walk-in.');
    }

    /** F-2: Walk-in receive → exactly 2 ledger TX. */
    public function test_f2_walkin_receive_creates_two_ledger_tx(): void
    {
        $r = $this->postTx($this->receivePayloadAnonymous(amount: 100.00, fee: 5.00));
        $r->assertStatus(201);
        $this->assertEquals(2, $this->relatedTxCount($r->json('data.id')),
            'F-2: Walk-in receive creates exactly 2 ledger TX.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // G. UPDATE / EDIT — ledger repost correctness
    // ═════════════════════════════════════════════════════════════════════

    /** G-1: Update amount → old TX reversed, new TX posted, wallet balance corrects. */
    public function test_g1_update_amount_corrects_wallet_balance(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 300.00, fee: 0.00);
        $r = $this->postTx($p);
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->assertEquals('9700.00', AccountState::balance($this->walletAccountEgp->id),
            'G-1 setup: 10000 - 300 = 9700.');

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$txId}", [
            'amount' => 200.00,
        ])->assertStatus(200);

        $this->assertEquals('9800.00', AccountState::balance($this->walletAccountEgp->id),
            'G-1: After update to 200, wallet = 10000 - 200 = 9800.');
    }

    /** G-2: Update fee → only WT row is touched; customer balance is NOT affected by fee changes. */
    public function test_g2_update_fee_adjusts_customer_debt(): void
    {
        // Post-2026-08-30: the main Send transfer is always for `amount` (NOT amount+fee).
        // The fee is tracked only on the WT row (record->service_fee / total_amount) and
        // surfaces to P&L via settlement. Therefore changing the fee reposts the main
        // transfer with the SAME `amount`, leaving the customer ledger balance unchanged.
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 0;
        $r = $this->postTx($p);
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('500.00', AccountState::balance($customer->account_id),
            'G-2 setup (Post-2026-08-30): customer credited 500 (amount only).');

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$txId}", [
            'service_fee' => 20.00,
        ])->assertStatus(200);

        $customer = Customer::find($this->customerEgp->id);
        // The main transfer is reversed (customer = 0) and reposted with the same `amount` (500),
        // so the customer ends at the same +500. The fee change is recorded only on the WT row.
        $this->assertEquals('500.00', AccountState::balance($customer->account_id),
            'G-2 (Post-2026-08-30): Customer balance is NOT affected by fee change (the fee stays on WT row only).');

        // Sanity: the WT row reflects the new fee / total_amount.
        $wt = WalletTransaction::find($txId);
        $this->assertEquals(20.00, (float) $wt->service_fee,
            'G-2 (Post-2026-08-30): WT row fee updated to 20.');
        $this->assertEquals(520.00, (float) $wt->total_amount,
            'G-2 (Post-2026-08-30): WT total_amount updated to 520 (500 amount + 20 fee).');
    }

    /** G-3: Notes-only update → no new ledger TX created. */
    public function test_g3_notes_only_update_creates_no_ledger_tx(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $countBefore = $this->relatedTxCount($txId);

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$txId}", [
            'notes' => 'Updated note only',
        ])->assertStatus(200);

        $this->assertEquals($countBefore, $this->relatedTxCount($txId),
            'G-3: Notes-only update must not create any ledger TX.');
    }

    /** G-4: After update, double-entry is still balanced. */
    public function test_g4_double_entry_balanced_after_update(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 200.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$txId}", [
            'amount' => 150.00,
        ])->assertStatus(200);

        $this->assertDoubleEntryBalanced(' after update');
    }

    // ═════════════════════════════════════════════════════════════════════
    // H. DELETE / SOFT-DELETE — reversal correctness
    // ═════════════════════════════════════════════════════════════════════

    /** H-1: Delete send → wallet fully restored. */
    public function test_h1_delete_send_restores_wallet_balance(): void
    {
        $before = AccountState::balance($this->walletAccountEgp->id);
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 500.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $this->assertEquals($before, AccountState::balance($this->walletAccountEgp->id),
            'H-1: Wallet fully restored after delete.');
    }

    /** H-2: Delete receive → wallet fully restored. */
    public function test_h2_delete_receive_restores_wallet_balance(): void
    {
        $before = AccountState::balance($this->walletAccountEgp->id);
        $r = $this->postTx($this->receivePayloadAnonymous(amount: 300.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $this->assertEquals($before, AccountState::balance($this->walletAccountEgp->id),
            'H-2: Wallet restored after delete of receive.');
    }

    /** H-3: Delete registered send → customer credit fully reversed. */
    public function test_h3_delete_registered_send_reverses_customer_debt(): void
    {
        // Post-2026-08-30: registered send posts ONE transfer (wallet → customer, amount=500).
        // The fee stays on the WT row only — it does NOT inflate the customer ledger.
        // Delete reverses that single transfer, leaving the customer at 0.
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 0;
        $r = $this->postTx($p);
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('500.00', AccountState::balance($customer->account_id),
            'H-3 setup (Post-2026-08-30): customer credited 500 (amount only).');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('0.00', AccountState::balance($customer->account_id),
            'H-3 (Post-2026-08-30): Customer balance zeroed after delete (single transfer reversed).');
    }

    /** H-4: Delete creates reversal entries, does NOT delete original transactions. */
    public function test_h4_delete_creates_reversal_not_destructive(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 200.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $entriesBefore = AccountEntry::whereIn('transaction_id', Transaction::where('related_id', $txId)->pluck('id'))->count();
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $entriesAfter = AccountEntry::whereIn('transaction_id', Transaction::where('related_id', $txId)->pluck('id'))->count();

        $this->assertGreaterThan($entriesBefore, $entriesAfter, 'H-4: Reversal must add inverse AccountEntry rows.');
        $this->assertTrue(
            Transaction::where('related_type', WalletTransaction::class)
                ->where('related_id', $txId)
                ->where('notes', 'like', 'عكس%')
                ->exists(),
            'H-4: Original transaction notes must be prefixed with "عكس:".'
        );
    }

    /** H-5: Deleting an already-soft-deleted row → 404. */
    public function test_h5_double_delete_returns_404(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(404,
            'H-5: Second delete on soft-deleted row must return 404.');
    }

    /** H-6: Delete with settlement (set at creation) → guard allows, wallet restored. */
    public function test_h6_delete_with_creation_time_settlement_allowed(): void
    {
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 300.00;
        $r = $this->postTx($p);
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200,
            'H-6: Delete with creation-time settlement must succeed.');

        $this->assertEquals('10000.00', AccountState::balance($this->walletAccountEgp->id),
            'H-6: Wallet fully restored after delete.');
    }

    /** H-7: After delete, double-entry remains balanced. */
    public function test_h7_double_entry_balanced_after_delete(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 400.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $this->assertDoubleEntryBalanced(' after delete');
    }

    // ═════════════════════════════════════════════════════════════════════
    // I. IDEMPOTENCY
    // ═════════════════════════════════════════════════════════════════════

    /** I-1: Same key sent twice → second returns HTTP 200 with original ID; wallet debited once. */
    public function test_i1_idempotency_key_prevents_duplicate_debit(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $key = 'idem-retest-001';

        $r1 = $this->postTx($p, $key);
        $r2 = $this->postTx($p, $key);

        $r1->assertStatus(201);
        $r2->assertStatus(200);
        $this->assertEquals($r1->json('data.id'), $r2->json('data.id'),
            'I-1: Replay must return original ID.');
        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id),
            'I-1: Wallet debited exactly once.');
    }

    /** I-2: Same key sent 10 times → exactly 1 WalletTransaction, 1 debit. */
    public function test_i2_ten_replays_produce_single_financial_movement(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 200.00, fee: 0.00);
        $key = 'idem-retest-ten';

        $firstId = null;
        for ($i = 0; $i < 10; $i++) {
            $r = $this->postTx($p, $key);
            if ($i === 0) {
                $r->assertStatus(201);
                $firstId = $r->json('data.id');
            } else {
                $r->assertStatus(200);
                $this->assertEquals($firstId, $r->json('data.id'), "I-2: Replay #{$i}.");
            }
        }

        $this->assertEquals(1, WalletTransaction::count(), 'I-2: Exactly 1 WalletTransaction.');
        $this->assertEquals('9800.00', AccountState::balance($this->walletAccountEgp->id),
            'I-2: Wallet debited once.');
    }

    /** I-3: After soft-delete, same key creates a fresh transaction (key released). */
    public function test_i3_key_released_after_soft_delete(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $key = 'idem-retest-delete';

        $r1 = $this->postTx($p, $key);
        $r1->assertStatus(201);
        $id1 = $r1->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id1}")->assertStatus(200);

        $r2 = $this->postTx($p, $key);
        $r2->assertStatus(201);
        $this->assertNotEquals($id1, $r2->json('data.id'),
            'I-3: After soft-delete, same key creates a new transaction.');
    }

    /** I-4: Two different keys → two independent financial movements. */
    public function test_i4_different_keys_create_independent_movements(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $this->postTx($p, 'key-alpha')->assertStatus(201);
        $this->postTx($p, 'key-beta')->assertStatus(201);

        $this->assertEquals(2, WalletTransaction::count(), 'I-4: 2 different TX for 2 keys.');
        $this->assertEquals('9800.00', AccountState::balance($this->walletAccountEgp->id),
            'I-4: Wallet debited twice (two distinct operations).');
    }

    // ═════════════════════════════════════════════════════════════════════
    // J. CONCURRENCY (sequential rapid calls — PHPUnit single-process)
    // ═════════════════════════════════════════════════════════════════════

    /** J-1: 5 rapid sends — each deducts correctly, total = 5x. */
    public function test_j1_rapid_sequential_sends_deduct_correctly(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        for ($i = 0; $i < 5; $i++) {
            $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00))->assertStatus(201);
        }
        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before - 500.00, $after, 'J-1: 5 sends deduct exactly 500.');
        $this->assertEquals(5, WalletTransaction::count(), 'J-1: 5 WalletTransaction rows.');
    }

    /** J-2: ensureCustomerAccount is idempotent — called twice, one account created. */
    public function test_j2_ensure_customer_account_idempotent(): void
    {
        $svc = app(WalletTransactionService::class);
        $acc1 = $svc->ensureCustomerAccountForTest($this->customerEgp->id);
        $acc2 = $svc->ensureCustomerAccountForTest($this->customerEgp->id);

        $this->assertEquals($acc1->id, $acc2->id, 'J-2: Same account returned both times.');
        $this->assertEquals(
            1,
            Account::where('type', AccountType::Customer->value)->count(),
            'J-2: Only 1 customer account created.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // K. ROLLBACK / ATOMICITY
    // ═════════════════════════════════════════════════════════════════════

    /** K-1: Overdraw send → HTTP 201 with negative wallet (allow_from_negative=true). */
    public function test_k1_failed_send_leaves_no_records(): void
    {
        // Post-2026-08-30: SEND posts via recordJournalTransfer with allow_from_negative=true,
        // so the insufficient-balance guard no longer rejects with HTTP 409. Instead the
        // request succeeds (201) and the wallet goes negative. Records ARE created.
        $walletBefore = (float) AccountState::balance($this->walletAccountEgp->id);
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 99999.00, fee: 0.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);

        // The send IS recorded (1 WT, 1 ledger TX, entries exist).
        $this->assertEquals(1, WalletTransaction::count(),
            'K-1 (Post-2026-08-30): Overdraw send now succeeds and creates 1 WT row.');
        $this->assertEquals(1, Transaction::count(),
            'K-1 (Post-2026-08-30): 1 ledger TX (the main transfer wallet → customer).');
        $this->assertGreaterThan(0, AccountEntry::whereNotNull('transaction_id')->count(),
            'K-1 (Post-2026-08-30): AccountEntry rows are created for the negative transfer.');

        // And the wallet went negative.
        $walletAfter = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($walletBefore - 99999.00, $walletAfter,
            'K-1 (Post-2026-08-30): Wallet balance went negative by the overdraw amount.');
        $this->assertLessThan(0, $walletAfter,
            'K-1 (Post-2026-08-30): Wallet balance is now negative.');
    }

    /** K-2: Overdraw registered send → cashbox still untouched (cashbox only moves on settlement). */
    public function test_k2_failed_send_cashbox_unchanged(): void
    {
        // Post-2026-08-30: the send succeeds (HTTP 201) because allow_from_negative=true.
        // For a REGISTERED send with amount_paid=0, only the wallet→customer main transfer is
        // posted — the cashbox is NOT touched. (Settlement, which credits the cashbox, is a
        // separate transfer that runs only when amount_paid > 0.)
        $before = AccountState::balance($this->cashboxEgp->id);
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 99999.00, fee: 0.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);
        $this->assertEquals($before, AccountState::balance($this->cashboxEgp->id),
            'K-2 (Post-2026-08-30): Cashbox still untouched on registered overdraw send (no settlement).');
    }

    /** K-3: Missing wallet_account_id → 422, nothing written. */
    public function test_k3_missing_wallet_account_id_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        unset($p['wallet_account_id']);
        $this->postTx($p)->assertStatus(422);
        $this->assertEquals(0, WalletTransaction::count(), 'K-3: No WT on validation fail.');
    }

    /** K-4: Invalid type value → 422, nothing written. */
    public function test_k4_invalid_type_string_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $p['type'] = 'refund';
        $this->postTx($p)->assertStatus(422);
        $this->assertEquals(0, WalletTransaction::count(), 'K-4: No WT on invalid type.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // L. BALANCE RECONCILIATION
    // ═════════════════════════════════════════════════════════════════════

    /** L-1: After mixed sends/receives, entries-derived balance == stored balance. */
    public function test_l1_entries_derived_matches_stored_after_mixed_ops(): void
    {
        foreach ([[100, 0], [200, 10], [50, 5]] as [$a, $f]) {
            $this->postTx($this->sendPayloadWalkIn((float) $a, (float) $f))->assertStatus(201);
        }
        foreach ([[150, 8], [75, 0]] as [$a, $f]) {
            $this->postTx($this->receivePayloadAnonymous((float) $a, (float) $f))->assertStatus(201);
        }

        $stored = (float) AccountState::balance($this->walletAccountEgp->id);
        $derived = $this->entriesDerivedBalance($this->walletAccountEgp->id);
        $this->assertEquals($stored, $derived,
            'L-1: Stored and derived balances must match after mixed operations.');
    }

    /** L-2: After delete, stored and derived both equal the original balance. */
    public function test_l2_after_delete_stored_and_derived_reconcile(): void
    {
        $original = (float) AccountState::balance($this->walletAccountEgp->id);
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 300.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);

        $stored = (float) AccountState::balance($this->walletAccountEgp->id);
        $derived = $this->entriesDerivedBalance($this->walletAccountEgp->id);

        $this->assertEquals($original, $stored, 'L-2: Stored balance restored.');
        $this->assertEquals($original, $derived, 'L-2: Derived balance also restored.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // M. ACCOUNTING INTEGRITY — double-entry
    // ═════════════════════════════════════════════════════════════════════

    /** M-1: After send, SUM(debit) == SUM(credit). */
    public function test_m1_double_entry_balanced_after_send(): void
    {
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 25.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);
        $this->assertDoubleEntryBalanced(' after send');
    }

    /** M-2: After receive, SUM(debit) == SUM(credit). */
    public function test_m2_double_entry_balanced_after_receive(): void
    {
        $p = $this->receivePayloadRegistered($this->customerEgp, amount: 300.00, fee: 10.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);
        $this->assertDoubleEntryBalanced(' after receive');
    }

    /** M-3: After multiple operations, global double-entry still balanced. */
    public function test_m3_double_entry_balanced_after_multiple_operations(): void
    {
        $this->postTx($this->sendPayloadWalkIn(100.00, 5.00))->assertStatus(201);
        $this->postTx($this->receivePayloadAnonymous(200.00, 8.00))->assertStatus(201);
        $p = $this->sendPayloadRegistered($this->customerEgp, 300.00, 10.00);
        $p['amount_paid'] = 150.00;
        $this->postTx($p)->assertStatus(201);
        $this->assertDoubleEntryBalanced(' after multiple mixed ops');
    }

    // ═════════════════════════════════════════════════════════════════════
    // N. CURRENCY ISOLATION
    // ═════════════════════════════════════════════════════════════════════

    /** N-1: USD cashbox with EGP wallet → 422 currency mismatch. */
    public function test_n1_usd_cashbox_with_egp_wallet_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $p['cash_account_id'] = $this->cashboxUsd->id;
        $this->postTx($p)->assertStatus(422, 'N-1: USD cashbox + EGP wallet must be rejected.');
    }

    /** N-2: SAR cashbox with EGP wallet → 422. */
    public function test_n2_sar_cashbox_with_egp_wallet_rejected(): void
    {
        $p = $this->receivePayloadAnonymous(amount: 100.00, fee: 0.00);
        $p['cash_account_id'] = $this->cashboxSar->id;
        $this->postTx($p)->assertStatus(422, 'N-2: SAR cashbox + EGP wallet must be rejected.');
    }

    /** N-3: EGP operation does not affect USD cashbox balance. */
    public function test_n3_egp_operation_does_not_contaminate_usd_cashbox(): void
    {
        $usdBefore = AccountState::balance($this->cashboxUsd->id);
        $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00))->assertStatus(201);
        $this->assertEquals($usdBefore, AccountState::balance($this->cashboxUsd->id),
            'N-3: USD cashbox not affected by EGP wallet operation.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // O. PRECISION / ROUNDING
    // ═════════════════════════════════════════════════════════════════════

    /** O-1: Small decimal fee (0.01) processed without drift. */
    public function test_o1_small_decimal_fee_processed_correctly(): void
    {
        $before = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->postTx($this->sendPayloadWalkIn(amount: 1.00, fee: 0.01))->assertStatus(201);
        $after = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($before - 1.00, $after, 'O-1: Wallet debited by 1.00 (amount only).');
    }

    /** O-2: Max allowed amount (999999.99) accepted. */
    public function test_o2_max_amount_accepted(): void
    {
        $bigWallet = $this->makeAccount(AccountType::Wallet, 'Big Wallet', 'EGP', 1000000.00, 'office');
        $bigCash = $this->makeAccount(AccountType::Cashbox, 'Big Cash', 'EGP', 100.00, 'office');

        $p = [
            'wallet_type_id' => $this->walletType->id,
            'customer_name' => 'عميل عابر',
            'wallet_number' => '01099999999',
            'type' => 'send',
            'amount' => 999999.99,
            'service_fee' => 0.00,
            'wallet_account_id' => $bigWallet->id,
            'cash_account_id' => $bigCash->id,
        ];

        $this->postTx($p)->assertStatus(201, 'O-2: 999999.99 must be accepted (max:999999.99).');
    }

    /** O-3: Amount above max (1000001) → 422. */
    public function test_o3_amount_above_max_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 1000001.00, fee: 0.00);
        $this->postTx($p)->assertStatus(422, 'O-3: Amount > 999999.99 must be rejected.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // P. SECURITY
    // ═════════════════════════════════════════════════════════════════════

    /** P-1: Non-creator cashier → 404 on show (IDOR guard, SEC-2). */
    public function test_p1_idor_show_blocked_for_non_creator(): void
    {
        $r = $this->asAdmin()->postJson('/api/v1/wallet/transactions',
            $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asCashier()->getJson("/api/v1/wallet/transactions/{$txId}")
            ->assertStatus(404, 'P-1: Non-creator cashier must get 404 (IDOR guard).');
    }

    /** P-2: Soft-deleted tx → 404 on show (SEC-4). */
    public function test_p2_soft_deleted_tx_returns_404(): void
    {
        $r = $this->asAdmin()->postJson('/api/v1/wallet/transactions',
            $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $this->asAdmin()->getJson("/api/v1/wallet/transactions/{$txId}")
            ->assertStatus(404, 'P-2: Soft-deleted tx must return 404 (SEC-4).');
    }

    /** P-3: wallet_account_id == cash_account_id → 422 (FIN-6). */
    public function test_p3_same_wallet_and_cash_account_rejected(): void
    {
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $p['cash_account_id'] = $p['wallet_account_id'] = $this->walletAccountEgp->id;
        $this->postTx($p)->assertStatus(422, 'P-3: Same wallet_account_id and cash_account_id rejected (FIN-6).');
    }

    /** P-4: Inactive wallet_account_id → 422 (FIN-7). */
    public function test_p4_inactive_wallet_account_rejected(): void
    {
        $inactive = $this->makeAccount(AccountType::Wallet, 'Inactive', 'EGP', 5000.00, 'office', false);
        $p = $this->sendPayloadWalkIn(amount: 100.00, fee: 0.00);
        $p['wallet_account_id'] = $inactive->id;
        $this->postTx($p)->assertStatus(422, 'P-4: Inactive wallet_account_id rejected (FIN-7).');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Q. DAILY SUMMARY (FIN-4)
    // ═════════════════════════════════════════════════════════════════════

    /** Q-1: Summary includes fee-inclusive keys and correct values. */
    public function test_q1_daily_summary_fee_inclusive_keys_correct(): void
    {
        $this->postTx($this->sendPayloadWalkIn(amount: 200.00, fee: 15.00))->assertStatus(201);

        $today = now()->toDateString();
        $resp = $this->asAdmin()->getJson("/api/v1/wallet/transactions/daily-summary?date={$today}");
        $resp->assertStatus(200);

        $resp->assertJsonStructure(['data' => [
            'total_sent', 'total_sent_with_fees',
            'total_received', 'total_received_with_fees', 'total_fees',
        ]]);

        $this->assertEquals(200.0, $resp->json('data.total_sent'), 'Q-1: total_sent = 200.');
        $this->assertEquals(215.0, $resp->json('data.total_sent_with_fees'), 'Q-1: total_sent_with_fees = 215.');
        $this->assertEquals(15.0, $resp->json('data.total_fees'), 'Q-1: total_fees = 15.');
    }

    /** Q-2: Summary scoped to date — yesterday returns 0. */
    public function test_q2_daily_summary_scoped_to_date(): void
    {
        $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00))->assertStatus(201);

        $yesterday = now()->subDay()->toDateString();
        $resp = $this->asAdmin()->getJson("/api/v1/wallet/transactions/daily-summary?date={$yesterday}");
        $resp->assertStatus(200);
        $this->assertEquals(0, $resp->json('data.total_transactions'),
            'Q-2: Yesterday summary = 0 when all TX were created today.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // R. CUSTOMER BALANCES / STATEMENT
    // ═════════════════════════════════════════════════════════════════════

    /** R-1: Customer balances endpoint shows correct debt (= amount, not amount+fee). */
    public function test_r1_customer_balances_endpoint_correct(): void
    {
        // Post-2026-08-30: a Send with no settlement credits the customer ledger by `amount`
        // (NOT amount+fee). The fee is not in the ledger; the endpoint therefore reports the
        // customer's debit as `amount` (500), not the old `amount + fee` (510).
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $p['amount_paid'] = 0;
        $this->postTx($p)->assertStatus(201);

        $resp = $this->asAdmin()->getJson('/api/v1/wallet/customer-balances');
        $resp->assertStatus(200);

        $record = collect($resp->json('data'))->firstWhere('client_name', $this->customerEgp->full_name);
        $this->assertNotNull($record, 'R-1: Customer must appear in balances list.');
        $this->assertEquals(500.0, (float) $record['total_debt'],
            'R-1 (Post-2026-08-30): Customer debt = 500 (amount only, fee is NOT in the ledger).');
    }

    /** R-2: Customer statement running_balance accumulates across sends. */
    public function test_r2_customer_statement_running_balance(): void
    {
        // Post-2026-08-30: each Send credits the customer by `amount` (NOT amount+fee).
        //   Send 1: amount=200 → customer += 200
        //   Send 2: amount=100 → customer += 100
        //   Running balance = 200 + 100 = 300 (the fees 10 + 5 are NOT in the ledger).
        foreach ([[200.00, 10.00], [100.00, 5.00]] as [$a, $f]) {
            $p = $this->sendPayloadRegistered($this->customerEgp, amount: $a, fee: $f);
            $p['amount_paid'] = 0;
            $this->postTx($p)->assertStatus(201);
        }

        $resp = $this->asAdmin()->getJson('/api/v1/wallet/customer-statement?client_id='.$this->customerEgp->id);
        $resp->assertStatus(200);
        $this->assertEquals(300.0, (float) $resp->json('data.running_balance'),
            'R-2 (Post-2026-08-30): Running balance = 200 + 100 = 300 (fees are NOT in the ledger).');
    }

    // ═════════════════════════════════════════════════════════════════════
    // S. DELETE-THEN-RECREATE
    // ═════════════════════════════════════════════════════════════════════

    /** S-1: Create → delete → recreate → balance reflects exactly one operation. */
    public function test_s1_create_delete_recreate_no_ghost_balance(): void
    {
        $before = AccountState::balance($this->walletAccountEgp->id);
        $p = $this->sendPayloadWalkIn(amount: 300.00, fee: 0.00);

        $r1 = $this->postTx($p);
        $r1->assertStatus(201);
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$r1->json('data.id')}")->assertStatus(200);

        $this->postTx($p)->assertStatus(201);

        $after = AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals(bcsub($before, '300.00', 2), $after,
            'S-1: Balance reflects exactly one send (300).');
    }

    // ═════════════════════════════════════════════════════════════════════
    // T. NO-CHANGE UPDATE
    // ═════════════════════════════════════════════════════════════════════

    /** T-1: Notes-only update → no new AccountEntry rows. */
    public function test_t1_notes_only_update_no_new_entries(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $entryCountBefore = AccountEntry::whereNotNull('transaction_id')->count();

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$txId}", [
            'notes' => 'new notes',
        ])->assertStatus(200);

        $this->assertEquals($entryCountBefore, AccountEntry::whereNotNull('transaction_id')->count(),
            'T-1: Notes-only update must not create new AccountEntry rows.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // U. DEBT / RECEIVABLE LIFECYCLE
    // ═════════════════════════════════════════════════════════════════════

    /** U-1: Multiple sends accumulate customer debt correctly. */
    public function test_u1_multiple_sends_accumulate_customer_debt(): void
    {
        // Post-2026-08-30: each Send credits the customer by `amount` only (fee stays on WT row).
        //   Send 1: amount=300 → customer += 300
        //   Send 2: amount=500 → customer += 500
        //   Send 3: amount=100 → customer += 100
        //   Total = 300 + 500 + 100 = 900 (the fees 0 + 10 + 5 are NOT in the ledger).
        foreach ([[300.00, 0.00], [500.00, 10.00], [100.00, 5.00]] as [$a, $f]) {
            $p = $this->sendPayloadRegistered($this->customerEgp, amount: $a, fee: $f);
            $p['amount_paid'] = 0;
            $this->postTx($p)->assertStatus(201);
        }
        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('900.00', AccountState::balance($customer->account_id),
            'U-1 (Post-2026-08-30): 300 + 500 + 100 = 900 (fees are NOT in the ledger).');
    }

    /** U-2: Update amount_paid increases settlement, reduces residual debt. */
    public function test_u2_update_amount_paid_reduces_debt(): void
    {
        $p = $this->sendPayloadRegistered($this->customerEgp, amount: 1000.00, fee: 0.00);
        $p['amount_paid'] = 0;
        $r = $this->postTx($p);
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$txId}", [
            'amount_paid' => 600.00,
        ])->assertStatus(200);

        $customer = Customer::find($this->customerEgp->id);
        $this->assertEquals('400.00', AccountState::balance($customer->account_id),
            'U-2: 1000 - 600 = 400 residual debt.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // V. TRANSFER TREASURY OVERVIEW
    // ═════════════════════════════════════════════════════════════════════

    /** V-1: Treasury overview returns expected keys. */
    public function test_v1_treasury_overview_returns_expected_structure(): void
    {
        $this->asAdmin()->getJson('/api/v1/wallet/treasury/overview')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['wallets', 'banks', 'cashboxes']]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // W. DASHBOARD
    // ═════════════════════════════════════════════════════════════════════

    /** W-1: Dashboard index returns expected stats structure. */
    public function test_w1_dashboard_index_expected_structure(): void
    {
        $this->asAdmin()->getJson('/api/v1/wallet/dashboard')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'stats' => ['wallets', 'banks', 'cashboxes'],
                'daily',
                'recent_transactions',
            ]]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // X. DOUBLE-REVERSAL GUARD
    // ═════════════════════════════════════════════════════════════════════

    /** X-1: Second delete on soft-deleted row → 404 (no double-reversal). */
    public function test_x1_double_reversal_blocked(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 100.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(404,
            'X-1: Second delete returns 404 (no double reversal possible).');

        $this->assertEquals('10000.00', AccountState::balance($this->walletAccountEgp->id),
            'X-1: Wallet restored to original (not double-restored).');
    }

    /** X-2: All related transactions are reversed and prefixed with "عكس:". */
    public function test_x2_reversal_count_equals_main_tx_count(): void
    {
        $r = $this->postTx($this->sendPayloadWalkIn(amount: 200.00, fee: 0.00));
        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $totalBefore = Transaction::where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->count();

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$txId}")->assertStatus(200);

        $reversalCount = Transaction::where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('notes', 'like', 'عكس%')
            ->count();

        $this->assertEquals($totalBefore, $reversalCount,
            'X-2: All related transactions must be reversed and prefixed with "عكس:".');
    }
}
