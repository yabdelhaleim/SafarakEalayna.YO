# تحليل شامل لموديول الطيران - الفجوات وخطة التطوير

## تاريخ التحليل: 2026-05-03

---

## 📊 ملخص تنفيذي

تم إجراء تحليل شامل لموديول الطيران الحالي في مشروع SafarakEalayna. يوجد بنية جيدة بالفعل لكنها تحتاج إلى تطوير كبير لتلبية المتطلبات الجديدة.

---

## ✅ ما هو موجود حالياً

### 1. الجداول (Migrations)
- ✅ `flight_bookings` - جدول الحجوزات الرئيسي
- ✅ `flight_segments` - قطع الرحلة
- ✅ `flight_payments` - المدفوعات
- ✅ `flight_refunds` - الاسترجاعات
- ✅ `airline_accounts` - حسابات شركات الطيران
- ✅ `airline_transactions` - معاملات شركات الطيران
- ✅ `customers` - جدول العملاء
- ✅ `accounts` - جدول الحسابات المالية
- ✅ `treasury_transactions` - معاملات الخزنة

### 2. النماذج (Models)
- ✅ `FlightBooking` - مع كل العلاقات
- ✅ `FlightSegment` - قطع الرحلة
- ✅ `FlightPassenger` - المسافرون
- ✅ `FlightPayment` - المدفوعات
- ✅ `FlightRefund` - الاسترجاعات
- ✅ `AirlineAccount` - حسابات الشركات مع طرق debit/credit
- ✅ `AirlineTransaction` - معاملات الشركات

### 3. الـ Enums
- ✅ `FlightSystemType` - أنواع الأنظمة (Amadeus, NDC, شركات الطيران، المجموعات)
- ✅ `FlightBookingStatus` - حالات الحجز
- ✅ `FlightPaymentMethod` - طرق الدفع
- ✅ `Currency` - العملات (EGP, KWD, SAR, USD)
- ✅ `PassengerType` - أنواع المسافرين

### 4. الخدمات (Services)
- ✅ `FlightBookingService` - خدمة الحجوزات بكل المنطق
- ✅ `AviationService` - خدمة الطيران

### 5. الـ Controllers
- ✅ `FlightController` - إدارة الحجوزات
- ✅ `AirlineAccountController` - إدارة حسابات الشركات
- ✅ `AviationController` - عمليات الطيران

### 6. Filament Resources
- ✅ `FlightBookingResource` - (بسيط جداً يحتاج تطوير)

### 7. Vue Components & Pages
- ✅ `FlightIndex.vue` - صفحة القائمة
- ✅ `FlightCreate.vue` - صفحة الإنشاء
- ✅ `FlightShow.vue` - صفحة التفاصيل
- ✅ `FlightEdit.vue` - صفحة التعديل
- ✅ `CustomerSelect.vue` - اختيار العميل
- ✅ `PassengerForm.vue` - نموذج المسافرين
- ✅ `FlightSegmentForm.vue` - نموذج قطع الرحلة
- ✅ `PricingBox.vue` - عرض الأسعار
- ✅ `BookingSummary.vue` - ملخص الحجز
- ✅ `flightStore.js` - Store إدارة الحالة

---

## ❌ ما هو ناقص (يحتاج إضافة)

### 1. هيكل 3 مستويات (System → Carrier → Group)
**الوضع الحالي:** الـ `FlightSystemType` enum يحتوي على كل شيء ممزوج
**المطلوب:**
- ❌ جدول `flight_systems` للأنظمة (Amadeus, NDC, NDC X, TP3)
- ❌ جدول `flight_carriers` للشركات/الساكن (الجزيرة، العربية، نسما، أبو كابيرو)
- ❌ جدول `flight_groups` للمجموعات (الشعلة، فرياج، العلا، المهاجر)
- ❌ علاقة: system → has_many → carriers → has_many → groups
- ❌ ربط هذه الجداول في `flight_bookings` table

