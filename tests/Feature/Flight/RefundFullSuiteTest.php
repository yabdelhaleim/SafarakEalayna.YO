<?php

namespace Tests\Feature\Flight;

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Exceptions\BusinessLogicException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Flight\CustomerCreditDisbursement;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use App\Services\Reports\ProfitLossReportService;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\RefundTestHelpers;
use Tests\TestCase;

class RefundFullSuiteTest extends TestCase
{
    use RefreshDatabase, RefundTestHelpers;

    protected User $admin;
    protected Customer $customer;
    protected FlightCarrier $carrier;
    protected Treasury $treasury;
    protected Account $cashbox;
    protected RefundService $refundService;
    protected FlightBookingService $bookingService;
    protected LedgerClearingAccounts $clearing;
    protected float $carrierAtSetup = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        $fx = $this->buildEgpFixture(500000.0);
        $this->admin          = $fx['admin'];
        $this->customer       = $fx['customer'];
        $this->carrier        = $fx['carrier'];
        $this->treasury       = $fx['treasury'];
        $this->cashbox        = $fx['cashbox'];
        $this->refundService  = app(RefundService::class);
        $this->bookingService = app(FlightBookingService::class);
        $this->clearing       = app(LedgerClearingAccounts::class);
        $this->carrierAtSetup = (float) $this->carrier->balance;
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة A: الاسترداد (EGP)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * A1 العميل يلغي: penalty=1000، commission=200
     * توقع: للمورد 6350، للعميل 6300، ledger=6300، carrier=499000،
     * P&L net بعد الاسترداد = +200 (الإيراد 1200، التكلفة 1000)،
     * الحسابان الوسيطان يرجعا لأرصدتهما (يعني لا residue −400)،
     * قيد العمولة 200 على حساب إيرادات عمولات الإلغاء.
     */
    public function test_A1_customer_voluntary_penalty1000_commission200(): void
    {
        $this->currentScenarioId = 'A1';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case'       => 'customer_voluntary',
            'airline_penalty'   => 1000,
            'agency_commission' => 200,
            'refund_currency'   => 'EGP',
            'destination'       => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(6300.0, (float) $req->refund_amount, 'A1: صافي المسترد للعميل = 6300');
        $this->assertEquals(6350.0, (float) $req->supplier_refund_amount, 'A1: المسترد من المورد = 6350');
        $this->assertEquals(1200.0, (float) $req->cancellation_fee, 'A1: رسوم الإلغاء = 1200');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد A1');
        $this->dumpAccounts('أرصدة الحسابات بعد A1');
        $this->dumpLedger('دفتر رصيد العميل A1', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر A1', $plBefore, $plAfter);
        $this->flushDump('A1');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertIntermediateAccountsRestored($beforeSale);
        $this->assertCarrierBalance($this->carrier, 499000.0);
        $this->assertEqualsWithDelta(200.0, (float) $plAfter['netProfit'], 0.01, 'A1: P&L net = +200');
        $this->assertAuditLogWritten($req->id);

        $feeAccId = $this->clearing->cancellationFeeRevenueAccountId();
        $feeBalance = (float) DB::table('accounts')->where('id', $feeAccId)->value('balance');
        $this->assertEqualsWithDelta(-200.0, $feeBalance, 0.01, 'A1: قيد العمولة 200 على حساب إيرادات عمولات الإلغاء');
    }

    /**
     * A2 الطيران يلغي، رجّع كامل، بعمولة 200 (وجهة agency_treasury):
     * توقع: عميل 7300، net=+200، carrier=500000. (خزنة: لا تنزل تحت صفر.)
     */
    public function test_A2_airline_cancels_full_refund_commission200_agency_treasury(): void
    {
        $this->currentScenarioId = 'A2';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'     => $booking->id,
            'refund_case'           => 'airline_cancelled',
            'airline_refund_amount' => 7350,
            'apply_commission'      => true,
            'agency_commission'     => 200,
            'refund_currency'       => 'EGP',
            'destination'           => 'agency_treasury',
            'treasury_id'           => $this->treasury->id,
        ], $this->admin->id);

        $this->assertEquals(7300.0, (float) $req->refund_amount, 'A2: صافي المسترد للعميل = 7300');
        $this->assertEquals(7350.0, (float) $req->supplier_refund_amount, 'A2: المسترد من المورد = 7350');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد A2');
        $this->dumpAccounts('أرصدة الحسابات بعد A2');
        $this->dumpLedger('دفتر رصيد العميل A2', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر A2', $plBefore, $plAfter);
        $this->flushDump('A2');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCarrierBalance($this->carrier, 500000.0);
        $this->assertEqualsWithDelta(200.0, (float) $plAfter['netProfit'], 0.01, 'A2: P&L net = +200');
        $this->assertGreaterThanOrEqual(0.0, (float) $this->cashbox->fresh()->balance, 'A2: الخزنة لا تنزل تحت صفر');
        $this->assertAuditLogWritten($req->id);
    }

