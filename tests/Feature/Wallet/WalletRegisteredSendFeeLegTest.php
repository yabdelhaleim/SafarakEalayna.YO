<?php

namespace Tests\Feature\Wallet;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * WLT-FEE-LEG-REG (2026-09-03) — regression tests for the registered-customer
 * SEND fee-as-debt bug.
 *
 * Background:
 *   Before this fix, registered-customer SENDs posted only ONE transfer
 *   `wallet → customer_account` for `amount`. The settlement
 *   `customer_account → cash` then moved the FULL `amount_paid`
 *   (= amount + fee) out of the customer account, leaving it at -fee
 *   (a payable / مديونية, per Account.php:64-75 sign convention).
 *
 *   After this fix (WLT-FEE-LEG-REG):
 *     1. main_transfer: wallet → customer_account with `amount` (100)
 *        - Wallet provider debited by principal only.
 *        - Customer account credited by principal only (debt = `amount`).
 *     2. fee_income:    recordIncome(fee=10) → cash_account
 *        - Cash credited 10, agency revenue (income clearing) debited 10.
 *     3. settlement:    customer_account → cash with `min(amount_paid, amount)`
 *        - Customer debt cleared (NOT over-paid).
 *        - If amount_paid = amount + fee, settlement = amount → customer = 0.
 *
 *   Net effect on balances for amount=100, fee=10, amount_paid=110:
 *     - wallet:        −100 (debit)
 *     - customer:      0  (cleared — fee is agency revenue, NOT customer credit)
 *     - cashbox:       +110 (settlement of 100 + fee income of 10)
 *     - fee_clearing:  −10 (income clearing holds the uncollected-then-recognized fee)
 *
 * Companion file: WalletAnonymousSendFeeLegTest covers the walk-in path.
 * Together they pin the dual-accounting-treatments invariant: the fee is
 * ALWAYS recorded as agency income, never as a customer-side liability.
 */
class WalletRegisteredSendFeeLegTest extends WalletTestCase
{
    /**
     * Invariant: a registered SEND with full settlement MUST leave the
     * customer account at 0, NOT at -fee.
     *
     * Pre-fix bug: customer = +amount - amount_paid = -fee (= مديونية).
     * Post-fix: customer = +amount - min(amount_paid, amount) = 0.
     */
    public function test_registered_send_with_fee_does_not_leave_customer_in_debt(): void
    {
        $amount = 100.00;
        $fee = 10.00;
        $amountPaid = $amount + $fee;  // 110 — full cash collected

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = $amountPaid;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        // ── Invariant: customer balance MUST be 0 after full settlement ────
        $this->customerEgp->refresh();
        $customerAccountId = $this->customerEgp->account_id;
        $this->assertNotNull($customerAccountId, 'registered customer must have an account');

        $customerBalance = (float) Account::find($customerAccountId)->balance;
        $this->assertEqualsWithDelta(
            0.00,
            $customerBalance,
            0.01,
            'WLT-FEE-LEG-REG: customer balance MUST be 0 after full settlement '
            . '(the fee is agency revenue, NOT customer debt). Pre-fix bug: -fee.'
        );
    }

    /**
     * Invariant: a registered SEND posts THREE ledger rows:
     *   (1) main_transfer: type=Transfer, amount=`amount`, wallet → customer
     *   (2) fee_income:    type=Income,   amount=`fee`,    clearing → cash
     *   (3) settlement:    type=Transfer, amount=`amount`, customer → cash
     *
     * The income leg MUST exist as a separate Transaction row pointing to
     * the wallet income-clearing account.
     */
    public function test_registered_send_creates_fee_income_leg(): void
    {
        $amount = 100.00;
        $fee = 10.00;
        $amountPaid = $amount + $fee;

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = $amountPaid;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $wtId = $response->json('data.id');
        $wt = WalletTransaction::with('incomeTransaction', 'expenseTransaction')->find($wtId);

        $this->assertNotNull($wt);
        $this->assertNotNull($wt->customer_id, 'this is a registered-customer SEND');
        $this->assertEquals($amount, (float) $wt->amount);
        $this->assertEquals($fee, (float) $wt->service_fee);
        $this->assertEquals($amountPaid, (float) $wt->total_amount);

        // Income leg points to the fee-income transaction.
        $this->assertNotNull($wt->income_transaction_id, 'income_transaction_id must be set');
        $this->assertNotNull($wt->incomeTransaction, 'incomeTransaction relation must resolve');

        $incomeLeg = $wt->incomeTransaction;
        $this->assertEquals(
            TransactionType::Income->value,
            $incomeLeg->type->value,
            'income leg must be type=Income (agency revenue, NOT a transfer)'
        );
        $this->assertEqualsWithDelta($fee, (float) $incomeLeg->amount, 0.01,
            'fee income amount must equal the fee');

        // Expense leg points to the main wallet → customer transfer.
        $this->assertNotNull($wt->expenseTransaction, 'expenseTransaction relation must resolve');
        $expenseLeg = $wt->expenseTransaction;
        $this->assertEquals(
            TransactionType::Transfer->value,
            $expenseLeg->type->value,
            'expense leg must be type=Transfer (main wallet → customer)'
        );
        $this->assertEqualsWithDelta($amount, (float) $expenseLeg->amount, 0.01,
            'main transfer amount must equal the principal (no fee on this leg)');
        $this->assertEquals($this->walletAccountEgp->id, $expenseLeg->from_account_id);
        $this->customerEgp->refresh();
        $this->assertEquals($this->customerEgp->account_id, $expenseLeg->to_account_id);

        // ── THREE ledger rows exist for this WT ─────────────────────
        $relatedCount = DB::table('transactions')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $wtId)
            ->count();
        $this->assertEquals(3, $relatedCount,
            'WLT-FEE-LEG-REG: registered SEND with full settlement creates exactly '
            . '3 ledger rows (main + fee income + settlement).');

