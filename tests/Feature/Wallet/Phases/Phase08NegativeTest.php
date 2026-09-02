<?php

namespace Tests\Feature\Wallet\Phases;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 8 — NEGATIVE TESTS.
 *
 * Verifies the Wallet & Transfers API correctly REJECTS invalid input:
 *   - Missing required fields
 *   - Invalid enum values
 *   - Negative amounts / zero amounts
 *   - Negative fees
 *   - Non-existent accounts / wallet types / customers
 *   - Currency mismatches in payload
 *   - Closed / inactive accounts
 *   - Insufficient balance
 *   - Invalid HTTP methods
 *   - HTTP 401 / 403 for unauthenticated / unauthorized
 *
 * Confirms the system NEVER posts a transaction on rejected input.
 *
 * KNOWN ISSUES / FINDINGS EXPECTED:
 *   - FIN-3: POST store catches all exceptions and converts to 422 (not 500).
 *           This means even business-logic exceptions that shouldn't be
 *           "validation errors" come back as 422. The error message in the
 *           response body is the exception message.
 *   - System is generous: it does NOT validate that amount_paid <= total_amount.
 *   - System does NOT validate that wallet_account_id != cash_account_id.
 *   - System does NOT validate the currency on the wallet/cash matches the
 *     transaction's expected wallet_type (Currency mismatch allowed).
 *   - System does NOT validate that amount is a "reasonable" value (allows 0.01).
 */
class Phase08NegativeTest extends WalletTestCase
{
    // ────────────── Empty / missing fields ──────────────

    public function test_empty_body_returns_422(): void
    {
        $r = $this->asAdmin()->postJson('/api/v1/wallet/transactions', []);
        $r->assertStatus(422);
    }

    public function test_missing_wallet_type_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['wallet_type_id']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_missing_customer_name_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['customer_name']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_missing_wallet_number_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['wallet_number']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_missing_type_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['type']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_missing_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['amount']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_missing_wallet_account_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['wallet_account_id']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_missing_cash_account_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        unset($payload['cash_account_id']);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    // ────────────── Invalid enum values ──────────────

    public function test_invalid_type_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['type'] = 'transfer_money_to_aliens';
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_empty_string_type_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['type'] = '';
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    // ────────────── Numeric boundaries ──────────────