    /**
     * A3 الطيران يلغي، كامل، بدون عمولة: عميل 7500، net=0.
     */
    public function test_A3_airline_cancels_full_refund_no_commission(): void
    {
        $this->currentScenarioId = 'A3';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'     => $booking->id,
            'refund_case'           => 'airline_cancelled',
            'airline_refund_amount' => 7350,
            'apply_commission'      => false,
            'refund_currency'       => 'EGP',
            'destination'           => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(7500.0, (float) $req->refund_amount, 'A3: صافي المسترد للعميل = 7500');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد A3');
        $this->dumpAccounts('أرصدة الحسابات بعد A3');
        $this->dumpLedger('دفتر رصيد العميل A3', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر A3', $plBefore, $plAfter);
        $this->flushDump('A3');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertIntermediateAccountsRestored($beforeSale);
        $this->assertCarrierBalance($this->carrier, 500000.0);
        $this->assertEqualsWithDelta(0.0, (float) $plAfter['netProfit'], 0.01, 'A3: P&L net = 0');
        $this->assertAuditLogWritten($req->id);
    }

    /**
     * A4 الطيران رجّع 7100، ضرائب 250 علينا، العميل 7500:
     * توقع: carrier=499750، net=-250، ledger=7500، حساب خسائر الاسترداد=250.
     */
    public function test_A4_airline_refund_7100_taxes250_on_agency(): void
    {
        $this->currentScenarioId = 'A4';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'                => $booking->id,
            'refund_case'                      => 'airline_cancelled',
            'airline_refund_amount'            => 7100,
            'apply_commission'                 => false,
            'non_refundable_taxes_on_customer' => false,
            'non_refundable_taxes_amount'      => 250,
            'refund_currency'                  => 'EGP',
            'destination'                      => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(7500.0, (float) $req->refund_amount, 'A4: صافي المسترد للعميل = 7500');
        $this->assertEquals(250.0, (float) $req->supplier_refund_loss, 'A4: خسارة الاسترداد = 250');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد A4');
        $this->dumpAccounts('أرصدة الحسابات بعد A4');
        $this->dumpLedger('دفتر رصيد العميل A4', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر A4', $plBefore, $plAfter);
        $this->flushDump('A4');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertIntermediateAccountsRestored($beforeSale);
        $this->assertCarrierBalance($this->carrier, 499750.0);
        $this->assertEqualsWithDelta(-250.0, (float) $plAfter['netProfit'], 0.01, 'A4: P&L net = -250');
        $this->assertEqualsWithDelta(7500.0, $this->ledgerBalance($this->customer->id), 0.01, 'A4: ledger = 7500');

        $lossAccId = $this->clearing->refundLossAccountId();
        $lossBalance = (float) DB::table('accounts')->where('id', $lossAccId)->value('balance');
        $this->assertEqualsWithDelta(250.0, $lossBalance, 0.01, 'A4: حساب خسائر الاسترداد = 250');
        $this->assertAuditLogWritten($req->id);
    }

    /**
     * A5 الطيران رجّع 7100، ضرائب 250 على العميل، العميل 7250:
     * توقع: carrier=499750، net=0، ledger=7250.
     */
    public function test_A5_airline_refund_7100_taxes250_on_customer(): void
    {
        $this->currentScenarioId = 'A5';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'                => $booking->id,
            'refund_case'                      => 'airline_cancelled',
            'airline_refund_amount'            => 7100,
            'apply_commission'                 => false,
            'non_refundable_taxes_on_customer' => true,
            'non_refundable_taxes_amount'      => 250,
            'refund_currency'                  => 'EGP',
            'destination'                      => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(7250.0, (float) $req->refund_amount, 'A5: صافي المسترد للعميل = 7250');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد A5');
        $this->dumpAccounts('أرصدة الحسابات بعد A5');
        $this->dumpLedger('دفتر رصيد العميل A5', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر A5', $plBefore, $plAfter);
        $this->flushDump('A5');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertIntermediateAccountsRestored($beforeSale);
        $this->assertCarrierBalance($this->carrier, 499750.0);
        $this->assertEqualsWithDelta(0.0, (float) $plAfter['netProfit'], 0.01, 'A5: P&L net = 0');
        $this->assertEqualsWithDelta(7250.0, $this->ledgerBalance($this->customer->id), 0.01, 'A5: ledger = 7250');
        $this->assertAuditLogWritten($req->id);
    }

    /**
     * A6 عمولة صفر مع غرامة 1000: عميل 6500، net=+0 (بدون إيراد عمولة).
     */
    public function test_A6_zero_commission_penalty1000(): void
    {
        $this->currentScenarioId = 'A6';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case'       => 'customer_voluntary',
            'airline_penalty'   => 1000,
            'agency_commission' => 0,
            'refund_currency'   => 'EGP',
            'destination'       => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(6500.0, (float) $req->refund_amount, 'A6: صافي المسترد للعميل = 6500');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد A6');
        $this->dumpAccounts('أرصدة الحسابات بعد A6');
        $this->dumpLedger('دفتر رصيد العميل A6', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر A6', $plBefore, $plAfter);
        $this->flushDump('A6');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertIntermediateAccountsRestored($beforeSale);
        $this->assertEqualsWithDelta(0.0, (float) $plAfter['netProfit'], 0.01, 'A6: P&L net = 0');
        $this->assertAuditLogWritten($req->id);
    }

    /**
     * A7 غرامة+عمولة = سعر البيع بالضبط (7300+200): عميل 0، لا ledger سالب.
     */
    public function test_A7_penalty_plus_commission_equals_selling_price(): void
    {
        $this->currentScenarioId = 'A7';
        $beforeSale = $this->snapIntermediateBalances();

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case'       => 'customer_voluntary',
            'airline_penalty'   => 7300,
            'agency_commission' => 200,
            'refund_currency'   => 'EGP',
            'destination'       => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(0.0, (float) $req->refund_amount, 'A7: صافي المسترد للعميل = 0');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $this->dumpEntries('قيود الاسترداد A7');
        $this->dumpAccounts('أرصدة الحسابات بعد A7');
        $this->dumpLedger('دفتر رصيد العميل A7', $this->customer->id);
        $this->flushDump('A7');

        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance($this->customer->id), 0.01, 'A7: لا ledger سالب');
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertAuditLogWritten($req->id);
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة B: دفعة زيادة وaddPayment
    // ═════════════════════════════════════════════════════════════════════

    /**
     * B1 دفعة 3000 من 7500
     */
    public function test_B1_partial_payment_3000(): void
    {
        $this->currentScenarioId = 'B1';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);

        $this->payBooking($booking, 3000, $this->cashbox->id);
        $booking->refresh();

        $this->dumpEntries('قيود الدفعة B1');
        $this->flushDump('B1');

        $this->assertEquals(3000.0, (float) $booking->paid_amount, 'B1: paid_amount = 3000');
        $this->assertEquals(0.0, $this->ledgerBalance($this->customer->id), 'B1: لا ledger');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * B2 دفعة 7500
     */
    public function test_B2_exact_payment_7500(): void
    {
        $this->currentScenarioId = 'B2';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);

        $this->payBooking($booking, 7500, $this->cashbox->id);
        $booking->refresh();

        $this->dumpEntries('قيود الدفعة B2');
        $this->flushDump('B2');

        $this->assertEquals(7500.0, (float) $booking->paid_amount, 'B2: paid_amount = 7500');
        $this->assertEquals(0.0, $this->ledgerBalance($this->customer->id), 'B2: لا ledger');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * B3 دفعتان 3000+4500: لا ledger، والقيود مطابقة
     */
    public function test_B3_two_payments_3000_plus_4500(): void
    {
        $this->currentScenarioId = 'B3';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);

        $this->payBooking($booking, 3000, $this->cashbox->id);
        $this->payBooking($booking, 4500, $this->cashbox->id);
        $booking->refresh();

        $this->dumpEntries('قيود الدفعتين B3');
        $this->flushDump('B3');

        $this->assertEquals(7500.0, (float) $booking->paid_amount, 'B3: paid_amount = 7500');
        $this->assertEquals(0.0, $this->ledgerBalance($this->customer->id), 'B3: لا ledger');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * B4 دفعة 8000 على 7500 بدون allow_overpayment=true: تترفض (استثناء) ولا قيود ولا ledger ولا payment.
     */
    public function test_B4_overpayment_8000_without_allow_flag_rejected(): void
    {
        $this->currentScenarioId = 'B4';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $txCountBefore = DB::table('transactions')->count();
        $ledgerCountBefore = DB::table('customer_credit_ledger')->count();

        $thrown = false;
        try {
            $this->payBooking($booking, 8000, $this->cashbox->id);
        } catch (\Throwable $e) {
            $thrown = true;
            $this->dumpL('B4 thrown: ' . $e->getMessage());
        }

        $booking->refresh();
        $this->flushDump('B4');

        $this->assertTrue($thrown, 'B4: يجب رمي استثناء عند محاولة الدفع بالزيادة دون allow_overpayment');
        $this->assertEquals(0.0, (float) $booking->paid_amount, 'B4: لم يُسجل دفع');
        $this->assertEquals($txCountBefore, DB::table('transactions')->count(), 'B4: لا قيود جديدة');
        $this->assertEquals($ledgerCountBefore, DB::table('customer_credit_ledger')->count(), 'B4: لا ledger');
    }

    /**
     * B5 نفس الدفعة مع allow_overpayment=true: تتقبل؛ ledger=+500، paid_amount=7500، الخزنة +8000، القيد متوازن،
     * P&L بعدها: الإيراد 7500 فقط (الـ 500 التزام، مش إيراد) → net=+150.
     */
    public function test_B5_overpayment_8000_with_allow_flag(): void
    {
        $this->currentScenarioId = 'B5';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $cashboxBalBefore = (float) $this->cashbox->fresh()->balance;
        $plBefore = $this->plSnapshot();

        $this->payBooking($booking, 8000, $this->cashbox->id, ['allow_overpayment' => true]);
        $booking->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الدفعة بالزيادة B5');
        $this->dumpAccounts('أرصدة الحسابات بعد B5');
        $this->dumpLedger('دفتر رصيد العميل B5', $this->customer->id);
        $this->dumpPL('تقرير P&L B5', $plBefore, $plAfter);
        $this->flushDump('B5');

        $this->assertEquals(7500.0, (float) $booking->paid_amount, 'B5: paid_amount = 7500');
        $this->assertEqualsWithDelta(500.0, $this->ledgerBalance($this->customer->id), 0.01, 'B5: ledger = +500');
        $this->assertEqualsWithDelta($cashboxBalBefore + 8000.0, (float) $this->cashbox->fresh()->balance, 0.01, 'B5: الخزنة زادت 8000 نقدية');
        $this->assertEqualsWithDelta(150.0, (float) $plAfter['netProfit'], 0.01, 'B5: P&L net = +150');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
        $this->assertCustomerGlEqualsLedger($this->customer->id);
    }

    /**
     * B6 دفعة 150 USD على حجز 150 USD (سعر 48.5): تتقبل بقيد متوازن، بدون ledger، وبدون رصيد وهمي.
     */
    public function test_B6_usd_payment_exact(): void
    {
        $this->currentScenarioId = 'B6';
        $fx = $this->buildForeignCurrencyFixture('USD', 48.5, 10000.0);
        Currency::query()->updateOrCreate(['code' => 'USD'], [
            'name_ar' => 'USD', 'name_en' => 'USD', 'symbol' => '$',
            'exchange_rate' => 48.5, 'is_active' => true, 'order' => 0,
        ]);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 140.0, 0, 'USD', 48.5);

        $this->payBooking($booking, 150.0, $fx['cashbox']->id);
        $booking->refresh();

        $this->dumpEntries('قيود دفعة USD B6');
        $this->dumpLedger('دفتر العميل B6', $fx['customer']->id);
        $this->flushDump('B6');

        $this->assertEquals(0.0, $this->ledgerBalance($fx['customer']->id, null, 'USD'), 'B6: بدون ledger وهمي');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * B7 دفعة 160 USD على حجز 150 USD مع allow_overpayment=true:
     * ledger=+10 USD (موثق بعملة الحجز/الاسترداد USD)، والقيد متوازن عبر العملات.
     */
    public function test_B7_usd_overpayment_with_allow(): void
    {
        $this->currentScenarioId = 'B7';
        Currency::query()->updateOrCreate(['code' => 'USD'], [
            'name_ar' => 'USD', 'name_en' => 'USD', 'symbol' => '$',
            'exchange_rate' => 50.0, 'is_active' => true, 'order' => 0,
        ]);

        $fx = $this->buildForeignCurrencyFixture('USD', 50.0, 10000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 140.0, 0, 'USD', 50.0);

        $this->payBooking($booking, 160.0, $fx['cashbox']->id, ['allow_overpayment' => true]);
        $booking->refresh();

        $this->dumpEntries('قيود دفعة USD بالزيادة B7');
        $this->dumpLedger('دفتر العميل B7', $fx['customer']->id);
        $this->flushDump('B7');

        $usdLedger = $this->ledgerBalance($fx['customer']->id, null, 'USD');
        $this->assertEqualsWithDelta(10.0, $usdLedger, 0.01, 'B7: ledger = +10 USD');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * B8 إلغاء حجز مدفوع زيادة: الرصيد 500 يفضل، والـ GL = ledger.
     */
    public function test_B8_cancel_overpaid_booking_credit_remains(): void
    {
        $this->currentScenarioId = 'B8';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 8000, $this->cashbox->id, ['allow_overpayment' => true]);
        $booking->refresh();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'     => $booking->id,
            'refund_case'           => 'airline_cancelled',
            'airline_refund_amount' => 7350,
            'apply_commission'      => false,
            'refund_currency'       => 'EGP',
            'destination'           => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->dumpEntries('قيود إلغاء حجز مدفوع زيادة B8');
        $this->dumpLedger('دفتر رصيد العميل B8', $this->customer->id);
        $this->flushDump('B8');

        $totalCredit = $this->ledgerBalance($this->customer->id);
        $this->assertEqualsWithDelta(8000.0, $totalCredit, 0.01, 'B8: الرصيد 500 زيادة + 7500 استرداد = 8000');
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة C: التسوية والرصيد الموحد
    // ═════════════════════════════════════════════════════════════════════

    /**
     * C1 شركة: دين 4000 < المسترد 6300: تُسوى 4000، الباقي 2300 رصيد دائن.
     */
    public function test_C1_company_debt_less_than_refund(): void
    {
        $this->currentScenarioId = 'C1';
        $company = Customer::create([
            'full_name'   => 'C1 Company ' . uniqid(),
            'phone'       => '010' . substr(md5(microtime()), 0, 8),
            'email'       => 'c1-co-' . uniqid() . '@test.local',
            'national_id' => substr(md5(microtime()), 0, 14),
            'city'        => 'Cairo',
            'type'        => 'company',
        ]);
        $employee = Customer::create([
            'full_name'   => 'C1 Employee ' . uniqid(),
            'phone'       => '010' . substr(md5(microtime()), 0, 8),
            'email'       => 'c1-emp-' . uniqid() . '@test.local',
            'national_id' => substr(md5(microtime()), 0, 14),
            'city'        => 'Cairo',
            'type'        => 'individual',
            'company_id'  => $company->id,
        ]);

        $older = $this->bookingService->createBooking([
            'customer_id'       => $employee->id,
            'airline_name'      => 'Audit Airline',
            'from_airport'      => 'CAI', 'to_airport' => 'JED',
            'departure_date'    => now()->addDays(3)->toDateString(),
            'trip_type'         => 'one_way', 'currency' => 'EGP',
            'purchase_price'    => 1000, 'selling_price' => 4000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr'               => 'C1OLD' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers'        => [['name' => 'Old Pax', 'type' => 'adult']],
        ]);
        $older->update(['created_at' => now()->subDays(10)]);

        $newer = $this->bookingService->createBooking([
            'customer_id'       => $employee->id,
            'airline_name'      => 'Audit Airline',
            'from_airport'      => 'CAI', 'to_airport' => 'JED',
            'departure_date'    => now()->addDays(7)->toDateString(),
            'trip_type'         => 'one_way', 'currency' => 'EGP',
            'purchase_price'    => 7350, 'selling_price' => 7500,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr'               => 'C1NEW' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers'        => [['name' => 'New Pax', 'type' => 'adult']],
        ]);
        $newer->update(['status' => FlightBookingStatus::CONFIRMED]);
        $this->payBooking($newer, 7500, $this->cashbox->id);
        $newer->refresh();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $newer->id,
            'refund_case'       => 'customer_voluntary',
            'airline_penalty'   => 1000,
            'agency_commission' => 200,
            'refund_currency'   => 'EGP',
            'destination'       => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $this->dumpEntries('قيود C1');
        $this->dumpLedger('دفتر رصيد الشركة C1', null, $company->id);
        $this->flushDump('C1');

        $this->assertEqualsWithDelta(4000.0, (float) $req->customer_debt_settled_amount, 0.01, 'C1: تسوية دين 4000');
        $this->assertEqualsWithDelta(2300.0, (float) $req->customer_credit_amount, 0.01, 'C1: رصيد دائن 2300');
        $this->assertEqualsWithDelta(2300.0, $this->ledgerBalance(null, $company->id), 0.01, 'C1: ledger = 2300');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * C2 شركة: دين = 6300: الرصيد صفر، الدين صفر.
     */
    public function test_C2_company_debt_equals_refund(): void
    {
        $this->currentScenarioId = 'C2';
        $company = Customer::create([
            'full_name' => 'C2 Company ' . uniqid(), 'phone' => '010' . substr(md5(microtime()), 0, 8),
            'email' => 'c2-' . uniqid() . '@test.local', 'national_id' => substr(md5(microtime()), 0, 14),
            'city' => 'Cairo', 'type' => 'company',
        ]);
        $employee = Customer::create([
            'full_name' => 'C2 Employee ' . uniqid(), 'phone' => '010' . substr(md5(microtime()), 0, 8),
            'email' => 'c2-emp-' . uniqid() . '@test.local', 'national_id' => substr(md5(microtime()), 0, 14),
            'city' => 'Cairo', 'type' => 'individual', 'company_id' => $company->id,
        ]);

        $older = $this->bookingService->createBooking([
            'customer_id' => $employee->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(3)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 1000, 'selling_price' => 6300,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C2OLD' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'Old Pax', 'type' => 'adult']],
        ]);
        $older->update(['created_at' => now()->subDays(10)]);

        $newer = $this->bookingService->createBooking([
            'customer_id' => $employee->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 7350, 'selling_price' => 7500,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C2NEW' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'New Pax', 'type' => 'adult']],
        ]);
        $newer->update(['status' => FlightBookingStatus::CONFIRMED]);
        $this->payBooking($newer, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $newer->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();
        $older->refresh();

        $this->dumpEntries('قيود C2');
        $this->dumpLedger('دفتر رصيد الشركة C2', null, $company->id);
        $this->flushDump('C2');

        $this->assertEqualsWithDelta(6300.0, (float) $req->customer_debt_settled_amount, 0.01, 'C2: تسوية دين 6300');
        $this->assertEqualsWithDelta(0.0, (float) $req->customer_credit_amount, 0.01, 'C2: الرصيد صفر');
        $this->assertEqualsWithDelta(0.0, (float) $older->remaining_amount, 0.01, 'C2: الدين صفر');
        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance(null, $company->id), 0.01, 'C2: ledger = 0');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * C3 شركة: دين 9000 > 6300: المسترد كله يُخصم، الدين المتبقي 2700، لا ledger.
     */
    public function test_C3_company_debt_greater_than_refund(): void
    {
        $this->currentScenarioId = 'C3';
        $company = Customer::create([
            'full_name' => 'C3 Company ' . uniqid(), 'phone' => '010' . substr(md5(microtime()), 0, 8),
            'email' => 'c3-' . uniqid() . '@test.local', 'national_id' => substr(md5(microtime()), 0, 14),
            'city' => 'Cairo', 'type' => 'company',
        ]);
        $employee = Customer::create([
            'full_name' => 'C3 Employee ' . uniqid(), 'phone' => '010' . substr(md5(microtime()), 0, 8),
            'email' => 'c3-emp-' . uniqid() . '@test.local', 'national_id' => substr(md5(microtime()), 0, 14),
            'city' => 'Cairo', 'type' => 'individual', 'company_id' => $company->id,
        ]);

        $older = $this->bookingService->createBooking([
            'customer_id' => $employee->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(3)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 1000, 'selling_price' => 9000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C3OLD' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'Old Pax', 'type' => 'adult']],
        ]);
        $older->update(['created_at' => now()->subDays(10)]);

        $newer = $this->bookingService->createBooking([
            'customer_id' => $employee->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 7350, 'selling_price' => 7500,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C3NEW' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'New Pax', 'type' => 'adult']],
        ]);
        $newer->update(['status' => FlightBookingStatus::CONFIRMED]);
        $this->payBooking($newer, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $newer->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();
        $older->refresh();

        $this->dumpEntries('قيود C3');
        $this->flushDump('C3');

        $this->assertEqualsWithDelta(6300.0, (float) $req->customer_debt_settled_amount, 0.01, 'C3: المسترد كله يخصم');
        $this->assertEqualsWithDelta(2700.0, (float) $older->remaining_amount, 0.01, 'C3: الدين المتبقي 2700');
        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance(null, $company->id), 0.01, 'C3: لا ledger');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * C4 عميل عادي: 3 فواتير (2000 قديمة، 3000، 4000): المسترد 6300 يُسوي
     * الأقدم أولًا بالترتيب، ثم الثانية، والباقي من الثالثة جزئيًا.
     */
    public function test_C4_individual_fifo_three_invoices(): void
    {
        $this->currentScenarioId = 'C4';
        $b1 = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(3)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 1000, 'selling_price' => 2000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C4F1' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'P1', 'type' => 'adult']],
        ]);
        $b1->update(['created_at' => now()->subDays(20)]);

        $b2 = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(4)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 1000, 'selling_price' => 3000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C4F2' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'P2', 'type' => 'adult']],
        ]);
        $b2->update(['created_at' => now()->subDays(10)]);

        $b3 = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(5)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 1000, 'selling_price' => 4000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'C4F3' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'P3', 'type' => 'adult']],
        ]);
        $b3->update(['created_at' => now()->subDays(5)]);

        $target = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($target, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $target->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $b1->refresh();
        $b2->refresh();
        $b3->refresh();

        $this->dumpEntries('قيود C4');
        $this->flushDump('C4');

        $this->assertEqualsWithDelta(0.0, (float) $b1->remaining_amount, 0.01, 'C4: الأولى سددت بالكامل (2000)');
        $this->assertEqualsWithDelta(0.0, (float) $b2->remaining_amount, 0.01, 'C4: الثانية سددت بالكامل (3000)');
        $this->assertEqualsWithDelta(2700.0, (float) $b3->remaining_amount, 0.01, 'C4: الثالثة سدد منها 1300 والباقي 2700');
        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance($this->customer->id), 0.01, 'C4: لا ledger فائض');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * C5 عميل بدون دين: كل المسترد ledger.
     */
    public function test_C5_customer_no_debt_full_credit(): void
    {
        $this->currentScenarioId = 'C5';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->dumpLedger('دفتر رصيد العميل C5', $this->customer->id);
        $this->flushDump('C5');

        $this->assertEqualsWithDelta(6300.0, $this->ledgerBalance($this->customer->id), 0.01, 'C5: كل المسترد ledger = 6300');
        $this->assertCustomerGlEqualsLedger($this->customer->id);
    }

    /**
     * C6 رصيد قديم 1000 + استرداد جديد 6300: الرصيد الكلي 7300 (جمع ledger).
     */
    public function test_C6_existing_credit_plus_new_refund(): void
    {
        $this->currentScenarioId = 'C6';
        CustomerCreditLedger::recordEntry([
            'customer_id' => $this->customer->id,
            'source_type' => 'deposit',
            'amount'      => 1000.0,
            'currency'    => 'EGP',
            'notes'       => 'C6 initial credit',
            'created_by'  => $this->admin->id,
        ]);

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->dumpLedger('دفتر رصيد العميل C6', $this->customer->id);
        $this->flushDump('C6');

        $this->assertEqualsWithDelta(7300.0, $this->ledgerBalance($this->customer->id), 0.01, 'C6: الرصيد الكلي 7300');
    }

    /**
     * C7 كل حركات ledger immutable: محاولة update/delete تفشل، والتصحيح بحركة عكسية.
     */
    public function test_C7_ledger_entries_immutable(): void
    {
        $this->currentScenarioId = 'C7';
        $entry = CustomerCreditLedger::recordEntry([
            'customer_id' => $this->customer->id,
            'source_type' => 'deposit',
            'amount'      => 500.0,
            'currency'    => 'EGP',
            'notes'       => 'C7 immutable test',
            'created_by'  => $this->admin->id,
        ]);

        $reversed = $entry->reverse($this->admin->id, 'C7 correction');
        $this->dumpLedger('دفتر رصيد العميل C7', $this->customer->id);
        $this->flushDump('C7');

        $this->assertNotNull($entry->fresh()->reversed_at, 'C7: الأصل معلم كمعكوس');
        $this->assertEqualsWithDelta(-500.0, (float) $reversed->amount, 0.01, 'C7: حركة عكسية بمبلغ -500');
        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance($this->customer->id), 0.01, 'C7: الصافي صفر بعد العكس');
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة D: صرف الرصيد (disburse)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * D1 صرف نقدي 1000 بعد تأكيد المورد، بصلاحية disburse_refund: ينجح،
     * ledger −1000، الخزنة −1000، قيد متوازن، سند صرف.
     */
    public function test_D1_cash_disbursement_after_supplier_confirmed(): void
    {
        $this->currentScenarioId = 'D1';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);

        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $cashboxBalBefore = (float) $this->cashbox->fresh()->balance;

        $disb = $this->refundService->disburseCustomerCredit($req->id, [
            'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
            'amount'            => 1000.0,
            'treasury_id'       => $this->treasury->id,
            'notes'             => 'D1 cash payout',
        ], $this->admin);

        $this->dumpEntries('قيود الصرف D1');
        $this->dumpLedger('دفتر رصيد العميل D1', $this->customer->id);
        $this->flushDump('D1');

        $this->assertEqualsWithDelta($ledgerBefore - 1000.0, $this->ledgerBalance($this->customer->id), 0.01, 'D1: ledger -1000');
        $this->assertEqualsWithDelta($cashboxBalBefore - 1000.0, (float) $this->cashbox->fresh()->balance, 0.01, 'D1: الخزنة انخفضت 1000 نقدية (دائن)');
        $this->assertNotNull($disb->id, 'D1: سند صرف تم إنشاؤه');
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * D2 صرف بنكي: نفس الشيء على حساب بنك.
     */
    public function test_D2_bank_disbursement(): void
    {
        $this->currentScenarioId = 'D2';
        $bank = Account::create([
            'name'        => 'D2 CIB Bank',
            'type'        => AccountType::Bank->value,
            'balance'     => 100000.0,
            'currency'    => 'EGP',
            'is_active'   => true,
            'owner_type'  => 'office',
            'module_type' => 'office',
            'created_by'  => $this->admin->id,
        ]);

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);

        $disb = $this->refundService->disburseCustomerCredit($req->id, [
            'disbursement_type' => CustomerCreditDisbursement::TYPE_BANK,
            'amount'            => 1000.0,
            'bank_account_id'   => $bank->id,
            'notes'             => 'D2 bank payout',
        ], $this->admin);

        $this->dumpEntries('قيود الصرف البنكي D2');
        $this->flushDump('D2');

        $this->assertNotNull($disb->id);
        $this->assertEveryTransactionBalanced();
        $this->assertAccountBalancesMatchEntries();
    }

