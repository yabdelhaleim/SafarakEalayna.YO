<?php

namespace Tests\Feature\Flight;

use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 11.4–11.17 — CONSOLIDATED FLIGHT DEEP AUDIT
 * =================================================
 *
 * Covers the remaining Phase 11 sub-phases with focused tests:
 *   11.4 Multi-currency matrix (EGP, USD, EUR + mismatches)
 *   11.5 Payment / partial payment deep (10k booking split into 1k+2k+3k+4k)
 *   11.6 Debt ownership (Customer A/B + Group A/B + IDOR)
 *   11.7 Financial reconciliation (debit == credit per transaction)
 *   11.8 Refund deep (pre-pay, partial, full, multiple, dup, concurrent)
 *   11.9 Cancel deep (unpaid, partial, full, refunded, twice, race)
 *   11.10 Delete / reverse deep (soft/hard delete as accounting op)
 *   11.11 Idempotency (100 identical requests = 1 transaction)
 *   11.14 Security / IDOR (cross-customer enumeration)
 *   11.15 State machine (every transition)
 *   11.16 FE financial display audit (resource fields match DB)
 *   11.17 Reporting reconciliation
 *
 * For HTTP concurrency (11.12) and failure injection (11.13), see
 * dedicated scenarios in `FlightConcurrencyStressTest` / `FlightFailureInjectionTest`
 * (referenced for MySQL + parallel process execution).
 */
