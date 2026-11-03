<?php

namespace Tests\Audit;

use App\Enums\FlightBookingStatus;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * QA — Section 4 of the brief:
 *   reverseRefundRequest restores every balance / protection rules.
 *
 * CONVENTION: account.balance = SUM(credit) - SUM(debit)
 */
class ReversalProtectionTest extends AuditTestCase
{
    protected array $snapshotBefore = [];

    protected array $snapshotAfter = [];

    /** Full financial fingerprint of the books. */
    protected function fingerprint(): array
    {
        $accounts = DB::table('accounts')->orderBy('id')
            ->pluck('balance', 'id')->map(fn ($v) => round((float) $v, 2))->all();

        $ledger = round((float) DB::table('customer_credit_ledger')->whereNull('reversed_at')->sum('amount'), 2);

        return [
            'accounts' => $accounts,
            'ledger' => $ledger,
            'carrier' => round($this->carrierBalance(), 2),
            'treasury' => round($this->treasuryBalance(), 2),
            'gl_customer' => round($this->glCustomerCredit(), 2),
            'pl_net' => (float) $this->plSnapshot()['netProfit'],
            'tx_count' => DB::table('transactions')->count(),
            'entry_count' => DB::table('account_entries')->count(),
        ];
    }

    protected function dumpFingerprint(string $title, array $fp): void
    {
        $this->dumpLine('');
        $this->dumpLine('── '.$title.' ──');
        foreach ($fp['accounts'] as $id => $bal) {
            if (abs((float) $bal) < 0.005) {
                continue;
            }
            $name = DB::table('accounts')->where('id', $id)->value('name');
            $this->dumpLine(sprintf('   acc#%-4d %-42s %14s', $id, mb_substr((string) $name, 0, 42), number_format((float) $bal, 2, '.', '')));
        }
        $this->dumpLine(sprintf(
            '   ledger=%s carrier=%s treasury=%s gl_customer=%s pl_net=%s tx=%d entries=%d',
            number_format($fp['ledger'], 2, '.', ''),
            number_format($fp['carrier'], 2, '.', ''),
            number_format($fp['treasury'], 2, '.', ''),
            number_format($fp['gl_customer'], 2, '.', ''),
            number_format($fp['pl_net'], 2, '.', ''),
            $fp['tx_count'],
            $fp['entry_count']
        ));
    }

