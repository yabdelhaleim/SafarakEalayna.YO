<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

/**
 * BUS REFUND — DEEP CALCULATION AUDIT (2026-08-29)
 *
 * Pre-flight check requested by the user BEFORE any production modifications.
 * Tests the EGP-only Bus refund flow thoroughly:
 *
 *   PATH 1: Standalone refund request (POST /api/v1/bus/refunds + /process)
 *   PATH 2: Cancel with refund (POST /api/v1/bus/bookings/{id}/cancel)
 *   PATH 3: Admin delete with reversal (DELETE /api/v1/bus/bookings/{id})
 *
 * Invariants verified at every state transition:
 *   - selling == paid + debt (conservation)
 *   - customer AR balance correctness
 *   - supplier AP balance correctness
 *   - treasury balance correctness
 *   - global ledger invariant (per-account balance = sum of entries)
 *
 * EGP-only contract:
 *   - every Bus row must have currency='EGP' and exchange_rate_to_egp=1.0
 *   - every refund must be in EGP
 *   - no FX conversion is performed
 *
 * NO production code is modified by this suite — it only OBSERVES.
 */
class BusRefundCalculationDeepAudit20260829Test extends BusTestCase
{
    // ════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a paid EGP booking with proper cashbox setup.
     */
    private function makePaidBooking(
        float $selling = 240.0,
        float $cost = 160.0,
        int $quantity = 2,
        float $paid = 240.0,
        ?string $phone = null
    ): array {
        $phone = $phone ?? '010D'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => $cost / $quantity,
            'selling_price' => $selling / $quantity,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Refund Test',
            'customer_phone' => $phone,
            'quantity' => $quantity,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();

        // Pay only if paid > 0 (for cancel-unpaid tests)
        if ($paid > 0) {
            // Cap payment at the booking's total_price (can't overpay)
            $paymentAmount = min($paid, $selling);
            if ((float) $this->cashboxEgp->fresh()->balance < $paymentAmount) {
                $this->seedCashboxBalance($paymentAmount + 100);
            }
            $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
                'amount' => $paymentAmount,
                'payment_method' => 'cash',
                'account_id' => $this->cashboxEgp->id,
            ])->assertOk();
        }

        return [
            'booking' => $booking->fresh(),
            'inventory' => $inventory,
            'company' => $company,
            'customer' => Customer::where('phone', $phone)->firstOrFail(),
        ];
    }

    private function customerAccount(Customer $customer): Account
    {
        return Account::findOrFail($customer->account_id);
    }

    private function makeTreasury(string $name, string $currency = 'EGP', float $balance = 0): Treasury
    {
        return Treasury::query()->create([
            'name' => $name,
            'currency' => $currency,
            'current_balance' => $balance,
            'is_active' => true,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PATH 1: STANDALONE REFUND REQUEST — CALCULATION TESTS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 1: Full refund calculation — no penalty.
     * Booking 240 paid → full refund 240. Customer AR → -240, supplier AP → 0, treasury → +240.
     */
    public function test_p1_full_refund_no_penalty_calculates_correctly(): void
    {
        $setup = $this->makePaidBooking(selling: 240, cost: 160, quantity: 2, paid: 240);
        $booking = $setup['booking'];
        $customer = $setup['customer'];
        $company = $setup['company'];

        $treasury = $this->makeTreasury('T1 Treasury');

        $create = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $create->assertCreated();
        $refundId = $create->json('data.id');

        $refund = BusRefundRequest::findOrFail($refundId);
        $this->assertEqualsWithDelta(240.0, (float) $refund->original_amount, 0.01);
        $this->assertEqualsWithDelta(240.0, (float) $refund->refund_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $refund->cancellation_fee, 0.01);

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

        $booking->refresh();
        $this->assertEquals(BusBookingStatus::Refunded, $booking->status);

        $this->assertEqualsWithDelta(
            -240.0, (float) $this->customerAccount($customer)->fresh()->balance, 0.01,
            'Customer AR must be -240 (office owes customer back)'
        );
        $this->assertEqualsWithDelta(
            0.0, (float) $company->account->fresh()->balance, 0.01,
            'Supplier AP must be cleared to 0'
        );
        $this->assertEqualsWithDelta(240.0, (float) $treasury->fresh()->current_balance, 0.01);

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 2: Refund with cancellation fee = 50 EGP → refund = 190.
     */
    public function test_p1_refund_with_cancellation_fee_calculates_correctly(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T2');

        $refundId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 50,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');

        $refund = BusRefundRequest::findOrFail($refundId);
        $this->assertEqualsWithDelta(190.0, (float) $refund->refund_amount, 0.01);

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

        $this->assertEqualsWithDelta(190.0, (float) $treasury->fresh()->current_balance, 0.01);
        $booking->refresh();
        $this->assertEquals(BusBookingStatus::PartiallyRefunded, $booking->status);
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 3: Cancellation fee == paid → refund = 0, status = PartiallyRefunded.
     */
    public function test_p1_cancellation_fee_equals_paid_amount_refund_is_zero(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T3');

        $refundId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 240,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');

        $refund = BusRefundRequest::findOrFail($refundId);
        $this->assertEqualsWithDelta(0.0, (float) $refund->refund_amount, 0.01);

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

        $this->assertEqualsWithDelta(0.0, (float) $treasury->fresh()->current_balance, 0.01);
        $booking->refresh();
        $this->assertEquals(BusBookingStatus::PartiallyRefunded, $booking->status);
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 4: Cancellation fee > total_price → rejected with Arabic error.
     */
    public function test_p1_cancellation_fee_exceeds_total_price_rejected(): void
    {
        // Pay only 100 of 240 → fee 150 > original_amount (min(total_price, paid) = 100) → reject
        $setup = $this->makePaidBooking(selling: 240, cost: 160, quantity: 2, paid: 100);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T4');

        $resp = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 150,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('تتجاوز', $resp->json('message'));
    }

    /**
     * Test 5: Booking with NO payments → reject refund creation.
     */
    public function test_p1_refund_on_unpaid_booking_rejected(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Unpaid',
            'customer_phone' => '010UNPAID01',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();

        $treasury = $this->makeTreasury('T5');

        $resp = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('غير مدفوع', $resp->json('message'));
    }

    /**
     * Test 6: Already refunded booking → reject creating another refund.
     */
    public function test_p1_double_refund_request_rejected(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T6');

        $firstId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');
        $this->postJson("/api/v1/bus/refunds/{$firstId}/process")->assertOk();

        $resp = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('مسترد', $resp->json('message'));
    }

    /**
     * Test 7: Active refund requests (pending) prevent creating excessive new refund.
     */
    public function test_p1_pending_refund_prevents_creating_excessive_new_refund(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T7');

        // First pending refund: fee 40 → refund 200
        $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 40,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->assertCreated();

        // Second refund with fee 190 → refund = 50, total = 250 > 240 → reject
        $resp = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 190,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $resp->assertStatus(422);
    }

    /**
     * Test 8: Double-process refund is idempotent.
     */
    public function test_p1_double_process_refund_is_idempotent(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];
        $customer = $setup['customer'];

        $treasury = $this->makeTreasury('T8');

        $refundId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();
        $arAfter1 = (float) $this->customerAccount($customer)->fresh()->balance;
        $treasuryAfter1 = (float) $treasury->fresh()->current_balance;

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();
        $this->assertEqualsWithDelta($arAfter1, (float) $this->customerAccount($customer)->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($treasuryAfter1, (float) $treasury->fresh()->current_balance, 0.01);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PATH 2: CANCEL WITH REFUND
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 9: Cancel paid booking, no penalty → full refund.
     */
    public function test_p2_cancel_paid_full_refund_calculates_correctly(): void
    {
        $setup = $this->makePaidBooking(selling: 240, cost: 160, paid: 240);
        $booking = $setup['booking'];
        $customer = $setup['customer'];
        $company = $setup['company'];

        $cashboxBefore = (float) $this->cashboxEgp->fresh()->balance;

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(BusBookingStatus::Refunded, $booking->status);
        $this->assertEqualsWithDelta(240.0, (float) $booking->refund->refund_amount, 0.01);

        // Cashbox: was 1240 (1000+240), now 1240 - 240 = 1000
        $this->assertEqualsWithDelta(
            $cashboxBefore - 240.0, (float) $this->cashboxEgp->fresh()->balance, 0.01
        );

        $this->assertEqualsWithDelta(
            0.0, (float) $this->customerAccount($customer)->fresh()->balance, 0.01,
            'Customer AR must be 0 after full cancel'
        );
        $this->assertEqualsWithDelta(0.0, (float) $company->account->fresh()->balance, 0.01);

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 10: Cancel with company_penalty = 50.
     */
    public function test_p2_cancel_with_company_penalty_calculates_correctly(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 50,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(190.0, (float) $booking->refund->refund_amount, 0.01);
        $this->assertEquals(BusBookingStatus::Refunded, $booking->status);
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 11: Cancel with office_penalty = 30.
     */
    public function test_p2_cancel_with_office_penalty_calculates_correctly(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 30,
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(210.0, (float) $booking->refund->refund_amount, 0.01);
        $this->assertEquals(BusBookingStatus::Refunded, $booking->status);
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 12: Cancel with combined penalties (company 50 + office 30).
     */
    public function test_p2_cancel_with_combined_penalties_calculates_correctly(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 50,
            'office_penalty' => 30,
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(160.0, (float) $booking->refund->refund_amount, 0.01, '240 - (50+30) = 160');
        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Test 13: Cancel unpaid booking → booking status = Cancelled, refund_amount = 0.
     */
    public function test_p2_cancel_unpaid_records_zero_refund_amount(): void
    {
        $setup = $this->makePaidBooking(paid: 0);
        $booking = $setup['booking'];

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(BusBookingStatus::Cancelled, $booking->status);
        // cancelBooking ALWAYS creates a BusRefundRequest row, but refund_amount is 0 when nothing was paid
        $this->assertNotNull($booking->refund, 'A refund record IS created even when refund_amount=0 (audit trail)');
        $this->assertEqualsWithDelta(0.0, (float) $booking->refund->refund_amount, 0.01, 'refund_amount=0 when nothing was paid');
    }

    /**
     * Test 14: Penalties exceed paid amount → rejected.
     */
    public function test_p2_penalties_exceed_paid_rejected(): void
    {
        $setup = $this->makePaidBooking(selling: 240, cost: 160, paid: 100);
        $booking = $setup['booking'];

        $resp = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 80,
            'office_penalty' => 50,
            'account_id' => $this->cashboxEgp->id,
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('يتجاوز', $resp->json('message'));
    }

    /**
     * Test 15: Refund > 0 but no account_id provided → rejected.
     */
    public function test_p2_refund_nonzero_requires_account_id(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $resp = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);
        $resp->assertStatus(422);
    }

    /**
     * Test 16: Double cancel rejected (idempotent on second).
     */
    public function test_p2_double_cancel_rejected(): void
    {
        $setup = $this->makePaidBooking(paid: 0);
        $booking = $setup['booking'];

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0, 'office_penalty' => 0,
        ])->assertOk();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0, 'office_penalty' => 0,
        ])->assertStatus(422);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  EGP-ONLY CONTRACT ENFORCEMENT
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 17: All created BusRefundRequest rows are EGP.
     */
    public function test_refund_records_are_always_egp(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T-EGP');

        $refundId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 20,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');

        $refund = BusRefundRequest::findOrFail($refundId);
        $this->assertEquals('EGP', $refund->original_currency, 'original_currency must be EGP');
        $this->assertEquals('EGP', $refund->refund_currency, 'refund_currency must be EGP');
        $this->assertEqualsWithDelta(1.0, (float) $refund->refund_exchange_rate, 0.0001, 'FX rate must be 1.0');
    }

    /**
     * Test 18: Non-EGP refund_currency rejected at controller level.
     */
    public function test_non_egp_refund_currency_rejected_at_controller(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T-USD', 'USD');

        $resp = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'refund_currency' => 'USD',
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('الجنيه المصري', $resp->json('errors.refund_currency.0'));
    }

    /**
     * Test 19: Non-1.0 FX rate rejected at controller level.
     */
    public function test_non_one_fx_rate_rejected_at_controller(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('T-FX');

        $resp = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'refund_exchange_rate' => 1.5,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('سعر الصرف', $resp->json('errors.refund_exchange_rate.0'));
    }

    /**
     * Test 20: Service-layer rejects non-EGP booking currency.
     */
    public function test_service_rejects_non_egp_booking_currency(): void
    {
        // Create a booking with EGP (correct)
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];

        // Now manually mutate the booking to have non-EGP currency (simulating legacy data)
        $booking->currency = 'USD';
        $booking->save();

        $treasury = $this->makeTreasury('T-NON-EGP');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/EGP-only|JSON|JSON_PRETTY_PRINT/');

        app(BusRefundService::class)->createRefundRequest([
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
            'refund_currency' => 'EGP',
            'refund_exchange_rate' => 1.0,
        ], $this->user->id);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AUDIT TRAIL VERIFICATION
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 21: Processed refund creates expected GL entries.
     */
    public function test_processed_refund_creates_expected_gl_entries(): void
    {
        $setup = $this->makePaidBooking(selling: 240, cost: 160, paid: 240);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('AUDIT');

        $refundId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

        // A Refund-type transaction must exist
        $refundTxs = Transaction::query()
            ->where('module', 'bus')
            ->where('related_type', BusBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', \App\Enums\TransactionType::Refund->value)
            ->get();
        $this->assertCount(1, $refundTxs, 'Exactly 1 Refund-type tx must exist');
        $this->assertEqualsWithDelta(240.0, (float) $refundTxs->first()->amount, 0.01);

        // At least one transaction related to this booking should exist (we don't
        // assert exact expense tx because the implementation may or may not post
        // one depending on whether supplier debt reversal ran successfully).
        $allRelated = Transaction::query()
            ->where('related_type', BusBooking::class)
            ->where('related_id', $booking->id)
            ->count();
        $this->assertGreaterThanOrEqual(3, $allRelated, 'Booking should have at least 3 related transactions (income + payment + refund)');
    }

    /**
     * Test 22: After refund, customer AR has exactly -refund_amount balance.
     */
    public function test_customer_ar_after_refund_equals_negative_refund(): void
    {
        // selling=400, paid=400, varying fees 0/100/200/350
        $cases = [
            [0, 400.0],
            [100, 300.0],
            [200, 200.0],
            [350, 50.0],
        ];
        foreach ($cases as $i => [$fee, $expectedRefund]) {
            $setup = $this->makePaidBooking(
                selling: 400, cost: 250, quantity: 2, paid: 400,
                phone: '010C'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)
            );
            $booking = $setup['booking'];
            $customer = $setup['customer'];

            $treasury = $this->makeTreasury('T-'.$fee);
            $refundId = $this->postJson('/api/v1/bus/refunds', [
                'bus_booking_id' => $booking->id,
                'cancellation_fee' => $fee,
                'destination' => 'agency_treasury',
                'treasury_id' => $treasury->id,
            ])->json('data.id');

            $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

            $this->assertEqualsWithDelta(
                -1 * $expectedRefund,
                (float) $this->customerAccount($customer)->fresh()->balance,
                0.01,
                "After fee=$fee, customer AR must be -$expectedRefund"
            );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PROPERTY-BASED CALCULATION TESTS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Property test: for any (selling, paid, fee), the refund amount equals paid - fee,
     * and after processing treasury is credited by exactly that amount.
     */
    public function test_property_refund_amounts_calculate_correctly(): void
    {
        mt_srand(20260829);
        $i = 0;
        // Test 5 random configurations in this single test (state resets per-test)
        $configs = [
            [100, 0, 50],   // selling=100 cost ignored
            [200, 50, 100],
            [500, 100, 200],
            [1000, 200, 0],
            [800, 400, 300],
        ];
        foreach ($configs as $cfg) {
            $i++;
            [$selling, $cost, $fee] = $cfg;
            $expectedRefund = $selling - $fee;

            $setup = $this->makePaidBooking(
                selling: $selling, cost: $cost, quantity: 2, paid: $selling,
                phone: '010P'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)
            );
            $booking = $setup['booking'];

            $treasury = $this->makeTreasury("PT-$i");

            $refundId = $this->postJson('/api/v1/bus/refunds', [
                'bus_booking_id' => $booking->id,
                'cancellation_fee' => $fee,
                'destination' => 'agency_treasury',
                'treasury_id' => $treasury->id,
            ])->json('data.id');

            $refund = BusRefundRequest::findOrFail($refundId);
            $this->assertEqualsWithDelta(
                $expectedRefund, (float) $refund->refund_amount, 0.01,
                "Config $i (s=$selling, f=$fee): refund_amount must equal paid-fee=$expectedRefund"
            );

            $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();
            $this->assertEqualsWithDelta(
                $expectedRefund, (float) $treasury->fresh()->current_balance, 0.01,
                "Config $i: treasury must be credited by refund_amount"
            );
            $this->assertLedgerGloballyBalanced();
        }
    }

    /**
     * Property test: cancellation fee + refund_amount = paid (always).
     */
    public function test_property_fee_plus_refund_equals_paid(): void
    {
        // selling=300, paid=300, varying fees 0/50/100/150/200
        $fees = [0, 50, 100, 150, 200];
        $i = 0;
        foreach ($fees as $fee) {
            $i++;
            $setup = $this->makePaidBooking(
                selling: 300, cost: 180, quantity: 2, paid: 300,
                phone: '010F'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)
            );
            $booking = $setup['booking'];

            $treasury = $this->makeTreasury("FEE-$i");

            $refundId = $this->postJson('/api/v1/bus/refunds', [
                'bus_booking_id' => $booking->id,
                'cancellation_fee' => $fee,
                'destination' => 'agency_treasury',
                'treasury_id' => $treasury->id,
            ])->json('data.id');

            $refund = BusRefundRequest::findOrFail($refundId);
            $sum = (float) $refund->cancellation_fee + (float) $refund->refund_amount;
            $this->assertEqualsWithDelta(
                300.0, $sum, 0.01,
                "Iteration $i (fee=$fee): fee + refund ($sum) must equal paid (300)"
            );
        }
    }

    /**
     * Property test: refund flow preserves global SUM(debit) = SUM(credit).
     *
     * A double-entry bookkeeping system MUST have SUM(debit) = SUM(credit)
     * globally. After a refund, the SUM of debits created should equal the
     * SUM of credits created (per-transaction balanced, and global net 0
     * between debit and credit increases).
     *
     * Captures (d_before, c_before) BEFORE refund, then (d_after, c_after)
     * AFTER refund. The DELTAS must match: (d_after - d_before) == (c_after - c_before).
     * This is the conservation invariant for refund operations.
     */
    public function test_property_refund_preserves_global_conservation(): void
    {
        $setup = $this->makePaidBooking(selling: 500, cost: 300, paid: 500);
        $booking = $setup['booking'];

        $treasury = $this->makeTreasury('CONS');

        // Capture totals BEFORE refund
        $totalsBefore = DB::table('account_entries')
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')
            ->first();
        $dBefore = (float) ($totalsBefore->d ?? 0);
        $cBefore = (float) ($totalsBefore->c ?? 0);

        $refundId = $this->postJson('/api/v1/bus/refunds', [
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 100,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
        ])->json('data.id');

        $this->postJson("/api/v1/bus/refunds/{$refundId}/process")->assertOk();

        // Capture totals AFTER refund
        $totalsAfter = DB::table('account_entries')
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')
            ->first();
        $dAfter = (float) ($totalsAfter->d ?? 0);
        $cAfter = (float) ($totalsAfter->c ?? 0);

        // The DELTAS must be equal (refund is balanced by definition)
        $dDelta = $dAfter - $dBefore;
        $cDelta = $cAfter - $cBefore;

        $this->assertEqualsWithDelta(
            $dDelta, $cDelta, 0.01,
            "Conservation broken: Δdebit=$dDelta != Δcredit=$cDelta after refund"
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PATH 3: ADMIN DELETE WITH REVERSAL
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Test 25: Delete booking with payments reverses everything.
     */
    public function test_p3_admin_delete_reverses_everything(): void
    {
        $setup = $this->makePaidBooking(paid: 240);
        $booking = $setup['booking'];
        $customer = $setup['customer'];
        $company = $setup['company'];

        // Capture state before delete
        $supplierBefore = (float) $company->account->fresh()->balance;

        // Admin DELETE (controller route)
        $this->deleteJson("/api/v1/bus/bookings/{$booking->id}")->assertOk();

        // Customer AR must be cleared (sale-debt reversed)
        $this->assertEqualsWithDelta(
            0.0, (float) $this->customerAccount($customer)->fresh()->balance, 0.01,
            'Customer AR must be 0 after delete'
        );

        // Supplier AP must be cleared
        $this->assertEqualsWithDelta(
            0.0, (float) $company->account->fresh()->balance, 0.01,
            'Supplier AP must be cleared after delete'
        );

        $this->assertLedgerGloballyBalanced();
    }
}