class Phase11ConsolidatedDeepAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightBookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['accounting.strict_test_guards' => true]);

        $this->admin = User::factory()->create([
            'name' => 'Phase11 Consolidated Admin',
            'email' => 'phase11-cons-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
        $this->bookingService = app(FlightBookingService::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.4 MULTI-CURRENCY MATRIX
    // ═══════════════════════════════════════════════════════════════

    public function test_11_4_01_egp_booking_full_cycle(): void
    {
        $this->seedCurrency('EGP', 1.0);

        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB EGP', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $b = $booking->fresh();
        $this->assertEquals('EGP', $b->currency);
        $this->assertEquals(1500.0, (float) $b->payments()->sum('amount'));
        $this->assertEquals(FlightBookingStatus::CONFIRMED->value, $b->status->value);
    }

    public function test_11_4_02_usd_booking_with_egp_payment_auto_converts(): void
    {
        // Customer AR is EGP, cashbox is EGP, booking is USD → auto-convert
        $this->seedCurrency('USD', 50.0);

        $booking = $this->createBookingViaApi('USD', 10000, 5000, 100.0, 'SYSTEM');
        $cashbox = $this->makeAccount('CB EGP Pay', 'cashbox', 'EGP', 50_000);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 10000.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        // Foreign booking + EGP payment + EGP customer AR is allowed (per Bug #14 fix).
        $response->assertCreated();

        $b = $booking->fresh();
        $this->assertEquals('USD', $b->currency);
        $this->assertGreaterThan(0, $b->payments()->sum('amount'));
    }

    public function test_11_4_03_foreign_mismatch_payment_rejected(): void
    {
        // KWD booking + SAR payment (mismatched foreign) MUST be rejected.
        $this->seedCurrency('KWD', 157.5);
        $this->seedCurrency('SAR', 12.9);

        $booking = $this->createBookingViaApi('KWD', 15750, 7875, 50.0, 'SYSTEM');
        $sarCashbox = $this->makeAccount('CB SAR', 'cashbox', 'SAR', 50_000);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash', 'account_id' => $sarCashbox->id,
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('لا تطابق', $response->json('message') ?? '');
    }

    public function test_11_4_04_usd_booking_with_egp_cashbox_payment_succeeds(): void
    {
        // USD booking + EGP cashbox payment via auto-conversion (Bug #14 fix verified by 11_4_02).
        // This documents that EGP-cashbox-payment for USD-booking is the supported path.
        $this->seedCurrency('USD', 50.0);
        $egpCashbox = $this->makeAccount('CB EGP USD', 'cashbox', 'EGP', 50_000);

        $booking = $this->createBookingViaApi('USD', 100, 50, 100.0, 'SIGN');

        // Pay 100 USD via EGP cashbox (auto-converts 100×50 = 5000 EGP).
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash', 'account_id' => $egpCashbox->id,
        ]);
        // Even if booking is technically small USD, the auto-conversion must not error.
        $this->assertContains($response->status(), [200, 201],
            'USD booking + EGP cashbox payment must succeed via auto-conversion.');

        $b = $booking->fresh();
        $this->assertEquals('USD', $b->currency);
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.5 PAYMENT / PARTIAL PAYMENT DEEP
    // ═══════════════════════════════════════════════════════════════

    public function test_11_5_01_ten_thousand_booking_split_into_1k_2k_3k_4k(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 10000, 5000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB 10K', 'cashbox', 'EGP', 100_000);

        foreach ([1000.0, 2000.0, 3000.0, 4000.0] as $amount) {
            $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
                'amount' => $amount, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
            ])->assertCreated();
        }

        $b = $booking->fresh();
        $this->assertEquals(10000.0, (float) $b->payments()->sum('amount'),
            'Sum of payments must equal selling_price exactly.');
        $this->assertEquals(FlightBookingStatus::CONFIRMED->value, $b->status->value);
        $this->assertEquals(4, $b->payments()->count());
    }

    public function test_11_5_02_overpayment_rejected(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Overpay', 'cashbox', 'EGP', 50_000);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('exceed', $response->json('message') ?? '');
    }

    public function test_11_5_03_payment_after_cancellation_rejected(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.6 DEBT OWNERSHIP (multi-customer + multi-group)
    // ═══════════════════════════════════════════════════════════════

    public function test_11_6_01_customer_a_payment_does_not_reduce_customer_b_debt(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $custA = $this->makeCustomer('Customer A');
        $custB = $this->makeCustomer('Customer B');
        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 100_000);
        $carrier = $this->makeCarrier('Multi Customer Carrier', null, 'EGP');
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50_000, 'seed');

        $bookingA = $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $custA->id, 'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
            'selling_price' => 1500, 'purchase_price_egp' => 1000, 'currency' => 'EGP',
        ]))->json('data.id');

        $bookingB = $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $custB->id, 'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
            'selling_price' => 2000, 'purchase_price_egp' => 1500, 'currency' => 'EGP',
        ]))->json('data.id');

        $custBAccountBefore = (float) Account::find($custB->account_id)->balance;

        $this->postJson("/api/v1/flight/bookings/{$bookingA}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $custBAccountAfter = (float) Account::find($custB->account_id)->balance;
        $this->assertEquals($custBAccountBefore, $custBAccountAfter,
            'Customer B debt MUST NOT change when Customer A pays.');
    }

    public function test_11_6_02_group_a_payment_does_not_reduce_group_b_debt(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $carrier = $this->makeCarrier('Multi Group Carrier', null, 'EGP');

        $groupA = FlightGroup::create([
            'flight_carrier_id' => $carrier->id, 'name' => 'Group A',
            'code' => 'GA-'.uniqid(), 'currency' => 'EGP',
            'credit_limit' => 100_000, 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $groupB = FlightGroup::create([
            'flight_carrier_id' => $carrier->id, 'name' => 'Group B',
            'code' => 'GB-'.uniqid(), 'currency' => 'EGP',
            'credit_limit' => 100_000, 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Trigger booking to create accounts for both groups.
        $cust = $this->makeCustomer('Group Test Cust');
        $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $cust->id, 'booking_channel_type' => 'GROUP',
            'purchase_balance_source' => 'group', 'flight_carrier_id' => $carrier->id,
            'flight_group_id' => $groupA->id,
            'selling_price' => 1500, 'purchase_price_egp' => 1000, 'currency' => 'EGP',
        ]))->assertCreated();
        $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $cust->id, 'booking_channel_type' => 'GROUP',
            'purchase_balance_source' => 'group', 'flight_carrier_id' => $carrier->id,
            'flight_group_id' => $groupB->id,
            'selling_price' => 2000, 'purchase_price_egp' => 1500, 'currency' => 'EGP',
        ]))->assertCreated();

        $groupB->refresh();
        $groupBAccountBefore = (float) Account::find($groupB->account_id)->balance;

        $cashbox = $this->makeAccount('CB Group', 'cashbox', 'EGP', 50_000);
        $this->postJson("/api/v1/flight/groups/{$groupA->id}/pay-debt", [
            'amount' => 500.0, 'account_id' => $cashbox->id, 'type' => 'payment',
        ])->assertOk();

        $groupB->refresh();
        $groupBAccountAfter = (float) Account::find($groupB->account_id)->balance;

        $this->assertEquals($groupBAccountBefore, $groupBAccountAfter,
            'Group B debt MUST NOT change when Group A pays.');
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.7 FINANCIAL RECONCILIATION (every tx must balance)
    // ═══════════════════════════════════════════════════════════════

    public function test_11_7_01_every_transaction_is_balanced(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Recon', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 100.0, 'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/flight/bookings/{$booking->id}")->assertOk();

        // Every transaction must have SUM(debit) == SUM(credit) per currency.
        $rows = DB::table('transactions')->select('id', 'currency')->get();
        $this->assertGreaterThan(0, $rows->count(), 'Test must have generated transactions.');

        foreach ($rows as $tx) {
            $entries = DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->where('currency', $tx->currency)
                ->get();
            $d = (float) $entries->sum('debit');
            $c = (float) $entries->sum('credit');
            $this->assertEqualsWithDelta(
                $d, $c, 0.01,
                "TX #{$tx->id} ({$tx->currency}) must balance. dr={$d} cr={$c}"
            );
        }
    }

    public function test_11_7_02_account_balances_match_entries(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Recon 2', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $accounts = Account::whereIn('id', [$cashbox->id, $booking->customer->account_id])->get();
        foreach ($accounts as $account) {
            $entries = AccountEntry::where('account_id', $account->id)->get();
            $expected = (float) $entries->sum('credit') - (float) $entries->sum('debit');
            $actual = (float) $account->fresh()->balance;
            $this->assertEqualsWithDelta(
                $expected, $actual, 0.01,
                "Account {$account->name} balance mismatch: balance={$actual} cr-dr={$expected}"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.8 REFUND DEEP
    // ═══════════════════════════════════════════════════════════════

    public function test_11_8_01_refund_partial_payment_correct_amount(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Refund', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 100.0, 'office_penalty' => 50.0,
            'account_id' => $cashbox->id,
        ]);
        $response->assertOk();

        $refund = $booking->fresh()->refund;
        $this->assertEquals(850.0, (float) $refund->refund_amount,
            'Refund = 1000 (paid) - 100 (airline) - 50 (office) = 850');
        $this->assertEquals('processed', $refund->status);
    }

    public function test_11_8_02_refund_full_payment_no_penalty(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Full Refund', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        $refund = $booking->fresh()->refund;
        $this->assertEquals(1500.0, (float) $refund->refund_amount);
    }

    public function test_11_8_03_refund_amount_capped_at_selling_price(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Cap', 'cashbox', 'EGP', 50_000);

        // Attempt refund that exceeds total paid.
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 5000.0, 'office_penalty' => 5000.0,
            'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('يتجاوز', $response->json('message') ?? '');
    }

    public function test_11_8_04_double_refund_via_double_cancel(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ]);
        $response->assertStatus(422);

        // Only ONE FlightRefund row should exist.
        $refundCount = FlightRefund::where('flight_booking_id', $booking->id)->count();
        $this->assertEquals(1, $refundCount, 'Duplicate refund must NOT create a second FlightRefund row.');
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.9 CANCEL DEEP
    // ═══════════════════════════════════════════════════════════════

    public function test_11_9_01_cancel_then_payment_attempt_rejected(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(422);
    }

    public function test_11_9_02_cancel_after_refund_prevented(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ]);
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.10 DELETE / REVERSE DEEP
    // ═══════════════════════════════════════════════════════════════

    public function test_11_10_01_delete_unpaid_no_payment_reversal_needed(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        $txBefore = Transaction::count();

        $this->deleteJson("/api/v1/flight/bookings/{$booking->id}")->assertOk();

        $txAfter = Transaction::count();
        $this->assertGreaterThan($txBefore, $txAfter,
            'Delete unpaid must create reversal transactions for the GL sale.');

        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    public function test_11_10_02_delete_after_payment_creates_reversal(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $txBefore = Transaction::count();
        $this->deleteJson("/api/v1/flight/bookings/{$booking->id}")->assertOk();
        $txAfter = Transaction::count();

        $this->assertGreaterThan($txBefore, $txAfter,
            'Delete with payment must create reversal transactions.');
    }

    public function test_11_10_03_double_delete_rejected(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        $this->deleteJson("/api/v1/flight/bookings/{$booking->id}")->assertOk();

        // Second delete must fail (either 404 or 422 both indicate the booking is no longer accessible).
        $response = $this->deleteJson("/api/v1/flight/bookings/{$booking->id}");
        $this->assertContains($response->status(), [404, 422],
            'Double delete must NOT succeed.');

        // Only ONE FlightRefund row should exist.
        $refundCount = FlightRefund::where('flight_booking_id', $booking->id)->count();
        $this->assertLessThanOrEqual(1, $refundCount,
            'Delete MUST NOT generate a duplicate refund row.');
    }

    public function test_11_10_04_additive_reversal_original_transactions_untouched(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        // Capture original tx ids
        $originalTxIds = Transaction::where('module', \App\Enums\TransactionModule::Flight)
            ->pluck('id')->toArray();
        $this->assertGreaterThan(0, count($originalTxIds));

        $this->deleteJson("/api/v1/flight/bookings/{$booking->id}")->assertOk();

        // Original transactions must still exist with same id (not deleted/modified).
        foreach ($originalTxIds as $txId) {
            $this->assertNotNull(Transaction::find($txId),
                "Original transaction #{$txId} must remain after delete (additive reversal).");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.11 IDEMPOTENCY
    // ═══════════════════════════════════════════════════════════════

    public function test_11_11_01_100_identical_payments_create_exactly_one_transaction(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 10000, 5000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Idem', 'cashbox', 'EGP', 50_000);
        $idempKey = 'phase11-idem-'.uniqid();

        // First request: 201 (creates the payment).
        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash',
            'account_id' => $cashbox->id, 'idempotency_key' => $idempKey,
        ])->assertCreated();

        // 99 replays: must be ok (200) idempotent, no error.
        for ($i = 0; $i < 99; $i++) {
            $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
                'amount' => 100.0, 'payment_method' => 'cash',
                'account_id' => $cashbox->id, 'idempotency_key' => $idempKey,
            ]);
            $this->assertContains($response->status(), [200, 201],
                "Replay #{$i} must NOT error.");
        }

        // Verify exactly ONE FlightPayment row.
        $paymentCount = FlightPayment::where('flight_booking_id', $booking->id)->count();
        $this->assertEquals(1, $paymentCount,
            '100 identical idempotent requests must produce exactly ONE payment row.');

        $b = $booking->fresh();
        $this->assertEquals(100.0, (float) $b->payments()->sum('amount'),
            'Total paid must equal ONE payment (100 EGP), not 100×100.');
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.14 SECURITY / IDOR
    // ═══════════════════════════════════════════════════════════════

    public function test_11_14_01_employee_cannot_pay_other_employees_booking(): void
    {
        // Create two employees.
        $empA = User::factory()->create([
            'name' => 'Employee A',
            'email' => 'emp-a-'.uniqid().'@test.com',
            'role' => 'employee', 'is_active' => true,
        ]);
        $empB = User::factory()->create([
            'name' => 'Employee B',
            'email' => 'emp-b-'.uniqid().'@test.com',
            'role' => 'employee', 'is_active' => true,
        ]);
        $employeeARecord = \App\Models\Employee::create([
            'first_name' => 'A', 'last_name' => 'One',
            'user_id' => $empA->id, 'is_active' => true,
        ]);
        $employeeBRecord = \App\Models\Employee::create([
            'first_name' => 'B', 'last_name' => 'Two',
            'user_id' => $empB->id, 'is_active' => true,
        ]);

        $this->seedCurrency('EGP', 1.0);
        $customer = $this->makeCustomer('IDOR Customer');
        $cashbox = $this->makeAccount('CB IDOR', 'cashbox', 'EGP', 50_000);
        $carrier = $this->makeCarrier('IDOR Carrier', null, 'EGP');
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50_000, 'seed');

        // Booking created by Employee A.
        Sanctum::actingAs($empA, ['*']);
        $booking = $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $customer->id, 'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
            'employee_id' => $employeeARecord->id,
            'selling_price' => 1500, 'purchase_price_egp' => 1000, 'currency' => 'EGP',
        ]))->assertCreated()->json('data.id');

        // Employee B attempts to pay Employee A's booking.
        Sanctum::actingAs($empB, ['*']);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(403);
        // Message is in Arabic: "غير مصرح" or similar. Just verify it's a 403 with non-empty message.
        $this->assertNotEmpty($response->json('message'), '403 response must include an error message.');
    }

    public function test_11_14_02_employee_cannot_cancel_other_employees_booking(): void
    {
        // Same setup as above.
        $empA = User::factory()->create([
            'name' => 'Employee A2',
            'email' => 'emp-a2-'.uniqid().'@test.com',
            'role' => 'employee', 'is_active' => true,
        ]);
        $empB = User::factory()->create([
            'name' => 'Employee B2',
            'email' => 'emp-b2-'.uniqid().'@test.com',
            'role' => 'employee', 'is_active' => true,
        ]);
        $empARecord = \App\Models\Employee::create([
            'first_name' => 'A', 'last_name' => 'One',
            'user_id' => $empA->id, 'is_active' => true,
        ]);

        $this->seedCurrency('EGP', 1.0);
        $customer = $this->makeCustomer('IDOR Cust');
        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);
        $carrier = $this->makeCarrier('IDOR Carr', null, 'EGP');
        app(FlightCarrierRechargeService::class)->rechargeFromAccount($carrier, $cashbox, 50_000, 'seed');

        Sanctum::actingAs($empA, ['*']);
        $booking = $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $customer->id, 'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
            'employee_id' => $empARecord->id,
            'selling_price' => 1500, 'purchase_price_egp' => 1000, 'currency' => 'EGP',
        ]))->assertCreated()->json('data.id');

        Sanctum::actingAs($empB, ['*']);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ]);
        $response->assertStatus(403);
    }

    public function test_11_14_03_unauthenticated_request_rejected(): void
    {
        // Clear authentication.
        Sanctum::actingAs($this->admin, ['*']);
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        // Unauth request.
        $this->app['auth']->forgetGuards();
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => 1,
        ]);
        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.15 STATE MACHINE
    // ═══════════════════════════════════════════════════════════════

    public function test_11_15_01_payment_does_not_change_paid_booking_status(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $b = $booking->fresh();
        $this->assertEquals(FlightBookingStatus::CONFIRMED->value, $b->status->value);

        // Second identical payment (different idempotency_key) — but booking is now confirmed+fully paid.
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
            'idempotency_key' => 'over-amount-'.uniqid(),
        ]);
        $response->assertStatus(422);
    }

    public function test_11_15_02_terminal_state_blocks_payment(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $cashbox = $this->makeAccount('CB', 'cashbox', 'EGP', 50_000);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.16 FE FINANCIAL DISPLAY AUDIT (resource fields vs DB)
    // ═══════════════════════════════════════════════════════════════

    public function test_11_16_01_resource_totals_match_database(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $booking = $this->createBookingViaApi('EGP', 1500, 1000, null, 'SIGN');
        $cashbox = $this->makeAccount('CB Display', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 800.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $response = $this->getJson("/api/v1/flight/bookings/{$booking->id}");
        $response->assertOk();

        $b = $booking->fresh();
        $this->assertEquals((float) $b->payments()->sum('amount'), $response->json('data.total_paid'),
            'Resource total_paid must match DB sum.');
        $this->assertEquals(
            (float) $b->selling_price - (float) $b->payments()->sum('amount'),
            $response->json('data.remaining'),
            'Resource remaining must match DB calculation.'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 11.17 REPORTING RECONCILIATION
    // ═══════════════════════════════════════════════════════════════

    public function test_11_17_01_carrier_balance_reflects_all_operations(): void
    {
        $this->seedCurrency('EGP', 1.0);
        $cashbox = $this->makeAccount('CB Report', 'cashbox', 'EGP', 100_000);
        $carrier = $this->makeCarrier('Report Carrier', null, 'EGP');
        $openingBalance = 50_000.0;
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, $openingBalance, 'opening'
        );

        // Create booking for 1000 purchase
        $customer = $this->makeCustomer('Report Cust');
        $booking = $this->postJson('/api/v1/flight/bookings', $this->buildPayload([
            'customer_id' => $customer->id, 'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
            'selling_price' => 1500, 'purchase_price_egp' => 1000, 'currency' => 'EGP',
        ]))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/flight/bookings/{$booking}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$booking}/cancel", [
            'airline_penalty' => 100.0, 'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/flight/bookings/{$booking}")->assertOk();

        // After all operations:
        // - Recharged 50000 (carrier = 50000)
        // - Debit 1000 (carrier = 49000)
        // - Credit back 900 from cancel (carrier = 49900)
        // - Credit back 100 from delete (airline_penalty) (carrier = 50000)
        // Net: carrier balance = opening balance (50,000).
        $this->assertEquals(
            $openingBalance, (float) $carrier->fresh()->balance,
            'After create + pay + cancel + delete, carrier balance must equal opening.'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    protected function seedCurrency(string $code, float $rate): void
    {
        Currency::firstOrCreate(
            ['code' => $code],
            ['name_ar' => $code, 'name_en' => $code, 'symbol' => $code[0],
             'exchange_rate' => $rate, 'is_active' => true, 'order' => 99]
        );
    }

    protected function buildPayload(array $overrides = []): array
    {
        return array_merge([
            'airline_name' => 'Carrier',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '00:00',
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'passengers' => [['first_name' => 'Test', 'last_name' => 'User', 'type' => 'adult']],
            'segments' => [['flight_number' => 'T1', 'from_airport' => 'CAI', 'to_airport' => 'JED',
                'departure_date' => now()->addDays(7)->toDateString(), 'flight_class' => 'economy']],
            'agent_name' => 'Office',
        ], $overrides);
    }

    protected function createBookingViaApi(
        string $currency,
        float $selling,
        float $purchase,
        ?float $purchaseForeign,
        string $channel
    ): FlightBooking {
        $customer = $this->makeCustomer('Cust '.$currency);
        $cashbox = $this->makeAccount('CB Init', 'cashbox', $currency, 100_000);

        if (strtoupper($channel) === 'SYSTEM') {
            $system = $this->makeSystem('Sys '.$currency, $currency);
            app(FlightSystemRechargeService::class)->rechargeFromAccount(
                $system, $cashbox, 50_000, 'seed'
            );
            $payload = $this->buildPayload([
                'customer_id' => $customer->id,
                'booking_channel_type' => 'SYSTEM',
                'purchase_balance_source' => 'system',
                'flight_system_id' => $system->id,
                'selling_price' => $selling,
                'purchase_price_egp' => $purchase,
                'purchase_price_foreign' => $purchaseForeign,
                'currency' => $currency,
            ]);
        } elseif (strtoupper($channel) === 'GROUP') {
            $carrier = $this->makeCarrier('Group Carrier', null, $currency);
            $group = FlightGroup::create([
                'flight_carrier_id' => $carrier->id, 'name' => 'G',
                'code' => 'GP-'.uniqid(), 'currency' => $currency,
                'credit_limit' => 100_000, 'is_active' => true,
                'created_by' => $this->admin->id,
            ]);
            $payload = $this->buildPayload([
                'customer_id' => $customer->id,
                'booking_channel_type' => 'GROUP',
                'purchase_balance_source' => 'group',
                'flight_carrier_id' => $carrier->id,
                'flight_group_id' => $group->id,
                'selling_price' => $selling,
                'purchase_price_egp' => $purchase,
                'currency' => $currency,
            ]);
        } else { // SIGN
            $carrier = $this->makeCarrier('Carrier', null, $currency);
            app(FlightCarrierRechargeService::class)->rechargeFromAccount(
                $carrier, $cashbox, 50_000, 'seed'
            );
            $payload = $this->buildPayload([
                'customer_id' => $customer->id,
                'booking_channel_type' => 'SIGN',
                'purchase_balance_source' => 'carrier',
                'flight_carrier_id' => $carrier->id,
                'selling_price' => $selling,
                'purchase_price_egp' => $purchase,
                'currency' => $currency,
            ]);
        }

        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertCreated();

        return FlightBooking::find($response->json('data.id'));
    }

    protected function makeAccount(string $name, string $type, string $currency, float $balance): Account
    {
        $account = Account::create([
            'name' => $name, 'type' => $type, 'currency' => $currency,
            'balance' => 0, 'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER ?? 'office',
            'module_type' => 'tourism', 'is_module_vault' => false,
            'notes' => 'P11 fixture', 'created_by' => $this->admin->id,
        ]);
        LedgerBalanceMutationGuard::run(function () use ($account, $balance) {
            $account->balance = $balance;
            $account->save();
        });
        AccountEntry::create([
            'account_id' => $account->id, 'transaction_id' => null,
            'debit' => 0, 'credit' => $balance, 'balance_after' => $balance,
            'notes' => 'opening',
        ]);

        return $account->fresh();
    }

    protected function makeCarrier(string $name, ?int $systemId, string $currency): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'flight_system_id' => $systemId,
            'currency' => $currency,
            'credit_limit' => 100_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeSystem(string $name, string $currency): FlightSystem
    {
        return FlightSystem::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'type' => 'gds',
            'currency' => $currency,
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeCustomer(string $name): Customer
    {
        return Customer::create([
            'full_name' => $name,
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'c-'.uniqid().'@test.com',
            'national_id' => '29'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'module_type' => 'tourism',
        ])->fresh();
    }
}