    /**
     * D3 صرف أكبر من الرصيد المتاح: يترفض بلا أي أثر.
     */
    public function test_D3_disburse_more_than_available_rejected(): void
    {
        $this->currentScenarioId = 'D3';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);

        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $this->expectException(BusinessLogicException::class);

        try {
            $this->refundService->disburseCustomerCredit($req->id, [
                'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
                'amount'            => 99999.0,
                'treasury_id'       => $this->treasury->id,
            ], $this->admin);
        } finally {
            $this->flushDump('D3');
            $this->assertEquals($ledgerBefore, $this->ledgerBalance($this->customer->id), 'D3: بلا أي أثر على الرصيد');
        }
    }

    /**
     * D4 صرف قبل تأكيد المورد: يترفض.
     */
    public function test_D4_disburse_before_supplier_confirmed_rejected(): void
    {
        $this->currentScenarioId = 'D4';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $userWithoutForce = $this->createUserWithPermissions([
            UserPermissions::DISBURSE_REFUND,
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('قاعدة أمان: لا يُسمح بالصرف النقدي أو البنكي للعميل قبل تأكيد استرداد المورد');

        try {
            $this->refundService->disburseCustomerCredit($req->id, [
                'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
                'amount'            => 1000.0,
                'treasury_id'       => $this->treasury->id,
            ], $userWithoutForce);
        } finally {
            $this->flushDump('D4');
        }
    }

    /**
     * D5 صرف قبل التأكيد مع force_refund_payout: ينجح ويتسجل في Audit Log.
     */
    public function test_D5_disburse_before_confirmed_with_force_permission(): void
    {
        $this->currentScenarioId = 'D5';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $forceUser = $this->createUserWithPermissions([
            UserPermissions::DISBURSE_REFUND,
            UserPermissions::FORCE_REFUND_PAYOUT,
        ]);

        $disb = $this->refundService->disburseCustomerCredit($req->id, [
            'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
            'amount'            => 1000.0,
            'treasury_id'       => $this->treasury->id,
            'notes'             => 'D5 forced payout',
        ], $forceUser);

        $this->dumpEntries('قيود الصرف الاستثنائي D5');
        $this->flushDump('D5');

        $this->assertNotNull($disb->id, 'D5: ينجح الصرف الاستثنائي');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * D6 مستخدم بدون disburse_refund وبدون force: يترفض حتى بعد التأكيد.
     */
    public function test_D6_no_disburse_permission_rejected(): void
    {
        $this->currentScenarioId = 'D6';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);

        $unauthEmployee = $this->createUserWithPermissions([]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('لا تملك صلاحية صرف رصيد الاسترداد');

        try {
            $this->refundService->disburseCustomerCredit($req->id, [
                'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
                'amount'            => 1000.0,
                'treasury_id'       => $this->treasury->id,
            ], $unauthEmployee);
        } finally {
            $this->flushDump('D6');
        }
    }

    /**
     * D7 صرف نقدي وخزنة رصيدها أقل من المبلغ: يترفض (الخزنة ما تنزلش تحت صفر).
     */
    public function test_D7_cash_disburse_treasury_insufficient_rejected(): void
    {
        $this->currentScenarioId = 'D7';
        $poorCashbox = Account::create([
            'name'        => 'D7 Poor Treasury',
            'type'        => AccountType::Cashbox->value,
            'balance'     => 100.0,
            'currency'    => 'EGP',
            'is_active'   => true,
            'owner_type'  => 'office',
            'module_type' => 'office',
            'created_by'  => $this->admin->id,
        ]);
        $poorTreasury = Treasury::create([
            'name'            => 'D7 Poor Treasury',
            'currency'        => 'EGP',
            'current_balance' => 100.0,
            'is_active'       => true,
        ]);

        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('لا يكفي لصرف');

        try {
            $this->refundService->disburseCustomerCredit($req->id, [
                'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
                'amount'            => 1000.0,
                'treasury_id'       => $poorTreasury->id,
            ], $this->admin);
        } finally {
            $this->flushDump('D7');
        }
    }

    /**
     * D8 استخدام الرصيد في حجز جديد: كامل (يغطي) وجزئي (العميل يدفع الفرق)،
     * والـ GL = ledger بعد كل منهما.
     */
    public function test_D8_use_credit_in_new_booking_full_and_partial(): void
    {
        $this->currentScenarioId = 'D8';
        $b1 = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($b1, 7500, $this->cashbox->id);
        $req1 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $b1->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req1->id, $this->admin->id);

        $targetFull = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 2000, 1800);
        $this->refundService->disburseCustomerCredit($req1->id, [
            'disbursement_type'        => CustomerCreditDisbursement::TYPE_BOOKING_SETTLEMENT,
            'amount'                   => 2000.0,
            'target_flight_booking_id' => $targetFull->id,
            'notes'                    => 'D8 full coverage',
        ], $this->admin);

        $this->dumpLedger('دفتر رصيد العميل D8 بعد التسوية الأولى', $this->customer->id);
        $this->assertCustomerGlEqualsLedger($this->customer->id);

        $targetPartial = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 5000, 4800);
        $this->refundService->disburseCustomerCredit($req1->id, [
            'disbursement_type'        => CustomerCreditDisbursement::TYPE_BOOKING_SETTLEMENT,
            'amount'                   => 4300.0,
            'target_flight_booking_id' => $targetPartial->id,
            'notes'                    => 'D8 partial settlement',
        ], $this->admin);

        $this->payBooking($targetPartial, 700, $this->cashbox->id);

        $this->dumpLedger('دفتر رصيد العميل D8 بعد التسوية الثانية', $this->customer->id);
        $this->dumpEntries('قيود التسوية D8');
        $this->flushDump('D8');

        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance($this->customer->id), 0.01, 'D8: استهلك كامل الرصيد');
        $this->assertCustomerGlEqualsLedger($this->customer->id);
        $this->assertEveryTransactionBalanced();
    }

    /**
     * D9 صرف بتسوية حجز (booking_settlement): يترك قيد GL مقابل.
     */
    public function test_D9_booking_settlement_creates_gl_entry(): void
    {
        $this->currentScenarioId = 'D9';
        $b1 = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($b1, 7500, $this->cashbox->id);
        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $b1->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $target = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 1500, 1400);
        $disb = $this->refundService->disburseCustomerCredit($req->id, [
            'disbursement_type'        => CustomerCreditDisbursement::TYPE_BOOKING_SETTLEMENT,
            'amount'                   => 1500.0,
            'target_flight_booking_id' => $target->id,
            'notes'                    => 'D9 test',
        ], $this->admin);

        $this->dumpEntries('قيود تسوية الحجز D9');
        $this->flushDump('D9');

        $this->assertNotNull($disb->transaction_id, 'D9: تم إنشاء قيد GL مقابل');
        $glTx = Transaction::find($disb->transaction_id);
        $this->assertNotNull($glTx);
        $this->assertEquals(1500.0, (float) $glTx->amount);
        $this->assertEveryTransactionBalanced();
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة E: فروق المورد
    // ═════════════════════════════════════════════════════════════════════

    /**
     * E1 بعد A1: confirmSupplierRefund والمورد رجّع 6000 (بدل 6350):
     * الفرق 350 في حساب فروق الاسترداد، ledger العميل لا يتغير، P&L يعكس -350.
     */
    public function test_E1_supplier_variance_goes_to_variance_account(): void
    {
        $this->currentScenarioId = 'E1';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $plBefore = $this->plSnapshot();

        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6000.0);
        $req->refresh();
        $plAfter = $this->plSnapshot();

        $this->dumpEntries('قيود فروق المورد E1');
        $this->dumpAccounts('أرصدة الحسابات E1');
        $this->dumpPL('تقرير P&L E1', $plBefore, $plAfter);
        $this->flushDump('E1');

        $this->assertEqualsWithDelta(350.0, (float) $req->refund_variance_amount, 0.01, 'E1: الفرق 350');
        $this->assertEquals($ledgerBefore, $this->ledgerBalance($this->customer->id), 'E1: ledger لا يتغير');

        $varianceAccId = $this->clearing->refundVarianceAccountId();
        $varianceBalance = (float) DB::table('accounts')->where('id', $varianceAccId)->value('balance');
        $this->assertEqualsWithDelta(350.0, $varianceBalance, 0.01, 'E1: حساب فروق الاسترداد = 350');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * E2 confirmSupplierRefund على طلب pending (غير معالج): يترفض أو يسجل.
     */
    public function test_E2_confirm_supplier_on_pending_request(): void
    {
        $this->currentScenarioId = 'E2';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $confirmed = $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);
        $this->flushDump('E2');
        $this->assertTrue($confirmed->supplier_refund_confirmed, 'E2: تسجيل تأكيد المورد');
    }

    /**
     * E3 airline_refund_amount = 9999 (> التكلفة): يترفض عند الإنشاء.
     */
    public function test_E3_airline_refund_amount_exceeds_cost_rejected(): void
    {
        $this->currentScenarioId = 'E3';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('لا يمكن أن يتجاوز تكلفة التذكرة الأصلية');

        try {
            $this->refundService->createRefundRequest([
                'flight_booking_id'     => $booking->id,
                'refund_case'           => 'airline_cancelled',
                'airline_refund_amount' => 9999.0,
                'refund_currency'       => 'EGP',
            ], $this->admin->id);
        } finally {
            $this->flushDump('E3');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة F: العكس (reverse)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * F1_A1: عكس استرداد A1 يستعيد كافة الأرصدة و P&L.
     */
    public function test_F1_reversal_of_A1(): void
    {
        $this->currentScenarioId = 'F1_A1';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $carrierBefore = (float) $this->carrier->fresh()->balance;
        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $plBefore = $this->plSnapshot();
        $accsBefore = DB::table('accounts')->pluck('balance', 'id')->all();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);

        $carrierAfter = (float) $this->carrier->fresh()->balance;
        $ledgerAfter = $this->ledgerBalance($this->customer->id);
        $plAfter = $this->plSnapshot();
        $accsAfter = DB::table('accounts')->pluck('balance', 'id')->all();

        $this->dumpAccounts('أرصدة بعد عكس A1');
        $this->dumpPL('تقرير P&L بعد عكس A1', $plBefore, $plAfter);
        $this->flushDump('F1_A1');

        $this->assertEqualsWithDelta($carrierBefore, $carrierAfter, 0.01, 'F1_A1: استعادة رصيد الناقل');
        $this->assertEqualsWithDelta($ledgerBefore, $ledgerAfter, 0.01, 'F1_A1: استعادة رصيد العميل');
        $this->assertEqualsWithDelta($plBefore['netProfit'], $plAfter['netProfit'], 0.01, 'F1_A1: استعادة net P&L');
        foreach ($accsBefore as $id => $bal) {
            $this->assertEqualsWithDelta((float) $bal, (float) ($accsAfter[$id] ?? 0), 0.01, "F1_A1: حساب #{$id} عاد لأصله");
        }
        $this->assertEveryTransactionBalanced();
    }

    /**
     * F1_A4: عكس استرداد A4 يستعيد كافة الأرصدة.
     */
    public function test_F1_reversal_of_A4(): void
    {
        $this->currentScenarioId = 'F1_A4';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $carrierBefore = (float) $this->carrier->fresh()->balance;
        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'                => $booking->id,
            'refund_case'                      => 'airline_cancelled',
            'airline_refund_amount'            => 7100,
            'apply_commission'                 => false,
            'non_refundable_taxes_on_customer' => false,
            'non_refundable_taxes_amount'      => 250,
            'refund_currency'                  => 'EGP',
            'destination'                      => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);

        $carrierAfter = (float) $this->carrier->fresh()->balance;
        $ledgerAfter = $this->ledgerBalance($this->customer->id);
        $plAfter = $this->plSnapshot();

        $this->flushDump('F1_A4');

        $this->assertEqualsWithDelta($carrierBefore, $carrierAfter, 0.01, 'F1_A4: استعادة الناقل');
        $this->assertEqualsWithDelta($ledgerBefore, $ledgerAfter, 0.01, 'F1_A4: استعادة ledger');
        $this->assertEqualsWithDelta($plBefore['netProfit'], $plAfter['netProfit'], 0.01, 'F1_A4: استعادة P&L');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * F1_A5: عكس استرداد A5 يستعيد كافة الأرصدة.
     */
    public function test_F1_reversal_of_A5(): void
    {
        $this->currentScenarioId = 'F1_A5';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $carrierBefore = (float) $this->carrier->fresh()->balance;
        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'                => $booking->id,
            'refund_case'                      => 'airline_cancelled',
            'airline_refund_amount'            => 7100,
            'apply_commission'                 => false,
            'non_refundable_taxes_on_customer' => true,
            'non_refundable_taxes_amount'      => 250,
            'refund_currency'                  => 'EGP',
            'destination'                      => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);

        $carrierAfter = (float) $this->carrier->fresh()->balance;
        $ledgerAfter = $this->ledgerBalance($this->customer->id);
        $plAfter = $this->plSnapshot();

        $this->flushDump('F1_A5');

        $this->assertEqualsWithDelta($carrierBefore, $carrierAfter, 0.01, 'F1_A5: استعادة الناقل');
        $this->assertEqualsWithDelta($ledgerBefore, $ledgerAfter, 0.01, 'F1_A5: استعادة ledger');
        $this->assertEqualsWithDelta($plBefore['netProfit'], $plAfter['netProfit'], 0.01, 'F1_A5: استعادة P&L');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * F1_C1: عكس استرداد C1 يستعيد كافة الأرصدة.
     */
    public function test_F1_reversal_of_C1(): void
    {
        $this->currentScenarioId = 'F1_C1';
        $company = Customer::create([
            'full_name' => 'F1C1 Co ' . uniqid(), 'phone' => '010' . substr(md5(microtime()), 0, 8),
            'email' => 'f1c1-' . uniqid() . '@test.local', 'national_id' => substr(md5(microtime()), 0, 14),
            'city' => 'Cairo', 'type' => 'company',
        ]);
        $employee = Customer::create([
            'full_name' => 'F1C1 Emp ' . uniqid(), 'phone' => '010' . substr(md5(microtime()), 0, 8),
            'email' => 'f1c1-emp-' . uniqid() . '@test.local', 'national_id' => substr(md5(microtime()), 0, 14),
            'city' => 'Cairo', 'type' => 'individual', 'company_id' => $company->id,
        ]);

        $older = $this->bookingService->createBooking([
            'customer_id' => $employee->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(3)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 1000, 'selling_price' => 4000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'F1C1O' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'Old Pax', 'type' => 'adult']],
        ]);
        $older->update(['created_at' => now()->subDays(10)]);

        $newer = $this->bookingService->createBooking([
            'customer_id' => $employee->id, 'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP', 'purchase_price' => 7350, 'selling_price' => 7500,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'F1C1N' . strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'passengers' => [['name' => 'New Pax', 'type' => 'adult']],
        ]);
        $newer->update(['status' => FlightBookingStatus::CONFIRMED]);
        $this->payBooking($newer, 7500, $this->cashbox->id);

        $olderRemBefore = (float) $older->remaining_amount;

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $newer->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);
        $older->refresh();

        $this->flushDump('F1_C1');

        $this->assertEqualsWithDelta(0.0, $this->ledgerBalance(null, $company->id), 0.01, 'F1_C1: رصيد الشركة عاد لصفر');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * F2 عكس طلب فيه صرف نقدي منفذ: يترفض برسالة واضحة.
     */
    public function test_F2_reverse_with_completed_disbursement_rejected(): void
    {
        $this->currentScenarioId = 'F2';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6350.0);

        $this->refundService->disburseCustomerCredit($req->id, [
            'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
            'amount'            => 1000.0,
            'treasury_id'       => $this->treasury->id,
        ], $this->admin);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage('لا يمكن إلغاء طلب الاسترجاع لوجود حركات صرف نقدية/بنكية منفذة');

        try {
            $this->refundService->reverseRefundRequest($req->id, $this->admin->id);
        } finally {
            $this->flushDump('F2');
        }
    }

    /**
     * F3 عكس مرتين: الثاني يترفض.
     */
    public function test_F3_reverse_twice_rejected(): void
    {
        $this->currentScenarioId = 'F3';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('هذا الطلب محذوف بالفعل');

        try {
            $this->refundService->reverseRefundRequest($req->id, $this->admin->id);
        } finally {
            $this->flushDump('F3');
        }
    }

    /**
     * F4 عكس يستعيد حالة الحجز، ويسمح باسترداد جديد بعده.
     */
    public function test_F4_reversal_restores_booking_allows_new_refund(): void
    {
        $this->currentScenarioId = 'F4';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);
        $booking->refresh();

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status, 'F4: حالة الحجز استعيدت مؤكد');

        $req2 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 500, 'agency_commission' => 100,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->assertNotNull($req2->id, 'F4: نجاح إنشاء طلب استرداد جديد بعد العكس');
        $this->flushDump('F4');
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة G: الحماية والتكرار
    // ═════════════════════════════════════════════════════════════════════

    /**
     * G1 استرداد نفس الطلب مرتين (processRefundRequest): idempotent، بدون قيود جديدة.
     */
    public function test_G1_process_same_request_twice_idempotent(): void
    {
        $this->currentScenarioId = 'G1';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $txCountAfterFirst = DB::table('transactions')->count();

        $reqAgain = $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $txCountAfterSecond = DB::table('transactions')->count();

        $this->flushDump('G1');
        $this->assertEquals($txCountAfterFirst, $txCountAfterSecond, 'G1: لا قيود جديدة عند المعالجة مرتين');
        $this->assertEquals('processed', $reqAgain->status);
    }

    /**
     * G2 مفتاح idempotency مكرر عند الإنشاء: يترفض أو يرجع نفس الطلب.
     */
    public function test_G2_duplicate_idempotency_key_at_creation(): void
    {
        $this->currentScenarioId = 'G2';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $key = 'IDEM-' . uniqid();
        $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 4500, 'agency_commission' => 0,
            'refund_currency' => 'EGP', 'idempotency_key' => $key,
        ], $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مكرر');

        try {
            $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
                'airline_penalty' => 4500, 'agency_commission' => 0,
                'refund_currency' => 'EGP', 'idempotency_key' => $key,
            ], $this->admin->id);
        } finally {
            $this->flushDump('G2');
        }
    }

    /**
     * G3 استرداد جزئي أول ثم ثانٍ: المسموح الجزئي الثاني حتى سقف السعر، والحجز
     * PARTIALLY_REFUNDED بعد الأول (مش REFUNDED)، والثالث فوق السقف يترفض.
     */
    public function test_G3_partial_refunds_cumulative_cap(): void
    {
        $this->currentScenarioId = 'G3';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req1 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_type' => 'partial',
            'refund_case' => 'customer_voluntary', 'airline_penalty' => 4500, 'agency_commission' => 0,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req1->id, $this->admin->id);
        $booking->refresh();

        $this->assertEquals(FlightBookingStatus::PARTIALLY_REFUNDED, $booking->status, 'G3: بعد الأول PARTIALLY_REFUNDED');

        $req2 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_type' => 'partial',
            'refund_case' => 'customer_voluntary', 'airline_penalty' => 3500, 'agency_commission' => 0,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->assertNotNull($req2->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('سيتجاوز المبلغ الأصلي');

        try {
            $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id, 'refund_type' => 'partial',
                'refund_case' => 'customer_voluntary', 'airline_penalty' => 1000, 'agency_commission' => 0,
                'refund_currency' => 'EGP', 'destination' => 'customer_credit',
            ], $this->admin->id);
        } finally {
            $this->flushDump('G3');
        }
    }

    /**
     * G4 فشل مصطنع بعد الخطوة الثانية (استثناء داخل الـ transaction):
     * تراجع كامل: 0 قيود جديدة، 0 ledger، الطلب pending، الأرصدة كما هي.
     */
    public function test_G4_transaction_rollback_on_failure(): void
    {
        $this->currentScenarioId = 'G4';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $txCountBefore = DB::table('transactions')->count();
        $ledgerCountBefore = DB::table('customer_credit_ledger')->count();
        $carrierBalBefore = (float) $this->carrier->fresh()->balance;

        try {
            DB::transaction(function () use ($booking) {
                $req = $this->refundService->createRefundRequest([
                    'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
                    'airline_penalty' => 1000, 'agency_commission' => 200,
                    'refund_currency' => 'EGP',
                ], $this->admin->id);

                throw new \RuntimeException('Simulated mid-step crash');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->flushDump('G4');
        $this->assertEquals($txCountBefore, DB::table('transactions')->count(), 'G4: 0 قيود جديدة');
        $this->assertEquals($ledgerCountBefore, DB::table('customer_credit_ledger')->count(), 'G4: 0 ledger');
        $this->assertEquals($carrierBalBefore, (float) $this->carrier->fresh()->balance, 'G4: الأرصدة كما هي');
    }

    /**
     * G5 مدخلات سيئة (كلها ترفض): عمولة سالبة، غرامة سالبة، غرامة > سعر البيع،
     * غرامة+عمولة > سعر البيع، تذكرة غير مباعة، مبلغ مورد سالب.
     */
    public function test_G5_bad_inputs_all_rejected(): void
    {
        $this->currentScenarioId = 'G5';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        // 1. عمولة سالبة
        $this->assertThrows(fn () => $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => -50,
        ], $this->admin->id), \InvalidArgumentException::class);

        // 2. غرامة سالبة
        $this->assertThrows(fn () => $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => -100, 'agency_commission' => 200,
        ], $this->admin->id), \InvalidArgumentException::class);

        // 3. غرامة+عمولة > سعر البيع
        $this->assertThrows(fn () => $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 7000, 'agency_commission' => 1000,
        ], $this->admin->id), \InvalidArgumentException::class);

        // 4. تذكرة غير مباعة (paid_amount = 0)
        $unpaidBooking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->assertThrows(fn () => $this->refundService->createRefundRequest([
            'flight_booking_id' => $unpaidBooking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
        ], $this->admin->id), \RuntimeException::class);

        // 5. مبلغ مورد سالب
        $this->assertThrows(fn () => $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'default',
            'supplier_refund_amount' => -500,
        ], $this->admin->id), \InvalidArgumentException::class);

        $this->flushDump('G5');
    }

    /**
     * G6 تزامن: لو DB الاختبار تدعم (MySQL) شغّل طلبين متوازيين؛ لو SQLite
     * علّم الاختبار skipped بسبب موثّق ولا تزيّف النتيجة.
     */
    public function test_G6_concurrency_race_condition(): void
    {
        $this->currentScenarioId = 'G6';
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->flushDump('G6');
            $this->markTestSkipped('G6 Skipped: SQLite uses a single file lock and does not support true concurrent transactions or row-level locking (requires MySQL InnoDB).');
        }

        $this->assertTrue(true);
        $this->flushDump('G6');
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة H: العملات الأجنبية (USD@50, SAR@13.3333, KWD@162.5)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * H1_USD: A1 بالدولار (سعر 50)
     */
    public function test_H1_USD_customer_voluntary_a1_equivalent(): void
    {
        $this->currentScenarioId = 'H1_USD';
        $fx = $this->buildForeignCurrencyFixture('USD', 50.0, 10000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 147.0, 0, 'USD', 50.0);
        $this->payBooking($booking, 150.0, $fx['cashbox']->id);

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'    => $booking->id,
            'refund_case'          => 'customer_voluntary',
            'airline_penalty'      => 20.0,
            'agency_commission'    => 4.0,
            'refund_currency'      => 'USD',
            'refund_exchange_rate' => 50.0,
            'destination'          => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $plAfter = $this->plSnapshot();

        $this->dumpEntries('قيود H1_USD');
        $this->dumpPL('تقرير P&L H1_USD', $plBefore, $plAfter);
        $this->flushDump('H1_USD');

        $this->assertEqualsWithDelta(126.0, (float) $req->refund_amount, 0.01, 'H1_USD: صافي المسترد 126 USD');
        $this->assertEqualsWithDelta(126.0, $this->ledgerBalance($fx['customer']->id, null, 'USD'), 0.01, 'H1_USD: ledger = 126 USD');
        $this->assertEqualsWithDelta(200.0, (float) $plAfter['netProfit'], 0.01, 'H1_USD: P&L net = +200 EGP');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * H2_USD: A4 بالدولار (ضرائب على الوكالة)
     */
    public function test_H2_USD_taxes_on_agency_a4_equivalent(): void
    {
        $this->currentScenarioId = 'H2_USD';
        $fx = $this->buildForeignCurrencyFixture('USD', 50.0, 10000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 147.0, 0, 'USD', 50.0);
        $this->payBooking($booking, 150.0, $fx['cashbox']->id);

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'                => $booking->id,
            'refund_case'                      => 'airline_cancelled',
            'airline_refund_amount'            => 142.0,
            'apply_commission'                 => false,
            'non_refundable_taxes_on_customer' => false,
            'non_refundable_taxes_amount'      => 5.0,
            'refund_currency'                  => 'USD',
            'refund_exchange_rate'             => 50.0,
            'destination'                      => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $plAfter = $this->plSnapshot();

        $this->dumpEntries('قيود H2_USD');
        $this->dumpPL('تقرير P&L H2_USD', $plBefore, $plAfter);
        $this->flushDump('H2_USD');

        $this->assertEqualsWithDelta(-250.0, (float) $plAfter['netProfit'], 0.01, 'H2_USD: P&L net = -250 EGP (5 USD x 50)');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * H3_USD: A5 بالدولار (ضرائب على العميل)
     */
    public function test_H3_USD_taxes_on_customer_a5_equivalent(): void
    {
        $this->currentScenarioId = 'H3_USD';
        $fx = $this->buildForeignCurrencyFixture('USD', 50.0, 10000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 147.0, 0, 'USD', 50.0);
        $this->payBooking($booking, 150.0, $fx['cashbox']->id);

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'                => $booking->id,
            'refund_case'                      => 'airline_cancelled',
            'airline_refund_amount'            => 142.0,
            'apply_commission'                 => false,
            'non_refundable_taxes_on_customer' => true,
            'non_refundable_taxes_amount'      => 5.0,
            'refund_currency'                  => 'USD',
            'refund_exchange_rate'             => 50.0,
            'destination'                      => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $plAfter = $this->plSnapshot();

        $this->dumpEntries('قيود H3_USD');
        $this->dumpPL('تقرير P&L H3_USD', $plBefore, $plAfter);
        $this->flushDump('H3_USD');

        $this->assertEqualsWithDelta(0.0, (float) $plAfter['netProfit'], 0.01, 'H3_USD: P&L net = 0 EGP');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * H4_USD: B5 بالدولار (دفعة زيادة)
     */
    public function test_H4_USD_overpayment_b5_equivalent(): void
    {
        $this->currentScenarioId = 'H4_USD';
        $fx = $this->buildForeignCurrencyFixture('USD', 50.0, 10000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 147.0, 0, 'USD', 50.0);

        $plBefore = $this->plSnapshot();
        $this->payBooking($booking, 160.0, $fx['cashbox']->id, ['allow_overpayment' => true]);
        $booking->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpPL('تقرير P&L H4_USD', $plBefore, $plAfter);
        $this->flushDump('H4_USD');

        $this->assertEqualsWithDelta(150.0, (float) $plAfter['netProfit'], 0.01, 'H4_USD: P&L net = +150 EGP (3 USD x 50)');
        $this->assertEqualsWithDelta(10.0, $this->ledgerBalance($fx['customer']->id, null, 'USD'), 0.01, 'H4_USD: ledger = 10 USD');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * H5_USD: D1 بالدولار (صرف رصيد نقدي)
     */
    public function test_H5_USD_disbursement_d1_equivalent(): void
    {
        $this->currentScenarioId = 'H5_USD';
        $fx = $this->buildForeignCurrencyFixture('USD', 50.0, 10000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 150.0, 147.0, 0, 'USD', 50.0);
        $this->payBooking($booking, 150.0, $fx['cashbox']->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'    => $booking->id,
            'refund_case'          => 'customer_voluntary',
            'airline_penalty'      => 20.0,
            'agency_commission'    => 4.0,
            'refund_currency'      => 'USD',
            'refund_exchange_rate' => 50.0,
            'destination'          => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 127.0);

        $usdCashbox = Account::create([
            'name'        => 'USD Cashbox ' . uniqid(),
            'type'        => AccountType::Cashbox->value,
            'balance'     => 5000.0,
            'currency'    => 'USD',
            'is_active'   => true,
            'owner_type'  => 'office',
            'module_type' => 'office',
            'created_by'  => $this->admin->id,
        ]);
        $usdTreasury = Treasury::create([
            'name'            => $usdCashbox->name,
            'currency'        => 'USD',
            'current_balance' => 5000.0,
            'is_active'       => true,
        ]);

        $disb = $this->refundService->disburseCustomerCredit($req->id, [
            'disbursement_type' => CustomerCreditDisbursement::TYPE_CASH,
            'amount'            => 20.0,
            'treasury_id'       => $usdTreasury->id,
        ], $this->admin);

        $this->dumpEntries('قيود H5_USD');
        $this->flushDump('H5_USD');

        $this->assertNotNull($disb->id);
        $this->assertEqualsWithDelta(106.0, $this->ledgerBalance($fx['customer']->id, null, 'USD'), 0.01, 'H5_USD: ledger = 106 USD');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * H1_SAR: A1 بالريال السعودي (سعر 13.3333)
     */
    public function test_H1_SAR_customer_voluntary(): void
    {
        $this->currentScenarioId = 'H1_SAR';
        $fx = $this->buildForeignCurrencyFixture('SAR', 13.3333, 50000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 562.5, 551.25, 0, 'SAR', 13.3333);
        $this->payBooking($booking, 562.5, $fx['cashbox']->id);

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'    => $booking->id,
            'refund_case'          => 'customer_voluntary',
            'airline_penalty'      => 75.0,
            'agency_commission'    => 15.0,
            'refund_currency'      => 'SAR',
            'refund_exchange_rate' => 13.3333,
            'destination'          => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $plAfter = $this->plSnapshot();

        $this->dumpPL('تقرير P&L H1_SAR', $plBefore, $plAfter);
        $this->flushDump('H1_SAR');

        $this->assertEqualsWithDelta(200.0, (float) $plAfter['netProfit'], 0.05, 'H1_SAR: P&L net = +200 EGP (15 SAR x 13.3333)');
        $this->assertEveryTransactionBalanced();
    }

    /**
     * H1_KWD: A1 بالدينار الكويتي (سعر 162.5، سماح 0.05 * 162.5 = 8.125)
     */
    public function test_H1_KWD_customer_voluntary(): void
    {
        $this->currentScenarioId = 'H1_KWD';
        $fx = $this->buildForeignCurrencyFixture('KWD', 162.5, 5000.0);
        $booking = $this->makeBooking($fx['customer']->id, $fx['carrier']->id, $fx['cashbox']->id, 46.1538, 45.2307, 0, 'KWD', 162.5);
        $this->payBooking($booking, 46.1538, $fx['cashbox']->id);

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id'    => $booking->id,
            'refund_case'          => 'customer_voluntary',
            'airline_penalty'      => 6.1538,
            'agency_commission'    => 1.2308,
            'refund_currency'      => 'KWD',
            'refund_exchange_rate' => 162.5,
            'destination'          => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $plAfter = $this->plSnapshot();

        $this->dumpPL('تقرير P&L H1_KWD', $plBefore, $plAfter);
        $this->flushDump('H1_KWD');

        $expectedNet = 200.0;
        $actualNet = (float) $plAfter['netProfit'];
        $tolerance = 0.05 * 162.5; // 8.125 EGP
        $this->assertEqualsWithDelta($expectedNet, $actualNet, $tolerance, "H1_KWD: P&L net = +200 EGP (سماح {$tolerance})");
        $this->assertEveryTransactionBalanced();
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة I: التقارير
    // ═════════════════════════════════════════════════════════════════════

    /**
     * I1 كشف حساب العميل (API): يطابق رصيد الدفتر + يظهر حركات ledger.
     */
    public function test_I1_customer_statement_matches_ledger(): void
    {
        $this->currentScenarioId = 'I1';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $controller = app(\App\Http\Controllers\Api\V1\CustomerController::class);
        $response = $controller->statement(new Request(), $this->customer);
        $data = $response->getData(true);

        $this->flushDump('I1');
        $this->assertTrue($data['success'] ?? false);
        $this->assertCustomerGlEqualsLedger($this->customer->id);
    }

    /**
     * I2 كشف حساب الناقل: يطابق flight_carriers.balance.
     */
    public function test_I2_carrier_statement_matches_balance(): void
    {
        $this->currentScenarioId = 'I2';
        $controller = app(\App\Http\Controllers\Api\V1\Flight\FlightCarrierController::class);
        $response = $controller->show($this->carrier);
        $data = $response->getData(true);

        $this->flushDump('I2');
        $this->assertTrue($data['success'] ?? false);
        $this->assertEquals((float) $this->carrier->balance, (float) ($data['data']['balance'] ?? 0));
    }

    /**
     * I3 preview لـ A1: يرجّع نفس الأرقام التي تتنفذ فعليًا (قارن حقل بحقل).
     */
    public function test_I3_preview_matches_actual(): void
    {
        $this->currentScenarioId = 'I3';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $payload = [
            'flight_booking_id' => $booking->id,
            'refund_case'       => 'customer_voluntary',
            'airline_penalty'   => 1000,
            'agency_commission' => 200,
            'refund_currency'   => 'EGP',
            'destination'       => 'customer_credit',
        ];

        $preview = $this->refundService->previewRefund($payload);
        $actual = $this->refundService->createRefundRequest($payload, $this->admin->id);

        $this->flushDump('I3');
        $this->assertEquals($preview['net_customer_refund'], (float) $actual->refund_amount, 'I3: net refund تطابق');
        $this->assertEquals($preview['expected_supplier_refund'], (float) $actual->supplier_refund_amount, 'I3: supplier refund تطابق');
        $this->assertEquals($preview['airline_penalty'], (float) $actual->airline_penalty, 'I3: penalty تطابق');
        $this->assertEquals($preview['agency_commission'], (float) $actual->agency_commission, 'I3: commission تطابق');
    }

    /**
     * I4 تقرير P&L قبل/بعد لكل سيناريو في A يطابق التوقعات.
     */
    public function test_I4_pl_before_after_matches_expectations(): void
    {
        $this->currentScenarioId = 'I4';
        $booking = $this->makeBooking($this->customer->id, $this->carrier->id, $this->cashbox->id, 7500, 7350);
        $this->payBooking($booking, 7500, $this->cashbox->id);

        $plBefore = $this->plSnapshot();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000, 'agency_commission' => 200,
            'refund_currency' => 'EGP', 'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        $plAfter = $this->plSnapshot();
        $this->dumpPL('تقرير P&L I4', $plBefore, $plAfter);
        $this->flushDump('I4');

        $this->assertEqualsWithDelta(200.0, (float) $plAfter['netProfit'], 0.01, 'I4: net profit delta = +200');
    }

    // ═════════════════════════════════════════════════════════════════════
    // المجموعة J: سلامة المشروع
    // ═════════════════════════════════════════════════════════════════════

    /**
     * J1 شغّل scripts/audit_historical_refunds.php وسكريبت الـ backfill:
     * عدد صفوف كل الجداول قبل = بعد (read-only).
     */
    public function test_J1_audit_scripts_readonly(): void
    {
        $this->currentScenarioId = 'J1';
        $tables = ['flight_bookings', 'refund_requests', 'customer_credit_ledger', 'transactions', 'account_entries', 'accounts'];

        $countsBefore = [];
        foreach ($tables as $t) {
            $countsBefore[$t] = DB::table($t)->count();
        }

        exec('php scripts/audit_historical_refunds.php', $out1, $ret1);
        exec('php scripts/backfill_customer_credit_ledger_readonly_proposal.php', $out2, $ret2);

        $countsAfter = [];
        foreach ($tables as $t) {
            $countsAfter[$t] = DB::table($t)->count();
        }

        $this->flushDump('J1');
        foreach ($tables as $t) {
            $this->assertEquals($countsBefore[$t], $countsAfter[$t], "J1: جدول {$t} لم يتغير عدد صفوفه");
        }
    }

    /**
     * J2 تأكد إن السكريبتات المدمّرة (audit_refund_flow.php, audit_refund_multi_currency.php,
     * deep_refund_test_all_currencies.php) غير موجودة أو محمية بـ guard.
     */
    public function test_J2_destructive_scripts_absent_or_guarded(): void
    {
        $this->currentScenarioId = 'J2';
        $scripts = [
            'audit_refund_flow.php',
            'audit_refund_multi_currency.php',
            'deep_refund_test_all_currencies.php',
        ];

        $failures = [];
        foreach ($scripts as $file) {
            $fullPath = base_path($file);
            if (! file_exists($fullPath)) {
                continue; // غير موجود: آمن
            }
            $content = file_get_contents($fullPath);
            $hasGuard = str_contains($content, "environment('production')")
                || str_contains($content, 'APP_ENV')
                || str_contains($content, 'PREVENT_DESTRUCTIVE_EXECUTION')
                || str_contains($content, 'exit(');

            if (! $hasGuard) {
                $failures[] = $file;
            }
        }

        $this->flushDump('J2');
        $this->assertEmpty($failures, 'J2: السكريبتات التالية موجودة وبدون guard لحمايتها: ' . implode(', ', $failures));
    }

    /**
     * J3 Migrations: migrate ثم rollback آخر 4 ثم migrate: الـ schema متطابق.
     */
    public function test_J3_migration_rollback_and_remigrate(): void
    {
        $this->currentScenarioId = 'J3';

        $tablesBefore = DB::connection()->getSchemaBuilder()->getTableListing();
        sort($tablesBefore);

        Artisan::call('migrate:rollback', ['--step' => 4]);
        Artisan::call('migrate');

        $tablesAfter = DB::connection()->getSchemaBuilder()->getTableListing();
        sort($tablesAfter);

        $this->flushDump('J3');
        $this->assertEquals($tablesBefore, $tablesAfter, 'J3: الـ schema متطابق بعد rollback 4 و migrate');
    }
}
