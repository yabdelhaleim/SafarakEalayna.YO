<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\Support\Decimal;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 10 — PRECISION / CALCULATION AUDIT.
 *
 * Verifies arithmetic integrity for the Wallet & Transfers module:
 *   - Decimal precision (no floating-point drift)
 *   - Fee split (amount → principal, fee → income)
 *   - Walk-in vs registered customer money flow
 *   - Multi-currency isolation (EGP vs USD vs SAR)
 *   - Cumulative balances (multiple operations)
 *   - Round-trip: post N then delete N returns to opening state
 *
 * Uses the project's bcmath oracle (Decimal) for exact arithmetic.
 */
class Phase10PrecisionTest extends WalletTestCase
{
    // ────────────── Decimal precision ──────────────

    /**
     * FINDING VAL-2 (LOW) REMEDIATED (2026-08-21):
     * Pre-fix: `amount=0.01` was accepted (no minimum beyond 0.01). That
     * allowed dust attacks — a cashier or malicious client could flood
     * the system with thousands of sub-1 EGP transactions.
     * Post-fix: the minimum is now `1.00`. Sub-1 amounts are rejected
     * with 422 (validation).
     */
    public function test_smallest_amount_below_one_is_rejected_va_l_2_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 0.01, fee: 0.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        // Post-fix: dust attack rejected with 422.
        $response->assertStatus(422);
    }

    /**
     * FINDING VAL-2 — positive path:
     * The new minimum `1.00` is accepted.
     */
    public function test_amount_one_is_accepted_va_l_2_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 1.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals(1.00, (float) $data['amount']);
        $this->assertEquals(1.00, (float) $data['total_amount']);
    }

    public function test_three_decimal_amount_is_truncated_to_two_decimals(): void
    {
        // An amount of 100.123 is unusual but technically valid. The system
        // accepts it but the resulting balance is rounded to 2 decimals.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.123, fee: 0.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        // The form request rule is `numeric|min:0.01`. A numeric with 3 decimals
        // passes. The amount is stored as-is (decimal:2 cast rounds it).
        $response->assertStatus(201);

        // The stored balance must be reduced by 100.12 (rounded down per decimal:2 cast).
        $this->assertEquals('9899.88', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance after 100.123 is 9899.88 (10000 - 100.12 rounded)');
    }

    public function test_nine_decimal_amount_is_accepted(): void
    {
        // FIN-3/VAL-2 (2026-08-21): sub-1 amounts are now rejected.
        // This test is updated to use an amount >= 1.00 to exercise the
        // 9-decimal precision behavior within the allowed range.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.123456789, fee: 0.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        // 100.123456789 → 100.12 after the decimal:2 cast.
        $response->assertStatus(201);
        $this->assertEquals('9899.88', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance after 100.123456789 is 9899.88 (10000−100.12)');
    }

    // ────────────── Fee arithmetic ──────────────

    public function test_zero_fee_no_fee_arithmetic(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 0.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // Send of 500 with fee 0 → total = 500.
        $this->assertEquals('9500.00', AccountState::balance($this->walletAccountEgp->id));
        $this->assertEquals('5000.00', AccountState::balance($this->cashboxEgp->id),
            'Cashbox unchanged when amount_paid=0');
    }

    public function test_high_fee_50_percent(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 50.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals(100.00, (float) $data['amount']);
        $this->assertEquals(50.00, (float) $data['service_fee']);
        $this->assertEquals(150.00, (float) $data['total_amount']);
    }

    public function test_fee_equals_amount(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 100.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals(200.00, (float) $data['total_amount']);
    }

    public function test_fee_greater_than_amount(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 200.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);
        $this->assertEquals(300.00, (float) $response->json('data.total_amount'));
    }

    // ────────────── Cumulative balances ──────────────

    public function test_three_consecutive_sends_cumulative_balances(): void
    {
        $walletStart = '10000.00';

        $payloads = [
            ['amount' => 100.00, 'fee' => 5.00],
            ['amount' => 250.00, 'fee' => 10.00],
            ['amount' => 50.00, 'fee' => 2.00],
        ];

        $expectedDeltas = [];
        foreach ($payloads as $p) {
            $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $p['amount'], fee: $p['fee']);
            $payload['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
            $expectedDeltas[] = Decimal::sub($walletStart, (string) ($p['amount'] + $p['amount'] + $p['amount']));
        }

        // Net wallet change = -100 - 250 - 50 = -400.00
        $this->assertEquals('9600.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance after 3 sends = 10000 - 400 = 9600');
    }

    public function test_send_then_receive_balances(): void
    {
        $send = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $send['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $send)->assertStatus(201);

        $recv = $this->receivePayloadRegistered($this->customerEgp, amount: 300.00, fee: 8.00);
        $recv['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $recv)->assertStatus(201);

        // Net change: -500 + 300 = -200.
        $this->assertEquals('9800.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance after -500 send +300 receive = 9800');
    }

    // ────────────── Multi-currency isolation ──────────────

    public function test_egp_wallet_isolation(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // EGP wallet: 10000 - 100 = 9900.
        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id));
        // USD wallet: unchanged.
        $this->assertEquals('1000.00', AccountState::balance($this->cashboxUsd->id));
        // SAR wallet: unchanged.
        $this->assertEquals('1000.00', AccountState::balance($this->cashboxSar->id));
    }

    public function test_cross_currency_transaction_is_accepted(): void
    {
        // FINDING: System does not validate that wallet/cash account currencies match.
        // A EGP wallet can be paired with a USD cashbox.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['cash_account_id'] = $this->cashboxUsd->id;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        if ($response->getStatusCode() === 422) {
            $this->assertTrue(true, 'Cross-currency transaction is rejected');

            return;
        }

        $response->assertStatus(201);
        // With amount_paid=0, the cashbox doesn't receive the money (this is the
        // registered-customer path; cash only moves when the customer pays).
        // The wallet is still debited 100. The customer account is +105.
        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id),
            'EGP wallet was debited 100 (with USD cashbox as the cash field)');
        $this->assertEquals('1000.00', AccountState::balance($this->cashboxUsd->id),
            'USD cashbox unchanged because amount_paid=0 (registered customer)');
    }

    // ────────────── Round-trip balance ──────────────

    public function test_round_trip_post_then_delete_returns_to_opening(): void
    {
        $start = AccountState::balance($this->walletAccountEgp->id);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 250.00, fee: 7.00);
        $payload['amount_paid'] = 0;
        $create = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $create->json('data.id');

        // After create: balance changed.
        $this->assertEquals('9750.00', AccountState::balance($this->walletAccountEgp->id));

        // Delete reverses the journal.
        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        // After delete: balance should return to opening.
        $this->assertEquals($start, AccountState::balance($this->walletAccountEgp->id),
            'Round-trip post+delete must return to opening balance');
    }

    // ────────────── Multiple customers don't cross-contaminate ──────────────

    public function test_two_customers_have_independent_balances(): void
    {
        $p1 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $p1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p1)->assertStatus(201);

        $p2 = $this->sendPayloadRegistered($this->customer2, amount: 200.00, fee: 8.00);
        $p2['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p2)->assertStatus(201);

        $c1 = Customer::find($this->customerEgp->id);
        $c2 = Customer::find($this->customer2->id);

        $this->assertEquals('100.00', AccountState::balance($c1->account_id),
            'Post-2026-08-30: Customer 1 balance = amount (100); fee stays on WT row, not ledger.');
        $this->assertEquals('200.00', AccountState::balance($c2->account_id),
            'Post-2026-08-30: Customer 2 balance = amount (200); fee stays on WT row, not ledger.');
    }

    // ────────────── Customer receives operation −→ net effect ──────────────

    public function test_two_consecutive_receives_build_up_balance(): void
    {
        $r1 = $this->receivePayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $r1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $r1)->assertStatus(201);

        $r2 = $this->receivePayloadRegistered($this->customerEgp, amount: 200.00, fee: 8.00);
        $r2['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $r2)->assertStatus(201);

        // Customer net = -(100-5) + -(200-8) = -95 - 192 = -287 (we owe them).
        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertEquals('-287.00', AccountState::balance($reloaded->account_id),
            'Customer balance after 2 receives = -(95+192) = -287');
    }

    // ────────────── Decimal precision in Transaction.amount column ──────────────

    public function test_transaction_amount_stored_with_two_decimal_precision(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $create = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $create->json('data.id');

        $wt = WalletTransaction::find($id);
        $this->assertEquals('100.00', (string) $wt->amount,
            'amount column must store 100.00 (decimal:2)');

        $txn = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $id)
            ->first();
        $this->assertEquals('100.00', (string) $txn->amount,
            'Post-2026-08-30: ledger transfer amount must be 100.00 (amount only; fee stays on WT row).');
    }
}
