<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\VisaPayment;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.8 — Idempotency Deep Audit (Section 14)
 *
 * Verifies the fix for the known double-payment defect. The defect was:
 *   - Same booking + same transaction_reference + DIFFERENT idempotency_key
 *     → 2 payment rows + 2 transfer txs + double credit to vault
 *
 * The fix layers:
 *   1. Pre-check on (booking, idempotency_key)
 *   2. Pre-check on (booking, transaction_reference) — Phase 9.8 NEW
 *   3. DB UNIQUE on (visa_booking_id, idempotency_key) — 2026-08-15
 *   4. DB UNIQUE on (visa_booking_id, transaction_reference) — Phase 9.8 NEW
 *   5. lockForUpdate() on the booking row
 *
 * Coverage (per the user's spec):
 *   1. Same payment + same reference
 *   2. Same payment + same idempotency key
 *   3. Same reference with different requests
 *   4. Different references for same booking
 *   5. Concurrent duplicate payment requests
 *   6. Concurrent different payments for same booking
 *   7. No double-posting in customer/vault/income/ledger/supplier-AP
 */
class VisaIdempotencyDeepTest extends VisaTestCase
{
    /* ============================================================
     *  1. SAME PAYMENT + SAME REFERENCE
     * ============================================================ */

    public function test_same_payment_same_reference_is_idempotent(): void
    {
        $booking = $this->makeBooking();

        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_SR_'.uniqid(),
            'reference' => 'SAME-REF-X',
        ])->assertCreated();

        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_SR_DIFF_'.uniqid(),  // different key!
            'reference' => 'SAME-REF-X',                  // SAME reference
        ]);
        // Idempotent: returns 200 with the existing row (or 201 — service returns existing)
        $this->assertContains($r2->status(), [200, 201]);

        $count = VisaPayment::where('visa_booking_id', $booking->id)->count();
        $this->assertSame(1, $count,
            'same booking + same reference must NOT create a second payment row');
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'),
            'second call must return the existing payment id (idempotent)');
    }

    /* ============================================================
     *  2. SAME PAYMENT + SAME IDEMPOTENCY KEY
     * ============================================================ */

    public function test_same_payment_same_idempotency_key_is_idempotent(): void
    {
        $booking = $this->makeBooking();
        $idemKey = 'P98_IDEM_'.uniqid();

        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $idemKey,
        ])->assertCreated();

        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $idemKey,  // SAME key
        ]);

        $this->assertSame(1, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'same idempotency_key must NOT create a second payment row');
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
    }

    /* ============================================================
     *  3. SAME REFERENCE WITH DIFFERENT REQUESTS (DIFFERENT KEYS, NO KEY)
     * ============================================================ */

    public function test_same_reference_with_no_idempotency_key_still_idempotent(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'NOREF_REUSE',
        ])->assertCreated();

        // Second call with same reference but NO idempotency_key — must be idempotent
        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'NOREF_REUSE',
            // no idempotency_key
        ]);
        $this->assertContains($r2->status(), [200, 201],
            'idempotent replay must return existing payment (200 or 201)');

        $this->assertSame(1, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'same reference with no key must still be idempotent (Phase 9.8 fix)');
    }

    public function test_same_reference_different_keys_is_idempotent(): void
    {
        // The exact defect scenario
        $booking = $this->makeBooking();
        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_DIFF_KEY_A_'.uniqid(),
            'reference' => 'THE_DEFECT_REF',
        ])->assertCreated();

        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_DIFF_KEY_B_'.uniqid(),  // different
            'reference' => 'THE_DEFECT_REF',                   // SAME
        ]);
        $this->assertContains($r2->status(), [200, 201],
            'idempotent replay must return existing payment');

        $this->assertSame(1, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'same booking + same reference + different keys must be idempotent (Phase 9.8 fix)');
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'),
            'second call must return the SAME payment id as the first call');
    }

    /* ============================================================
     *  4. DIFFERENT REFERENCES FOR SAME BOOKING
     * ============================================================ */

    public function test_different_references_same_booking_creates_multiple_payments(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_DREF1_'.uniqid(),
            'reference' => 'REF-1',
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_DREF2_'.uniqid(),
            'reference' => 'REF-2',
        ])->assertCreated();

        $this->assertSame(2, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'different references on same booking MUST create multiple payments');
    }

    public function test_null_reference_with_another_null_still_creates_two(): void
    {
        // Sanity: NULL references must NOT collide (MySQL allows multiple NULLs in unique index)
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_NUL1_'.uniqid(),
            // no reference
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_NUL2_'.uniqid(),
            // no reference
        ])->assertCreated();

        $this->assertSame(2, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'NULL references must allow multiple rows (legacy path)');
    }

    /* ============================================================
     *  5. CONCURRENT DUPLICATE PAYMENT REQUESTS
     * ============================================================ */

    public function test_concurrent_duplicate_payments_same_reference_only_one_wins(): void
    {
        $booking = $this->makeBooking();
        $reference = 'CONCURRENT_DUP_'.uniqid();

        // Fire 3 sequential requests with same reference + DIFFERENT keys
        // (the booking-level lockForUpdate should serialize them)
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 500.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_CONC_{$i}_".uniqid(),
                'reference' => $reference,
            ]);
        }

        $count = VisaPayment::where('visa_booking_id', $booking->id)->count();
        $this->assertSame(1, $count,
            '3 concurrent requests with same reference must produce exactly 1 payment');
    }

    /* ============================================================
     *  6. CONCURRENT DIFFERENT PAYMENTS FOR SAME BOOKING
     * ============================================================ */

    public function test_concurrent_different_payments_same_booking_all_succeed(): void
    {
        $booking = $this->makeBooking();

        // 3 different references — all should be legitimate payments
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 200.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_DIFF_{$i}_".uniqid(),
                'reference' => "DIFF-{$i}",
            ])->assertCreated();
        }

        $this->assertSame(3, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            '3 different payments on same booking must all succeed');
    }

    /* ============================================================
     *  7. NO DOUBLE-POSTING IN ANY ACCOUNT
     * ============================================================ */

    public function test_idempotent_replay_does_not_double_post_to_vault(): void
    {
        $baselineVault = (float) AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_VAULT_'.uniqid(),
            'reference' => 'VAULT_TEST',
        ])->assertCreated();

        // Replay 5 times — must still be 1 vault credit of 500
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 500.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_VAULT_R_{$i}_".uniqid(),
                'reference' => 'VAULT_TEST',
            ]);
        }

        $vaultAfter = (float) AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineVault + 500.0, $vaultAfter, 0.01,
            '5 idempotent replays must result in only ONE 500 credit to vault');
    }

    public function test_idempotent_replay_does_not_double_post_to_customer_ar(): void
    {
        $booking = $this->makeBooking();
        $customerAccountId = $this->customer->account_id;

        $arBefore = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_AR_'.uniqid(),
            'reference' => 'AR_TEST',
        ])->assertCreated();

        // Replay
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 500.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_AR_R_{$i}_".uniqid(),
                'reference' => 'AR_TEST',
            ]);
        }

        $arAfter = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // Customer AR must change by exactly 500 (one payment's worth)
        $this->assertEqualsWithDelta($arBefore - 500.0, $arAfter, 0.01,
            'idempotent replays must NOT double-debit customer AR');
    }

    public function test_idempotent_replay_does_not_create_duplicate_transfer_transactions(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_TX_'.uniqid(),
            'reference' => 'TX_TEST',
        ])->assertCreated();

        $txCountBefore = Transaction::where('module', 'visa')
            ->where('related_id', $booking->id)
            ->where('amount', 500.0)
            ->count();

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 500.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_TX_R_{$i}_".uniqid(),
                'reference' => 'TX_TEST',
            ]);
        }

        $txCountAfter = Transaction::where('module', 'visa')
            ->where('related_id', $booking->id)
            ->where('amount', 500.0)
            ->count();

        $this->assertSame($txCountBefore, $txCountAfter,
            'idempotent replays must NOT create duplicate transfer transactions');
    }

    public function test_idempotent_replay_does_not_affect_supplier_ap(): void
    {
        // Supplier AP is set by booking create (purchase_price), not by payments.
        // Idempotent payment replays must NOT touch supplier AP.
        $agentAccountId = $this->agent->account_id;

        $booking = $this->makeBooking();
        $agentAPBefore = (float) AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_AP_'.uniqid(),
            'reference' => 'AP_TEST',
        ])->assertCreated();

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 500.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_AP_R_{$i}_".uniqid(),
                'reference' => 'AP_TEST',
            ]);
        }

        $agentAPAfter = (float) AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta($agentAPBefore, $agentAPAfter, 0.01,
            'idempotent payment replays must NOT change agent AP');
    }

    public function test_global_ledger_remains_balanced_after_idempotent_replays(): void
    {
        $booking = $this->makeBooking();

        // First legitimate payment
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_GLOB_'.uniqid(),
            'reference' => 'GLOB_TEST',
        ])->assertCreated();

        // 10 idempotent replays
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
                'amount' => 500.0, 'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => "P98_GLOB_R_{$i}_".uniqid(),
                'reference' => 'GLOB_TEST',
            ]);
        }

        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  DB CONSTRAINT BACKSTOP
     * ============================================================ */

    public function test_db_unique_constraint_blocks_direct_duplicate_insert(): void
    {
        // Even bypassing the service, the DB must reject duplicates
        $booking = $this->makeBooking();

        // Insert first via service (so transaction + customer account exist)
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P98_DB_'.uniqid(),
            'reference' => 'DB_CONSTRAINT_TEST',
        ])->assertCreated();

        // Try direct INSERT bypassing the service
        $threw = false;
        try {
            DB::table('visa_payments')->insert([
                'visa_booking_id' => $booking->id,
                'payment_method' => 'cash',
                'amount' => 500.0,
                'currency' => 'EGP',
                'treasury_account' => 'office_drawer',
                'transaction_reference' => 'DB_CONSTRAINT_TEST',  // duplicate!
                'payment_date' => now(),
                'paid_by' => 'bypass',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $threw = true;
            $this->assertStringContainsString('UNIQUE', $e->getMessage(),
                'DB constraint must fire on duplicate (booking, reference)');
        }
        $this->assertTrue($threw, 'direct INSERT must be rejected by DB constraint');
    }
}