        // The settlement row is identified by direction customer → cash (NOT reversed).
        $settlement = Transaction::where('related_type', WalletTransaction::class)
            ->where('related_id', $wtId)
            ->where('from_account_id', $this->customerEgp->account_id)
            ->where('to_account_id', $this->cashboxEgp->id)
            ->where('notes', 'not like', 'عكس%')
            ->first();
        $this->assertNotNull($settlement, 'settlement row must exist');
        $this->assertEqualsWithDelta($amount, (float) $settlement->amount, 0.01,
            'WLT-FEE-LEG-REG: settlement amount = `amount` (principal only), NOT amount_paid.');
    }

    /**
     * Invariant: cashbox gains amount + fee (settlement + fee income).
     * Walk-in and registered paths must produce the SAME net cashbox gain.
     */
    public function test_registered_send_cashbox_gains_amount_plus_fee(): void
    {
        $amount = 250.00;
        $fee = 15.00;
        $amountPaid = $amount + $fee;

        $cashBefore = (float) Account::find($this->cashboxEgp->id)->balance;

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = $amountPaid;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $cashAfter = (float) Account::find($this->cashboxEgp->id)->balance;
        $this->assertEqualsWithDelta(
            $amount + $fee,
            $cashAfter - $cashBefore,
            0.01,
            'WLT-FEE-LEG-REG: cashbox gains amount+fee (= amount_paid) on full settlement '
            . '(settlement of amount + fee income of fee).'
        );
    }

    /**
     * Invariant: registered SEND with fee=0 produces only ONE ledger row
     * (the main transfer). No fee income, no extra leg. This mirrors the
     * walk-in zero-fee invariant.
     */
    public function test_registered_send_with_zero_fee_posts_main_transfer_only(): void
    {
        $amount = 300.00;
        $fee = 0.00;

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = 0;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $wtId = $response->json('data.id');

        // One ledger row only.
        $relatedCount = DB::table('transactions')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $wtId)
            ->count();
        $this->assertEquals(1, $relatedCount,
            'zero-fee registered SEND must produce exactly 1 ledger row (main transfer only).');

        // No fee-income transaction.
        $incomeRows = DB::table('transactions')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $wtId)
            ->where('type', TransactionType::Income->value)
            ->count();
        $this->assertEquals(0, $incomeRows,
            'zero-fee registered SEND must NOT create an income leg.');

        // income_transaction_id and expense_transaction_id both point at the main transfer
        // (backward-compat: empty fee ⇒ return [$transfer, $transfer]).
        $wt = WalletTransaction::find($wtId);
        $this->assertEquals($wt->income_transaction_id, $wt->expense_transaction_id,
            'zero-fee backward-compat: both columns point at the SAME transfer.');
    }

    /**
     * Invariant: when amount_paid < amount (partial settlement), the
     * customer still owes the principal residual. The fee income leg is
     * still posted at creation (recognized at creation per user preference).
     */
    public function test_registered_send_partial_settlement_customer_owes_residual(): void
    {
        $amount = 1000.00;
        $fee = 20.00;
        $amountPaid = 500.00;  // partial — only half of the principal

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = $amountPaid;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $this->customerEgp->refresh();
        $customerBalance = (float) Account::find($this->customerEgp->account_id)->balance;
        $this->assertEqualsWithDelta(
            $amount - $amountPaid,  // 500 — the residual debt
            $customerBalance,
            0.01,
            'WLT-FEE-LEG-REG: partial settlement leaves customer with residual principal debt '
            . '(customer = amount - amount_paid).'
        );

        // Cashbox gains amount_paid (settlement) + fee (income) = 520.
        $cashAfter = (float) Account::find($this->cashboxEgp->id)->balance;
        $this->assertEqualsWithDelta(
            5000 + $amountPaid + $fee,  // 5520
            $cashAfter,
            0.01,
            'WLT-FEE-LEG-REG: cashbox gains amount_paid + fee on partial settlement '
            . '(settlement of amount_paid + fee income of fee).'
        );
    }

    /**
     * Invariant: cashbox gains fee income EVEN WHEN amount_paid=0.
     * Per user preference, the agency commission is recognized at creation
     * regardless of cash collection timing. The clearing account holds the
     * uncollected-fee timing difference.
     */
    public function test_registered_send_recognizes_fee_income_even_without_settlement(): void
    {
        $amount = 500.00;
        $fee = 12.00;

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = 0;  // no cash collected yet

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // Cashbox gained exactly the fee.
        $cashAfter = (float) Account::find($this->cashboxEgp->id)->balance;
        $this->assertEqualsWithDelta(
            5000 + $fee,
            $cashAfter,
            0.01,
            'WLT-FEE-LEG-REG: cashbox gains `fee` income even when amount_paid=0 '
            . '(agency commission recognized at creation).'
        );

        // Customer balance = +amount (full debt).
        $this->customerEgp->refresh();
        $customerBalance = (float) Account::find($this->customerEgp->account_id)->balance;
        $this->assertEqualsWithDelta(
            $amount,
            $customerBalance,
            0.01,
            'WLT-FEE-LEG-REG: customer owes the full principal (no settlement yet).'
        );
    }

    /**
     * Invariant: updating the service_fee reposts both the main pair AND
     * the fee income leg. The customer balance after re-settlement is
     * correct for the new fee amount.
     */
    public function test_registered_send_fee_update_reposts_income_leg(): void
    {
        $amount = 200.00;
        $feeInitial = 5.00;
        $feeUpdated = 25.00;
        $amountPaid = $amount + $feeUpdated;  // 225 — full settlement at new fee

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $feeInitial);
        $payload['amount_paid'] = 0;

        $createResponse = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $createResponse->assertStatus(201);
        $wtId = (int) $createResponse->json('data.id');

        // Update fee AND amount_paid simultaneously.
        $updatePayload = [
            'service_fee' => $feeUpdated,
            'amount_paid' => $amountPaid,
        ];
        $updateResponse = $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$wtId}", $updatePayload);
        $updateResponse->assertStatus(200);

        // Customer balance should still be 0 (full settlement at new fee).
        $this->customerEgp->refresh();
        $customerBalance = (float) Account::find($this->customerEgp->account_id)->balance;
        $this->assertEqualsWithDelta(
            0.00,
            $customerBalance,
            0.01,
            'WLT-FEE-LEG-REG: customer balance still 0 after fee update + full settlement.'
        );

        // The active fee income transaction should have the new amount.
        $wt = WalletTransaction::with('incomeTransaction')->find($wtId);
        $this->assertEqualsWithDelta(
            $feeUpdated,
            (float) $wt->incomeTransaction->amount,
            0.01,
            'WLT-FEE-LEG-REG: updated fee income transaction carries the new fee (not the old one).'
        );
    }

    /**
     * Invariant: the fee income leg routes through the configured wallet
     * income-clearing account (config('accounting.clearing.income.wallet'),
     * default: إقفال إيرادات المحافظات).
     */
    public function test_registered_send_fee_income_routes_through_wallet_clearing(): void
    {
        $amount = 200.00;
        $fee = 8.00;
        $amountPaid = $amount + $fee;

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = $amountPaid;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $wtId = DB::table('transactions')
            ->where('related_type', WalletTransaction::class)
            ->where('notes', 'like', '%عمولة الوكالة%')
            ->orderBy('id', 'desc')
            ->value('related_id');

        $this->assertNotNull($wtId);

        // Find the fee-income transaction.
        $wt = WalletTransaction::with('incomeTransaction')->find($wtId);
        $feeIncomeTx = $wt->incomeTransaction;

        // The income leg's "from" account (the contra) must be the wallet income clearing.
        $expectedClearingName = config('accounting.clearing.income.wallet', 'إقفال إيرادات المحافظات');
        $clearingAccount = Account::where('name', $expectedClearingName)->first();
        $this->assertNotNull($clearingAccount,
            "wallet income clearing account '{$expectedClearingName}' must exist (check config/accounting.php).");

        // The fee income Transaction is internally a journal transfer — its entries
        // show the debit on the clearing account and the credit on the cashbox.
        $entries = AccountEntry::where('transaction_id', $feeIncomeTx->id)->get();
        $this->assertGreaterThanOrEqual(2, $entries->count(),
            'fee income leg must have at least 2 account entries (debit + credit).');

        $clearingDebit = $entries->where('account_id', $clearingAccount->id)->sum('debit');
        $this->assertEqualsWithDelta($fee, (float) $clearingDebit, 0.01,
            "wallet income clearing '{$expectedClearingName}' must be debited by the fee.");
    }
}
