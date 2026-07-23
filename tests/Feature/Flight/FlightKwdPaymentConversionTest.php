<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the 2026-07-23 finding:
 *   "حجز من سيستم دينار نفس الموضوع لما حجزت من ساين بالدينار
 *    الشراء 50 دينار = 8000جم — البيع 8500مصري = 1,360,000 جم"
 *
 * Scenario: book a Jazeera-style KWD flight with selling_price=8500 KWD,
 * exchange_rate=160, but pay in EGP from a local EGP cashbox (1,360,000 EGP).
 *
 * Pre-fix: FlightBookingService::addPayment threw
 *   "عملة حساب الدفع (EGP) لا تطابق عملة الحجز (KWD)"
 * Post-fix: the booking is created, the customer AR (EGP) is balanced, and
 * the EGP cashbox receives the payment. booking.original_currency/original_amount
 * carry the customer-payment metadata in the right currency for refunds.
 */
class FlightKwdPaymentConversionTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $kwdCarrier;

    protected Account $egpCashbox;

    protected int $incomeClearingAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'name' => 'KWD Payment Admin',
            'email' => 'kwd-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        // Customer — CustomerLedgerObserver auto-creates an EGP AR account.
        $this->customer = Customer::create([
            'full_name' => 'عميل دينار كويتي',
            'phone' => '010KWDTEST01',
            'email' => 'kwd-customer@test.com',
            'national_id' => '29001011234567',
            'city' => 'Cairo',
            'travel_country' => 'الكويت',
        ]);
        $this->assertNotNull(
            $this->customer->account_id,
            'CustomerLedgerObserver must auto-create the AR account'
        );

        // Flight system (KWD) — purely structural, the booking uses 'carrier' source.
        $this->flightSystem = FlightSystem::create([
            'name' => 'KWD Test System',
            'code' => 'KWDS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'manual',
            'is_active' => true,
            'currency' => 'KWD',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        // Jazeera-style KWD carrier with sufficient balance.
        $this->kwdCarrier = FlightCarrier::create([
            'name' => 'الجزيرة (Test)',
            'code' => 'J9TST',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'KWD',
            'balance' => 0,
            'credit_limit' => 10000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // KWD cashbox — needed to recharge the KWD carrier (recharge enforces currency match).
        $kwdCashbox = Account::create([
            'name' => 'KWD Test KWD Cashbox',
            'type' => 'cashbox',
            'balance' => 5000,
            'currency' => 'KWD',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // EGP cashbox — the user's local cashbox (the one we'll pay FROM in the test).
        $this->egpCashbox = Account::create([
            'name' => 'KWD Test EGP Cashbox',
            'type' => 'cashbox',
            'balance' => 5000000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // Recharge the KWD carrier from the KWD cashbox so debitFlightCarrier has headroom.
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->kwdCarrier,
            $kwdCashbox,
            5000.00, // 5000 KWD prepaid
            'KWD test setup'
        );

        $this->incomeClearingAccountId = app(LedgerClearingAccounts::class)
            ->incomeContraIdForFlightBooking();

        $this->kwdCarrier->refresh();
        $this->egpCashbox->refresh();
    }

    public function test_kwd_booking_paid_from_egp_cashbox_succeeds_and_balances_correctly(): void
    {
        $exchangeRate = 160.0;
        $sellingPriceEgp = 1360000.0;            // user types EGP (per Vue label)
        $sellingPriceKwd = $sellingPriceEgp / $exchangeRate; // 8500 KWD (foreign equivalent)
        $purchasePriceKwd = 50.0;
        $purchasePriceEgp = $purchasePriceKwd * $exchangeRate; // 8,000 EGP

        $beforeCashbox = (float) $this->egpCashbox->balance;

        // ── ACT ── create the booking with a KWD sale and an EGP cashbox payment.
        $booking = $this->bookingService->createBooking([
            'customer_id'            => $this->customer->id,
            'airline_name'           => 'الجزيرة (Test)',
            'from_airport'           => 'SPX',
            'to_airport'             => 'CAI',
            'departure_date'         => now()->addDays(7)->toDateString(),
            'departure_time'         => '16:40',
            'arrival_time'           => '13:35',
            'trip_type'              => 'one_way',
            'currency'               => 'KWD',
            'foreign_currency'       => 'KWD',
            'exchange_rate'          => $exchangeRate,
            'purchase_price_foreign' => $purchasePriceKwd,
            'selling_price'          => $sellingPriceEgp, // EGP per Vue label (الـ fix الجديد)
            'flight_system_id'       => $this->flightSystem->id,
            'flight_carrier_id'      => $this->kwdCarrier->id,
            'purchase_balance_source'=> 'carrier',
            'pnr'                    => 'REPROKWD1',
            'passengers'             => [
                ['first_name' => 'KWD', 'last_name' => 'Test', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount'         => $sellingPriceEgp, // 1,360,000 EGP
                'account_id'     => $this->egpCashbox->id,
                'payment_method' => 'cash',
            ],
        ]);

        // ── ASSERT booking persisted with the right currency metadata ──
        $this->assertInstanceOf(FlightBooking::class, $booking);
        $this->assertEquals('KWD', $booking->currency);
        $this->assertEquals(
            $sellingPriceEgp,
            (float) $booking->selling_price,
            'selling_price stored AS-IS in EGP (1,360,000) — backend must NOT multiply by exchange_rate'
        );
        $this->assertEquals($purchasePriceEgp, (float) $booking->purchase_price, 'purchase_price stored in EGP');

        // ── ASSERT customer-payment metadata points at EGP (the actual paid currency) ──
        $this->assertEquals(
            'EGP',
            $booking->original_currency,
            'original_currency must reflect the customer\'s actual payment currency (EGP), not the booking currency (KWD)'
        );
        $this->assertEquals(
            $sellingPriceKwd,
            (float) $booking->original_amount,
            'original_amount must be in booking currency (8500 KWD), not payment currency (1,360,000 EGP). '
                .'RefundService uses this value as the booking-currency refund cap.'
        );

        // ── ASSERT the FlightPayment row carries the actual EGP payment info ──
        $payment = $booking->payments()->latest('id')->first();
        $this->assertNotNull($payment, 'A FlightPayment row must be created for the initial payment');
        $this->assertEquals('EGP', $payment->currency, 'payment.currency records the actual payment currency');
        $this->assertEquals(
            $sellingPriceEgp,
            (float) $payment->amount,
            'payment.amount must equal the EGP amount paid (1,360,000)'
        );
        $this->assertEquals(
            $sellingPriceEgp,
            (float) $payment->original_amount,
            'payment.original_amount preserves the EGP amount paid for refund traceability'
        );
        $this->assertEquals($this->egpCashbox->id, $payment->account_id);

        // ── ASSERT the EGP cashbox actually received the payment ──
        $this->assertEquals(
            round($beforeCashbox + $sellingPriceEgp, 2),
            round((float) $this->egpCashbox->fresh()->balance, 2),
            'Cashbox balance must increase by the EGP amount paid'
        );

        // ── ASSERT the customer AR is balanced (sale debit == payment credit, both in EGP) ──
        $ar = Account::find($this->customer->account_id);
        $this->assertEquals(0.0, round((float) $ar->balance, 2),
            'Customer AR (EGP) must net to zero — sale debit cancels payment credit');
    }

    public function test_kwd_booking_paid_from_egp_with_partial_amount_records_correct_original_amount(): void
    {
        // User pays half in EGP — original_amount must still be the KWD equivalent.
        $exchangeRate = 160.0;
        $sellingPriceEgp = 1360000.0;
        $partialEgp = $sellingPriceEgp / 2; // 680,000 EGP
        $expectedOriginalKwd = $partialEgp / $exchangeRate; // 4250 KWD

        $booking = $this->bookingService->createBooking([
            'customer_id'            => $this->customer->id,
            'airline_name'           => 'الجزيرة (Test)',
            'from_airport'           => 'SPX',
            'to_airport'             => 'CAI',
            'departure_date'         => now()->addDays(7)->toDateString(),
            'trip_type'              => 'one_way',
            'currency'               => 'KWD',
            'exchange_rate'          => $exchangeRate,
            'purchase_price_foreign' => 50.0,
            'selling_price'          => $sellingPriceEgp, // EGP
            'flight_carrier_id'      => $this->kwdCarrier->id,
            'purchase_balance_source'=> 'carrier',
            'passengers'             => [
                ['first_name' => 'Partial', 'last_name' => 'Pay', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount'     => $partialEgp,
                'account_id' => $this->egpCashbox->id,
            ],
        ]);

        $this->assertEquals('EGP', $booking->original_currency);
        $this->assertEqualsWithDelta(
            $expectedOriginalKwd,
            (float) $booking->original_amount,
            0.01,
            'Partial EGP payment must convert to the matching KWD original_amount (4250 KWD)'
        );
    }

    public function test_kwd_booking_paid_from_mismatched_foreign_currency_still_rejected(): void
    {
        // A SAR account paying for a KWD booking should still be rejected — only
        // the EGP-customer-AR path is allowed to bypass the strict currency check.
        $sarCashbox = Account::create([
            'name' => 'KWD Test SAR Cashbox',
            'type' => 'cashbox',
            'balance' => 5000000,
            'currency' => 'SAR',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/لا تطابق عملة الحجز/');

        $this->bookingService->createBooking([
            'customer_id'            => $this->customer->id,
            'airline_name'           => 'الجزيرة (Test)',
            'from_airport'           => 'SPX',
            'to_airport'             => 'CAI',
            'departure_date'         => now()->addDays(7)->toDateString(),
            'trip_type'              => 'one_way',
            'currency'               => 'KWD',
            'exchange_rate'          => 160.0,
            'purchase_price_foreign' => 50.0,
            'selling_price'          => 8500.0,
            'flight_carrier_id'      => $this->kwdCarrier->id,
            'purchase_balance_source'=> 'carrier',
            'passengers'             => [
                ['first_name' => 'Mismatch', 'last_name' => 'Test', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount'     => 8500.0 * 12.7, // arbitrary SAR amount
                'account_id' => $sarCashbox->id,
            ],
        ]);
    }
}