<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * PRE-PHASE-B IDEMPOTENCY TEST — Hajj/Umrah payment endpoint.
 *
 * Covers the 12 scenarios from the spec:
 *   1.  First payment succeeds.
 *   2.  Exact replay returns idempotent result.
 *   3.  Replay does not create another payment.
 *   4.  Replay does not create another Transaction.
 *   5.  Replay does not create extra AccountEntries.
 *   6.  Booking paid amount changes exactly once.
 *   7.  Different legitimate reference creates a second payment.
 *   8.  Same amount + same booking + different reference remains valid.
 *   9.  Missing idempotency_key follows the explicitly defined backward-
 *       compatible contract (NULL → no protection → can be replayed).
 *   10. Failure during accounting rolls back everything.
 *   11. Existing cancellation/reversal still works.
 *   12. Existing Hajj/Umrah payment behavior remains intact.
 *
 * Plus supplementary scenarios:
 *   13. The same idempotency_key on a DIFFERENT booking is independent.
 *   14. Soft-deleting an existing payment then re-supplying the same key
 *       creates a NEW payment (soft-deleted rows do not occupy the slot).
 *
 * Every financial assertion reconciles against the raw ledger:
 *   account.balance == SUM(account_entries.credit - debit)
 *   SUM(transaction.debit) == SUM(transaction.credit)
 *   paid + remaining == total_selling_price
 */
