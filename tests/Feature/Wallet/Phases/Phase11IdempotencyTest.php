<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 11 — IDEMPOTENCY / DUPLICATE REQUESTS.
 *
 * Pre-fix (2026-08-20, before IDM-1 remediation), these tests documented
 * the BUG: every retry/double-click/network-flake created a duplicate
 * financial transaction. After the IDM-1 fix, this file was rewritten to
 * assert the CORRECT behavior: the `Idempotency-Key` HTTP header is
 * honored, replays return the original transaction with HTTP 200, and
 * no extra ledger / audit row is written for a replay.
 *
 * Deep regression coverage (sequential retries, concurrent duplicates,
 * soft-delete collision, money conservation, per-user scoping) lives in
 * `PhaseIdempotencyRemediationTest`.
 */
class Phase11IdempotencyTest extends WalletTestCase
{
    public function test_double_post_same_payload_without_key_creates_two_transactions(): void
    {
        // Pre-fix behavior preserved: callers that DO NOT supply an
        // Idempotency-Key header still get the legacy behavior (one
        // POST = one transaction). Backward compat for non-idempotent
        // clients is intentional.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $r1 = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $r2 = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $r1->assertStatus(201);
        $r2->assertStatus(201);

        $this->assertEquals(2, WalletTransaction::query()->count(),
            'Without an Idempotency-Key, each POST creates a fresh transaction (legacy compat).');
        $this->assertNotEquals($r1->json('data.id'), $r2->json('data.id'),
            'Two different transaction IDs because no idempotency key was supplied.');

        // The wallet was debited twice.
        $this->assertEquals('9800.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance after 2x identical sends (no key) = 10000 - 200 = 9800');
    }

    public function test_ten_repeated_posts_without_key_create_ten_transactions(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 50.00, fee: 2.00);
        $payload['amount_paid'] = 0;

        for ($i = 0; $i < 10; $i++) {
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $this->assertEquals(10, WalletTransaction::query()->count(),
            'Without an Idempotency-Key, 10 identical POSTs create 10 transactions (legacy compat).');
        $this->assertEquals('9500.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance = 10000 - 10*50 = 9500');
    }

    public function test_idempotency_key_header_i_s_honored_replay_returns_200_and_same_id(): void
    {
        // POST IDM-1 FIX: standard `Idempotency-Key` header is honored.
        // A replay returns HTTP 200 (replay) with the ORIGINAL transaction id,
        // and no extra ledger / audit row is written.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $headers = [
            'Idempotency-Key' => 'idem-12345-abc',
        ];

        $r1 = $this->asAdmin()
            ->withHeaders($headers)
            ->postJson('/api/v1/wallet/transactions', $payload);
        $r2 = $this->asAdmin()
            ->withHeaders($headers)
            ->postJson('/api/v1/wallet/transactions', $payload);

        $r1->assertStatus(201);
        $r2->assertStatus(200);

        $this->assertTrue(
            (bool) ($r2->json('data.idempotent_replay') ?? false),
            'Replay response carries idempotent_replay=true.'
        );

