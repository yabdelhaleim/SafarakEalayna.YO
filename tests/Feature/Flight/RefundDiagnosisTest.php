<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Treasury;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تشخيص صارم لـ RefundService::processRefundRequest + reverseRefundRequest
 * (branch=agency_treasury) — كل assertion بـ assertEqualsWithDelta(0.0, ..., 0.01, ...)
 *
 * Scenarios:
 *   1) EGP booking, paid in full, full refund → كل البالانسات ترجع 0/initial
 *   2) EGP booking, paid in full, partial refund (cancellation_fee > 0) → البالانسات صحيحة
 *   3) refund → reverse → البالانسات ترجع EXACTLY كما كانت قبل الـ refund
 *   4) partial payment + partial refund + reverse → البالانسات صحيحة
 */
class RefundDiagnosisTest extends TestCase
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
            'name' => 'Diag Admin',
            'email' => 'diag-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Diag Customer',
            'phone' => '0123456789',
            'email' => 'diag-customer@test.com',
            'national_id' => '11122233344455',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Diag System',
            'code' => 'DIAG'.substr(md5((string) microtime(true)), 0, 4),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Diag Airline',
            'code' => 'DIAGA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Diag Cashbox',
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
            'Diag setup'
        );
        $this->cashbox->refresh();

        $this->treasury = Treasury::create([
            'name' => 'Diag Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        // إنشاء Account بنفس اسم الـ treasury عشان الـ resolveCashboxAccount يلاقيه
        // (في الإنتاج الـ Treasury عادةً يكون مرتبط بـ Account بنفس الاسم)
        $treasuryAccount = Account::create([
            'name' => 'Diag Treasury',
            'type' => 'cashbox',
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // استبدل الـ cashbox بالـ treasury account عشان التيست يفحص الحساب اللي الـ refund بيخصم منه فعلاً
        $this->cashbox = $treasuryAccount;
    }

    /**
     * Helper: أنشئ حجز مدفوع بالكامل وحدّث الـ sale_gl_transaction_id
     */
    protected function createFullyPaidBooking(int $selling, int $purchase): FlightBooking
    {
        $booking = $this->bookingService->createBooking([
            'customer_id'       => $this->customer->id,
            'airline_name'      => 'Diag Airline',
            'from_airport'      => 'CAI',
            'to_airport'        => 'DXB',
            'departure_date'    => now()->addDays(7)->toDateString(),
            'trip_type'         => 'one_way',
            'currency'          => 'EGP',
            'purchase_price'    => $purchase,
            'selling_price'     => $selling,
            'flight_carrier_id' => $this->carrier->id,
            'account_id'        => $this->cashbox->id,
            'passengers'        => [
                ['name' => 'Test Pax', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount'         => $selling,
            'payment_method' => 'cash',
            'account_id'     => $this->cashbox->id,
            'notes'          => 'Paid in full',
        ]);

        return $booking->refresh();
    }

    /**
     * [1] EGP booking paid in full → full refund → كل البالانسات ترجع 0/initial
     */
    public function test_full_refund_egp_restores_every_balance_to_zero(): void
    {
        $selling = 10000;
        $purchase = 8000;
        $cancellationFee = 0;
        $refundAmount = $selling - $cancellationFee; // 10000
        $purchaseNet = max(0, $purchase - $cancellationFee); // 8000

        $booking = $this->createFullyPaidBooking($selling, $purchase);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Snapshot قبل الـ refund
        $before = [
            'carrier'  => (float) $this->carrier->fresh()->balance,
            'cashbox'  => (float) $this->cashbox->fresh()->balance,
            'treasury' => (float) $this->treasury->fresh()->current_balance,
        ];

        // Act: refund كامل
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => $cancellationFee,
            'refund_currency'   => 'EGP',
            'destination'       => 'agency_treasury',
            'treasury_id'       => $this->treasury->id,
        ], $this->admin->id);
        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // Assert: كل البالانسات ترجع 0/initial
        $customerAccountId = (int) $this->customer->fresh()->account_id;
        $customerBalance = (float) DB::table('accounts')->where('id', $customerAccountId)->value('balance');
        $clearingBalance = (float) DB::table('accounts')->where('id', Transaction::find($booking->sale_gl_transaction_id)->from_account_id)->value('balance');

        $this->assertEqualsWithDelta(0.0, $customerBalance, 0.01,
            'customer.account.balance يجب أن يبقى 0 (مدفوع خلاص)');
        $this->assertEqualsWithDelta(0.0, $clearingBalance, 0.01,
            'clearing_account.balance يجب أن يعود لـ 0 (الإيراد اتمسح)');
        $this->assertEqualsWithDelta($before['cashbox'] - $refundAmount, $this->cashbox->fresh()->balance, 0.01,
            'cashbox.Account.balance يجب أن يُخصم منه refundAmount');
        $this->assertEqualsWithDelta($before['carrier'] + $purchaseNet, $this->carrier->fresh()->balance, 0.01,
            'carrier.balance يجب أن يُكرتَد بـ purchaseNet (إرجاع الرصيد)');
        $this->assertEqualsWithDelta($before['treasury'], $this->treasury->fresh()->current_balance, 0.01,
            'treasury.current_balance لا يجب أن يتغير');
    }

    /**
     * [2] EGP booking paid in full → partial refund (cancellation_fee > 0)
     * السلوك: carrier يُكرتَد بـ purchaseNet, clearing يُمسح بـ refundAmount (مش كامل الـ selling)
     */
    public function test_full_refund_with_cancellation_fee(): void
    {
        $selling = 10000;
        $purchase = 8000;
        $cancellationFee = 2000;
        $refundAmount = $selling - $cancellationFee;        // 8000
        $purchaseNet = max(0, $purchase - $cancellationFee); // 6000

        $booking = $this->createFullyPaidBooking($selling, $purchase);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $before = [
            'cashbox'  => (float) $this->cashbox->fresh()->balance,
            'carrier'  => (float) $this->carrier->fresh()->balance,
        ];

        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => $cancellationFee,
            'refund_currency'   => 'EGP',
            'destination'       => 'agency_treasury',
            'treasury_id'       => $this->treasury->id,
        ], $this->admin->id);
        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        $clearingBalance = (float) DB::table('accounts')->where('id', Transaction::find($booking->sale_gl_transaction_id)->from_account_id)->value('balance');

        // clearing لازم يكون -(selling - refundAmount) = -(10000 - 8000) = -2000 (رسوم الإلغاء المتبقية كإيراد)
        $this->assertEqualsWithDelta(-$cancellationFee, $clearingBalance, 0.01,
            "clearing.balance يجب أن يكون -{$cancellationFee} (إيراد رسوم الإلغاء المحفوظ)");

        // carrier: +purchaseNet (6000)
        $this->assertEqualsWithDelta($before['carrier'] + $purchaseNet, $this->carrier->fresh()->balance, 0.01,
            "carrier يجب أن يُكرتَد بـ purchaseNet ({$purchaseNet})");

        // cashbox: -refundAmount
        $this->assertEqualsWithDelta($before['cashbox'] - $refundAmount, $this->cashbox->fresh()->balance, 0.01,
            "cashbox يجب أن يُخصم منه refundAmount ({$refundAmount})");
    }

    /**
     * [3] refund → reverse → البالانسات ترجع EXACTLY كما كانت قبل الـ refund
     */
    public function test_refund_then_reverse_restores_pre_refund_state(): void
    {
        $selling = 10000;
        $purchase = 8000;
        $cancellationFee = 0;
        $refundAmount = $selling;
        $purchaseNet = $purchase;

        $booking = $this->createFullyPaidBooking($selling, $purchase);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $before = [
            'carrier'  => (float) $this->carrier->fresh()->balance,
            'cashbox'  => (float) $this->cashbox->fresh()->balance,
            'treasury' => (float) $this->treasury->fresh()->current_balance,
        ];

        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => $cancellationFee,
            'refund_currency'   => 'EGP',
            'destination'       => 'agency_treasury',
            'treasury_id'       => $this->treasury->id,
        ], $this->admin->id);
        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // Reverse الـ refund
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // Assert: البالانسات ترجع EXACTLY كما كانت قبل الـ refund (delta = 0)
        $this->assertEqualsWithDelta(0.0, $before['carrier'] - $this->carrier->fresh()->balance, 0.01,
            'carrier.balance delta يجب أن يكون 0 بعد reverse');
        $this->assertEqualsWithDelta(0.0, $before['cashbox'] - $this->cashbox->fresh()->balance, 0.01,
            'cashbox.balance delta يجب أن يكون 0 بعد reverse');
        $this->assertEqualsWithDelta(0.0, $before['treasury'] - $this->treasury->fresh()->current_balance, 0.01,
            'treasury.current_balance delta يجب أن يكون 0 بعد reverse');
    }

    /**
     * [4] partial payment + partial refund + reverse → كل الأرقام صحيحة
     */
    public function test_refund_then_reverse_with_partial_payment(): void
    {
        $selling = 10000;
        $purchase = 8000;
        $cancellationFee = 1000;
        $refundAmount = $selling - $cancellationFee;        // 9000
        $purchaseNet = max(0, $purchase - $cancellationFee); // 7000

        $booking = $this->bookingService->createBooking([
            'customer_id'       => $this->customer->id,
            'airline_name'      => 'Diag Airline',
            'from_airport'      => 'CAI',
            'to_airport'        => 'DXB',
            'departure_date'    => now()->addDays(7)->toDateString(),
            'trip_type'         => 'one_way',
            'currency'          => 'EGP',
            'purchase_price'    => $purchase,
            'selling_price'     => $selling,
            'flight_carrier_id' => $this->carrier->id,
            'account_id'        => $this->cashbox->id,
            'passengers'        => [
                ['name' => 'Test Pax', 'type' => 'adult'],
            ],
        ]);

        // دفع كامل (full payment حتى لو cancellation_fee > 0, العميل دفع الـ selling كله)
        $this->bookingService->addPayment($booking, [
            'amount'         => $selling,
            'payment_method' => 'cash',
            'account_id'     => $this->cashbox->id,
            'notes'          => 'Paid in full',
        ]);

        $booking = $booking->refresh();
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $before = [
            'carrier'  => (float) $this->carrier->fresh()->balance,
            'cashbox'  => (float) $this->cashbox->fresh()->balance,
        ];

        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => $cancellationFee,
            'refund_currency'   => 'EGP',
            'destination'       => 'agency_treasury',
            'treasury_id'       => $this->treasury->id,
        ], $this->admin->id);
        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // بعد الـ refund
        $this->assertEqualsWithDelta($before['cashbox'] - $refundAmount, $this->cashbox->fresh()->balance, 0.01,
            "cashbox بعد refund: خُصم منه refundAmount ({$refundAmount})");
        $this->assertEqualsWithDelta($before['carrier'] + $purchaseNet, $this->carrier->fresh()->balance, 0.01,
            "carrier بعد refund: +purchaseNet ({$purchaseNet})");

        // Reverse
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // بعد reverse: البالانسات ترجع كما كانت
        $this->assertEqualsWithDelta($before['cashbox'], $this->cashbox->fresh()->balance, 0.01,
            'cashbox بعد reverse: رجع لـ before.cashbox');
        $this->assertEqualsWithDelta($before['carrier'], $this->carrier->fresh()->balance, 0.01,
            'carrier بعد reverse: رجع لـ before.carrier');
    }
}