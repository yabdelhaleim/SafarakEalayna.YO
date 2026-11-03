<?php

namespace Tests\Audit;

use App\Models\Flight\FlightBooking;
use App\Services\Flight\FlightBookingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA — Section 5 of the brief: FlightBookingService::addPayment.
 *
 * The probe (test_addpayment_probe) writes a deterministic JSON snapshot that is
 * produced identically on `main` and on the branch so the two can be diffed
 * entry-by-entry.
 */
class AddPaymentAuditTest extends AuditTestCase
{
    protected function ledgerTableExists(): bool
    {
        return Schema::hasTable('customer_credit_ledger');
    }

    protected function ledgerRowsFor(int $customerId): array
    {
        if (! $this->ledgerTableExists()) {
            return [];
        }

        return DB::table('customer_credit_ledger')->where('customer_id', $customerId)->orderBy('id')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    protected function paymentRows(int $bookingId): array
    {
        $cols = ['id', 'amount', 'currency', 'payment_method', 'notes', 'transaction_id'];
        if (Schema::hasColumn('flight_payments', 'original_amount')) {
            $cols[] = 'original_amount';
        }
        if (Schema::hasColumn('flight_payments', 'overpayment_amount')) {
            $cols[] = 'overpayment_amount';
        }

        return DB::table('flight_payments')->where('flight_booking_id', $bookingId)->orderBy('id')
            ->get($cols)->map(fn ($r) => (array) $r)->all();
    }

    protected function entriesFor(int $bookingId): array
    {
        $txIds = DB::table('transactions')->where('related_type', 'App\Models\Flight\FlightPayment')->pluck('id');

        return DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->whereIn('ae.transaction_id', $txIds)
            ->select(['ae.transaction_id', 'a.name as account', 'ae.debit', 'ae.credit', 'ae.balance_after', 't.notes'])
            ->orderBy('ae.id')
            ->get()->map(fn ($r) => [
                'account' => $r->account,
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
                'balance_after' => (float) $r->balance_after,
                'notes' => $r->notes,
            ])->all();
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.1 partial payment (< remainder)
    // ─────────────────────────────────────────────────────────────────

    public function test_partial_payment_creates_no_credit_ledger(): void
    {
        $this->dumpName = 'PAY_PARTIAL';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 3000);
        $booking->refresh();

        $this->dumpLine('═══ دفعة جزئية 3000 من 7500 ═══');
        $this->dumpEntries('قيود الدفعة الجزئية');
        $this->dumpLine('   payments = '.json_encode($this->paymentRows($booking->id)));
        $this->dumpLine('   ledger rows = '.json_encode($this->ledgerRowsFor($this->customer->id)));

        $this->assertBalanced('PAY_PARTIAL');
        $this->assertEquals(
            [],
            $this->ledgerRowsFor($this->customer->id),
            'دفعة جزئية أقل من المتبقي يجب ألا تُنشئ أي حركة في دفتر الرصيد الموحد'
        );
        $this->assertEqualsWithDelta(3000.0, (float) $booking->paid_amount, 0.01, 'المدفوع = 3000');
        $this->assertEqualsWithDelta(4500.0, (float) $booking->remaining_amount, 0.01, 'المتبقي = 4500');
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.2 payment equal to the remainder
    // ─────────────────────────────────────────────────────────────────

    public function test_full_payment_creates_no_credit_ledger(): void
    {
        $this->dumpName = 'PAY_FULL';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 7500);
        $booking->refresh();

        $this->dumpLine('═══ دفعة كاملة 7500 ═══');
        $this->dumpEntries('قيود الدفعة الكاملة');
        $this->dumpLine('   payments = '.json_encode($this->paymentRows($booking->id)));

        $this->assertBalanced('PAY_FULL');
        $this->assertEquals([], $this->ledgerRowsFor($this->customer->id), 'دفعة كاملة يجب ألا تُنشئ حركة رصيد');
        $this->assertEqualsWithDelta(7500.0, (float) $booking->paid_amount, 0.01, 'المدفوع = 7500');
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.3 two payments completing the booking
    // ─────────────────────────────────────────────────────────────────

    public function test_two_payments_completing_the_booking_create_no_credit_ledger(): void
    {
        $this->dumpName = 'PAY_TWO';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 3000);
        $booking->refresh();
        $this->payBooking($booking, 4500);
        $booking->refresh();

        $this->dumpLine('═══ دفعتان: 3000 ثم 4500 ═══');
        $this->dumpEntries('قيود الدفعتين');
        $this->dumpLine('   payments = '.json_encode($this->paymentRows($booking->id)));

        $this->assertBalanced('PAY_TWO');
        $this->assertEquals([], $this->ledgerRowsFor($this->customer->id), 'دفعتان تكملان الحجز يجب ألا تُنشئا حركة رصيد');
        $this->assertEqualsWithDelta(7500.0, (float) $booking->paid_amount, 0.01, 'المدفوع = 7500');
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.4 overpayment WITHOUT allow_overpayment must be rejected
    // ─────────────────────────────────────────────────────────────────

    public function test_overpayment_without_allow_overpayment_flag_must_be_rejected(): void
    {
        $this->dumpName = 'PAY_OVERPAY';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);

        $this->dumpLine('═══ دفعة زيادة 8000 على حجز 7500 ═══');
        $this->dumpLine('   does the code know an "allow_overpayment" flag? '.(str_contains(
            file_get_contents(base_path('app/Services/Flight/FlightBookingService.php')),
            'allow_overpayment'
        ) ? 'YES' : 'NO'));

        $threw = false;
        $msg = '';
        try {
            $this->payBooking($booking, 8000);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $booking->refresh();

        $this->dumpLine('   rejected='.($threw ? 'YES' : 'NO').' — '.$msg);
        $this->dumpEntries('قيود الدفعة الزائدة');
        $this->dumpLine('   payments = '.json_encode($this->paymentRows($booking->id)));
        $this->dumpLine('   ledger rows = '.json_encode($this->ledgerRowsFor($this->customer->id)));

        $this->assertTrue(
            $threw,
            'دفعة أكبر من المتبقي يجب أن تُرفض ما لم يُمرَّر allow_overpayment=true'
        );
        $this->assertEquals([], $this->paymentRows($booking->id), 'الدفعة المرفوضة لا تُنشئ سطر دفع');
        $this->assertEquals([], $this->ledgerRowsFor($this->customer->id), 'الدفعة المرفوضة لا تُنشئ رصيداً دائناً');
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.5 overpayment WITH the flag ⇒ accepted + credit
    // ─────────────────────────────────────────────────────────────────

    public function test_overpayment_creates_customer_credit(): void
    {
        $this->dumpName = 'PAY_OVERPAY_OK';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 8000, ['allow_overpayment' => true]);
        $booking->refresh();

        $this->dumpLine('═══ دفعة زيادة 8000 (بعد قبولها) ═══');
        $this->dumpEntries('قيود الدفعة الزائدة');
        $this->dumpLine('   payments = '.json_encode($this->paymentRows($booking->id)));
        $this->dumpLine('   ledger rows = '.json_encode($this->ledgerRowsFor($this->customer->id)));
        $this->dumpLine('   flight_payments columns = '.implode(',', array_map(
            fn ($r) => $r->name,
            DB::select("PRAGMA table_info('flight_payments')")
        )));

        $this->assertBalanced('PAY_OVERPAY_OK');
        if ($this->ledgerTableExists()) {
            $this->assertEqualsWithDelta(
                500.0,
                $this->ledgerBalance($this->customer->id),
                0.01,
                'الدفعة الزائدة يجب أن تُنشئ رصيداً دائناً 500'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.6 foreign currency payment — rounding + ledger
    // ─────────────────────────────────────────────────────────────────

    public function test_foreign_currency_payment_rounding(): void
    {
        $this->dumpName = 'PAY_USD';

        $usdAccount = \App\Models\Account::create([
            'name' => 'Audit USD Cashbox',
            'type' => 'cashbox',
            'balance' => 100000,
            'currency' => 'USD',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // The exchange rate always comes from the server (`currencies` table) —
        // createBooking ignores the rate sent by the client.
        $usdRate = (float) (\App\Models\Setting\Currency::where('code', 'USD')->value('exchange_rate') ?? 48.5);
        $sellingEgp = round(150 * $usdRate, 2);   // a 150 USD booking

        $booking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'USD',
            // selling_price is ALWAYS stored in EGP (createBooking 2026-07-23 fix):
            // a 150 USD booking = 150 × rate EGP, and selling_price_foreign = 150 USD.
            'purchase_price' => round($sellingEgp - 150, 2),
            'selling_price' => $sellingEgp,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $usdAccount->id,
            'pnr' => 'USD'.substr(md5((string) microtime(true)), 0, 4),
            'passengers' => [['name' => 'USD Pax', 'type' => 'adult']],
        ]);
        $booking->update(['status' => \App\Enums\FlightBookingStatus::CONFIRMED]);
        $booking->refresh();

        $this->dumpLine('═══ دفعة 150 USD على حجز 150 USD (سعر الصرف '.$usdRate.') ═══');

        $threw = false;
        $msg = '';
        try {
            $this->bookingService->addPayment($booking, [
                'amount' => 150,
                'payment_method' => 'cash',
                'account_id' => $usdAccount->id,
                'notes' => 'USD payment',
            ]);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $booking->refresh();

        $this->dumpLine('   rejected='.($threw ? 'YES' : 'NO').' — '.$msg);
        $this->dumpEntries('قيود دفعة USD');
        $this->dumpLine('   payments = '.json_encode($this->paymentRows($booking->id)));
        $this->dumpLine('   ledger rows = '.json_encode($this->ledgerRowsFor($this->customer->id)));
        $this->dumpLine('   usd cashbox balance = '.$usdAccount->fresh()->balance);
        $this->dumpLine('   EGP customer account balance = '.$this->accountBalance($this->customerAccountId()));

        $this->assertBalanced('PAY_USD');
        $this->assertFalse($threw, 'دفعة USD كاملة يجب أن تُقبل');
        $this->assertEqualsWithDelta($sellingEgp, (float) $booking->paid_amount, 0.01, 'المدفوع (بمعيار EGP) = 150 USD × '.$usdRate);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->accountBalance($this->customerAccountId()),
            0.01,
            'حساب العميل = 0 — البيع 7,500 مدين يقابله سداد 7,500 (توازن الحجز)'
        );
        $this->assertEqualsWithDelta(150.0, (float) $usdAccount->fresh()->balance - 100000, 0.01, 'صندوق USD = 150');
        $this->assertEquals([], $this->ledgerRowsFor($this->customer->id), 'دفعة USD كاملة يجب ألا تُنشئ حركة رصيد');
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.7 cancelling a payment that partly became credit
    // ─────────────────────────────────────────────────────────────────

    public function test_cancelling_a_payment_that_partly_became_credit(): void
    {
        $this->dumpName = 'PAY_CANCEL';
        $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
        $this->payBooking($booking, 8000, ['allow_overpayment' => true]);
        $booking->refresh();

        $this->dumpLine('═══ إلغاء دفعة تحوّل جزءها لرصيد دائن ═══');
        $this->dumpLine('   ledger before cancel = '.json_encode($this->ledgerRowsFor($this->customer->id)));

        $payment = $booking->payments()->first();
        $threw = false;
        $msg = '';
        try {
            // There is no payment-level delete endpoint; the only reversal path
            // for a flight payment is cancelling the booking.
            $this->bookingService->cancelBooking($booking, [
                'reason' => 'QA cancel of an overpaid booking',
                'cancellation_fee' => 0,
                'account_id' => $this->cashbox->id,
            ]);
        } catch (\Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $booking->refresh();

        $this->dumpLine('   cancelBooking rejected='.($threw ? 'YES' : 'NO').' — '.$msg);
        $this->dumpLine('   ledger after cancel = '.json_encode($this->ledgerRowsFor($this->customer->id)));
        $this->dumpLine('   payments after cancel = '.json_encode($this->paymentRows($booking->id)));
        $this->dumpEntries('قيود بعد الإلغاء');
        $this->dumpLine('   paid_amount = '.$booking->paid_amount);

        $this->assertBalanced('PAY_CANCEL');
        if ($this->ledgerTableExists() && ! $threw) {
            // The cancel flow refunds 7500 (the capped booking amount) in cash and
            // does NOT touch the credit ledger, so the 500 surplus stays as credit.
            // Reconciliation: paid 8000 = refunded 7500 + credit 500.
            $this->assertEqualsWithDelta(
                500.0,
                $this->ledgerBalance($this->customer->id),
                0.01,
                'بعد الإلغاء: المدفوع 8000 = المسترد 7500 نقداً + رصيد دائن 500'
            );
            $this->assertEqualsWithDelta(
                500.0,
                $this->glCustomerCredit(),
                0.01,
                'بعد الإلغاء: حساب العميل في الدفتر يجب أن يعرض 500 دائن (استرداد 7500 مقابل دفعة 8000)'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 5.8 deterministic probe for the main-vs-branch diff
    // ─────────────────────────────────────────────────────────────────

    public function test_addpayment_probe(): void
    {
        $this->dumpName = 'PROBE';
        $out = [];

        $scenarios = [
            'partial_3000' => [3000, []],
            'equal_7500' => [7500, []],
            'two_3000_4500' => [3000, [4500]],
            'overpay_8000' => [8000, []],
        ];

        foreach ($scenarios as $label => [$first, $rest]) {
            $booking = $this->makeBooking(['selling_price' => 7500, 'purchase_price' => 7350]);
            $error = null;
            try {
                $this->payBooking($booking, $first);
                foreach ($rest as $r) {
                    $this->payBooking($booking, $r);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $booking->refresh();

            $out[$label] = [
                'error' => $error,
                'entries' => $this->entriesFor($booking->id),
                'payments' => $this->paymentRows($booking->id),
                'paid_amount' => (float) $booking->paid_amount,
                'remaining_amount' => (float) $booking->remaining_amount,
                'booking_status' => $booking->status->value ?? (string) $booking->status,
                'ledger' => $this->ledgerRowsFor($this->customer->id),
            ];
        }

        // ---- foreign currency scenario (USD) ----
        $usdAccount = \App\Models\Account::create([
            'name' => 'Audit USD Cashbox',
            'type' => 'cashbox',
            'balance' => 100000,
            'currency' => 'USD',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);
        $usdRate = (float) (\App\Models\Setting\Currency::where('code', 'USD')->value('exchange_rate') ?? 48.5);
        $usdSellingEgp = round(150 * $usdRate, 2);
        $usdBooking = $this->bookingService->createBooking([
            'customer_id' => $this->customer->id,
            'airline_name' => 'Audit Airline',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'USD',
            'purchase_price' => round($usdSellingEgp - 150, 2),
            'selling_price' => $usdSellingEgp,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $usdAccount->id,
            'pnr' => 'USD'.substr(md5((string) microtime(true)), 0, 4),
            'passengers' => [['name' => 'USD Pax', 'type' => 'adult']],
        ]);
        $usdBooking->update(['status' => \App\Enums\FlightBookingStatus::CONFIRMED]);
        $usdBooking->refresh();

        $usdError = null;
        try {
            $this->bookingService->addPayment($usdBooking, [
                'amount' => 150,
                'payment_method' => 'cash',
                'account_id' => $usdAccount->id,
                'notes' => 'USD payment',
            ]);
        } catch (\Throwable $e) {
            $usdError = $e->getMessage();
        }
        $usdBooking->refresh();

        $out['usd_150'] = [
            'error' => $usdError,
            'exchange_rate' => (float) $usdBooking->exchange_rate,
            'entries' => $this->entriesFor($usdBooking->id),
            'payments' => $this->paymentRows($usdBooking->id),
            'paid_amount' => (float) $usdBooking->paid_amount,
            'remaining_amount' => (float) $usdBooking->remaining_amount,
            'booking_status' => $usdBooking->status->value ?? (string) $usdBooking->status,
            'ledger' => $this->ledgerRowsFor($this->customer->id),
            'usd_cashbox_balance' => (float) $usdAccount->fresh()->balance,
            'egp_customer_balance' => (float) $this->accountBalance($this->customerAccountId()),
        ];

        $path = __DIR__.'/out/addpayment_probe.json';
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        $this->dumpLine('   probe written to '.$path);
    }
}