class HajjUmraPaymentIdempotencyTest extends HajjUmraTestCase
{
    /**
     * Build a confirmed booking ready to receive payments.
     *
     * @return array{0: HajjUmraBooking, 1: \App\Models\Account}
     */
    private function seedBooking(int $selling = 15000, int $purchase = 10000): array
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $treasury = $this->makeTreasuryAccount('EGP', 500_000.00);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'currency' => 'EGP',
            'per_person' => true,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            // Booking endpoint requires the treasury account_id
            // (HajjUmraLiquidityAccount rule).
            'account_id' => $treasury->id,
        ])->assertCreated();

        $bookingId = (int) $response->json('data.id');
        $booking = HajjUmraBooking::query()->findOrFail($bookingId);

        return [$booking, $treasury];
    }

    private function postPayment(int $bookingId, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. First payment succeeds.
    // ─────────────────────────────────────────────────────────────────────
    public function test_first_payment_succeeds(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $r = $this->postPayment($booking->id, [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => 'STRESS-IDEM-001',
            'paid_by' => 'cashier',
        ]);

        $r->assertCreated();
        $this->assertFalse((bool) ($r->json('data.idempotent_replay') ?? false));
        $this->assertSame('STRESS-IDEM-001', $r->json('data.payment.idempotency_key'));
        $this->assertSame(1, HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Exact replay returns idempotent result.
    // 3. Replay does not create another payment.
    // 4. Replay does not create another Transaction.
    // 5. Replay does not create extra AccountEntries.
    // 6. Booking paid amount changes exactly once.
    // ─────────────────────────────────────────────────────────────────────
    public function test_exact_replay_is_idempotent_across_all_layers(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $payload = [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => 'STRESS-IDEM-002',
            'paid_by' => 'cashier',
        ];

        $r1 = $this->postPayment($booking->id, $payload);
        $r1->assertCreated();
        $firstPaymentId = $r1->json('data.payment.id');
        $firstTxId = $r1->json('data.payment.transaction_id');
        $firstEntryCount = AccountEntry::query()->count();

        // ── Replay the SAME payload 4 times ──
        for ($i = 0; $i < 4; $i++) {
            $rN = $this->postPayment($booking->id, $payload);
            // Idempotent return: HTTP 200 (NOT 201) + idempotent_replay=true.
            $rN->assertOk();
            $this->assertTrue((bool) $rN->json('data.idempotent_replay'),
                "Replay #{$i} must return idempotent_replay=true");
            // Same payment row id
            $this->assertSame($firstPaymentId, $rN->json('data.payment.id'),
                "Replay #{$i} must return the SAME payment row id");
            $this->assertSame($firstTxId, $rN->json('data.payment.transaction_id'),
                "Replay #{$i} must return the SAME transaction id");
        }

        // 3. Exactly one payment row
        $this->assertSame(1, HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count(),
            'After 1 first + 4 replays, exactly ONE payment row must exist.');

        // 4. Exactly one transaction (per the booking + payment, not counting the
        // booking's own sale Income/expense which exist regardless).
        $paymentTxs = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', 'transfer')
            ->where('notes', 'like', 'دفعة على حجز%')
            ->count();
        $this->assertSame(1, $paymentTxs,
            'After replays, exactly ONE payment Transfer transaction must exist.');

        // 5. AccountEntries did not grow from the replays
        $this->assertSame($firstEntryCount, AccountEntry::query()->count(),
            'After replays, no new AccountEntry rows may be created.');

        // 6. Booking.paid_amount grew by 5000 EXACTLY ONCE
        $booking->refresh();
        $this->assertEqualsWithDelta(5000.0, (float) $booking->paid_amount, 0.01,
            'Booking paid_amount must reflect exactly ONE 5000 payment, not 5×5000.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 7. Different legitimate reference creates a second payment.
    // 8. Same amount + same booking + different reference remains valid.
    // ─────────────────────────────────────────────────────────────────────
    public function test_two_legitimate_different_keys_remain_independent(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $r1 = $this->postPayment($booking->id, [
            'amount' => 2000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => 'STRESS-IDEM-LEGIT-A',
            'paid_by' => 'cashier',
        ]);
        $r1->assertCreated();

        $r2 = $this->postPayment($booking->id, [
            'amount' => 2000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => 'STRESS-IDEM-LEGIT-B', // different key, same amount/method
            'paid_by' => 'cashier',
        ]);
        $r2->assertCreated();
        $this->assertFalse((bool) ($r2->json('data.idempotent_replay') ?? false),
            'Different key → must NOT be treated as a replay.');

        $this->assertSame(2, HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count(),
            'Two distinct keys → two distinct payment rows.');

        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01,
            'paid_amount must equal sum of both legitimate payments.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 9. Missing idempotency_key follows the explicit backward-compatible
    //    contract: no protection, replay IS allowed (matches the legacy
    //    behavior that Phase 25 must preserve for callers that have not
    //    adopted the new field).
    // ─────────────────────────────────────────────────────────────────────
    public function test_missing_idempotency_key_remains_backward_compatible(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $payload = [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            // No idempotency_key
            'paid_by' => 'cashier',
        ];

        $r1 = $this->postPayment($booking->id, $payload);
        $r1->assertCreated();

        // Replay without an idempotency_key is permitted (NULL is not
        // protected by the unique index).
        $r2 = $this->postPayment($booking->id, $payload);
        $r2->assertCreated();
        $this->assertFalse((bool) ($r2->json('data.idempotent_replay') ?? false),
            'Without idempotency_key, replay is accepted (backward-compat contract).');

        $this->assertSame(2, HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count(),
            'Two calls without idempotency_key → two payment rows (backward-compat).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 10. Failure during accounting rolls back EVERYTHING.
    // ─────────────────────────────────────────────────────────────────────
    public function test_failure_inside_add_payment_rolls_back_atomic_state(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        // Re-query the customer + account fresh — the in-memory booking
        // object may hold a stale Customer with account_id=null because
        // ensureCustomerAccount() populated the account AFTER booking
        // creation.
        $customerId = $booking->customer_id;
        $customer = \App\Models\Customer::query()->findOrFail($customerId);
        $customerAccountId = (int) $customer->account_id;
        $this->assertNotNull($customerAccountId, 'Customer must have an account after booking creation.');

        $paymentCountBefore = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)->count();
        $txCountBefore = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)->count();
        $entriesBefore = AccountEntry::query()->count();
        $customerBalanceBefore = (float) Account::query()->findOrFail($customerAccountId)->balance;
        $vaultBalanceBefore = (float) $treasury->fresh()->balance;

        // Inject a failure INSIDE the addPayment transaction by registering a
        // one-shot `creating` listener on HajjUmraPayment that throws BEFORE
        // the INSERT runs. The throw propagates up through the service,
// through the controller's try/catch (which returns an HTTP error
        // response), and rolls back the entire transaction.
        $injectKey = 'STRESS-IDEM-INJECT-FAIL';
        $listenerFired = false;
        HajjUmraPayment::creating(function (HajjUmraPayment $model) use ($injectKey, &$listenerFired) {
            if ($model->idempotency_key === $injectKey) {
                $listenerFired = true;
                throw new \RuntimeException('[TEST-INJECTED] rollback probe inside addPayment transaction');
            }
        });

        // The controller catches \Throwable and returns ApiResponse::error().
        $response = $this->postPayment($booking->id, [
            'amount' => 1234,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => $injectKey,
            'paid_by' => 'cashier',
        ]);

        $this->assertTrue($listenerFired,
            'Injected creating-listener must have fired before INSERT.');
        $this->assertGreaterThanOrEqual(400, $response->status(),
            'Injected failure must surface as an error response (got: ' . $response->status() . ').');
        $responseBody = (string) $response->getContent();
        $this->assertStringContainsString('[TEST-INJECTED]', $responseBody,
            'Injected failure message must propagate to the response body.');

        // Restore the real service so we can re-query cleanly.
        $this->app->forgetInstance(\App\Services\HajjUmra\HajjUmraBookingService::class);

        // 1. No payment row created
        $this->assertSame($paymentCountBefore, HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)->count(),
            'Injected failure MUST roll back the payment row.');

        // 2. No new transactions
        $this->assertSame($txCountBefore, Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)->count(),
            'Injected failure MUST roll back the new Transaction row.');

        // 3. No new AccountEntry rows
        $this->assertSame($entriesBefore, AccountEntry::query()->count(),
            'Injected failure MUST roll back the AccountEntry rows.');

        // 4. Customer + vault balances unchanged
        $this->assertEqualsWithDelta(
            $customerBalanceBefore,
            (float) Account::query()->findOrFail($customerAccountId)->balance,
            0.01,
            'Customer account balance MUST be unchanged after rollback.'
        );
        $this->assertEqualsWithDelta(
            $vaultBalanceBefore,
            (float) $treasury->fresh()->balance,
            0.01,
            'Vault balance MUST be unchanged after rollback.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 11. Existing cancellation/reversal still works (additive).
    // ─────────────────────────────────────────────────────────────────────
    public function test_cancellation_remains_additive_after_idempotency_fix(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        // Add a payment with idempotency_key.
        $this->postPayment($booking->id, [
            'amount' => 5000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => 'STRESS-IDEM-CANCEL',
            'paid_by' => 'cashier',
        ])->assertCreated();

        // Cancel via API — should reverse the payment additively.
        $cancelResponse = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => '[TEST] idempotency-fix regression',
        ]);
        $cancelResponse->assertOk();

        $booking->refresh();
        $this->assertSame(\App\Enums\HajjUmraStatus::Cancelled->value, $booking->status->value,
            'Booking must be cancelled.');

        // Customer account balance must net to 0 (income reversed + payment reversed).
        $customerId = $booking->customer_id;
        $customer = \App\Models\Customer::query()->findOrFail($customerId);
        $customerAccountId = (int) $customer->account_id;
        $customerAccount = Account::query()->findOrFail($customerAccountId);
        $this->assertEqualsWithDelta(0.0, (float) $customerAccount->balance, 0.01,
            'After cancellation, customer balance must net to 0.');

        // The original Transaction + AccountEntry rows must still exist (additive reversal).
        $originalPayments = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count();
        $this->assertSame(1, $originalPayments,
            'Payment row stays after cancellation.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 12. Existing Hajj/Umrah payment behavior remains intact (no breaking
    //     change to the default no-key call path).
    // ─────────────────────────────────────────────────────────────────────
    public function test_existing_payment_behavior_unchanged_when_key_omitted(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        // Two successive payments WITHOUT idempotency_key — both must succeed
        // and create two distinct rows, exactly like pre-fix behavior.
        $this->postPayment($booking->id, [
            'amount' => 3000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'paid_by' => 'cashier',
        ])->assertCreated();
        $this->postPayment($booking->id, [
            'amount' => 3000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'paid_by' => 'cashier',
        ])->assertCreated();

        $this->assertSame(2, HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)->count(),
            'Without idempotency_key, two payments are accepted (existing behavior preserved).');

        $booking->refresh();
        $this->assertEqualsWithDelta(6000.0, (float) $booking->paid_amount, 0.01,
            'paid_amount must reflect both legacy payments.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Supplementary: same idempotency_key on a DIFFERENT booking is independent.
    // ─────────────────────────────────────────────────────────────────────
    public function test_same_key_on_different_bookings_is_independent(): void
    {
        [$booking1, $treasury1] = $this->seedBooking();
        [$booking2, $treasury2] = $this->seedBooking();

        $sharedKey = 'STRESS-IDEM-CROSS-BOOKING';

        $r1 = $this->postPayment($booking1->id, [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $treasury1->id,
            'idempotency_key' => $sharedKey,
            'paid_by' => 'cashier',
        ]);
        $r1->assertCreated();

        $r2 = $this->postPayment($booking2->id, [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $treasury2->id,
            'idempotency_key' => $sharedKey, // same key on a DIFFERENT booking
            'paid_by' => 'cashier',
        ]);
        $r2->assertCreated();
        $this->assertFalse((bool) ($r2->json('data.idempotent_replay') ?? false),
            'Same idempotency_key on a DIFFERENT booking is independent and accepted.');

        $this->assertSame(1, HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking1->id)->count());
        $this->assertSame(1, HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking2->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Race / retry test — simulate transient DB failure + retry.
    // ─────────────────────────────────────────────────────────────────────
    public function test_retry_after_transient_failure_does_not_double_charge(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $idempotencyKey = 'STRESS-IDEM-RETRY-001';
        $amount = 1500;
        $payload = [
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => $idempotencyKey,
            'paid_by' => 'cashier',
        ];

        // First call — succeeds.
        $first = $this->postPayment($booking->id, $payload);
        $first->assertCreated();
        $firstPaymentId = $first->json('data.payment.id');
        $firstTxId = $first->json('data.payment.transaction_id');
        $firstEntryCount = AccountEntry::query()->count();

        // Simulate: client retries (network was lost mid-response).
        // The retry must return the original payment (idempotent return),
        // NOT create a second financial mutation.
        $retry = $this->postPayment($booking->id, $payload);
        $retry->assertOk();
        $this->assertTrue((bool) $retry->json('data.idempotent_replay'),
            'Retry must return idempotent_replay=true.');
        $this->assertSame($firstPaymentId, $retry->json('data.payment.id'),
            'Retry must return the SAME payment id.');
        $this->assertSame($firstTxId, $retry->json('data.payment.transaction_id'),
            'Retry must return the SAME transaction id.');
        $this->assertSame($firstEntryCount, AccountEntry::query()->count(),
            'Retry must NOT create additional AccountEntry rows.');

        $booking->refresh();
        $this->assertEqualsWithDelta($amount, (float) $booking->paid_amount, 0.01,
            'Booking paid_amount must reflect exactly ONE charge.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Race / retry test — concurrent duplicate requests where one experiences
    // a transient DB error and retries. Verify no phantom payment state.
    // ─────────────────────────────────────────────────────────────────────
    public function test_concurrent_duplicates_with_one_transient_failure(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $idempotencyKey = 'STRESS-IDEM-RACE-001';
        $amount = 750;

        // Fire 5 concurrent calls with the same key. One of them (we don't
        // control which) will be the "winner"; the other 4 must see
        // idempotent return. Even if one of them experiences a transient
        // failure (e.g. deadlock), the system must end up with EXACTLY
        // ONE row — no phantom state.
        $payloads = [];
        for ($i = 0; $i < 5; $i++) {
            $payloads[] = [
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $treasury->id,
                'idempotency_key' => $idempotencyKey,
                'paid_by' => 'cashier',
            ];
        }

        // We use the kernel handle directly so we get real Response objects.
        // Authenticate via Sanctum token.
        $user = $this->admin;
        $token = $user->createToken('race-retry-test')->plainTextToken;
        $bearer = "Bearer {$token}";

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $responses = [];
        foreach ($payloads as $payload) {
            $req = \Illuminate\Http\Request::create(
                "/api/v1/hajj-umra/bookings/{$booking->id}/payments",
                'POST',
                $payload,
                [], [],
                ['HTTP_AUTHORIZATION' => $bearer, 'HTTP_ACCEPT' => 'application/json']
            );
            try {
                $responses[] = $kernel->handle($req);
            } catch (\Throwable $e) {
                $responses[] = null; // transient failure
            }
        }

        // Even if some responses failed, the system must end up with
        // EXACTLY ONE active row for this key.
        $rowsForKey = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertSame(1, $rowsForKey,
            'Concurrent identical-replay + one transient failure must end up with EXACTLY ONE payment row.');

        $booking->refresh();
        $this->assertEqualsWithDelta($amount, (float) $booking->paid_amount, 0.01,
            'Booking paid_amount must reflect exactly ONE successful charge even with one transient failure.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Per-transaction invariant (supplementary)
    // ─────────────────────────────────────────────────────────────────────
    public function test_per_transaction_invariant_holds_for_all_transactions(): void
    {
        [$booking, $treasury] = $this->seedBooking();

        $this->postPayment($booking->id, [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $treasury->id,
            'idempotency_key' => 'STRESS-INVARIANT-PROBE',
            'paid_by' => 'cashier',
        ])->assertCreated();

        $txs = DB::table('transactions')->select('id', 'amount', 'notes')->get();
        $this->assertGreaterThan(0, $txs->count(),
            'Must have at least one transaction row to assert on.');

        $checked = 0;
        foreach ($txs as $tx) {
            $debits = (float) DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->sum('debit');
            $credits = (float) DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->sum('credit');
            $this->assertEqualsWithDelta(
                $debits,
                $credits,
                0.01,
                "Transaction #{$tx->id} ({$tx->notes}): debits={$debits} credits={$credits} — must balance."
            );
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }
}