### 2. بيانات العميل المحدثة
**الوضع الحالي:** جدول `customers` موجود لكن ينقصه بعض الحقول
**المطلوب:**
- ✅ الاسم بالعربي (موجود: `full_name`)
- ✅ رقم الهاتف (موجود: `phone`)
- ✅ الرقم القومي (موجود: `national_id`)
- ⚠️ المدينة / المحافظة (موجود: `city` - يحتاج تحقق)
- ❌ التصنيف (عميل عادي / مميز / وكيل) - موجود `customer_tier` لكن يحتاج مراجعة
- ✅ الملاحظات (موجود: `notes`)

### 3. بيانات الرحلة المحسّنة
**الوضع الحالي:** الحقول الأساسية موجودة
**المطلوب:**
- ✅ من/إلى (موجود: `from_airport`, `to_airport`)
- ✅ التواريخ والأوقات (موجودة)
- ✅ رقم PNR (موجود: `pnr`)
- ❌ اسم أول بالإنجليزي (First Name) - يجب إضافته في `flight_passengers`
- ❌ اسم أخير بالإنجليزي (Last Name) - يجب إضافته في `flight_passengers`
- ✅ شركة الطيران (موجودة: `airline_name`)
- ✅ وزن الأمتعة (موجود: `baggage_allowance_kg`)
- ✅ نوع الرحلة (موجود: `trip_type`)

### 4. المسافرون بثلاثة أقسام منفصلة
**الوضع الحالي:** `flight_passengers` جدول موجود بسيط
**المطلوب:**
- ❌ فصل المسافرين إلى 3 repeaters منفصلة:
  - `passenger_type = 'adult'` (بالغ > 12 سنة)
  - `passenger_type = 'child'` (طفل 2-12 سنة)
  - `passenger_type = 'infant'` (رضيع < 2 سنة)
- ❌ حقول إضافية: `first_name_en`, `last_name_en`, `date_of_birth`

### 5. التسعير المزدوج (جنيه + عملة أجنبية)
**الوضع الحالي:** حقول السعر موجودة في `flight_bookings`
**المطلوب:**
- ✅ حقول الجنيه موجودة: `purchase_price`, `selling_price`, `profit`
- ❌ إضافة حقول العملة الأجنبية:
  - `foreign_currency` (KWD, SAR, USD, etc.)
  - `purchase_price_foreign` (السعر بالعملة الأجنبية)
  - `exchange_rate` (سعر الصرف المستخدم)
  - `purchase_price_egp` (السعر محول بالجنيه)
- ❌ منطق حساب تلقائي: `purchase_price_egp = purchase_price_foreign × exchange_rate`

### 6. طرق الدفع الخمسة
**الوضع الحالي:** `FlightPaymentMethod` enum موجود بـ 7 طرق
**المطلوب:**
- ✅ نقدي (cash) - موجود
- ✅ تحويل بنكي (bank_transfer) - موجود
- ✅ محفظة كاش (cash_wallet) - موجود
- ✅ بريد (postal_transfer) - موجود
- ✅ درج المكتب (office_drawer) - موجود
- ⚠️ يحتاج:
  - ❌ حقل `bank_name` في `flight_payments`
  - ❌ حقل `account_holder_name` في `flight_payments`
  - ❌ حقل `wallet_number` في `flight_payments`
  - ❌ حقل `wallet_holder` في `flight_payments`
  - ❌ ربط كل دفع بحساب خزنة محدد

### 7. الخزنة متعددة العملات (الأهم)
**الوضع الحالي:** جدول `accounts` و `treasury_transactions` موجودان
**المطلوب:**
- ✅ هيكل الحسابات موجود (cashbox, wallet, bank, treasury)
- ✅ دعم العملات موجود (currency field)
- ❌ **ينقص:**
  - فصل الحسابات حسب العملة (EGP treasury, KWD treasury, etc.)
  - حسابات لكل عملة:
    - نقدي مصري / نقدي دينار / نقدي ريال / نقدي دولار
    - بنك مصر / سفرك علينا (جاري) - لكل عملة
    - بنك مصر / يأسر محمود (فضي) - لكل عملة
    - بنك الأهلي / سفرك علينا - لكل عملة
    - بريد سفرك علينا - لكل عملة
    - كاش المكتب / درج المكتب - لكل عملة
  - ❌ سجل العمليات التفصيلي لكل حساب
  - ❌ عرض الرصيد الحالي + سجل العمليات