        $this->assertEquals(1, WalletTransaction::query()->count(),
            'Same Idempotency-Key + same payload = exactly 1 transaction.');
        $this->assertEquals($r1->json('data.id'), $r2->json('data.id'),
            'Replay returns the ORIGINAL transaction id.');

        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet debited exactly once (10000 - 100 = 9900).');
    }

    public function test_x_request_id_header_is_no_t_honored(): void
    {
        // X-Request-Id is a tracing header, not an idempotency key. It
        // is intentionally NOT used as a dedup signal. Two POSTs with
        // the same X-Request-Id but no Idempotency-Key each create a
        // fresh transaction (legacy behavior preserved).
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $headers = ['X-Request-Id' => 'req-99999-xyz'];

        $r1 = $this->asAdmin()
            ->withHeaders($headers)
            ->postJson('/api/v1/wallet/transactions', $payload);
        $r2 = $this->asAdmin()
            ->withHeaders($headers)
            ->postJson('/api/v1/wallet/transactions', $payload);

        $r1->assertStatus(201);
        $r2->assertStatus(201);

        $this->assertEquals(2, WalletTransaction::query()->count(),
            'X-Request-Id is not an idempotency key; both POSTs succeed.');
    }

    public function test_different_payloads_same_idempotency_key_return_same_transaction(): void
    {
        // When a client reuses an Idempotency-Key with a DIFFERENT payload,
        // the safe behavior (matches Hajj/Umra / Visa / Flight / Bus
        // project convention) is to return the ORIGINAL transaction. The
        // key identifies the original request; a payload mismatch is a
        // client error and is NOT silently executed.
        $p1 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $p1['amount_paid'] = 0;
        $p2 = $this->sendPayloadRegistered($this->customerEgp, amount: 200.00, fee: 5.00);
        $p2['amount_paid'] = 0;

        $r1 = $this->asAdmin()
            ->withHeaders(['Idempotency-Key' => 'idem-same'])
            ->postJson('/api/v1/wallet/transactions', $p1);
        $r2 = $this->asAdmin()
            ->withHeaders(['Idempotency-Key' => 'idem-same'])
            ->postJson('/api/v1/wallet/transactions', $p2);

        $r1->assertStatus(201);
        $r2->assertStatus(200);
        $this->assertEquals(1, WalletTransaction::query()->count(),
            'Same key + different payload still yields 1 transaction (replay of the original).');
        $this->assertEquals($r1->json('data.id'), $r2->json('data.id'));
        $this->assertTrue((bool) ($r2->json('data.idempotent_replay') ?? false));
    }

    public function test_ledger_audit_trail_no_t_duplicated_for_replays(): void
    {
        // POST IDM-1 FIX: a replay must NOT write a fresh audit log or
        // a fresh ledger row. The financial effect (1 journal pair) and
        // the audit effect (1 audit log) belong to the FIRST request
        // only; replays are read-only.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $headers = ['Idempotency-Key' => 'idem-audit'];

        for ($i = 0; $i < 3; $i++) {
            $this->asAdmin()
                ->withHeaders($headers)
                ->postJson('/api/v1/wallet/transactions', $payload)
                ->assertStatus($i === 0 ? 201 : 200);
        }

        $auditCount = \DB::table('audit_logs')
            ->where('model_type', WalletTransaction::class)
            ->where('action', 'wallet_transaction.created')
            ->count();
        $this->assertEquals(1, $auditCount,
            '3 replays with same key = 1 audit log entry (only the first request is audited).');

        $ledgerCount = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->count();
        // Post-2026-08-30: SEND now posts a single journal transfer (wallet → customer),
        // not the legacy income+expense pair. Ledger count for one SEND = 1.
        $this->assertEquals(1, $ledgerCount,
            '3 replays with same key = 1 ledger row (1 journal transfer) — the financial effect happens exactly once.');
    }

    public function test_creating_transaction_with_amount_too_high_after_first_wallet_drain(): void
    {
        // Pre-fix behavior preserved: callers without an Idempotency-Key
        // can still drain the wallet to 0. After 100 sends, the 101st
        // fails with insufficient balance. No idempotency key = no
        // dedup, so the drain works as before.
        // First send succeeds (100 of 10000).
        $p1 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $p1['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p1)->assertStatus(201);

        // Duplicate: also succeeds.
        $p2 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $p2['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p2)->assertStatus(201);

        // Now 9980 left. Spam 99 more times → 10000 - 100*100 = 0.
        for ($i = 0; $i < 98; $i++) {
            $pi = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
            $pi['amount_paid'] = 0;
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $pi)->assertStatus(201);
        }

        $this->assertEquals('0.00', AccountState::balance($this->walletAccountEgp->id),
            'After 100 sends of 100 EGP each, wallet is at 0');
        $this->assertEquals(100, WalletTransaction::query()->count(),
            'Without idempotency keys, 100 identical sends created 100 transactions.');

        // The 101st send — Post-2026-08-30 the wallet provider transfer now
        // uses allow_from_negative=true (per WalletTransactionService::postMainSendPair),
        // so an overdraw does NOT reject the request. The transfer succeeds
        // and the wallet balance goes negative by the principal amount.
        $p101 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $p101['amount_paid'] = 0;
        $r = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $p101);
        $r->assertStatus(201,
            'Post-2026-08-30: overdraw succeeds (allow_from_negative=true); wallet goes negative.');
        $this->assertEquals(101, WalletTransaction::query()->count(),
            'The 101st send was accepted (overdraw allowed); one extra transaction was created.');
        $this->assertEquals('-100.00', AccountState::balance($this->walletAccountEgp->id),
            'Post-2026-08-30: wallet balance after 101 sends = 10000 - 101*100 = -100.');
    }
}
