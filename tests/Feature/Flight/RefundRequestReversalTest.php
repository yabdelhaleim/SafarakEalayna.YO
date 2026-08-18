<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for RefundRequest reversal — Priority 3.3 + 3.4.
 *
 * Branches tested:
 *   - `agency_treasury`: reverses (a) GL prepaid→cashbox, (b) carrier/system debit, (c) treasury receipt.
 *   - `airline_credit`: cancels the linked AirlineCredit voucher (no GL posted).
 *
 * Invariants:
 *   1. Net balance delta == 0 for every financial account touched.
 *   2. Original RefundRequest + AirlineCredit/TreasuryTransaction rows preserved.
 *   3. New reversal transaction created (related_type=RefundRequest).
 *   4. RefundRequest is soft-deleted after reversal.
 */
class RefundRequestReversalTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected RefundService $refundService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);
        $this->refundService = app(RefundService::class);

        $this->admin = User::factory()->create([
            'name' => 'Refund Test Admin',
            'email' => 'refund-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Refund Test Customer',
            'phone' => '0123456789',
            'email' => 'refund-customer@test.com',
            'national_id' => '11122233344455',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Refund Test System',
            'code' => 'RFS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Refund Test Airline',
            'code' => 'RFA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Refund Test Cashbox',
            'type' => 'cashbox',
            'balance' => 100000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            100000.00,
            'Refund test setup'
        );

        $this->cashbox->refresh();

        $this->treasury = Treasury::create([
            'name' => 'Refund Test Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        // إنشاء Account بنفس اسم الـ treasury عشان الـ resolveCashboxAccount يلاقيه
        // (في الإنتاج الـ Treasury عادةً يكون مرتبط بـ Account بنفس الاسم)
        $treasuryAccount = Account::create([
            'name' => 'Refund Test Treasury',
            'type' => 'cashbox',
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // استبدل الـ cashbox بالـ treasury account عشان التيست يفحص الحساب الذي الـ refund بيخصم منه فعلاً
        $this->cashbox = $treasuryAccount;

        Log::info('RefundRequestReversalTest setUp complete', [
            'cashbox_id' => $this->cashbox->id,
            'treasury_id' => $this->treasury->id,
        ]);
    }

    /**
     * Helper: create a paid booking ready for refund.
     */
    protected function createPaidBooking(int $sellingPrice = 18000, int $purchasePrice = 15000): FlightBooking
    {
        $booking = $this->bookingService->createBooking([
            'customer_id'      => $this->customer->id,
            'airline_name'     => 'Refund Test Airline',
            'from_airport'     => 'CAI',
            'to_airport'       => 'JED',
            'departure_date'   => now()->addDays(7)->toDateString(),
            'trip_type'        => 'one_way',
            'currency'         => 'EGP',
            'purchase_price'   => $purchasePrice,
            'selling_price'    => $sellingPrice,
            'flight_carrier_id'=> $this->carrier->id,
            'account_id'       => $this->cashbox->id,
            'passengers'       => [
                ['name' => 'Refund Pax', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount'         => $sellingPrice,
            'payment_method' => 'cash',
            'account_id'     => $this->cashbox->id,
            'notes'          => 'Paid in full',
        ]);

        return $booking;
    }

    public function test_refund_to_agency_treasury_reversal_restores_all_balances(): void
    {
        Log::info('Starting: test_refund_to_agency_treasury_reversal_restores_all_balances');

        $sellingPrice = 18000.0;
        $cancellationFee = 1000.0;
        $refundAmount = $sellingPrice - $cancellationFee;        // 17000 (cash to customer)
        $purchaseEgp = 15000.0;
        $purchaseNet = $purchaseEgp - $cancellationFee;          // 14000 (credit back to carrier)

        $booking = $this->createPaidBooking((int) $sellingPrice, (int) $purchaseEgp);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Snapshot BEFORE refund
        $before = [
            'carrier'   => (float) $this->carrier->fresh()->balance,    // initial - 15000 = 92000
            'cashbox'   => (float) $this->cashbox->fresh()->balance,    // 18000 (customer paid in full)
            'treasury'  => (float) $this->treasury->fresh()->current_balance, // 0
        ];

        // Create + process refund to agency_treasury
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => $cancellationFee,
            'refund_currency'   => 'EGP',
            'destination'       => 'agency_treasury',
            'treasury_id'       => $this->treasury->id,
        ], $this->admin->id);

        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // ── ASSERT (بعد الـ refund — السلوك الصحيح): ─────────────────
        //   1. carrier: +purchaseNet (تم إرجاع الـ prepaid للجهة — عكس خصم الشراء الأصلي)
        //   2. cashbox.Account: -refundAmount (تم صرف الكاش للعميل)
        //   3. treasury.current_balance: لا تغيير (الـ Treasury model منفصل عن الـ GL)
        //   4. clearing (income): -refundAmount (تم مسح الإيراد)
        //   5. customer.account.balance: 0 (الدين اتمسح بعد Step A + Step C)
        $carrierAfter = (float) $this->carrier->fresh()->balance;
        $cashboxAfter = (float) $this->cashbox->fresh()->balance;
        $treasuryAfter = (float) $this->treasury->fresh()->current_balance;
        $customerBalance = (float) DB::table('accounts')->where('id', $this->customer->fresh()->account_id)->value('balance');
        $clearingBalance = $booking->sale_gl_transaction_id
            ? (float) DB::table('accounts')->where('id', Transaction::find($booking->sale_gl_transaction_id)->from_account_id)->value('balance')
            : 0.0;

        $this->assertEqualsWithDelta(
            $before['carrier'] + $purchaseNet,
            $carrierAfter,
            0.01,
            'carrier يجب أن يُكرتَد بـ purchaseNet بعد الـ refund'
        );
        $this->assertEqualsWithDelta(
            $before['cashbox'] - $refundAmount,
            $cashboxAfter,
            0.01,
            'cashbox.Account يجب أن يُخصم منه refundAmount (الكاش بيرجع للعميل)'
        );
        $this->assertEqualsWithDelta(
            $before['treasury'],
            $treasuryAfter,
            0.01,
            'treasury.current_balance لا يجب أن يتغير (الـ Treasury model منفصل عن الـ GL)'
        );
        $this->assertEqualsWithDelta(
            0.0,
            $customerBalance,
            0.01,
            'customer.account.balance يجب أن يبقى 0 (الدين اتمسح)'
        );
        $this->assertEqualsWithDelta(
            -$cancellationFee,
            $clearingBalance,
            0.01,
            "clearing.balance يجب أن يكون -{$cancellationFee} (رسوم الإلغاء المتبقية كإيراد — pattern متطابق مع cancelBooking)"
        );

        // مجموع كل الدلتا = 0 (الحساب المزدوج محفوظ)
        //
        // ملاحظة مهمة: لازم نضم الـ COGS expense_clearing account لأنه بيتأثر بـ refundCogs:
        // - Step B بيعمل refundCogs(expenseContra → prepaid, purchaseNet)
        // - expenseContra.balance بيتخصم منه purchaseNet (14000)
        // - carrier.balance (الـ prepaid) بيتزاد بـ purchaseNet (14000)
        // فالناتج الإجمالي للدلتا = 0 بشرط إننا نضم الـ expenseContra
        $expenseContraId = app(\App\Services\Finance\LedgerClearingAccounts::class)
            ->expenseContraIdForModule(\App\Enums\TransactionModule::Flight);
        $expenseContraBalance = $expenseContraId
            ? (float) DB::table('accounts')->where('id', $expenseContraId)->value('balance')
            : 0.0;
        $expenseContraDelta = $expenseContraBalance - 0.0; // كان 0 قبل (لم يتأثر بالحجز)

        $deltaSum = ($carrierAfter - $before['carrier'])
            + ($cashboxAfter - $before['cashbox'])
            + ($customerBalance - 0.0)
            + ($clearingBalance - (-$sellingPrice))
            + $expenseContraDelta;
        $this->assertEqualsWithDelta(
            0.0,
            $deltaSum,
            0.01,
            'مجموع كل تغيرات البالانسات يجب أن يكون 0 (الحساب المزدوج محفوظ — يشمل expenseContra للـ COGS)'
        );

        $txCountBeforeReverse = Transaction::query()
            ->where('related_type', RefundRequest::class)
            ->where('related_id', $refundRequest->id)
            ->count();
        // الـ processRefundRequest بيـ recordJournalTransfer بـ related_type=RefundRequest
        // للـ treasury refund step. الـ COGS reversal بـ related_type=FlightBooking.
        // الـ sale reversal بـ related_type=FlightBooking.
        // فعدد القيود بـ related_type=RefundRequest قبل الـ reverse = 1.

        // ── ACT: reverse the refund ──────────────────────────────
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // ── ASSERT 1: RefundRequest soft-deleted ──────────────────
        $refundRequest->refresh();
        $this->assertTrue($refundRequest->trashed(), 'RefundRequest must be soft-deleted after reversal');

        // ── ASSERT 2: كل البالانسات ترجع EXACTLY كما كانت قبل الـ refund ─
        $after = [
            'carrier'   => (float) $this->carrier->fresh()->balance,
            'cashbox'   => (float) $this->cashbox->fresh()->balance,
            'treasury'  => (float) $this->treasury->fresh()->current_balance,
        ];
        $customerBalanceAfterReverse = (float) DB::table('accounts')->where('id', $this->customer->fresh()->account_id)->value('balance');
        $clearingBalanceAfterReverse = $booking->sale_gl_transaction_id
            ? (float) DB::table('accounts')->where('id', Transaction::find($booking->sale_gl_transaction_id)->from_account_id)->value('balance')
            : 0.0;

        $this->assertEqualsWithDelta(
            round($before['carrier'] - $after['carrier'], 4),
            0.0,
            0.01,
            'Carrier balance delta must be zero after refund reversal'
        );
        $this->assertEqualsWithDelta(
            round($before['cashbox'] - $after['cashbox'], 4),
            0.0,
            0.01,
            'Cashbox balance delta must be zero after refund reversal'
        );
        $this->assertEqualsWithDelta(
            round($before['treasury'] - $after['treasury'], 4),
            0.0,
            0.01,
            'Treasury balance delta must be zero after refund reversal'
        );
        $this->assertEqualsWithDelta(
            0.0,
            $customerBalanceAfterReverse,
            0.01,
            'customer.account.balance يجب أن يبقى 0 بعد الـ reverse (العميل دفع خلاص، الـ reverse ما يضيفش دين جديد)'
        );
        $this->assertEqualsWithDelta(
            -$sellingPrice,
            $clearingBalanceAfterReverse,
            0.01,
            'clearing يجب أن يعود لـ -sellingPrice (إعادة قيد البيع)'
        );

        // ── ASSERT 3: reversal transaction exists with RefundRequest related_type ─
        $txCountAfterReverse = Transaction::query()
            ->where('related_type', RefundRequest::class)
            ->where('related_id', $refundRequest->id)
            ->count();

        $this->assertGreaterThan(
            $txCountBeforeReverse,
            $txCountAfterReverse,
            'Reversal must create a new transaction with related_type=RefundRequest'
        );

        Log::info('PASSED: test_refund_to_agency_treasury_reversal_restores_all_balances', [
            'before' => $before,
            'after' => $after,
            'customer_balance_after_reverse' => $customerBalanceAfterReverse,
            'clearing_balance_after_reverse' => $clearingBalanceAfterReverse,
        ]);
    }

    public function test_refund_to_airline_credit_reversal_cancels_credit_voucher(): void
    {
        Log:: info('Starting: test_refund_to_airline_credit_reversal_cancels_credit_voucher');

        $sellingPrice = 18000.0;

        $booking = $this->createPaidBooking((int) $sellingPrice, 15000);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Create + process refund to airline_credit
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => 0,
            'refund_currency'   => 'EGP',
            'destination'       => 'airline_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // AirlineCredit voucher should exist + be active
        $credit = AirlineCredit::query()->where('refund_request_id', $refundRequest->id)->first();
        $this->assertNotNull($credit, 'AirlineCredit voucher must be created on refund processing');
        $this->assertEquals('active', $credit->status, 'AirlineCredit must be active before reversal');

        // Carrier balance unchanged (no debit on airline_credit destination)
        $carrierBalanceAfterRefund = (float) $this->carrier->fresh()->balance;

        // ── ACT: reverse the refund ──────────────────────────────
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // ── ASSERT 1: RefundRequest soft-deleted ──────────────────
        $refundRequest->refresh();
        $this->assertTrue($refundRequest->trashed(), 'RefundRequest must be soft-deleted after reversal');

        // ── ASSERT 2: AirlineCredit voucher cancelled AND soft-deleted ─
        $credit->refresh();
        $this->assertEquals('cancelled', $credit->status, 'AirlineCredit must be cancelled after reversal');
        $this->assertTrue($credit->trashed(), 'AirlineCredit voucher must be soft-deleted after cancelCredit()');

        // ── ASSERT 3: carrier balance unchanged ───────────────────
        $this->assertEquals(
            $carrierBalanceAfterRefund,
            (float) $this->carrier->fresh()->balance,
            'Carrier balance must not change on airline_credit reversal (no GL posted originally)'
        );

        Log::info('PASSED: test_refund_to_airline_credit_reversal_cancels_credit_voucher', [
            'credit_status' => $credit->status,
            'carrier_balance' => (float) $this->carrier->fresh()->balance,
        ]);
    }

    public function test_double_reversal_of_refund_request_throws(): void
    {
        $booking = $this->createPaidBooking(18000, 15000);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // First reversal succeeds
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // Second reversal must throw (idempotency)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/محذوف بالفعل/');

        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);
    }

    public function test_reversing_unprocessed_refund_request_soft_deletes_without_gl_change(): void
    {
        $booking = $this->createPaidBooking(18000, 15000);
        // Required: a booking must be CONFIRMED before a refund request can be created
        // (Bug #C4 fix from RefundService — PENDING bookings can't be refunded).
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Create refund request but DO NOT process it
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $this->admin->id);

        $carrierBefore = (float) $this->carrier->fresh()->balance;

        // Reverse the pending refund
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // No GL impact (was never processed)
        $this->assertEquals(
            $carrierBefore,
            (float) $this->carrier->fresh()->balance,
            'Carrier balance must not change when reversing unprocessed refund'
        );

        $refundRequest->refresh();
        $this->assertTrue($refundRequest->trashed(), 'Pending refund must be soft-deleted');

        Log::info('PASSED: test_reversing_unprocessed_refund_request_soft_deletes_without_gl_change');
    }
}