<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use Illuminate\Support\Str;

/**
 * Phase 9.2 — Admin E2E (Section 6 of the 30-section prompt).
 *
 * Exercises the admin-only /api/v1/visa/* surface across the full lifecycle:
 *
 *   CREATE → PAYMENT (multi-method) → CANCEL / REFUND / DELETE → financial
 *   assertions on the ledger and per-account balances.
 *
 * The headline gap closed by this file is **multi-method payment on the same
 * booking** (cash + bank on one EGP booking) — the existing E2E tests each
 * pay a single amount through a single account, never verifying that two
 * sequential `addPayment` calls with different accounts behave correctly.
 */
class VisaAdminFullLifecycleTest extends VisaTestCase
{
    /* ============================================================
     *  1. CREATE — initial payment shapes
     * ============================================================ */

    public function test_admin_can_create_booking_with_full_initial_payment(): void
    {
        $payload = $this->bookingPayload([
            'initial_payment' => [
                'amount' => 1600.0,   // 1500 selling + 100 fee
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ]);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $booking = VisaBooking::findOrFail($bookingId);
        $this->assertSame(1600.0, (float) $booking->paid_amount,
            'paid_amount must equal the full selling+fee after single-shot payment');
        $this->assertSame(0.0, (float) $booking->remaining_amount,
            'remaining_amount must be 0 after full payment');
    }

    public function test_admin_can_create_booking_with_zero_initial_payment(): void
    {
        $payload = $this->bookingPayload();
        unset($payload['initial_payment']);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $booking = VisaBooking::findOrFail($bookingId);
        $this->assertSame(0.0, (float) $booking->paid_amount);
        $this->assertSame(1600.0, (float) $booking->remaining_amount,
            'remaining_amount must equal full selling+fee when no initial payment');
    }

    public function test_admin_can_create_booking_with_partial_initial_payment(): void
    {
        $payload = $this->bookingPayload([
            'initial_payment' => [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ]);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $booking = VisaBooking::findOrFail($bookingId);
        $this->assertSame(500.0, (float) $booking->paid_amount);
        $this->assertSame(1100.0, (float) $booking->remaining_amount,
            'remaining must be selling+fee - paid = 1600 - 500');
    }

    /* ============================================================
     *  2. PAYMENT — including the HEADLINE multi-method test
     * ============================================================ */

    public function test_admin_can_add_payment_to_existing_booking(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_FULL_PAY_'.uniqid(),
        ]);
        $response->assertCreated();

        $this->assertSame(1600.0, (float) $booking->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $booking->fresh()->remaining_amount);
    }