    /** Run a refund scenario and return the created request. */
    protected function runRefund(FlightBooking $booking, array $params, string $destination = 'customer_credit'): \App\Models\Flight\RefundRequest
    {
        $req = $this->refundService->createRefundRequest(array_merge([
            'flight_booking_id' => $booking->id,
            'refund_currency' => 'EGP',
            'destination' => $destination,
        ], $params), $this->admin->id);
        $this->refundService->processRefundRequest($req->id, $this->admin->id);

        return $req->fresh();
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.1 — reversal restores every balance (S1 shape)
    // ─────────────────────────────────────────────────────────────────

    public function test_reverse_customer_voluntary_refund_restores_every_balance(): void
    {
        $this->dumpName = 'REV_S1';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ عكس استرجاع حالة العميل (S1 shape) ═══');
        $before = $this->fingerprint();
        $beforePl = $this->plSnapshot();
        $this->dumpFingerprint('قبل الاسترداد', $before);

        $req = $this->runRefund($booking, [
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
        ]);

        $mid = $this->fingerprint();
        $afterPl = $this->plSnapshot();
        $this->dumpFingerprint('بعد الاسترداد', $mid);
        $this->dumpEntries('قيود الاسترداد');
        $this->dumpLine('   booking status after refund = '.(string) DB::table('flight_bookings')->where('id', $booking->id)->value('status'));

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);

        $after = $this->fingerprint();
        $this->dumpFingerprint('بعد العكس', $after);
        $this->dumpEntries('قيود العكس');
        $this->dumpLedger('دفتر رصيد العميل بعد العكس', $this->customer->id);
        $this->dumpPL('P&L عبر دورة الاسترداد ثم العكس', $beforePl, $afterPl);
        $this->dumpPL('P&L قبل الاسترداد مقابل بعد العكس', $beforePl, $this->plSnapshot());
        $this->dumpLine('   refund request soft-deleted? '.(DB::table('refund_requests')->where('id', $req->id)->value('deleted_at') ? 'YES' : 'NO'));
        $this->dumpLine('   booking status after reversal = '.(string) DB::table('flight_bookings')->where('id', $booking->id)->value('status'));

        $this->assertBalanced('REV');

        // The brief: after reversal every balance returns EXACTLY to pre-refund state.
        foreach ($before['accounts'] as $id => $bal) {
            if (abs((float) $bal) < 0.005) {
                continue;
            }
            $this->assertEqualsWithDelta(
                (float) $bal,
                (float) ($after['accounts'][$id] ?? 0),
                0.01,
                "عكس الاسترجاع: الحساب #$id لم يرجع لرصيده قبل الاسترداد"
            );
        }
        $this->assertEqualsWithDelta(
            $before['ledger'],
            $after['ledger'],
            0.01,
            'عكس الاسترجاع: رصيد دفتر الرصيد الموحد لم يرجع'
        );
        $this->assertEqualsWithDelta(
            $before['carrier'],
            $after['carrier'],
            0.01,
            'عكس الاسترجاع: رصيد الناقل لم يرجع'
        );
        $this->assertEqualsWithDelta(
            $before['gl_customer'],
            $after['gl_customer'],
            0.01,
            'عكس الاسترجاع: رصيد حساب العميل في الدفتر لم يرجع'
        );
        $this->assertEqualsWithDelta(
            $before['pl_net'],
            $after['pl_net'],
            0.01,
            'عكس الاسترجاع: تقرير الأرباح والخسائر لم يرجع'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.2 — reversal restores every balance (S4 shape: airline short refund)
    // ─────────────────────────────────────────────────────────────────

    public function test_reverse_airline_cancelled_refund_restores_every_balance(): void
    {
        $this->dumpName = 'REV_S4';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $before = $this->fingerprint();
        $req = $this->runRefund($booking, [
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100,
            'apply_commission' => false,
            'non_refundable_taxes_on_customer' => false,
            'non_refundable_taxes_amount' => 250,
        ]);
        $this->dumpFingerprint('بعد الاسترداد S4', $this->fingerprint());

        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);

        $after = $this->fingerprint();
        $this->dumpFingerprint('بعد العكس S4', $after);
        $this->dumpEntries('قيود S4');

        $this->assertBalanced('REV-S4');
        foreach ($before['accounts'] as $id => $bal) {
            if (abs((float) $bal) < 0.005) {
                continue;
            }
            $this->assertEqualsWithDelta(
                (float) $bal,
                (float) ($after['accounts'][$id] ?? 0),
                0.01,
                "عكس الاسترجاع S4: الحساب #$id لم يرجع"
            );
        }
        $this->assertEqualsWithDelta($before['carrier'], $after['carrier'], 0.01, 'عكس S4: رصيد الناقل لم يرجع');
        $this->assertEqualsWithDelta($before['pl_net'], $after['pl_net'], 0.01, 'عكس S4: الأرباح والخسائر لم ترجع');
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.3 — reversal restores every balance (S5 shape)
    // ─────────────────────────────────────────────────────────────────

    public function test_reverse_airline_cancelled_taxes_on_customer_restores_every_balance(): void
    {
        $this->dumpName = 'REV_S5';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $before = $this->fingerprint();
        $req = $this->runRefund($booking, [
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100,
            'apply_commission' => false,
            'non_refundable_taxes_on_customer' => true,
            'non_refundable_taxes_amount' => 250,
        ]);
        $this->refundService->reverseRefundRequest($req->id, $this->admin->id);
        $after = $this->fingerprint();
        $this->dumpFingerprint('بعد العكس S5', $after);
        $this->dumpEntries('قيود S5');

        $this->assertBalanced('REV-S5');
        foreach ($before['accounts'] as $id => $bal) {
            if (abs((float) $bal) < 0.005) {
                continue;
            }
            $this->assertEqualsWithDelta(
                (float) $bal,
                (float) ($after['accounts'][$id] ?? 0),
                0.01,
                "عكس الاسترجاع S5: الحساب #$id لم يرجع"
            );
        }
        $this->assertEqualsWithDelta($before['carrier'], $after['carrier'], 0.01, 'عكس S5: رصيد الناقل لم يرجع');
        $this->assertEqualsWithDelta($before['pl_net'], $after['pl_net'], 0.01, 'عكس S5: الأرباح والخسائر لم ترجع');
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.4 — reversing a request with an executed cash disbursement
    // ─────────────────────────────────────────────────────────────────

    public function test_reverse_rejected_when_cash_disbursement_already_executed(): void
    {
        $this->dumpName = 'REV_DISB';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $req = $this->runRefund($booking, [
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'destination' => 'agency_treasury',
            'treasury_id' => $this->treasury->id,
        ], 'agency_treasury');

        $this->dumpLine('═══ عكس طلب صرف نقدي منفّذ ═══');
        $this->dumpLine('   disbursements = '.DB::table('customer_credit_disbursements')->count());
        $this->dumpLine('   treasury.current_balance = '.$this->treasuryBalance());

        $threw = false;
        $msg = '';
        try {
            $this->refundService->reverseRefundRequest($req->id, $this->admin->id);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
            $this->dumpLine('   REJECTED: '.$msg);
        }
        $this->assertTrue($threw, 'عكس طلب صرف نقدي منفّذ يجب أن يُرفض');
        $this->assertStringContainsString('صرف', $msg);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.5 — double refund (sequential)
    // ─────────────────────────────────────────────────────────────────

    public function test_sequential_double_refund_is_rejected(): void
    {
        $this->dumpName = 'DOUBLE';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ استرجاع مزدوج متتالي ═══');

        $req1 = $this->runRefund($booking, [
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
        ]);

        // (a) identical second request must be rejected by the cumulative ceiling
        $threw = false;
        $msg = '';
        try {
            $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'refund_case' => 'customer_voluntary',
                'airline_penalty' => 1000,
                'agency_commission' => 200,
                'refund_currency' => 'EGP',
                'destination' => 'customer_credit',
            ], $this->admin->id);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
            $this->dumpLine('   [a] second identical request REJECTED: '.$msg);
        }
        $this->assertTrue($threw, 'الاسترجاع المزدوج المتتالي يجب أن يُرفض');

        // (b) processing the SAME request twice must be idempotent
        $txBefore = DB::table('account_entries')->count();
        $this->refundService->processRefundRequest($req1->id, $this->admin->id);
        $txAfter = DB::table('account_entries')->count();
        $this->dumpLine("   [b] re-process same request: entries {$txBefore} -> {$txAfter}");
        $this->assertEquals($txBefore, $txAfter, 'إعادة معالجة نفس الطلب يجب أن تكون بلا أثر (idempotent)');

        // (c) a partial second refund up to the ceiling SHOULD be allowed
        //     (only 6300 of 7500 was refunded) — document what actually happens.
        $status = (string) DB::table('flight_bookings')->where('id', $booking->id)->value('status');
        $this->dumpLine('   booking status after the first (partial) refund = '.$status);
        $threw3 = false;
        $msg3 = '';
        try {
            $req2 = $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'refund_case' => 'customer_voluntary',
                'airline_penalty' => 0,
                'agency_commission' => 0,
                'refund_currency' => 'EGP',
                'destination' => 'customer_credit',
            ], $this->admin->id);
            $this->refundService->processRefundRequest($req2->id, $this->admin->id);
        } catch (\Throwable $e) {
            $threw3 = true;
            $msg3 = $e->getMessage();
        }
        $this->dumpLine('   [c] second PARTIAL refund (1200) rejected='.($threw3 ? 'YES' : 'NO').' — '.$msg3);

        $this->dumpEntries('قيود الاسترجاع المزدوج');
        $this->dumpLine('   total refunded = '.DB::table('refund_requests')->where('flight_booking_id', $booking->id)->sum('refund_amount'));
        $this->assertBalanced('DOUBLE');
        $this->assertEqualsWithDelta(
            7500.0,
            (float) DB::table('refund_requests')->where('flight_booking_id', $booking->id)->sum('refund_amount'),
            0.01,
            'إجمالي المبالغ المستردة يجب ألا يتجاوز سعر البيع (7500)'
        );
        // DEFECT: the booking is flagged REFUNDED after a PARTIAL refund because
        // RefundService L959 compares `original_amount` (= selling price) with the
        // selling price instead of comparing the refunded amount.
        $this->assertEquals(
            'PARTIALLY_REFUNDED',
            $status,
            'بعد استرجاع جزئي (6300 من 7500) يجب أن تبقى حالة الحجز PARTIALLY_REFUNDED لا REFUNDED'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.6 — mid-operation failure ⇒ full rollback
    // ─────────────────────────────────────────────────────────────────

    public function test_failure_in_the_middle_of_processing_rolls_back_everything(): void
    {
        $this->dumpName = 'ROLLBACK';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ فشل في منتصف الاسترداد ⇒ تراجع كامل ═══');

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'refund_currency' => 'EGP',
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $before = $this->fingerprint();
        $entriesBefore = DB::table('account_entries')->count();
        $ledgerBefore = DB::table('customer_credit_ledger')->count();

        // Inject a failure on the 3rd journal transfer (i.e. after the supplier
        // leg and the customer leg have already been posted).
        $real = app(TransactionService::class);
        $calls = 0;
        $spy = Mockery::mock(TransactionService::class)->makePartial();
        $spy->shouldReceive('recordJournalTransfer')->andReturnUsing(function ($data) use ($real, &$calls) {
            $calls++;
            if ($calls === 3) {
                throw new \RuntimeException('QA-injected failure after leg 2');
            }

            return $real->recordJournalTransfer($data);
        });
        app()->instance(TransactionService::class, $spy);
        app()->forgetInstance(\App\Services\Flight\RefundService::class);
        $svc = app(\App\Services\Flight\RefundService::class);

        $threw = false;
        $msg = '';
        try {
            $svc->processRefundRequest($req->id, $this->admin->id);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $this->dumpLine('   injected failure thrown: '.var_export($threw, true).' — '.$msg);
        $this->assertTrue($threw, 'كان يجب أن يفشل الاسترداد بسبب الخطأ المحقون');

        app()->forgetInstance(\App\Services\Flight\RefundService::class);
        app()->instance(TransactionService::class, $real);

        $after = $this->fingerprint();
        $this->dumpFingerprint('بعد التراجع', $after);

        $this->assertEquals($entriesBefore, DB::table('account_entries')->count(), 'تراجع: تم إضافة قيود account_entries');
        $this->assertEquals($ledgerBefore, DB::table('customer_credit_ledger')->count(), 'تراجع: تم إضافة حركات في دفتر الرصيد');
        $this->assertEquals('pending', (string) DB::table('refund_requests')->where('id', $req->id)->value('status'), 'تراجع: حالة الطلب لم تعد pending');
        foreach ($before['accounts'] as $id => $bal) {
            $this->assertEqualsWithDelta(
                (float) $bal,
                (float) ($after['accounts'][$id] ?? 0),
                0.01,
                "تراجع: رصيد الحساب #$id تغيّر"
            );
        }
        $this->assertEqualsWithDelta(
            $before['carrier'],
            $after['carrier'],
            0.01,
            'تراجع: رصيد الناقل تغيّر'
        );
        $this->assertEquals(0, DB::table('flight_payment_allocations')->count(), 'تراجع: تم إنشاء تخصيصات دفع');
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.7 — bad input rejection matrix
    // ─────────────────────────────────────────────────────────────────

    public function test_bad_inputs_are_rejected(): void
    {
        $this->dumpName = 'BADINPUT';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ مصفوفة المدخلات الخاطئة ═══');

        $cases = [
            'negative commission' => ['refund_case' => 'customer_voluntary', 'agency_commission' => -50],
            'negative penalty' => ['refund_case' => 'customer_voluntary', 'airline_penalty' => -50],
            'penalty > sale price' => ['refund_case' => 'customer_voluntary', 'airline_penalty' => 8000],
            'commission + penalty > sale price' => ['refund_case' => 'customer_voluntary', 'airline_penalty' => 5000, 'agency_commission' => 3000],
            'supplier amount > cost' => ['refund_case' => 'airline_cancelled', 'airline_refund_amount' => 9999],
            'unsold ticket (no payment)' => ['refund_case' => 'customer_voluntary', 'airline_penalty' => 100, 'agency_commission' => 0, '_unpaid_booking' => true],
        ];

        foreach ($cases as $label => $params) {
            $target = $booking;
            if (! empty($params['_unpaid_booking'])) {
                unset($params['_unpaid_booking']);
                $target = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
            }
            $threw = false;
            $msg = '';
            try {
                $this->refundService->createRefundRequest(array_merge([
                    'flight_booking_id' => $target->id,
                    'refund_currency' => 'EGP',
                    'destination' => 'customer_credit',
                ], $params), $this->admin->id);
            } catch (\Throwable $e) {
                $threw = true;
                $msg = $e->getMessage();
            }
            $this->dumpLine(sprintf('   %-40s rejected=%s  %s', $label, $threw ? 'YES' : 'NO', mb_substr($msg, 0, 90)));
            $this->assertTrue($threw, "المدخل الخاطئ يجب أن يُرفض: {$label}");
        }

        // ---- idempotency ----
        $this->dumpLine('── idempotency key ──');
        $idem = ['flight_booking_id' => $booking->id, 'refund_case' => 'customer_voluntary',
            'airline_penalty' => 100, 'agency_commission' => 0, 'refund_currency' => 'EGP',
            'destination' => 'customer_credit', 'idempotency_key' => 'QA-IDEM-1'];
        $r1 = $this->refundService->createRefundRequest($idem, $this->admin->id);
        $threwIdem = false;
        $msgIdem = '';
        try {
            $this->refundService->createRefundRequest($idem, $this->admin->id);
        } catch (\Throwable $e) {
            $threwIdem = true;
            $msgIdem = $e->getMessage();
        }
        $this->dumpLine('   duplicate idempotency_key rejected='.($threwIdem ? 'YES' : 'NO').' — '.$msgIdem);
        $this->assertTrue($threwIdem, 'المفتاح المكرر يجب أن يُرفض');
        $this->assertEquals(1, DB::table('refund_requests')->where('idempotency_key', 'QA-IDEM-1')->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // 4.8 — permissions
    // ─────────────────────────────────────────────────────────────────

    public function test_disbursement_requires_disburse_refund_permission(): void
    {
        $this->dumpName = 'PERM';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ صلاحيات الصرف ═══');

        $req = $this->runRefund($booking, [
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000,
            'agency_commission' => 200,
            'treasury_id' => $this->treasury->id,
            'supplier_refund_confirmed' => true,
        ]);

        // A user that has manage_refunds but NOT disburse_refund / force_refund_payout
        $agent = User::create([
            'name' => 'Audit Agent No Disburse',
            'email' => 'audit-agent-nodisburse@test.local',
            'password' => bcrypt('password'),
            'role' => 'agent',
            'is_active' => true,
        ]);

        $perms = \App\Support\UserPermissions::effectiveFor($agent);
        $this->dumpLine('   agent effective permissions: '.json_encode($perms));
        $this->assertNotContains('disburse_refund', $perms, 'الاختبار: يجب ألا يملك الوكيل صلاحية disburse_refund');
        $this->assertNotContains('force_refund_payout', $perms, 'الاختبار: يجب ألا يملك الوكيل صلاحية force_refund_payout');

        $threw = false;
        $msg = '';
        try {
            $this->refundService->disburseCustomerCredit($req->id, [
                'type' => 'cash_treasury',
                'amount' => 100,
                'treasury_id' => $this->treasury->id,
            ], $agent);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $this->dumpLine('   agent disbursement rejected='.($threw ? 'YES' : 'NO').' — '.$msg);
        $this->dumpLine('   disbursement rows = '.DB::table('customer_credit_disbursements')->count());

        $this->assertTrue(
            $threw,
            'مستخدم بدون صلاحية disburse_refund يجب أن يُمنع من صرف رصيد العميل (المورد مؤكد مسبقاً)'
        );
    }
}
