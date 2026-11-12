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

        $this->assertBalanced('Scenario 1: Counter cash refund');
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

        $this->assertBalanced('Scenario 2: Counter credit refund');
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

        $this->assertBalanced('Scenario 3: Corporate FIFO offset');
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

        $this->assertBalanced('Scenario 4: Corporate zero debt adds credit');
    }

    /**
     * السيناريو 5أ: إلغاء من شركة الطيران باسترجاع كامل (بدون أي خصم/ضرائب).
     * غرامة الطيران = 0 افتراضياً، والناقل يسترد تكلفة الشراء بالكامل،
     * والعميل يسترد كامل مبلغه (مخصوماً منه عمولة المكتب فقط إن وُجدت).
     */
    public function test_scenario_5a_airline_cancelled_full_refund_zero_penalty(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 7000.0);

        $carrierBalanceBefore = (float) $this->carrier->fresh()->available_balance;

        // استرجاع كامل من الطيران (airline_penalty = 0)
        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'airline_cancelled',
            'destination' => 'cash',
            'airline_penalty' => 0.0,
            'office_penalty' => 200.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'إلغاء الرحلة من الخط الجوي بدون خصم',
        ]);

        $this->assertEquals(0.0, (float) $refund->airline_penalty);
        $this->assertEquals(200.0, (float) $refund->office_penalty);

        // المبلغ المرتجع = 7000 - 0 - 200 = 6800
        $this->assertEquals(6800.0, (float) $refund->refund_amount);

        // الناقل استرد كامل تكلفة الشراء (5000)
        $carrierBalanceAfter = (float) $this->carrier->fresh()->available_balance;
        $this->assertEqualsWithDelta($carrierBalanceBefore + 5000.0, $carrierBalanceAfter, 0.01);

        $this->assertBalanced('Scenario 5A: Airline cancellation full refund');
    }

    /**
     * السيناريو 5ب: إلغاء من شركة الطيران مع خصم ضرائب غير مستردة (الواقع العملي الجديد).
     * التذكرة: سعر بيع 7,000 ج.م، شراء 5,000 ج.م.
     * الطيران ألغى الرحلة وخصم ضرائب غير مستردة قدرها 400 ج.م.
     * الناقل يسترد فقط: 5,000 - 400 = 4,600 ج.م.
     * العميل يسترد: 7,000 - 400 - عمولة مكتب (0) = 6,600 ج.م.
     * ميزان المراجعة متزن تماماً 0.00%.
     */
    public function test_scenario_5b_airline_cancelled_with_non_refundable_taxes(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 7000.0);

        $carrierBalanceBefore = (float) $this->carrier->fresh()->available_balance;

        // الطيران خصم ضرائب 400 ج.م
        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'airline_cancelled',
            'destination' => 'cash',
            'airline_penalty' => 400.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'إلغاء من الطيران مع خصم ضرائب غير مستردة 400 ج.م',
        ]);

        $this->assertEquals(400.0, (float) $refund->airline_penalty);
        $this->assertEquals(0.0, (float) $refund->office_penalty);

        // العميل استرد 6600 (7000 - 400)
        $this->assertEquals(6600.0, (float) $refund->refund_amount);

        // الناقل استرد الصافي الفعلي: 5000 - 400 = 4600 ج.م
        $carrierBalanceAfter = (float) $this->carrier->fresh()->available_balance;
        $this->assertEqualsWithDelta($carrierBalanceBefore + 4600.0, $carrierBalanceAfter, 0.01);

        $this->assertBalanced('Scenario 5B: Airline cancellation with non-refundable taxes');
    }

    /**
     * السيناريو 6: تسوية ديون متعددة بنظام FIFO على طابور حجوزات للشركة.
     * الشركة لديها 3 حجوزات مدينة:
     * - الحجز 1: متبقي 1,500 ج.م
     * - الحجز 2: متبقي 2,000 ج.م
     * - الحجز 3: متبقي 5,000 ج.م
     * الحجز الملغي يسترد 2,800 ج.م.
     * التوزيع (FIFO):
     * - الحجز 1 يُسدد بالكامل (1,500) ويصبح المتبقي 0.
     * - الحجز 2 يُسدد منه 1,300 ويتبقى عليه 700 ج.م.
     * - الحجز 3 يبقى كما هو (5,000 ج.م).
     */
    public function test_scenario_6_corporate_multi_debt_fifo_queue(): void
    {
        $company = Customer::create([
            'full_name' => 'شركة الأهرام للسياحة',
            'phone' => '0100000010',
            'type' => CustomerType::Company,
        ]);

        // حجز 1: 3000 سدد 1500 => باقي 1500
        $b1 = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 3000.0,
            'purchase_price' => 2000.0,
        ]);
        $this->payBooking($b1, 1500.0);

        // حجز 2: 4000 سدد 2000 => باقي 2000
        $b2 = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 4000.0,
            'purchase_price' => 2500.0,
        ]);
        $this->payBooking($b2, 2000.0);

        // حجز 3: 5000 لم يسدد => باقي 5000
        $b3 = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 5000.0,
            'purchase_price' => 3500.0,
        ]);

        // حجز ملغي بقيمة 7000 ومسدد بالكامل
        $cancelBooking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($cancelBooking, 7000.0);

        // إلغاء واسترداد 2800 (غرامة 4000 + عمولة 200)
        $refund = $this->bookingService->cancelBooking($cancelBooking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'company_offset',
            'airline_penalty' => 4000.0,
            'office_penalty' => 200.0,
        ]);

        $this->assertEquals(2800.0, (float) $refund->refund_amount);

        // التحقق من الحجز 1: سُدد بالكامل
        $b1->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $b1->remaining_amount, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $b1->paid_amount, 0.01);

        // التحقق من الحجز 2: خُصم 1300 وبقي 700
        $b2->refresh();
        $this->assertEqualsWithDelta(700.0, (float) $b2->remaining_amount, 0.01);
        $this->assertEqualsWithDelta(3300.0, (float) $b2->paid_amount, 0.01);

        // التحقق من الحجز 3: لم يتغير
        $b3->refresh();
        $this->assertEqualsWithDelta(5000.0, (float) $b3->remaining_amount, 0.01);

        // التحقق من إنشاء تخصيصين دقيقين
        $this->assertEquals(1500.0, (float) FlightPaymentAllocation::where('flight_booking_id', $b1->id)->value('amount'));
        $this->assertEquals(1300.0, (float) FlightPaymentAllocation::where('flight_booking_id', $b2->id)->value('amount'));

        $this->assertBalanced('Scenario 6: Multi-booking FIFO queue');
    }

    /**
     * السيناريو 7: استرداد للشركة يتجاوز كامل مديونياتها المفتوحة.
     * الشركة عليها حجز قديم متبقي عليه 1,000 ج.م فقط.
     * الحجز الملغي مسترد منه 3,500 ج.م.
     * يُسدد الـ 1,000 ج.م للحجز القديم بالكامل، والـ 2,500 المتبقية تُودع في رصيد الشركة الدائن.
     */
    public function test_scenario_7_corporate_refund_exceeds_all_debts_remainder_to_credit(): void
    {
        $company = Customer::create([
            'full_name' => 'شركة طيبة الدولية',
            'phone' => '0100000011',
            'type' => CustomerType::Company,
        ]);

        $oldBooking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 3000.0,
            'purchase_price' => 2000.0,
        ]);
        $this->payBooking($oldBooking, 2000.0); // متبقي 1000

        $cancelBooking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($cancelBooking, 7000.0);

        // استرداد 3500 (غرامة 3300 + عمولة 200)
        $refund = $this->bookingService->cancelBooking($cancelBooking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'company_offset',
            'airline_penalty' => 3300.0,
            'office_penalty' => 200.0,
        ]);

        $this->assertEquals(3500.0, (float) $refund->refund_amount);

        // الحجز القديم سُدد بالكامل
        $oldBooking->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $oldBooking->remaining_amount, 0.01);

        // الرصيد الدائن المتبقي = 2500 ج.م
        $credit = CustomerCreditLedger::getAvailableCredit($company->id, $company->id);
        $this->assertEqualsWithDelta(2500.0, $credit, 0.01);

        $this->assertBalanced('Scenario 7: Debt offset with remainder to credit');
    }

    /**
     * السيناريو 8: حجز باسم فرد/موظف تابع لشركة وإلغاء مع خيار company_offset.
     * الموظف تابع للشركة عبر company_id.
     * الشركة عليها حجز قديم متبقي عليه 4,000 ج.م.
     * حجز الموظف أُلغي واسترداد 3,000 ج.م.
     * يجب أن يتم ربط وتخفيض دين الشركة القديم ليصبح 1,000 ج.م.
     */
    public function test_scenario_8_company_employee_booking_offsets_company_debt(): void
    {
        $company = Customer::create([
            'full_name' => 'شركة المتحدة للتجارة',
            'phone' => '0100000012',
            'type' => CustomerType::Company,
        ]);

        $employee = Customer::create([
            'full_name' => 'أحمد مندوب الشركة',
            'phone' => '0100000013',
            'type' => CustomerType::Individual,
            'company_id' => $company->id,
        ]);

        // حجز الشركة القديم
        $companyOldBooking = $this->makeBooking([
            'customer_id' => $company->id,
            'selling_price' => 8000.0,
            'purchase_price' => 6000.0,
        ]);
        $this->payBooking($companyOldBooking, 4000.0); // متبقي 4000

        // حجز الموظف الجديد المسدد
        $empBooking = $this->makeBooking([
            'customer_id' => $employee->id,
            'selling_price' => 6000.0,
            'purchase_price' => 4500.0,
        ]);
        $this->payBooking($empBooking, 6000.0);

        // إلغاء حجز الموظف مع تسوية ديون الشركة
        $refund = $this->bookingService->cancelBooking($empBooking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'company_offset',
            'airline_penalty' => 2800.0,
            'office_penalty' => 200.0,
        ]);

        $this->assertEquals(3000.0, (float) $refund->refund_amount);

        // دين شركة قديم انخفض من 4000 إلى 1000
        $companyOldBooking->refresh();
        $this->assertEqualsWithDelta(1000.0, (float) $companyOldBooking->remaining_amount, 0.01);

        $this->assertBalanced('Scenario 8: Employee booking offsets company debt');
    }

    /**
     * السيناريو 9: إلغاء اختياري لحجز مدفوع جزئياً.
     * الحجز: سعر 8,000 ج.م - المدفوع: 3,000 ج.م فقط (المتبقي غير مسدد 5,000).
     * غرامة الطيران: 1,000 ج.م - عمولة المكتب: 200 ج.م
     * المسترد = المدفوع (3000) - الغرامة (1000) - العمولة (200) = 1,800 ج.م.
     * الخزينة يخرج منها 1,800 ج.م فقط (وليس 8,000 ولا 3,000).
     */
    public function test_scenario_9_partially_paid_voluntary_cancellation(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 8000.0,
            'purchase_price' => 6000.0,
        ]);
        $this->payBooking($booking, 3000.0); // سدد 3000 فقط

        $cashBefore = (float) $this->cashbox->fresh()->balance;

        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'cash',
            'airline_penalty' => 1000.0,
            'office_penalty' => 200.0,
            'account_id' => $this->cashbox->id,
        ]);

        $this->assertEquals(1800.0, (float) $refund->refund_amount);
        $this->assertEquals(FlightBookingStatus::REFUNDED, $booking->fresh()->status);

        // الخزينة ينقص منها 1800 فقط
        $cashAfter = (float) $this->cashbox->fresh()->balance;
        $this->assertEqualsWithDelta($cashBefore - 1800.0, $cashAfter, 0.01);

        $this->assertBalanced('Scenario 9: Partially paid voluntary cancel');
    }

    /**
     * السيناريو 10: إلغاء طيران لحجز مدفوع جزئياً بدون ضرائب غير مستردة.
     * الحجز: سعر 7,000 ج.م - مدفوع 2,500 ج.م.
     * استرجاع كامل من الطيران بدون غرامة (0.0)، والعميل يسترد كامل ما دفعه (2,500).
     */
    public function test_scenario_10_partially_paid_airline_cancelled(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 7000.0,
            'purchase_price' => 5000.0,
        ]);
        $this->payBooking($booking, 2500.0);

        $refund = $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'airline_cancelled',
            'destination' => 'cash',
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashbox->id,
        ]);

        $this->assertEquals(0.0, (float) $refund->airline_penalty);
        $this->assertEquals(2500.0, (float) $refund->refund_amount);

        $this->assertBalanced('Scenario 10: Partially paid airline cancel');
    }

    /**
     * السيناريو 11: دورة كاملة: استرداد لرصيد دائن -> سداد حجز جديد من الرصيد الدائن.
     * 1. العميل يلغي حجزاً ويحول 4,000 ج.م كرصيد دائن.
     * 2. العميل ينشئ حجزاً جديداً بقيمة 3,000 ج.م.
     * 3. يتم سداد الحجز الجديد من الرصيد الدائن للعميل عبر consumeCustomerCredit().
     * 4. الرصيد الدائن ينخفض من 4,000 إلى 1,000، والحجز الجديد يصبح مسدداً بالكامل.
     */
    public function test_scenario_11_full_cycle_refund_to_credit_then_pay_new_booking(): void
    {
        // 1. حجز وإلغاء لرصيد دائن
        $b1 = $this->makeBooking([
            'selling_price' => 6000.0,
            'purchase_price' => 4500.0,
        ]);
        $this->payBooking($b1, 6000.0);

        $this->bookingService->cancelBooking($b1, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'customer_credit',
            'airline_penalty' => 1800.0,
            'office_penalty' => 200.0,
        ]);

        $credit = CustomerCreditLedger::getAvailableCredit($this->customer->id);
        $this->assertEqualsWithDelta(4000.0, $credit, 0.01);

        // 2. حجز جديد للعميل
        $b2 = $this->makeBooking([
            'selling_price' => 3000.0,
            'purchase_price' => 2200.0,
        ]);
        $this->assertEquals(3000.0, (float) $b2->remaining_amount);

        // 3. سداد الحجز الجديد من الرصيد الدائن
        $this->bookingService->consumeCustomerCredit($b2, 3000.0, $this->admin->id);

        // 4. التحقق من الحسابات
        $b2->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $b2->remaining_amount, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $b2->paid_amount, 0.01);

        // الرصيد الدائن للعميل تبقى منه 1000
        $creditAfter = CustomerCreditLedger::getAvailableCredit($this->customer->id);
        $this->assertEqualsWithDelta(1000.0, $creditAfter, 0.01);

        $this->assertBalanced('Scenario 11: Refund to credit then consume credit');
    }

    /**
     * السيناريو 12: حماية الأمان المحاسبي - منع التكرار (Double-Refund Prevention).
     * محاولة إلغاء حجز ملغي مسبقاً يجب أن تُرفض فوراً وتمنع أي صرف نقدي أو رصيد مكرر.
     */
    public function test_scenario_12_double_cancellation_prevented(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 5000.0,
            'purchase_price' => 3500.0,
        ]);
        $this->payBooking($booking, 5000.0);

        // إلغاء أول
        $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'cash',
            'airline_penalty' => 1000.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashbox->id,
        ]);

        $this->assertEquals(FlightBookingStatus::REFUNDED, $booking->fresh()->status);

        // محاولة إلغاء ثانية للحجز نفسه
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('الحجز ملغي أو مسترد بالفعل');

        $this->bookingService->cancelBooking($booking, [
            'refund_case' => 'customer_voluntary',
            'destination' => 'cash',
            'airline_penalty' => 1000.0,
            'account_id' => $this->cashbox->id,
        ]);
    }
}
