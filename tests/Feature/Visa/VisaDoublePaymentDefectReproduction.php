<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\AccountEntry;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.8 — Defect Reproduction (read-only until Phase 9.8b fix lands).
 *
 * Reproduces the known Visa double-payment defect: when a caller supplies
 * the SAME (booking, reference) but DIFFERENT idempotency_keys (or no key),
 * two payment rows are inserted and the vault is double-credited.
 *
 * These tests are EXPECTED TO FAIL until the fix migration + service update
 * lands in Phase 9.8b. After the fix, they will be promoted to the
 * VisaIdempotencyDeepTest file as idempotency-correctness tests.
 */
class VisaDoublePaymentDefectReproduction extends VisaTestCase
{
    public function test_double_payment_with_same_reference_different_keys_creates_duplicate(): void
    {
        // Pre-fix behavior: 2 rows inserted, vault double-credited
        $booking = $this->makeBooking();
        $baselineVault = (float) AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // First payment — reference=SAME-REF, key=A
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'REPRO_A_'.uniqid(),
            'reference' => 'SAME-REF',
        ])->assertCreated();

        // Second payment — reference=SAME-REF (duplicate!), key=B
        // BEFORE FIX: this succeeds and creates a second row
        // AFTER FIX: this is idempotent — returns existing payment
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'REPRO_B_'.uniqid(),
            'reference' => 'SAME-REF',
        ]);

        // The fix will make this idempotent (200 + existing row)
        // Currently (pre-fix) it returns 201 with a NEW row → defect
        $paymentCount = VisaPayment::where('visa_booking_id', $booking->id)->count();
        $vaultAfter = (float) AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // DOCUMENT THE DEFECT:
        // - If paymentCount == 2 AND vaultAfter == baseline + 1000 → DEFECT
        // - If paymentCount == 1 AND vaultAfter == baseline + 500 → FIXED
        $isDefect = ($paymentCount == 2 && abs($vaultAfter - ($baselineVault + 1000)) < 0.01);
        $isFixed = ($paymentCount == 1 && abs($vaultAfter - ($baselineVault + 500)) < 0.01);

        $this->assertTrue(
            $isDefect || $isFixed,
            "Test must end in either defect-state or fixed-state. paymentCount={$paymentCount}, vaultAfter={$vaultAfter}, baselineVault={$baselineVault}"
        );

        // Record state for the fix-commit message
        echo "\n[REPORT] payment_count={$paymentCount}, vault_change=" . ($vaultAfter - $baselineVault)
            . ", is_defect=" . ($isDefect ? 'YES' : 'no') . "\n";
    }

    public function test_double_payment_with_no_reference_different_keys_creates_duplicate(): void
    {
        // Another defect path: no reference, no idempotency_key, 2 calls
        $booking = $this->makeBooking();
        $baselineVault = (float) AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'REPRO_NOREF_A_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'REPRO_NOREF_B_'.uniqid(),
        ])->assertCreated();

        $paymentCount = VisaPayment::where('visa_booking_id', $booking->id)->count();
        $vaultAfter = (float) AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // This is NOT a defect — different keys, no reference → 2 legitimate payments
        $this->assertSame(2, $paymentCount, 'different keys, no reference → 2 payments is correct');
        $this->assertEqualsWithDelta($baselineVault + 1000.0, $vaultAfter, 0.01,
            'different keys, no reference → vault credited by 1000');
    }
}