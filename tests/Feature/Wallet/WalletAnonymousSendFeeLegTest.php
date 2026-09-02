<?php

namespace Tests\Feature\Wallet;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * WLT-FEE-LEG (2026-09-03) — regression tests for the anonymous SEND
 * "phantom fee" bug.
 *
 * Background:
 *   Before the fix, postMainSendPair() posted a single transfer
 *   `wallet → cash` for `amount` only. The service_fee was recorded on
 *   the WT row but had NO ledger leg — the cash received 50 instead of 60,
 *   and the fee was a 'phantom' (missing from account_entries entirely).
 *   Treasury / cashbox totals disagreed with WT records by exactly the
 *   sum of all fees in the period.
 *
 *   After the fix:
 *     1. main_transfer: wallet → cash_account with `amount` (50)
 *        - Wallet provider debited by principal only, cash credited 50.
 *     2. fee_income:    recordIncome(fee=10) → cash_account
 *        - Cash credited 10, agency revenue (income clearing) debited 10.
 *
 *   Net effect on balances:
 *     - wallet_account: −amount  (unchanged from pre-fix behaviour)
 *     - cash_account:   +totalAmount  (= 50 + 10)
 *     - income_clearing: −fee  (the phantom is now on the books as revenue)
 *
 * These tests pin the expected behaviour for the walk-in (anonymous)
 * send path. The registered-customer path is covered by the existing
 * `test_send_updates_accounts_correctly` in WalletTransactionCrudTest —
 * its semantics (settlement through postSettlementSend) are unchanged.
 */
class WalletAnonymousSendFeeLegTest extends WalletTestCase
{
    /**
     * Invariant: a walk-in send with fee MUST credit the cashbox with
     * `totalAmount = amount + fee`, NOT just `amount`.
     *
     * Pre-fix bug: cashbox would only get +50; the +10 fee vanished.
     */
    public function test_walk_in_send_credits_cashbox_with_total_amount_not_just_amount(): void
    {
        $amount = 500.00;
        $fee = 10.00;
        $expectedTotal = $amount + $fee;  // 510.00

        $payload = $this->sendPayloadWalkIn(amount: $amount, fee: $fee);

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        // ── Invariant 1: wallet account debited by `amount` only ─────
        // Wallet provider should NOT be charged the fee — the customer pays
        // the fee on top of the send at the counter, but the wallet
        // provider (Vodafone, Etisalat, etc.) only debits the principal.
        $this->assertDatabaseHas('accounts', [
            'id' => $this->walletAccountEgp->id,
            'balance' => 10000.00 - $amount,
        ]);

        // ── Invariant 2: cashbox credited with FULL totalAmount ────────
        // This is the headline assertion. Pre-fix: cashbox got +500 only.
        // Post-fix: cashbox gets +510 (= 500 transfer + 10 fee income).
        $this->assertDatabaseHas('accounts', [
            'id' => $this->cashboxEgp->id,
            'balance' => 5000.00 + $expectedTotal,
        ]);
    }

    /**
     * Invariant: the WT transaction row must reference the income leg
     * via `income_transaction_id`. The fee-income transaction must exist
     * with type=Income or type=Transfer and amount=fee.
     */
    public function test_walk_in_send_creates_fee_income_leg(): void
    {
        $amount = 500.00;
        $fee = 10.00;

        $payload = $this->sendPayloadWalkIn(amount: $amount, fee: $fee);
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $wtId = $response->json('data.id');
        $this->assertNotNull($wtId);

        $wt = WalletTransaction::find($wtId);
        $this->assertNotNull($wt);
        $this->assertEquals('send', $wt->type->value);
        $this->assertNull($wt->customer_id, 'walk-in must have null customer_id');

        // The fee income leg MUST exist as a Transaction row.
        $this->assertNotNull(
            $wt->income_transaction_id,
            'walk-in send must have an income_transaction_id pointing at the fee income'
        );

        // Re-fetch from DB to avoid in-memory staleness.
        $wt = WalletTransaction::with('incomeTransaction', 'expenseTransaction')->find($wtId);
        $this->assertNotNull($wt->incomeTransaction, 'incomeTransaction relation must resolve');

        // The income leg's amount must be exactly the fee.
        $this->assertEqualsWithDelta(
            $fee,
            (float) $wt->incomeTransaction->amount,
            0.01,
            'fee-income transaction amount must equal the fee'
        );

        // The expense leg (main transfer) must be the wallet-debit transfer.
        $this->assertNotNull($wt->expenseTransaction);
        $this->assertEqualsWithDelta(
            $amount,
            (float) $wt->expenseTransaction->amount,
            0.01,
            'main transfer amount must equal the principal (no fee)'
        );

        // Both legs must credit the cashbox in their account_entries.
        $entriesOnCashbox = AccountEntry::where('account_id', $this->cashboxEgp->id)
            ->whereIn('transaction_id', [
                $wt->income_transaction_id,
                $wt->expense_transaction_id,
            ])
            ->get();

        $this->assertGreaterThanOrEqual(
            2,
            $entriesOnCashbox->count(),
            'cashbox must have 2+ entries (one credit per leg)'
        );

        // Sum of credits to the cashbox across the two legs MUST equal totalAmount.
        $totalCredits = (float) $entriesOnCashbox->sum('credit');
        $this->assertEqualsWithDelta(
            $amount + $fee,
            $totalCredits,
            0.01,
            'sum of cashbox credits across both legs must equal amount+fee (= 510). '.
            'Pre-fix bug: would only be 500 because the fee leg was missing.'
        );
    }

    /**
     * Invariant: when fee=0 (e.g. promo / friend referral), no separate
     * fee-income leg is created — only the main transfer exists. The
     * callers must still get a valid (income, expense) tuple.
     */
    public function test_walk_in_send_with_zero_fee_posts_main_transfer_only(): void
    {
        $amount = 250.00;
        $fee = 0.00;

        $payload = $this->sendPayloadWalkIn(amount: $amount, fee: $fee);
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $wt = WalletTransaction::find($response->json('data.id'));

        // Wallet debited by amount, cashbox credited by amount (no fee).
        $this->assertDatabaseHas('accounts', [
            'id' => $this->walletAccountEgp->id,
            'balance' => 10000.00 - $amount,
        ]);
        $this->assertDatabaseHas('accounts', [
            'id' => $this->cashboxEgp->id,
            'balance' => 5000.00 + $amount,  // +250, no fee
        ]);

        // The (income, expense) tuple still resolves (uses main transfer
        // for both, mirroring the original backward-compat contract).
        $this->assertNotNull($wt->income_transaction_id);
        $this->assertNotNull($wt->expense_transaction_id);

        // Only ONE transaction leg exists for this WT (no fee income).
        $relatedCount = DB::table('transactions')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $wt->id)
            ->count();
        $this->assertEquals(1, $relatedCount, 'zero-fee walk-in must produce exactly 1 leg');
    }
}