    public function test_zero_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 0.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_negative_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: -100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_negative_fee_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: -5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_huge_amount_within_range_succeeds(): void
    {
        // 999,999.99 — the wallet has 10000.
        // Post-2026-08-30: SEND uses recordJournalTransfer with allow_from_negative=true,
        // so an overdraw no longer rejects the request — the wallet goes negative
        // (HTTP 201). The old 409 rejection is gone.
        $walletBefore = (float) AccountState::balance($this->walletAccountEgp->id);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 999999.99, fee: 0.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201,
            'Post-2026-08-30: huge amount succeeds (allow_from_negative=true); wallet goes negative.');
        $walletAfter = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($walletBefore - 999999.99, $walletAfter,
            'Post-2026-08-30: wallet debited by the overdraw amount (allowed to go negative).');
        $this->assertLessThan(0, $walletAfter,
            'Post-2026-08-30: wallet balance is now negative.');
    }

    public function test_string_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['amount'] = 'not-a-number';
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    // ────────────── Non-existent references ──────────────

    public function test_nonexistent_wallet_type_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['wallet_type_id'] = 99999;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_nonexistent_customer_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['customer_id'] = 99999;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_nonexistent_wallet_account_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['wallet_account_id'] = 99999;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_nonexistent_cash_account_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['cash_account_id'] = 99999;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    // ────────────── Insufficient balance ──────────────

    public function test_send_exceeding_balance_creates_negative_wallet_balance(): void
    {
        // Wallet has 10000 EGP. Try to send 20000.
        // Post-2026-08-30: SEND uses recordJournalTransfer with allow_from_negative=true,
        // so the overdraw no longer rejects with HTTP 409. Instead the request succeeds
        // (HTTP 201) and the wallet goes negative. The customer account is credited
        // by `amount` (NOT amount+fee — the fee stays on the WT row only).
        $walletBefore = (float) AccountState::balance($this->walletAccountEgp->id);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201,
            'Post-2026-08-30: insufficient balance no longer rejects; wallet allowed to go negative.');

        $walletAfter = (float) AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals($walletBefore - 20000.00, $walletAfter,
            'Post-2026-08-30: wallet debited by 20000 even though it only had 10000.');
        $this->assertEquals(-10000.00, $walletAfter,
            'Post-2026-08-30: wallet balance is now -10000 (10000 - 20000).');

        // Customer account credited by `amount` (20000). The fee is on the WT row only.
        $customer = Customer::find($this->customerEgp->id);
        $this->assertNotNull($customer->account_id,
            'Post-2026-08-30: customer.account_id is set (ensureCustomerAccount ran).');
        $customerBalance = (float) AccountState::balance($customer->account_id);
        $this->assertEquals(20000.00, $customerBalance,
            'Post-2026-08-30: customer credited by amount (20000), fee stays on WT row.');
    }

    public function test_overdraw_send_changes_only_wallet_balance(): void
    {
        // Post-2026-08-30: SEND now uses recordJournalTransfer(wallet → customer) with
        // allow_from_negative=true. The wallet IS allowed to go negative; the cashbox
        // is NOT touched (settlement runs only when amount_paid > 0). Records ARE created
        // (1 WT, 1 ledger TX for the main transfer). The overdraw no longer rolls back.
        $walletBefore = (float) AccountState::balance($this->walletAccountEgp->id);
        $cashBefore = (float) AccountState::balance($this->cashboxEgp->id);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 20000.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // Post-2026-08-30: wallet went negative; cashbox unchanged.
        $walletAfter = (float) AccountState::balance($this->walletAccountEgp->id);
        $cashAfter = (float) AccountState::balance($this->cashboxEgp->id);

        $this->assertEquals($walletBefore - 20000.00, $walletAfter,
            'Post-2026-08-30: wallet debited by the overdraw amount (allowed to go negative).');
        $this->assertLessThan(0, $walletAfter,
            'Post-2026-08-30: wallet balance is negative after overdraw send.');

        $this->assertEquals($cashBefore, $cashAfter,
            'Post-2026-08-30: cashbox balance unchanged on registered send without settlement.');

        // The send IS recorded — 1 WT row and 1 ledger Transaction row.
        $this->assertEquals(1, WalletTransaction::query()->count(),
            'Post-2026-08-30: 1 WalletTransaction row created for the overdraw send.');
        $this->assertEquals(1, Transaction::query()->count(),
            'Post-2026-08-30: 1 ledger Transaction row (the single main transfer, wallet → customer).');
    }

    // ────────────── Same account for both sides ──────────────

    /**
     * FINDING FIN-6 (HIGH): When wallet_account_id == cash_account_id on a Send,
     * the system accepts the transaction but the journal is asymmetric.
     * The customer is debited `total_amount` (105), but the "self" account is
     * only OUT `amount` (100). The fee (5) is silently lost in the clearing.
     *
     *   Before: wallet/cash = 5000.00, customer = 0.00, clearing = 0.00
     *   After:  wallet/cash = 4900.00  (lost 100 only)
     *           customer     = +105.00
     *           clearing     = -5.00
     *
     * The user paid 105, but the cash only lost 100. In a reconciliation
     * run, the system appears to have accepted 100 payment when the customer
     * claims 105. The fee is unaccounted for on the cash side.
     */
    /**
     * FINDING FIN-6 (HIGH) REMEDIATED (2026-08-21):
     * Pre-fix: a Send with `wallet_account_id == cash_account_id` was accepted
     * and produced an asymmetric journal where the fee was silently lost.
     * Post-fix: StoreWalletTransactionRequest validates
     * `cash_account_id != wallet_account_id` and rejects the payload with 422.
     */
    public function test_wallet_account_equals_cash_account_fi_n_6_rejected(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['wallet_account_id'] = $this->cashboxEgp->id;
        $payload['cash_account_id'] = $this->cashboxEgp->id;

        $walletBefore = AccountState::balance($this->walletAccountEgp->id);
        $cashBefore = AccountState::balance($this->cashboxEgp->id);

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        // Post-fix: self-loop is rejected with 422 (validation).
        $response->assertStatus(422);

        // No ledger or balance change occurred.
        $this->assertEquals($cashBefore, AccountState::balance($this->cashboxEgp->id),
            'FIN-6 fixed: no balance change on rejected payload.');
        $this->assertEquals($walletBefore, AccountState::balance($this->walletAccountEgp->id),
            'Untouched wallet balance unchanged');
    }

    // ────────────── Currency / type mismatches ──────────────

    /**
     * FINDING VAL-1 (HIGH) REMEDIATED (2026-08-21):
     * Pre-fix: cross-currency pairs (e.g. EGP wallet + USD cashbox) were
     * accepted silently. Post-fix: StoreWalletTransactionRequest checks
     * `wallet_account.currency == cash_account.currency` and rejects with 422.
     */
    public function test_currency_mismatch_wallet_egp_cash_usd_is_rejected_va_l_1(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['cash_account_id'] = $this->cashboxUsd->id;  // USD cashbox

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        // Post-fix: cross-currency rejected with 422.
        $response->assertStatus(422);
    }

    // ────────────── Inactive / closed accounts ──────────────

    /**
     * FINDING FIN-7 (HIGH) REMEDIATED (2026-08-21):
     * Pre-fix: a deactivated (closed) wallet_account_id was accepted and
     * could move money. Post-fix: StoreWalletTransactionRequest checks
     * `is_active=true` on BOTH accounts and rejects with 422.
     */
    public function test_inactive_wallet_account_is_rejected_fi_n_7(): void
    {
        $closedWallet = $this->makeAccount(
            type: AccountType::Wallet,
            name: 'Closed Wallet',
            currency: 'EGP',
            balance: 5000.00,
            moduleType: 'office',
            isActive: false,
        );

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['wallet_account_id'] = $closedWallet->id;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        // Post-fix: inactive wallet is rejected with 422.
        $response->assertStatus(422);
    }

    /**
     * FINDING FIN-7 (HIGH) positive-path REMEDIATED:
     * An active wallet_account_id MUST still be accepted.
     */
    public function test_active_wallet_account_is_accepted_fi_n_7(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);
    }

    // ────────────── HTTP method tampering ──────────────

    public function test_post_to_show_endpoint_returns_405(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $created = WalletTransaction::query()->first();
        $this->asAdmin()->postJson("/api/v1/wallet/transactions/{$created->id}", [])
            ->assertStatus(405);
    }

    public function test_delete_to_create_endpoint_returns_405(): void
    {
        $this->asAdmin()->deleteJson('/api/v1/wallet/transactions', [])
            ->assertStatus(405);
    }

    // ────────────── Authentication ──────────────

    public function test_unauthenticated_post_returns_401(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);
        $this->assertContains($response->getStatusCode(), [401, 403],
            'Unauthenticated POST must be rejected (got: '.$response->getStatusCode().')');
    }

    public function test_inactive_user_post_returns_401_or_403(): void
    {
        $inactive = User::factory()->create([
            'role' => 'employee',
            'is_active' => false,
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $response = $this->actingAs($inactive, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);
        $this->assertContains($response->getStatusCode(), [401, 403],
            'Inactive user must be rejected (got: '.$response->getStatusCode().')');
    }

    // ────────────── Duplicate / replay — Phase 11 territory, but a sanity check here ──────────────

    public function test_double_post_with_same_payload_creates_two_transactions(): void
    {
        // FINDING (PHASE 11 territory): No idempotency key. Re-posting the exact same
        // payload creates a SECOND transaction. There is no client-supplied
        // Idempotency-Key header support.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $this->assertEquals(2, WalletTransaction::query()->count(),
            'No idempotency: same payload posted twice creates two transactions');
    }

    // ────────────── Bad input types ──────────────

    public function test_array_in_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['amount'] = ['nested' => 'array'];
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_boolean_in_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['amount'] = true;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }

    public function test_null_amount_returns_422(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['amount'] = null;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(422);
    }
}