### 8. بيانات الموظف
**الوضع الحالي:** `employee_id` و `created_by` موجودان
**المطلوب:**
- ✅ اسم الموظف (موجود عبر العلاقة)
- ✅ الملاحظات (موجودة: `notes`)

### 9. طباعة التذكرة
**الوضع الحالي:** ❌ غير موجود
**المطلوب:**
- ❌ زر طباعة في Vue
- ❌ نافذة اختيار الحقول للطباعة
- ❌ تصميم print-friendly

### 10. قاعدة بيانات IATA للمطارات
**الوضع الحالي:** ❌ غير موجود
**المطلوب:**
- ❌ جدول `airports` يحتوي على:
  - `iata_code` (CAI, JED, KWI, etc.)
  - `city_name_ar` (القاهرة، جدة، الكويت, etc.)
  - `city_name_en` (Cairo, Jeddah, Kuwait, etc.)
  - `airport_name_ar`
  - `airport_name_en`
  - `country_code`
  - `country_name_ar`
  - `country_name_en`
- ❌ searchable select في Filament و Vue

### 11. Filament Resources المطلوبة
**الوضع الحالي:** `FlightBookingResource` موجود بسيط
**المطلوب:**
- ❌ `SystemResource` - إدارة السيستمات
- ❌ `CarrierResource` - إدارة الشركات/الساكن
- ❌ `GroupResource` - إدارة المجموعات
- ❌ `AirportResource` - إدارة المطارات
- ❌ تحديث `BookingResource` بشكل كامل:
  - Reactive forms (System → Carrier → Group)
  - Dynamic pricing form (EGP vs Foreign)
  - 3 Repeaters للمسافرين
  - Real-time profit calculation
  - Payment methods with extra fields

### 12. Vue Pages المطلوبة
**الوضع الحالي:** الصفحات الأساسية موجودة
**المطلوب:**
- ⚠️ تحديث `FlightIndex.vue`:
  - ❌ كروت لكل سيستم/شركة مع الرصيد
  - ✅ جدول الحجوزات موجود
  - ❌ فلاتر إضافية (نوع الرحلة، الشركة، السيستم، المجموعة)
- ⚠️ تحديث `FlightShow.vue`:
  - ✅ التفاصيل الأساسية موجودة
  - ❌ زر الطباعة
- ❌ صفحة جديدة: `TreasuryView.vue` - صفحة الخزنة بكل العملات

### 13. منطق الأتمتة
**الوضع الحالي:** بعض المنطق موجود في `FlightBookingService`
**المطلوب:**
- ⚠️ خصم سعر الشراء من رصيد الشركة - موجود جزئياً
- ❌ إضافة سعر البيع لحساب الخزنة المناسب
- ❌ تسجيل عملية دخول وخروج في سجل الخزنة
- ✅ حساب الربح - موجود
- ❌ تحديث الأرصدة في real-time في Vue

---

## 📋 خطة العمل المطلوبة

### المرحلة 1: Database & Models (الأساس)
1. ✅ إنشاء migrations للجداول الجديدة:
   - `flight_systems`
   - `flight_carriers`
   - `flight_groups`
   - `airports`
   - تحديث `flight_passengers` (إضافة first_name_en, last_name_en)
   - تحديث `flight_payments` (إضافة حقول إضافية)
   - تحديث `flight_bookings` (إضافة حقول العملة الأجنبية)

2. ✅ إنشاء/تحديث الـ Models:
   - `FlightSystem`
   - `FlightCarrier`
   - `FlightGroup`
   - `Airport`
   - تحديث `FlightPassenger`
   - تحديث `FlightPayment`

### المرحلة 2: Filament Resources (لوحة التحكم)
1. ✅ إنشاء Resources جديدة:
   - `SystemResource`
   - `CarrierResource`
   - `GroupResource`
   - `AirportResource`

2. ✅ تحديث `FlightBookingResource`:
   - Reactive selects (System → Carrier → Group)
   - Dynamic pricing form
   - 3 Repeaters للمسافرين
   - Payment methods مع حقول إضافية

