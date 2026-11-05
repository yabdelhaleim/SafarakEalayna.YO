<?php

namespace Tests\Feature\Flight;

use App\Enums\CustomerType;
use App\Enums\FlightBookingStatus;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPaymentAllocation;
use Tests\Audit\AuditTestCase;

class RealWorldRefundScenariosTest extends AuditTestCase
{
    /**
     * السيناريو 1: عميل كونتر - إلغاء برغبة العميل مع صرف نقدي فوري من الخزينة.
     * الرحلة: 7,000 ج.م - غرامة طيران: 4,000 ج.م - عمولة مكتب: 200 ج.م
     * صافي المسترد للعميل: 2,800 ج.م نقداً من الخزينة.
     */
    public function test_scenario_1_counter_customer_cash_refund(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 7000.0);

        $booking->refresh();
        $this->assertEquals(7000.0, (float) $booking->paid_amount);

        $cashBeforeCancel = (float) $this->cashbox->fresh()->balance;

        // تنفيذ الإلغاء مع صرف نقدي
        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'cash',
            'airline_penalty' => 4000.0,
            'office_penalty' => 200.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'إلغاء برغبة العميل وصرف نقدي',
        ]);

        $this->assertEquals(2800.0, (float) $refund->refund_amount);
        $this->assertEquals(FlightBookingStatus::REFUNDED, $booking->fresh()->status);

        // الخزينة انخفضت بمقدار 2,800 ج.م
        $cashAfterCancel = (float) $this->cashbox->fresh()->balance;
        $this->assertEqualsWithDelta($cashBeforeCancel - 2800.0, $cashAfterCancel, 0.01);
    }

    /**
     * السيناريو 2: عميل كونتر - إلغاء برغبة العميل مع إضافة المبلغ كرصيد دائن (أمانات).
     * صافي المسترد: 2,800 ج.م يضاف لدفتر الرصيد الدائن دون خروج مليم من الخزينة.
     */
    public function test_scenario_2_counter_customer_credit_ledger(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 7000.0);

        $booking->refresh();
        $cashBeforeCancel = (float) $this->cashbox->fresh()->balance;

        // تنفيذ الإلغاء مع اختيار customer_credit
        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'customer_credit',
            'airline_penalty' => 4000.0,
            'office_penalty' => 200.0,
            'notes' => 'إلغاء برغبة العميل وإضافة كرصيد دائن',
        ]);

        $this->assertEquals(2800.0, (float) $refund->refund_amount);
        $this->assertNull($refund->account_id, 'لا حساب صرف عند الإضافة لرصيد العميل');

        // الخزينة لم ينقص منها مليم واحد
        $cashAfterCancel = (float) $this->cashbox->fresh()->balance;
        $this->assertEqualsWithDelta($cashBeforeCancel, $cashAfterCancel, 0.01);

        // الرصيد الدائن للعميل في customer_credit_ledger أصبح 2,800 ج.م
        $credit = CustomerCreditLedger::getAvailableCredit($booking->customer_id);
        $this->assertEqualsWithDelta(2800.0, $credit, 0.01);
    }

    /**
     * السيناريو 3: عميل شركات - تسوية مديونية الشركة (خصم من ديون حجز قديم FIFO).
     * الشركة عليها حجز قديم متبقي عليه 5,000 ج.م.
     * الحجز الملغي: مسترد 2,800 ج.م.
     * يتم تخصيص 2,800 لسداد جزء من الحجز القديم فيصبح المتبقي عليه 2,200 ج.م.
     */
    public function test_scenario_3_corporate_customer_fifo_debt_offset(): void
    {
        // إنشاء شركة
        $company = Customer::create([
            'full_name' => 'شركة النور للسياحة',
            'phone' => '0100000009',
            'type' => CustomerType::Company,
        ]);

        // حجز سابق غير مسدد بالكامل (دين مفتوح)
        $oldBooking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 10000.0,
            'purchase_price' => 8000.0,
        ]);
        $this->payBooking($oldBooking, 5000.0); // دفع 5000 فقط، تبقى 5000 دين
        $oldBooking->refresh();
        $this->assertEquals(5000.0, (float) $oldBooking->remaining_amount);

        // حجز جديد للشركة تم سداده بالكامل
        $currentBooking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($currentBooking, 7000.0);

        $cashBeforeCancel = (float) $this->cashbox->fresh()->balance;

        // إلغاء الحجز الجديد مع اختيار company_offset
        $refund = $this->bookingService->cancelBooking($currentBooking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'company_offset',
            'airline_penalty' => 4000.0,
            'office_penalty' => 200.0,
            'notes' => 'تسوية ديون الشركة',
        ]);

        $this->assertEquals(2800.0, (float) $refund->refund_amount);

        // لم يخرج نقد من الخزينة
        $cashAfterCancel = (float) $this->cashbox->fresh()->balance;
        $this->assertEqualsWithDelta($cashBeforeCancel, $cashAfterCancel, 0.01);

        // الحجز القديم تم تخفيض دينه بـ 2800 ج.م ليصبح المتبقي 2200 ج.م
        $oldBooking->refresh();
        $this->assertEqualsWithDelta(2200.0, (float) $oldBooking->remaining_amount, 0.01);

        // التحقق من إنشاء تخصيص نشط
        $allocation = FlightPaymentAllocation::where('flight_booking_id', $oldBooking->id)->first();
        $this->assertNotNull($allocation);
        $this->assertEquals(2800.0, (float) $allocation->amount);
        $this->assertEquals('active', $allocation->status);
    }

    /**
     * السيناريو 4: عميل شركات - بدون ديون سابقة (تسوية الديون تنتج رصيداً دائناً).
     * الشركة ليس عليها أي ديون مفتوحة.
     * الحجز الملغي: مسترد 2,800 ج.م.
     * ينتقل الـ 2,800 ج.م إلى رصيد الشركة الدائن بالكامل.
     */
    public function test_scenario_4_corporate_customer_zero_debt_adds_credit(): void
    {
        $company = Customer::create([
            'full_name' => 'شركة الأفق للاستثمار',
            'phone' => '0100000008',
            'type' => CustomerType::Company,
        ]);

        $booking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 7000.0);

        $cashBeforeCancel = (float) $this->cashbox->fresh()->balance;

        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'company_offset',
            'airline_penalty' => 4000.0,
            'office_penalty' => 200.0,
        ]);

        $this->assertEquals(2800.0, (float) $refund->refund_amount);

        // لم يخرج نقد
        $cashAfterCancel = (float) $this->cashbox->fresh()->balance;
        $this->assertEqualsWithDelta($cashBeforeCancel, $cashAfterCancel, 0.01);

        // رصيد الشركة الدائن أصبح 2800
        $credit = CustomerCreditLedger::getAvailableCredit($company->id, $company->id);
        $this->assertEqualsWithDelta(2800.0, $credit, 0.01);
    }

    /**
     * السيناريو 5: إلغاء من شركة الطيران (Airline Cancellation).
     * غرامة الطيران تُقفل تلقائياً على صفر، والناقل يسترد تكلفة الشراء بالكامل،
     * والعميل يسترد كامل مبلغه (مخصوماً منه عمولة المكتب فقط إن وُجدت).
     */
    public function test_scenario_5_airline_cancelled_forces_zero_penalty(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 7000.0);

        $carrierBalanceBefore = (float) $this->carrier->fresh()->available_balance;

        // حتى لو أرسل المتصل غرامة طيران بالخطأ، يتم قفلها على 0
        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'airline_cancelled',
            'destination' => 'cash',
            'airline_penalty' => 9999.0, // يجب أن يتم تجاهلها وقفلها على 0
            'office_penalty' => 200.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'إلغاء الرحلة من الخط الجوي',
        ]);

        // غرامة الطيران يجب أن تكون 0
        $this->assertEquals(0.0, (float) $refund->airline_penalty);
        $this->assertEquals(200.0, (float) $refund->office_penalty);

        // المبلغ المرتجع = 7000 - 0 - 200 = 6800
        $this->assertEquals(6800.0, (float) $refund->refund_amount);

        // الناقل استرد كامل تكلفة الشراء (5000)
        $carrierBalanceAfter = (float) $this->carrier->fresh()->available_balance;
        $this->assertEqualsWithDelta($carrierBalanceBefore + 5000.0, $carrierBalanceAfter, 0.01);
    }
}