    public function test_admin_can_make_multi_method_payment_on_same_booking(): void
    {
        // HEADLINE GAP: two different payment methods (cash + bank) on the same booking
        $booking = $this->makeBooking();

        // 1st payment: 700 EGP via cashbox (vaultEgp)
        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 700.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_MULTI_CASH_'.uniqid(),
        ]);
        $r1->assertCreated();

        // 2nd payment: 900 EGP via bank (bankEgp) — same booking, different account
        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 900.0,
            'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'idempotency_key' => 'P92_MULTI_BANK_'.uniqid(),
        ]);
        $r2->assertCreated();

        // Total paid = 700 + 900 = 1600 (full selling+fee)
        $booking->refresh();
        $this->assertSame(1600.0, (float) $booking->paid_amount,
            'paid_amount must equal sum of all payments across methods');
        $this->assertSame(0.0, (float) $booking->remaining_amount);

        // Two distinct payment rows exist
        $payments = VisaPayment::where('visa_booking_id', $booking->id)->get();
        $this->assertCount(2, $payments, 'two addPayment calls must produce two payment rows');
        $this->assertEqualsCanonicalizing(
            [$this->vaultEgp->id, $this->bankEgp->id],
            $payments->pluck('account_id')->all(),
            'payments must be on their respective accounts'
        );
    }

    public function test_admin_payment_reduces_remaining_balance_correctly(): void
    {
        $booking = $this->makeBooking();
        $this->assertSame(1600.0, (float) $booking->remaining_amount,
            'sanity: full selling+fee starts as remaining');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 600.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_REMAIN_'.uniqid(),
        ])->assertCreated();

        $this->assertSame(600.0, (float) $booking->fresh()->paid_amount);
        $this->assertSame(1000.0, (float) $booking->fresh()->remaining_amount);
    }

    public function test_admin_cannot_make_payment_exceeding_remaining_balance(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 5000.0,   // 3x the remaining balance
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_OVERPAY_'.uniqid(),
        ]);
        $response->assertStatus(422);

        $this->assertSame(0.0, (float) $booking->fresh()->paid_amount,
            'overpayment must not change paid_amount');
    }

    public function test_admin_cannot_make_payment_on_cancelled_booking(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.2 cancel-before-payment test',
        ])->assertOk();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_PAY_CANCELLED_'.uniqid(),
        ]);
        $response->assertStatus(422, 'payment on cancelled booking must be rejected');
    }

    /* ============================================================
     *  3. CANCEL — with full / partial / zero payment
     * ============================================================ */

    public function test_admin_can_cancel_booking_with_full_payment(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_CXL_FULL_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.2 cancel after full payment',
        ]);
        $response->assertOk();

        $booking->refresh();
        $this->assertEquals(VisaStatus::Cancelled, $booking->status,
            'status must transition to cancelled');
        // paid_amount is the GROSS (additive-reversal pattern) — original preserved,
        // reversal recorded in ledger separately. Account balances are the NET.
        // See test_admin_full_lifecycle_create_pay_cancel_ends_with_zero_net_balance
        // for the NET verification.
    }

    public function test_admin_can_cancel_booking_with_partial_payment(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_CXL_PART_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.2 cancel after partial payment',
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(VisaStatus::Cancelled, $booking->status);
    }

    public function test_admin_can_cancel_booking_with_zero_payment(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.2 cancel of debt booking',
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(VisaStatus::Cancelled, $booking->status);
    }

    /* ============================================================
     *  4. REFUND
     * ============================================================ */

    public function test_admin_can_refund_fully_paid_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_REFUND_FULL_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.2 full refund',
        ]);
        $response->assertOk();

        $booking->refresh();
        $this->assertEquals(VisaStatus::Refunded, $booking->status,
            'refund must transition status to refunded');
    }

    public function test_admin_refund_of_unpaid_booking_is_no_op_with_status_change(): void
    {
        // Documented behavior: refund on unpaid booking is allowed and transitions
        // status to Refunded. The financial effect is a no-op (no payments to
        // reverse). This is the system's "void" path.
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.2 refund of unpaid — should succeed as no-op',
        ]);
        $response->assertOk();

        $booking->refresh();
        $this->assertEquals(VisaStatus::Refunded, $booking->status,
            'unpaid refund must still transition status to refunded');
        $this->assertSame(0.0, (float) $booking->paid_amount,
            'unpaid refund must not change paid_amount (still 0)');
    }

    /* ============================================================
     *  5. DELETE (soft)
     * ============================================================ */

    public function test_admin_can_soft_delete_unpaid_booking(): void
    {
        $booking = $this->makeBooking();

        $response = $this->deleteJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertOk();

        $booking = VisaBooking::withTrashed()->findOrFail($booking->id);
        $this->assertNotNull($booking->deleted_at, 'deleted_at must be set after soft-delete');
    }

    public function test_admin_can_soft_delete_paid_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_DEL_PAID_'.uniqid(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();

        $booking = VisaBooking::withTrashed()->findOrFail($booking->id);
        $this->assertNotNull($booking->deleted_at);
        $this->assertSame(0.0, (float) $booking->paid_amount,
            'paid_amount must be zeroed by additive reversal on delete');
    }

    /* ============================================================
     *  6. FINANCIAL VISIBILITY
     * ============================================================ */

    public function test_admin_can_view_treasury_overview(): void
    {
        $response = $this->getJson('/api/v1/visa/treasury/overview');
        $response->assertOk();
    }

    public function test_admin_can_view_customer_statement(): void
    {
        $this->makeBooking();

        $response = $this->getJson("/api/v1/visa/customer-statement?client_id={$this->customer->id}");
        $response->assertOk();
    }

    public function test_admin_can_view_customer_balances(): void
    {
        $response = $this->getJson('/api/v1/visa/customer-balances');
        $response->assertOk();
    }

    /* ============================================================
     *  7. FINANCIAL CORRECTNESS — ledger invariants
     * ============================================================ */

    public function test_admin_multi_method_payment_leaves_ledger_globally_balanced(): void
    {
        $booking = $this->makeBooking();

        // Multi-method payment: cash 700 + bank 900
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 700.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_INV_CASH_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 900.0, 'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'idempotency_key' => 'P92_INV_BANK_'.uniqid(),
        ])->assertCreated();

        // Each account must have balance == SUM(credit) - SUM(debit)
        $this->assertLedgerBalancedForAccount($this->vaultEgp);
        $this->assertLedgerBalancedForAccount($this->bankEgp);
        $this->assertLedgerBalancedForAccount($this->agent->account);

        // Global: SUM(credit) == SUM(debit) across ALL entries
        $this->assertLedgerGloballyBalanced();
    }

    public function test_admin_full_lifecycle_create_pay_cancel_ends_with_zero_net_balance(): void
    {
        $baselineVault = (float) $this->vaultEgp->fresh()->balance;
        $baselineBank = (float) $this->bankEgp->fresh()->balance;

        // 1. Create
        $booking = $this->makeBooking();
        // 2. Pay (multi-method)
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P92_LIFE_CASH_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 600.0, 'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'idempotency_key' => 'P92_LIFE_BANK_'.uniqid(),
        ])->assertCreated();
        // 3. Cancel
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.2 lifecycle test',
        ])->assertOk();

        // After full cycle (create + 2 pay + cancel), all additive reversals
        // must bring every account back to its baseline. The expense (cost)
        // reversal and payment reversal should both fire on cancel.
        $this->assertEqualsWithDelta(
            $baselineVault, (float) $this->vaultEgp->fresh()->balance, 0.01,
            'vaultEgp must return to baseline after create+pay+cancel cycle'
        );
        $this->assertEqualsWithDelta(
            $baselineBank, (float) $this->bankEgp->fresh()->balance, 0.01,
            'bankEgp must return to baseline after create+pay+cancel cycle'
        );

        // And the booking itself is cancelled
        $this->assertEquals(VisaStatus::Cancelled, $booking->fresh()->status);
    }
}