### المرحلة 3: API & Services (الباك إند)
1. ✅ تحديث الـ Controllers:
   - إضافة endpoints للجداول الجديدة
   - تحديث booking endpoints

2. ✅ تحديث الـ Services:
   - `FlightBookingService` - إضافة منطق العملة الأجنبية
   - إنشاء `TreasuryService` - لإدارة الخزنة
   - تحديث منطق الخصم والإضافة

### المرحلة 4: Vue Frontend (الواجهة)
1. ✅ تحديث الصفحات:
   - `FlightIndex.vue` - إضافة الكروت والفلاتر
   - `FlightCreate.vue` - إضافة الـ 3 مستويات والـ 3 repeaters
   - `FlightShow.vue` - إضافة زر الطباعة

2. ✅ إنشاء صفحات جديدة:
   - `TreasuryView.vue` - صفحة الخزنة
   - `PrintTicketModal.vue` - نافذة الطباعة

3. ✅ تحديث الـ Store:
   - `flightStore.js` - إضافة البيانات الجديدة

### المرحلة 5: الاختبار (Validation)
1. ✅ اختبار جميع migrations
2. ✅ اختبار العلاقات بين Models
3. ✅ اختبار Filament Forms
4. ✅ اختبار API endpoints
5. ✅ اختبار Vue pages
6. ✅ اختبار العمليات الحسابية
7. ✅ اختبار الخزنة متعددة العملات

---

## 🎯 الأولويات

### عالية الأهمية (Critical):
1. ✅ هيكل 3 مستويات (System → Carrier → Group)
2. ✅ التسعير المزدوج (جنيه + عملة أجنبية)
3. ✅ الخزنة متعددة العملات
4. ✅ المسافرون بثلاثة أقسام منفصلة

### متوسطة الأهمية (Important):
5. ✅ تحديث بيانات العميل
6. ✅ طرق الدفع الخمسة مع الحقول الإضافية
7. ✅ Filament Resources المحسّنة
8. ✅ قاعدة بيانات IATA

### منخفضة الأهمية (Nice to have):
9. ✅ طباعة التذكرة
10. ✅ تحسينات Vue UI

---

## 📝 ملاحظات مهمة

1. **لا تحذف أي كود موجود** - نضيف فقط ولا نحذف
2. **نستخدم migrations جديدة** - لا نعدل migrations قديمة
3. **نحافظ على نفس أسلوب الكود** - نتبع pattern الموجود
4. **كل شيء من الداتابيز** - لا يوجد hardcoded data
5. **الخزنة هي الأساس** - كل عملية مالية تمر منها

---

## ✅ قائمة التحقق النهائية

قبل أن نقول "تم"، يجب التحقق من:

- [ ] كل migrations شغالة بدون errors
- [ ] كل العلاقات بين Models صح
- [ ] Filament — فورم الحجز بيفلتر الشركات حسب السيستم
- [ ] Filament — فورم الحجز بيغير التسعير حسب العملة
- [ ] Filament — الربح بيتحسب تلقائياً في real-time
- [ ] Filament — الرصيد بيتخصم من الشركة عند حفظ الحجز
- [ ] Filament — الخزنة بتتحدث تلقائياً عند كل حجز
- [ ] Vue — كروت الشركات بتعرض الرصيد الصح
- [ ] Vue — جدول الحجوزات مع كل الفلاتر شغالة
- [ ] Vue — صفحة تفاصيل حجز واحد كاملة
- [ ] Vue — الطباعة شغالة مع اختيار الحقول
- [ ] Vue — صفحة الخزنة بكل العملات والحسابات
- [ ] API endpoints محمية ومنظمة
- [ ] لا يوجد بيانات hardcoded — كل شيء من الداتابيز
- [ ] اختبر بإضافة حجز كامل وتأكد إن الأرصدة اتحدثت صح
- [ ] اختبر حجز بالجنيه وحجز بالدينار وتأكد الحسابات
- [ ] اختبر إضافة شركة جديدة وتأكد إنها ظهرت في Vue فوراً

---

**التقرير أعده:** Claude Code
**التاريخ:** 2026-05-03
**الحالة:** جاهز للبدء في التنفيذ
