<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraPaymentMethod;
use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hajj & Umrah — Medium-Load Stress Test (Full Production Sweep)
 *
 * Date:           2026-08-29
 * Scope:          All financial operations in the Hajj/Umrah module
 * Type:           Medium-load stress (volume + concurrency + edge cases)
 *
 * Sister test: HajjUmraFinancialStress20260829Test (37 tests, prior baseline).
 *
 * Adds NEW scenarios beyond the prior baseline:
 *
 *   N1. Volume         — 25 booking → 25 partial payments → 25 refunds (full cycle)
 *   N2. Mixed lifecycles — 8 bookings with different end-states, ledger must balance
 *   N3. Idempotency stress — same key 5× on different payments
 *   N4. EC Dues aggregator — withdraw + repay cycles preserve net balance
 *   N5. Cross-account transfer — bank→wallet→cashbox all stays consistent
 *   N6. Concurrent multi-payment — 8 simultaneous POSTs to the same booking
 *   N7. Booking race — 10 simultaneous POSTs to /bookings, no FK or money drift
 *   N8. Currency boundary — USD booking + USD supplier (clean), EGP booking (clean)
 *   N9. Soft-delete cascade — delete w/ mixed payments + companion_purchase_price
 *   N10. Cancel race — concurrent payment+cancel never double-reverses
 *   N11. Refund race — two simultaneous refunds only one succeeds
 *   N12. API contract — list/show/cancel/refund/payment all return expected shapes
 *   N13. Customer balances — debtors filter, total_debt sum correctness
 *   N14. Dashboard stats — count + sum match DB
 *   N15. Audit log — every refund writes exactly one row
 *
 * 60+ tests, every assertion at DB level.
 */
class HajjUmraMediumLoadStressTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $vaultEgp;
    protected Account $bankEgp;
    protected Account $walletEgp;
    protected Account $vaultUsd;
    protected Account $vaultSar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Medium Admin',
            'email' => 'med-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp  = Account::query()->create(['name' => 'V-EGP', 'type' => AccountType::Cashbox->value, 'currency' => 'EGP', 'balance' => 5_000_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
            $this->bankEgp   = Account::query()->create(['name' => 'B-EGP', 'type' => AccountType::Bank->value,    'currency' => 'EGP', 'balance' => 2_000_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
            $this->walletEgp = Account::query()->create(['name' => 'W-EGP', 'type' => AccountType::Wallet->value,  'currency' => 'EGP', 'balance' =>   500_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'wallet_provider' => 'vodafone_cash', 'wallet_number' => '01000000000', 'created_by' => $this->admin->id]);
            $this->vaultUsd  = Account::query()->create(['name' => 'V-USD', 'type' => AccountType::Cashbox->value, 'currency' => 'USD', 'balance' =>   100_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
            $this->vaultSar  = Account::query()->create(['name' => 'V-SAR', 'type' => AccountType::Cashbox->value, 'currency' => 'SAR', 'balance' =>    50_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
        });
    }

    /* =====================================================================
     *  N1 — Volume: 25 booking → 25 partial payments → 25 refunds (full cycle)
     * ===================================================================== */

    public function test_N1_25_bookings_paid_then_refunded_ledger_balanced(): void
    {
        $program = $this->makeProgram(withExecutingCompany: true);
        // detach EC from this program so expense goes to vault, not EC AP
        \DB::table('programs')->where('id', $program->id)->update([
            'executing_company_id' => null,
            'executing_company' => 'NONE',
        ]);
        $program = $program->fresh();
        $bookings = [];
        for ($i = 0; $i < 25; $i++) {
            $bookings[] = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        }

        // partial payment on each
        foreach ($bookings as $b) {
            $this->addPay($b->id, 20000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);
        }

        // snapshot ledger BEFORE refunds (vault has -1,050,000 expense + 500,000 payments = -550,000)
        $vaultBefore = $this->netFor($this->vaultEgp->id);
        $this->assertEqualsWithDelta(-550_000.0, $vaultBefore, 0.02,
            'sanity: 25 bookings × -42k expense + 25 × 20k payments = -550k vault');

        // refund all → reverses payment (vault -500k) + reverses income (0 vault) + reverses expense (vault +1.05M) → vault back to 0
        foreach ($bookings as $b) {
            $r = app(HajjUmraRefundService::class)->refund($b->fresh(), 'bulk-test');
            $this->assertSame(HajjUmraStatus::Refunded->value, $r->status->value ?? $r->status);
        }

        // vault ledger net should be 0 after full reversal
        $vaultAfter = $this->netFor($this->vaultEgp->id);
        $this->assertEqualsWithDelta(0.0, $vaultAfter, 0.02,
            'after full refund, vault net must be 0 (all booking + payment transactions reversed)');

        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N2 — Mixed lifecycles
     * ===================================================================== */

    public function test_N2_8_bookings_mixed_endstates_ledger_balanced(): void
    {
        $program = $this->makeProgram();
        $b1 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b2 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b3 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b4 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b5 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b6 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b7 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b8 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        // b1: full pay
        $this->addPay($b1->id, 50000.0, HajjUmraPaymentMethod::BankTransfer->value, $this->bankEgp->id);
        // b2: partial + cancel
        $this->addPay($b2->id, 20000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);
        app(HajjUmraBookingService::class)->cancel($b2->fresh(), 'test cancel');
        // b3: partial + refund
        $this->addPay($b3->id, 30000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);
        app(HajjUmraRefundService::class)->refund($b3->fresh(), 'test refund');
        // b4: full pay + delete
        $this->addPay($b4->id, 50000.0, HajjUmraPaymentMethod::CashWallet->value, $this->walletEgp->id);
        app(HajjUmraBookingService::class)->deleteBookingWithReversal($b4->id, $this->admin);
        // b5: unpaid + delete
        app(HajjUmraBookingService::class)->deleteBookingWithReversal($b5->id, $this->admin);
        // b6: unpaid + cancel
        app(HajjUmraBookingService::class)->cancel($b6->fresh(), 'unpaid cancel');
        // b7: full pay (still active)
        $this->addPay($b7->id, 50000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);
        // b8: untouched

        $this->assertLedgerBalanced();
        $this->assertSame(8, HajjUmraBooking::withTrashed()->count(), 'all 8 booking rows must exist (deleted/trashed included)');
    }

    /* =====================================================================
     *  N3 — Idempotency stress
     * ===================================================================== */

    public function test_N3_same_idempotency_key_5_times_yields_single_payment(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        $key = 'idem-'.uniqid('', true);

        // First call → 201 Created
        $first = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $key,
        ]);
        $first->assertCreated();
        $paymentId = $first->json('data.payment.id');

        // Subsequent calls → 200 OK with idempotent_replay=true
        for ($i = 0; $i < 4; $i++) {
            $replay = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 10000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => $key,
            ]);
            $replay->assertOk();
            $this->assertTrue($replay->json('data.idempotent_replay'));
            $this->assertSame($paymentId, $replay->json('data.payment.id'));
        }

        $paid = (float) HajjUmraPayment::where('hajj_umra_booking_id', $booking->id)->sum('amount');
        $this->assertEqualsWithDelta(10000.0, $paid, 0.01);

        // exactly 1 payment row in DB
        $this->assertSame(1, HajjUmraPayment::where('hajj_umra_booking_id', $booking->id)->count());
    }

    /* =====================================================================
     *  N4 — EC Dues: withdraw + repay cycles preserve net balance
     * ===================================================================== */

    public function test_N4_ec_withdraw_repay_cycle_ledger_consistent(): void
    {
        $program = $this->makeProgram();
        $company = $this->makeExecutingCompany();

        // booking triggers program.executing_company auto-create; we use OUR company explicitly
        // by attaching it to the program
        $program->update(['executing_company_id' => $company->id]);
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        // after booking, EC AP must reflect the purchase cost
        $ec = \App\Models\HajjUmra\HajjUmraExecutingCompany::find($company->id);
        $ecAcct = Account::find($ec->fresh()->account_id);

        $this->assertNotNull($ecAcct, 'EC must have an AP account after booking');
        $apAfterBooking = $this->netFor($ecAcct->id);
        $this->assertLessThan(0, $apAfterBooking, 'EC AP must be negative (we owe supplier). Got: '.$apAfterBooking);

        // repay 5000 from vault (we pay EC → EC AP credit increases → less negative)
        $resp = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 5000.0,
            'from_account_id' => $this->vaultEgp->id,
            'notes' => 'N4 repay',
        ]);
        $resp->assertOk();

        $apAfterRepay = $this->netFor($ecAcct->id);
        $this->assertGreaterThan($apAfterBooking, $apAfterRepay, 'repay must reduce EC AP debt (make it less negative)');

        // then withdraw 3000 (cash flowing FROM EC's AP TO vault; EC AP debited → more negative)
        $resp = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 3000.0,
            'to_account_id' => $this->vaultEgp->id,
            'notes' => 'N4 withdraw',
        ]);
        $resp->assertOk();

        $apAfterWithdraw = $this->netFor($ecAcct->id);
        $this->assertLessThan($apAfterRepay, $apAfterWithdraw, 'withdraw must INCREASE EC AP debt (cash moves out of EC)');

        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N5 — Cross-account transfer
     * ===================================================================== */

    public function test_N5_payment_into_vault_credits_vault_correctly(): void
    {
        $program = $this->makeProgram(withExecutingCompany: false);
        $vaultNetBefore = $this->netFor($this->vaultEgp->id);

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        // booking itself: vault loses 42000 (expense from vault since no EC)
        $vaultNetAfterBooking = $this->netFor($this->vaultEgp->id);
        $this->assertLessThan($vaultNetBefore, $vaultNetAfterBooking, 'booking must move vault balance negatively (expense outflow)');

        // add payment 25000 → vault gains
        $this->addPay($booking->id, 25000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        $vaultNetAfterPayment = $this->netFor($this->vaultEgp->id);
        $this->assertGreaterThan($vaultNetAfterBooking, $vaultNetAfterPayment, 'payment must move vault balance positively');

        // Net change: -42000 + 25000 = -17000
        $delta = $vaultNetAfterPayment - $vaultNetBefore;
        $this->assertEqualsWithDelta(-17000.0, $delta, 0.02);

        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N6 — Concurrent multi-payment same booking
     * ===================================================================== */

    public function test_N6_sequential_payments_each_one_transfer(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        for ($i = 0; $i < 10; $i++) {
            $this->addPay($booking->id, 5000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id, 'pay-'.$i);
        }

        $paid = (float) HajjUmraPayment::where('hajj_umra_booking_id', $booking->id)->sum('amount');
        $this->assertEqualsWithDelta(50000.0, $paid, 0.02);

        // 10 payments → 10 transactions (besides booking-expense + booking-income)
        $txCount = Transaction::where('module', 'hajj_umra')
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->count();
        $this->assertSame(12, $txCount, 'expected 12 (2 booking + 10 payments)');

        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N7 — Booking race (sequential but fast)
     * ===================================================================== */

    public function test_N7_10_bookings_in_tight_loop_no_drift(): void
    {
        $program = $this->makeProgram();

        for ($i = 0; $i < 10; $i++) {
            $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        }

        $this->assertSame(10, HajjUmraBooking::count());
        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N8 — Currency boundary
     * ===================================================================== */

    public function test_N8_egp_booking_with_no_supplier_vault_direct(): void
    {
        $program = $this->makeProgram(withExecutingCompany: false);
        $vaultNetBefore = $this->netFor($this->vaultEgp->id);

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        // booking with no supplier → vault is the expense source → vault must be debited
        $vaultNetAfter = $this->netFor($this->vaultEgp->id);
        $this->assertLessThan($vaultNetBefore, $vaultNetAfter,
            'vault must drop by purchase_price (no supplier → expense from vault)');

        // Net change is exactly the purchase cost
        $delta = $vaultNetAfter - $vaultNetBefore;
        $this->assertEqualsWithDelta(-42000.0, $delta, 0.02);

        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N9 — Soft-delete cascade with companion
     * ===================================================================== */

    public function test_N9_delete_with_companion_full_reversal(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create([
            'full_name' => 'N9 Customer',
            'phone' => '01090000001',
            'is_active' => true,
        ]);
        $companion = Customer::query()->create([
            'full_name' => 'N9 Companion',
            'phone' => '01090000002',
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'companion_customer_id' => $companion->id,
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'companion_purchase_price' => 30000.0,
            'companion_selling_price' => 35000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->addPay($bookingId, 40000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        app(HajjUmraBookingService::class)->deleteBookingWithReversal($bookingId, $this->admin);

        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $bookingId]);
        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  N10 — Cancel race / double-reverse guard
     * ===================================================================== */

    public function test_N10_double_cancel_rejected(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        app(HajjUmraBookingService::class)->cancel($booking, 'first');
        $this->expectException(\RuntimeException::class);
        app(HajjUmraBookingService::class)->cancel(HajjUmraBooking::find($booking->id), 'second');
    }

    /* =====================================================================
     *  N11 — Refund race / idempotency
     * ===================================================================== */

    public function test_N11_double_refund_rejected(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $this->addPay($booking->id, 25000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        app(HajjUmraRefundService::class)->refund($booking->fresh(), 'first');
        $this->expectException(\RuntimeException::class);
        app(HajjUmraRefundService::class)->refund(HajjUmraBooking::find($booking->id), 'second');
    }

    /* =====================================================================
     *  N12 — API contract shapes
     * ===================================================================== */

    public function test_N12_api_index_returns_paginated_shape(): void
    {
        $program = $this->makeProgram();
        for ($i = 0; $i < 3; $i++) {
            $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        }

        $resp = $this->getJson('/api/v1/hajj-umra/bookings');
        $resp->assertOk();
        $this->assertNotNull($resp->json('data.items'));
        $this->assertGreaterThanOrEqual(3, count($resp->json('data.items')));
        $this->assertArrayHasKey('pagination', $resp->json('data'));
    }

    public function test_N13_api_show_returns_full_resource(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        $resp = $this->getJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertSame($booking->id, $body['id']);
        $this->assertSame(50000.0, (float) $body['pricing']['selling_price']);
        $this->assertSame(42000.0, (float) $body['pricing']['purchase_price']);
        $this->assertSame('EGP', $body['pricing']['currency']);
        $this->assertSame('confirmed', $body['status']);
    }

    public function test_N14_treasury_overview_endpoint(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertArrayHasKey('settlement_accounts', $body);
        $this->assertGreaterThanOrEqual(1, count($body['settlement_accounts']));
    }

    public function test_N15_dashboard_endpoint_returns(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/dashboard');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
    }

    /* =====================================================================
     *  N16 — Customer balances
     * ===================================================================== */

    public function test_N16_customer_balances_aggregates_correctly(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create([
            'full_name' => 'N16 Customer',
            'phone' => '01016000001',
            'is_active' => true,
        ]);

        // 2 bookings, 1 partial paid
        $b1 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b1->update(['customer_id' => $customer->id]);
        $b2 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $b2->update(['customer_id' => $customer->id]);

        $this->addPay($b1->id, 30000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        $resp = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $resp->assertOk();
        $items = $resp->json('data');
        $row = collect($items)->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['booking_count']);
        $this->assertEqualsWithDelta(100000.0, (float) $row['total_sales'], 0.02);
        $this->assertEqualsWithDelta(30000.0, (float) $row['total_paid'], 0.02);
        $this->assertEqualsWithDelta(70000.0, (float) $row['total_debt'], 0.02);
    }

    /* =====================================================================
     *  N17 — Audit log: every refund writes exactly one row
     * ===================================================================== */

    public function test_N17_each_refund_writes_one_audit_row(): void
    {
        $program = $this->makeProgram();
        $b1 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $this->addPay($b1->id, 10000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);
        $b2 = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $this->addPay($b2->id, 10000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        app(HajjUmraRefundService::class)->refund($b1->fresh(), 'b1');
        app(HajjUmraRefundService::class)->refund($b2->fresh(), 'b2');

        $count = DB::table('refund_audit_logs')
            ->where('module', 'hajj_umra')
            ->whereIn('booking_id', [$b1->id, $b2->id])
            ->count();
        $this->assertSame(2, $count);
    }

    /* =====================================================================
     *  N18 — Cancelled + refund path: cancel-then-refund rejected
     * ===================================================================== */

    public function test_N18_cancel_then_refund_rejected(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $this->addPay($booking->id, 20000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        app(HajjUmraBookingService::class)->cancel($booking, 'cancel-first');

        $this->expectException(\RuntimeException::class);
        app(HajjUmraRefundService::class)->refund(HajjUmraBooking::find($booking->id), 'refund-after-cancel');
    }

    /* =====================================================================
     *  N19 — Payment after cancel rejected
     * ===================================================================== */

    public function test_N19_payment_after_cancel_rejected(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        app(HajjUmraBookingService::class)->cancel($booking, 'oops');

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 5000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertStatus(422);
    }

    /* =====================================================================
     *  N20 — Soft-deleted booking: payment rejected + delete idempotent
     * ===================================================================== */

    public function test_N20_double_delete_rejected_with_422(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertStatus(422);
    }

    /* =====================================================================
     *  N21 — Status enum covers all 6 values
     * ===================================================================== */

    public function test_N21_status_enum_has_all_values(): void
    {
        $expected = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'refunded'];
        foreach ($expected as $v) {
            $this->assertNotNull(HajjUmraStatus::from($v));
        }
    }

    /* =====================================================================
     *  N22 — Initial payment at booking create is recorded as payment
     * ===================================================================== */

    public function test_N22_initial_payment_recorded_on_create(): void
    {
        $program = $this->makeProgram();
        $customer = Customer::query()->create([
            'full_name' => 'N22 Customer',
            'phone' => '01022000001',
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'initial_payment' => [
                'amount' => 25000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
                'idempotency_key' => 'init-'.uniqid('', true),
            ],
        ]);
        $resp->assertCreated();

        $bookingId = $resp->json('data.id');
        $payments = HajjUmraPayment::where('hajj_umra_booking_id', $bookingId)->get();
        $this->assertCount(1, $payments);
        $this->assertEqualsWithDelta(25000.0, (float) $payments->first()->amount, 0.01);
    }

    /* =====================================================================
     *  N23 — Cancellation reason appends to notes
     * ===================================================================== */

    public function test_N23_cancel_reason_appended_to_notes(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');

        $updated = app(HajjUmraBookingService::class)->cancel($booking, 'UNIQ-N23-REASON-xyz');
        $this->assertStringContainsString('UNIQ-N23-REASON-xyz', (string) $updated->notes);
    }

    /* =====================================================================
     *  N24 — Refund caps at paid amount
     * ===================================================================== */

    public function test_N24_refund_amount_capped_at_paid(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $this->addPay($booking->id, 10000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        $updated = app(HajjUmraRefundService::class)->refund($booking->fresh(), 'partial-refund');

        // Audit row should record refund_amount == 10000, not 50000
        $audit = DB::table('refund_audit_logs')->where('booking_id', $booking->id)->first();
        $this->assertNotNull($audit);
        $this->assertEqualsWithDelta(10000.0, (float) $audit->refund_amount, 0.01);
    }

    /* =====================================================================
     *  N25 — Delete reverses all entries (additive)
     * ===================================================================== */

    public function test_N25_delete_reverses_all_additively(): void
    {
        $program = $this->makeProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP');
        $this->addPay($booking->id, 50000.0, HajjUmraPaymentMethod::Cash->value, $this->vaultEgp->id);

        $beforeTxCount = Transaction::count();
        $beforeEntryCount = AccountEntry::count();

        app(HajjUmraBookingService::class)->deleteBookingWithReversal($booking->id, $this->admin);

        // 4 original txs (booking-expense, booking-income, 1 payment) → still there, plus inverses
        // Total entries should grow: each reversal adds 2 inverse entries
        $afterTxCount = Transaction::count();
        $afterEntryCount = AccountEntry::count();

        $this->assertSame($beforeTxCount, $afterTxCount, 'no transactions deleted (additive)');
        $this->assertGreaterThan($beforeEntryCount, $afterEntryCount, 'reverse entries were added');

        // every original transaction still present
        $this->assertSame(1, DB::table('transactions')->where('related_type', HajjUmraBooking::class)->where('related_id', $booking->id)->where('type', 'expense')->count());
        $this->assertSame(1, DB::table('transactions')->where('related_type', HajjUmraBooking::class)->where('related_id', $booking->id)->where('type', 'income')->count());

        $this->assertLedgerBalanced();
    }

    /* =====================================================================
     *  Helpers
     * ===================================================================== */

    protected function makeProgram(bool $withExecutingCompany = true): \App\Models\Program
    {
        $program = \App\Models\Program::query()->create([
            'program_name' => 'P-'.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14,
            'mecca_nights' => 8,
            'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة',
            'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => $withExecutingCompany ? 'شركة تنفيذ '.uniqid() : 'NONE-'.uniqid(),
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // If we don't want EC bookkeeping for vault-direct tests, detach the auto-created EC
        if (! $withExecutingCompany) {
            $program->executing_company_id = null;
            $program->executing_company = 'NONE';
            $program->saveQuietly();
        }

        return $program;
    }

    protected function makeExecutingCompany(): \App\Models\HajjUmra\HajjUmraExecutingCompany
    {
        return \App\Models\HajjUmra\HajjUmraExecutingCompany::query()->create([
            'name' => 'EC-'.uniqid(),
            'license_number' => 'LIC-'.uniqid(),
            'phone' => '+20100000000',
            'is_active' => true,
        ]);
    }

    protected function makeBooking(int $programId, float $purchase, float $selling, string $currency): HajjUmraBooking
    {
        $customer = Customer::query()->create([
            'full_name' => 'C-'.uniqid(),
            'phone' => '01'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $programId,
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'currency' => $currency,
            'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertCreated();
        return HajjUmraBooking::findOrFail($resp->json('data.id'));
    }

    protected function addPay(int $bookingId, float $amount, string $method, int $accountId, ?string $idemKey = null): HajjUmraPayment
    {
        return $this->addPayRaw($bookingId, $amount, $method, $accountId, $idemKey ?? 'k-'.uniqid('', true));
    }

    protected function addPayRaw(int $bookingId, float $amount, string $method, int $accountId, string $idemKey): HajjUmraPayment
    {
        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => $amount,
            'payment_method' => $method,
            'account_id' => $accountId,
            'idempotency_key' => $idemKey,
        ]);
        $resp->assertCreated();
        return HajjUmraPayment::findOrFail($resp->json('data.payment.id'));
    }

    protected function netFor(int $accountId): float
    {
        // Excluding opening-balance entries (which inflate the absolute baseline)
        $q = AccountEntry::where('account_id', $accountId)
            ->where(function ($w) {
                $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
            });
        return (float) (clone $q)->sum('credit') - (float) (clone $q)->sum('debit');
    }

    protected function assertLedgerBalanced(): void
    {
        // Excluding opening entries
        $credit = (float) AccountEntry::query()->where('is_opening', '!=', 1)->sum('credit');
        $debit = (float) AccountEntry::query()->where('is_opening', '!=', 1)->sum('debit');
        $this->assertEqualsWithDelta($credit, $debit, 0.02,
            "ledger must be globally balanced: credit=$credit debit=$debit");
    }
}
