<?php

namespace Tests\Audit;

use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * QA — scenarios S1..S10 of the flight refund + unified customer credit cycle.
 *
 * CONVENTION (system): account.balance = SUM(credit) - SUM(debit)
 *   from_account_id leg => DEBIT (balance decreases) ; to_account_id leg => CREDIT (balance increases)
 *   Standard accounting reads the SAME rows as: debit-positive / credit-negative,
 *   i.e. standard_balance = -system_balance. Both are printed in every dump.
 *
 * Destination note: `destination = customer_credit` is the service default
 * (RefundService::createRefundRequest L529) and is the ONLY destination that
 * keeps the money in the unified CustomerCreditLedger, so assertions 2 & 3 are
 * meaningful there. `agency_treasury` auto-disburses inside
 * processRefundRequest (L984) and is exercised in S2.
 */
class RefundScenariosTest extends AuditTestCase
{
    /** Carrier balance before any booking is created (right after the setup recharge). */
    protected float $carrierAtSetup = 0.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carrierAtSetup = $this->carrierBalance();
    }

    // ─────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────

    /** Snapshot: intermediate accounts before the sale. */
    protected function snapBeforeSale(): array
    {
        return $this->intermediateBalances();
    }

    protected function dumpAccountTable(string $title): void
    {
        $this->dumpLine('');
        $this->dumpLine('── '.$title.' ──');
        $this->dumpLine(sprintf('   %-6s %-42s %14s %14s', 'ACC', 'NAME', 'SYS_BAL', 'STD_BAL'));
        foreach (DB::table('accounts')->orderBy('id')->get() as $a) {
            if (abs((float) $a->balance) < 0.005 && ! $this->isInterestingAccount((int) $a->id)) {
                continue;
            }
            $this->dumpLine(sprintf(
                '   %-6d %-42s %14s %14s',
                $a->id,
                mb_substr((string) $a->name, 0, 42),
                number_format((float) $a->balance, 2, '.', ''),
                number_format(-1 * (float) $a->balance, 2, '.', '')
            ));
        }
    }

    protected function dumpAuditTrail(): void
    {
        $this->dumpLine('');
        $this->dumpLine('── Audit Trail ──');
        foreach ($this->refundAuditRows() as $r) {
            $this->dumpLine(sprintf(
                '   refund_audit_logs#%-4d user=%-6s action=%-22s amount=%-12s at=%s',
                $r['id'],
                (string) ($r['user_id'] ?? '-'),
                (string) ($r['action'] ?? '-'),
                number_format((float) ($r['refund_amount'] ?? 0), 2, '.', ''),
                (string) ($r['created_at'] ?? '-')
            ));
        }
        foreach ($this->auditLogRows() as $r) {
            if (! str_starts_with((string) $r['action'], 'refund.')) {
                continue;
            }
            $this->dumpLine(sprintf(
                '   audit_logs#%-4d user=%-6s action=%-22s model=%s#%s at=%s',
                $r['id'],
                (string) ($r['user_id'] ?? '-'),
                (string) $r['action'],
                (string) ($r['model_type'] ?? '-'),
                (string) ($r['model_id'] ?? '-'),
                (string) ($r['created_at'] ?? '-')
            ));
        }
    }

    protected function assertNoNonMemoOrphans(string $tag): void
    {
        $c = $this->classifyOrphanTransactions();
        foreach ($c['memo'] as $o) {
            $this->dumpLine(sprintf(
                '   [MEMO-ONLY TX] #%d type=%s amount=%s from=%s to=%s notes=%s',
                $o['id'], $o['type'], $o['amount'], $o['from_account_id'], $o['to_account_id'], mb_substr((string) $o['notes'], 0, 70)
            ));
        }
        foreach ($c['other'] as $o) {
            $this->dumpLine(sprintf(
                '   [ORPHAN TX] #%d type=%s amount=%s from=%s to=%s notes=%s',
                $o['id'], $o['type'], $o['amount'], $o['from_account_id'], $o['to_account_id'], mb_substr((string) $o['notes'], 0, 70)
            ));
        }
        $this->assertSame([], $c['other'], $tag.': يوجد Transaction بدون account_entries (خارج قيود الـ memo)');
    }

    // ─────────────────────────────────────────────────────────────────
    // S1
    // ─────────────────────────────────────────────────────────────────

    /**
     * S1 — customer cancels. Penalty 1000, agency commission 200.
     * Brief expectation: supplier gets 6350, customer gets 6300, P&L = +200.
     */
    public function test_s1_customer_voluntary_cancellation(): void
    {
        $this->dumpName = 'S1';
        $beforeSale = $this->snapBeforeSale();

        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();
        $afterSale = $this->intermediateBalances();
        $plBefore = $this->plSnapshot();

        $this->dumpLine('═══ S1: العميل يلغي — غرامة 1000، عمولة 200 ═══');
        $this->dumpLine('   intermediates BEFORE sale: '.json_encode($beforeSale));
        $this->dumpLine('   intermediates AFTER  sale: '.json_encode($afterSale));

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(6300.0, (float) $req->refund_amount, 'S1: صافي المسترد للعميل = 6300');
        $this->assertEquals(6350.0, (float) $req->supplier_refund_amount, 'S1: المسترد من المورد = 6350');
        $this->assertEquals(1200.0, (float) $req->cancellation_fee, 'S1: رسوم الإلغاء = 1200');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد S1');
        $this->dumpAccountTable('أرصدة الحسابات بعد S1');
        $this->dumpLedger('دفتر رصيد العميل S1', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر S1', $plBefore, $plAfter);
        $this->dumpAuditTrail();
        $this->dumpLine('   intermediates AFTER refund: '.json_encode($this->intermediateBalances()));

        // (1) double entry
        $this->assertBalanced('S1');

        // (2) GL customer credit == ledger available credit
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S1: رصيد حساب العميل في الدفتر ('.$this->glCustomerCredit().') لا يطابق customer_credit_ledger ('.$this->ledgerBalance($this->customer->id).')'
        );

        // (3) intermediate accounts back to pre-scenario
        foreach ($beforeSale as $id => $info) {
            $now = $this->accountBalance((int) $id);
            $this->dumpLine("   intermediate acc#{$id} ({$info['name']}) before=".number_format((float) $info['balance'], 2).' after='.number_format($now, 2));
            $this->assertEqualsWithDelta(
                (float) $info['balance'],
                $now,
                0.01,
                "S1: الحساب الوسيط #{$id} ({$info['name']}) لم يرجع لرصيده قبل السيناريو"
            );
        }

        // (4) carrier: setup balance minus ONLY the lost penalty
        $this->assertEqualsWithDelta(
            $this->carrierAtSetup - 1000.0,
            $this->carrierBalance(),
            0.01,
            'S1: رصيد الناقل يجب أن يساوي رصيده الأصلي ناقص الغرامة المفقودة (1000) فقط'
        );

        // (5) P&L — brief expects +200
        $this->assertEqualsWithDelta(
            200.0,
            (float) $plAfter['netProfit'],
            0.01,
            'S1: صافي ربح P&L بعد الاسترداد يجب أن يكون +200 (حسب البريف) — الفعلي: '.$plAfter['netProfit']
        );

        // (6) audit trail
        $this->assertGreaterThan(0, $this->refundAuditCount(), 'S1: لم يُكتب سجل تدقيق في refund_audit_logs');
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('action', 'like', 'refund.%')->count(), 'S1: لم يُكتب سجل تدقيق في audit_logs');

        $this->assertNoNonMemoOrphans('S1');
    }

    // ─────────────────────────────────────────────────────────────────
    // S2
    // ─────────────────────────────────────────────────────────────────

    /**
     * S2 — airline cancels, full cost refund, WITH commission 200.
     * destination = agency_treasury → the credit is auto-disbursed to the treasury.
     */
    public function test_s2_airline_cancelled_full_refund_with_commission(): void
    {
        $this->dumpName = 'S2';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();
        $plBefore = $this->plSnapshot();

        $this->dumpLine('═══ S2: الطيران ألغى — رجّع كامل، بعمولة 200 — الوجهة agency_treasury ═══');

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7350,
            'apply_commission' => true,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'agency_treasury',
            'treasury_id' => $this->treasury->id,
        ], $this->admin->id);

        $this->assertEquals(7300.0, (float) $req->refund_amount, 'S2: صافي المسترد للعميل = 7500-200 = 7300');
        $this->assertEquals(7350.0, (float) $req->supplier_refund_amount, 'S2: المسترد من المورد = 7350 (كامل)');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد S2');
        $this->dumpAccountTable('أرصدة الحسابات بعد S2');
        $this->dumpLedger('دفتر رصيد العميل S2', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر S2', $plBefore, $plAfter);
        $this->dumpLine('   treasury.current_balance = '.$this->treasuryBalance());
        $this->dumpLine('   disbursements = '.DB::table('customer_credit_disbursements')->count());

        $this->assertBalanced('S2');
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S2: رصيد حساب العميل في الدفتر لا يطابق customer_credit_ledger'
        );
        $this->assertEqualsWithDelta(
            $this->carrierAtSetup,
            $this->carrierBalance(),
            0.01,
            'S2: رصيد الناقل يجب أن يرجع كامل (التكلفة كلها استُردت)'
        );
        $this->assertEqualsWithDelta(
            200.0,
            (float) $plAfter['netProfit'],
            0.01,
            'S2: صافي ربح P&L بعد الاسترداد يجب أن يساوي عمولة الإلغاء (200) — الفعلي: '.$plAfter['netProfit']
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S3
    // ─────────────────────────────────────────────────────────────────

    /**
     * S3 — airline cancels, full refund, NO commission.
     */
    public function test_s3_airline_cancelled_full_refund_no_commission(): void
    {
        $this->dumpName = 'S3';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();
        $plBefore = $this->plSnapshot();

        $this->dumpLine('═══ S3: الطيران ألغى — رجّع كامل، بدون عمولة ═══');

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7350,
            'apply_commission' => false,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(7500.0, (float) $req->refund_amount, 'S3: صافي المسترد للعميل = 7500 (كامل)');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد S3');
        $this->dumpAccountTable('أرصدة الحسابات بعد S3');
        $this->dumpLedger('دفتر رصيد العميل S3', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر S3', $plBefore, $plAfter);

        $this->assertBalanced('S3');
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S3: رصيد حساب العميل في الدفتر لا يطابق customer_credit_ledger'
        );
        $this->assertEqualsWithDelta(
            $this->carrierAtSetup,
            $this->carrierBalance(),
            0.01,
            'S3: رصيد الناقل يجب أن يرجع كامل'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) $plAfter['netProfit'],
            0.01,
            'S3: صافي ربح P&L بعد استرداد كامل يجب أن يساوي صفر — الفعلي: '.$plAfter['netProfit']
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S4
    // ─────────────────────────────────────────────────────────────────

    /**
     * S4 — airline refunds 7100, taxes 250 borne by the AGENCY. Customer gets 7500.
     * Brief expectation: P&L = −250.
     */
    public function test_s4_airline_cancelled_short_refund_taxes_on_agency(): void
    {
        $this->dumpName = 'S4';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();
        $plBefore = $this->plSnapshot();

        $this->dumpLine('═══ S4: الطيران رجّع 7100 — الضرائب 250 علينا — العميل 7500 ═══');

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100,
            'apply_commission' => false,
            'non_refundable_taxes_on_customer' => false,
            'non_refundable_taxes_amount' => 250,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(7500.0, (float) $req->refund_amount, 'S4: صافي المسترد للعميل = 7500');
        $this->assertEquals(250.0, (float) $req->supplier_refund_loss, 'S4: خسارة الاسترداد = 250');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد S4');
        $this->dumpAccountTable('أرصدة الحسابات بعد S4');
        $this->dumpLedger('دفتر رصيد العميل S4', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر S4', $plBefore, $plAfter);
        $this->dumpAuditTrail();

        $this->assertBalanced('S4');
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S4: رصيد حساب العميل في الدفتر لا يطابق customer_credit_ledger'
        );
        $this->assertEqualsWithDelta(
            $this->carrierAtSetup - 250.0,
            $this->carrierBalance(),
            0.01,
            'S4: رصيد الناقل = رصيده الأصلي ناقص الضريبة المفقودة (250) فقط'
        );
        $this->assertEqualsWithDelta(
            -250.0,
            (float) $plAfter['netProfit'],
            0.01,
            'S4: صافي ربح P&L بعد الاسترداد يجب أن يكون −250 (الوكالة تتحمّل الضريبة) — الفعلي: '.$plAfter['netProfit']
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S5
    // ─────────────────────────────────────────────────────────────────

    /**
     * S5 — airline refunds 7100, taxes 250 borne by the CUSTOMER. Customer gets 7250.
     * Brief expectation: P&L = 0.
     */
    public function test_s5_airline_cancelled_short_refund_taxes_on_customer(): void
    {
        $this->dumpName = 'S5';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();
        $plBefore = $this->plSnapshot();

        $this->dumpLine('═══ S5: الطيران رجّع 7100 — الضرائب 250 على العميل — العميل 7250 ═══');

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100,
            'apply_commission' => false,
            'non_refundable_taxes_on_customer' => true,
            'non_refundable_taxes_amount' => 250,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->assertEquals(7250.0, (float) $req->refund_amount, 'S5: صافي المسترد للعميل = 7250');

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الاسترداد S5');
        $this->dumpAccountTable('أرصدة الحسابات بعد S5');
        $this->dumpLedger('دفتر رصيد العميل S5', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر S5', $plBefore, $plAfter);

        $this->assertBalanced('S5');
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S5: رصيد حساب العميل في الدفتر لا يطابق customer_credit_ledger'
        );
        $this->assertEqualsWithDelta(
            $this->carrierAtSetup - 250.0,
            $this->carrierBalance(),
            0.01,
            'S5: رصيد الناقل = رصيده الأصلي ناقص الضريبة (250) فقط'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) $plAfter['netProfit'],
            0.01,
            'S5: صافي ربح P&L بعد الاسترداد يجب أن يساوي صفر (الضريبة على العميل تعادل الخسارة) — الفعلي: '.$plAfter['netProfit']
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S6
    // ─────────────────────────────────────────────────────────────────

    /**
     * S6 — overpayment: 8000 on a 7500 booking => 500 credit.
     * Brief expectation: P&L = 0.
     */
    public function test_s6_overpayment_creates_credit(): void
    {
        $this->dumpName = 'S6';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $plBefore = $this->plSnapshot();

        $this->dumpLine('═══ S6: دفعة زيادة 8000 على حجز 7500 → رصيد 500 ═══');

        $threw = null;
        try {
            // QA FIX (2026-11-03): an overpayment is rejected by default; it is
            // only accepted when the caller explicitly allows it (the Vue client
            // sends allow_overpayment=true after the user confirms).
            $this->payBooking($booking, 8000, ['allow_overpayment' => true]);
        } catch (\Throwable $e) {
            $threw = $e->getMessage();
        }
        $this->dumpLine('   addPayment(8000) threw: '.var_export($threw, true));
        $booking->refresh();

        $payment = $booking->payments()->first();
        $plAfter = $this->plSnapshot();
        $this->dumpEntries('قيود الدفعة S6');
        $this->dumpAccountTable('أرصدة الحسابات بعد S6');
        $this->dumpLedger('دفتر رصيد العميل S6', $this->customer->id);
        $this->dumpPL('تقرير الأرباح والخسائر S6', $plBefore, $plAfter);

        $this->assertBalanced('S6');
        $this->assertEqualsWithDelta(
            500.0,
            $this->ledgerBalance($this->customer->id),
            0.01,
            'S6: الرصيد الدائن المتوقع = 500'
        );
        $this->assertEqualsWithDelta(
            7500.0,
            (float) $booking->paid_amount,
            0.01,
            'S6: المدفوع على الحجز يجب أن يُقتص على سعر البيع (7500)'
        );
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S6: رصيد حساب العميل في الدفتر لا يطابق customer_credit_ledger'
        );

        $this->dumpLine('   payment.amount='.$payment->amount.' original_amount='.$payment->original_amount);
        $this->dumpLine('   payment.overpayment_amount (DB) = '.var_export(
            DB::table('flight_payments')->where('id', $payment->id)->value('overpayment_amount'),
            true
        ));
        $this->dumpLine('   columns of flight_payments: '.implode(',', array_map(
            fn ($r) => $r->name,
            DB::select("PRAGMA table_info('flight_payments')")
        )));
    }

    // ─────────────────────────────────────────────────────────────────
    // S7
    // ─────────────────────────────────────────────────────────────────

    /**
     * S7 — company customer, debt < / == / > the refund.
     */
    public function test_s7_company_customer_fifo_settlement(): void
    {
        $this->dumpName = 'S7';
        $this->dumpLine('═══ S7: عميل شركة — تسوية FIFO للديون ═══');

        $company = Customer::create([
            'full_name' => 'Audit Company',
            'phone' => '0100000002',
            'email' => 'audit-company@test.local',
            'national_id' => '11122233344499',
            'city' => 'Cairo',
            'type' => 'company',
        ]);
        $employee = Customer::create([
            'full_name' => 'Audit Employee',
            'phone' => '0100000003',
            'email' => 'audit-employee@test.local',
            'national_id' => '11122233344488',
            'city' => 'Cairo',
            'type' => 'individual',
            'company_id' => $company->id,
        ]);

        $older = $this->bookingService->createBooking([
            'customer_id' => $employee->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(3)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 1000,
            'selling_price' => 4000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'pnr' => 'OLD'.substr(md5((string) microtime(true)), 0, 4),
            'passengers' => [['name' => 'Old Pax', 'type' => 'adult']],
        ]);
        $older->update(['created_at' => now()->subDays(10)]);
        $older->refresh();

        // NOTE: the booking MUST be created for the employee from the start —
        // changing customer_id afterwards makes createBooking() post the sale to
        // one customer account and addPayment() post the receipt to another.
        $newer = $this->bookingService->createBooking([
            'customer_id' => $employee->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 7350,
            'selling_price' => 7500,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'pnr' => 'NEW'.substr(md5((string) microtime(true)), 0, 4),
            'passengers' => [['name' => 'Audit Pax', 'type' => 'adult']],
        ]);
        $newer->update(['status' => \App\Enums\FlightBookingStatus::CONFIRMED]);
        $newer->refresh();
        $this->payBooking($newer, 7500);
        $newer->refresh();

        $this->dumpLine('   older booking #'.$older->id.' remaining='.$older->remaining_amount);
        $this->dumpLine('   newer booking #'.$newer->id.' remaining='.$newer->remaining_amount);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $newer->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $this->dumpEntries('قيود الاسترداد S7');
        $this->dumpLedger('دفتر رصيد الشركة S7', null, $company->id);
        $this->dumpAccountTable('أرصدة الحسابات بعد S7');
        foreach (DB::table('flight_payment_allocations')->orderBy('id')->get() as $a) {
            $this->dumpLine(sprintf(
                '   alloc#%d booking=%d amount=%s status=%s tx_id=%s notes=%s',
                $a->id, $a->flight_booking_id, $a->amount, $a->status, $a->transaction_id, mb_substr((string) $a->notes, 0, 60)
            ));
        }

        $this->assertBalanced('S7');
        $this->assertEqualsWithDelta(
            4000.0,
            (float) $req->customer_debt_settled_amount,
            0.01,
            'S7: يجب تسوية دين الحجز الأقدم بالكامل (4000)'
        );
        $this->assertEqualsWithDelta(
            2300.0,
            (float) $req->customer_credit_amount,
            0.01,
            'S7: الرصيد المتبقي = 6300 - 4000 = 2300'
        );
        $this->assertEqualsWithDelta(
            2300.0,
            $this->ledgerBalance(null, $company->id),
            0.01,
            'S7: رصيد الشركة في دفتر الرصيد الموحد = 2300'
        );

        // GL side: the account of the employee (whose bookings carry the debt)
        $employeeAccountId = (int) $employee->fresh()->account_id;
        $glEmployee = round(-$this->accountBalance($employeeAccountId), 2);
        $this->dumpLine("   employee GL account #{$employeeAccountId} credit-oriented balance = {$glEmployee}");
        $this->assertEqualsWithDelta(
            2300.0,
            $glEmployee,
            0.01,
            'S7: رصيد حساب العميل (الموظف) في الدفتر لا يطابق رصيد الشركة في دفتر الرصيد الموحد'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S8
    // ─────────────────────────────────────────────────────────────────

    /**
     * S8 — normal customer with multiple unpaid invoices, FIFO order.
     */
    public function test_s8_fifo_ordering_multiple_unpaid_bookings(): void
    {
        $this->dumpName = 'S8';
        $this->dumpLine('═══ S8: عميل عادي بفواتير متعددة — تسوية FIFO بالترتيب ═══');

        $b1 = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED',
            'departure_date' => now()->addDays(3)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP',
            'purchase_price' => 1000, 'selling_price' => 2000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'F1'.substr(md5((string) microtime(true)), 0, 4),
            'passengers' => [['name' => 'P1', 'type' => 'adult']],
        ]);
        $b1->update(['created_at' => now()->subDays(20)]);
        $b2 = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI', 'to_airport' => 'JED',
            'departure_date' => now()->addDays(4)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP',
            'purchase_price' => 1000, 'selling_price' => 3000,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->cashbox->id,
            'pnr' => 'F2'.substr(md5((string) microtime(true)), 0, 4),
            'passengers' => [['name' => 'P2', 'type' => 'adult']],
        ]);
        $b2->update(['created_at' => now()->subDays(10)]);
        $b1->refresh();
        $b2->refresh();

        $target = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($target, 7500);
        $target->refresh();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $target->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 0,
            'agency_commission' => 0,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $this->dumpEntries('قيود الاسترداد S8');
        $this->dumpLedger('دفتر رصيد العميل S8', $this->customer->id);
        $this->dumpAccountTable('أرصدة الحسابات بعد S8');
        foreach (DB::table('flight_payment_allocations')->orderBy('id')->get() as $a) {
            $this->dumpLine(sprintf(
                '   alloc#%d booking=%d amount=%s status=%s tx_id=%s notes=%s',
                $a->id, $a->flight_booking_id, $a->amount, $a->status, $a->transaction_id, mb_substr((string) $a->notes, 0, 60)
            ));
        }

        $this->assertBalanced('S8');
        $this->assertEqualsWithDelta(
            5000.0,
            (float) $req->customer_debt_settled_amount,
            0.01,
            'S8: تسوية FIFO = 2000 + 3000 = 5000'
        );
        $this->assertEqualsWithDelta(
            2500.0,
            (float) $req->customer_credit_amount,
            0.01,
            'S8: الرصيد المتبقي = 7500 - 5000 = 2500'
        );
        $this->assertEqualsWithDelta(
            2500.0,
            $this->ledgerBalance($this->customer->id),
            0.01,
            'S8: رصيد العميل في دفتر الرصيد الموحد = 2500'
        );
        $this->assertEqualsWithDelta(
            2500.0,
            $this->glCustomerCredit(),
            0.01,
            'S8: رصيد حساب العميل في الدفتر لا يطابق دفتر الرصيد الموحد'
        );

        $firstAlloc = DB::table('flight_payment_allocations')->orderBy('id')->first();
        $this->assertEquals(
            (int) $b1->id,
            (int) $firstAlloc->flight_booking_id,
            'S8: أول تخصيص FIFO يجب أن يكون على أقدم حجز'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S9
    // ─────────────────────────────────────────────────────────────────

    /**
     * S9 — supplier refunds less than expected AFTER processing S1.
     * The difference must land in refund_variance, not on the customer.
     */
    public function test_s9_supplier_variance_goes_to_variance_account(): void
    {
        $this->dumpName = 'S9';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ S9: الطيران رجّع أقل من المتوقع بعد S1 → refund_variance ═══');

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        // snapshot AFTER the refund, BEFORE the supplier confirmation
        $ledgerBefore = $this->ledgerBalance($this->customer->id);
        $glBefore = $this->glCustomerCredit();

        // Airline actually refunds 6000 instead of 6350 → variance 350
        $this->refundService->confirmSupplierRefund($req->id, $this->admin->id, 6000.0);
        $req->refresh();

        $this->dumpEntries('قيود تأكيد المورد S9');
        $this->dumpAccountTable('أرصدة الحسابات بعد S9');
        $this->dumpLedger('دفتر رصيد العميل S9', $this->customer->id);

        $this->assertEqualsWithDelta(350.0, (float) $req->refund_variance_amount, 0.01, 'S9: فرق الاسترداد = 350');
        $this->assertBalanced('S9');

        $this->assertEqualsWithDelta(
            $ledgerBefore,
            $this->ledgerBalance($this->customer->id),
            0.01,
            'S9: فرق المورد يجب ألا يمس رصيد العميل'
        );
        $this->assertEqualsWithDelta(
            $glBefore,
            $this->glCustomerCredit(),
            0.01,
            'S9: فرق المورد يجب ألا يمس حساب العميل في الدفتر'
        );

        $varianceAccountId = $this->clearing->refundVarianceAccountId();
        $varianceBalance = $this->accountBalance($varianceAccountId);
        $this->dumpLine('   refund_variance acc#'.$varianceAccountId.' balance='.$varianceBalance);
        $this->assertEqualsWithDelta(
            350.0,
            $varianceBalance,
            0.01,
            'S9: حساب فروق الاسترداد يجب أن يحمل 350'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // S10
    // ─────────────────────────────────────────────────────────────────

    /**
     * S10 — disbursements: cash, bank, booking settlement (full & partial),
     * over-disbursement rejected, pre-confirmation rejected, force permission.
     */
    public function test_s10_disbursement_matrix(): void
    {
        $this->dumpName = 'S10';
        $this->dumpLine('═══ S10: صرف الرصيد ═══');

        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
            'treasury_id' => $this->treasury->id,
            'supplier_refund_confirmed' => true,
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $this->dumpLine('   ledger available = '.$this->ledgerBalance($this->customer->id));
        $this->dumpLine('   GL customer credit = '.$this->glCustomerCredit());
        $this->dumpLine('   disbursement rows so far = '.DB::table('customer_credit_disbursements')->count());

        // ---- 10.1 over-disbursement must be rejected ----
        $threw = false;
        try {
            $this->refundService->disburseCustomerCredit($req->id, [
                'type' => 'cash_treasury',
                'amount' => 99999,
                'treasury_id' => $this->treasury->id,
            ], $this->admin);
        } catch (\Throwable $e) {
            $threw = true;
            $this->dumpLine('   [10.1] over-disbursement rejected: '.$e->getMessage());
        }
        $this->assertTrue($threw, 'S10.1: صرف أكبر من المتاح يجب أن يُرفض');
        $this->assertEquals(0, DB::table('customer_credit_disbursements')->count(), 'S10.1: لم يُنشأ سجل صرف');

        // ---- 10.2 disbursement BEFORE supplier confirmation must be rejected ----
        $booking2 = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking2, 7500);
        $booking2->refresh();
        $req2 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking2->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
            'treasury_id' => $this->treasury->id,
        ], $this->admin->id);
        $this->refundService->processRefundRequest($req2->id, $this->admin->id);

        $plainUser = \App\Models\User::create([
            'name' => 'Audit Plain',
            'email' => 'audit-plain@test.local',
            'password' => bcrypt('password'),
            'role' => 'agent',
            'is_active' => true,
        ]);

        $threw2 = false;
        try {
            $this->refundService->disburseCustomerCredit($req2->id, [
                'type' => 'cash_treasury',
                'amount' => 100,
                'treasury_id' => $this->treasury->id,
            ], $plainUser);
        } catch (\Throwable $e) {
            $threw2 = true;
            $this->dumpLine('   [10.2] pre-confirmation cash rejected: '.$e->getMessage());
        }
        $this->assertTrue($threw2, 'S10.2: الصرف قبل تأكيد المورد يجب أن يُرفض');

        // ---- 10.3 force_refund_payout must override the safety rule ----
        $forced = false;
        try {
            $this->refundService->disburseCustomerCredit($req2->id, [
                'type' => 'cash_treasury',
                'amount' => 100,
                'treasury_id' => $this->treasury->id,
            ], $this->admin);
            $forced = true;
        } catch (\Throwable $e) {
            $this->dumpLine('   [10.3] force attempt failed: '.$e->getMessage());
        }
        $this->dumpLine('   [10.3] force payout allowed for admin: '.($forced ? 'YES' : 'NO'));
        $this->assertTrue($forced, 'S10.3: مستخدم admin يملك force_refund_payout يجب أن يعدّي');

        // ---- 10.4 bank disbursement ----
        $bankAccount = \App\Models\Account::create([
            'name' => 'Audit Bank',
            'type' => 'bank',
            'balance' => 100000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);
        $bank = $this->refundService->disburseCustomerCredit($req2->id, [
            'type' => 'bank_transfer',
            'amount' => 500,
            'bank_account_id' => $bankAccount->id,
        ], $this->admin);
        $this->assertEquals(500.0, (float) $bank->amount, 'S10.4: مبلغ الصرف البنكي = 500');

        // ---- 10.5 booking settlement (partial) ----
        $target = $this->makeBooking(['selling_price' => 4000, 'purchase_price' => 3500]);
        $this->payBooking($target, 1000);
        $target->refresh();

        $glBeforeSettlement = $this->glCustomerCredit();
        $ledgerBeforeSettlement = $this->ledgerBalance($this->customer->id);
        $this->dumpLine('   before booking_settlement: GL='.$glBeforeSettlement.' ledger='.$ledgerBeforeSettlement);

        $this->refundService->disburseCustomerCredit($req2->id, [
            'type' => 'booking_settlement',
            'amount' => 1500,
            'target_flight_booking_id' => $target->id,
        ], $this->admin);

        $this->dumpEntries('قيود الصرف S10');
        $this->dumpLedger('دفتر رصيد العميل S10', $this->customer->id);
        $this->dumpAccountTable('أرصدة الحسابات بعد كل الصرف S10');
        foreach (DB::table('flight_payment_allocations')->orderBy('id')->get() as $a) {
            $this->dumpLine(sprintf(
                '   alloc#%d booking=%d amount=%s status=%s tx_id=%s notes=%s',
                $a->id, $a->flight_booking_id, $a->amount, $a->status, $a->transaction_id, mb_substr((string) $a->notes, 0, 60)
            ));
        }
        $this->dumpLine('   treasury.current_balance = '.$this->treasuryBalance());

        $this->assertBalanced('S10');

        // after a booking_settlement the ledger is debited but NO GL entry is posted
        $this->assertEqualsWithDelta(
            $this->ledgerBalance($this->customer->id),
            $this->glCustomerCredit(),
            0.01,
            'S10: بعد تسوية الحجز يجب أن يبقى رصيد حساب العميل في الدفتر مطابقاً لدفتر الرصيد الموحد'
        );

        $this->dumpLine('   ledger available='.$this->ledgerBalance($this->customer->id).' GL='.$this->glCustomerCredit());
    }

    protected function plSummary(array $pl): array
    {
        $out = [];
        foreach ($pl as $k => $v) {
            if (is_array($v)) {
                $out[$k] = [
                    'revenue' => $v['revenue'] ?? null,
                    'cogs' => $v['cogs'] ?? null,
                    'expense' => $v['expense'] ?? null,
                    'profit' => $v['profit'] ?? null,
                    'refund' => $v['refund'] ?? null,
                ];
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
