## إصلاح تصنيف تسديد شركات الباصات

### السبب الجذري المؤكد
- `BusCompanyController::payDebt()` ينشئ حركة liquidity → supplier دون `type`، و`TransactionService` يجعلها `transfer` افتراضيًا (`app/Services/Finance/TransactionService.php:596-602`).
- كان `backfill_bus_transaction_types.php:270-275` يحول أي ملاحظة تحتوي على `تسديد` إلى `expense`.
- هذا حوّل القيود الإنتاجية `69, 107, 159, 284, 285` البالغة `13,480` إلى مصروفات جديدة، مع أنها دفعات تسديد ذمم وليست تكاليف حجوزات.

### التنفيذ
1. استخراج منطق التصنيف من الـone-off script إلى `app/Services/Bus/BusTransactionTypeClassifier.php` مع إبقاء قواعده الحالية وإضافة قاعدة صريحة:
   - `تسديد` / `تسديد دين شركة باصات` → `transfer`.
   - `تكلفة حجز باص` → `expense`.
   - التحصيل والاسترداد والعكس تبقى بقواعدها الحالية.
2. تعديل `backfill_bus_transaction_types.php` ليستخدم الـclassifier بدل الـglobal function.
3. إضافة `type => TransactionType::Transfer` صراحةً في `BusCompanyController::payDebt()` لتوثيق العقد ومنع الاعتماد على default semantics.
4. تحديث `audit_bus_transaction_types.php` ليستنتج `transfer` لحركة liquidity → supplier، بما يطابق العقد ومسار `bus_company_payments`.
5. إضافة اختبارات:
   - Unit tests للـclassifier تغطي التسديد، تكلفة الحجز، التحصيل، وعكس التكلفة.
   - Feature test عبر API لـ`POST /api/v1/bus/companies/{id}/pay-debt` تؤكد أن الحركة `transfer` وأن `bus_company_payments` سجل مطابقًا.
6. لا تغيير على `BusBookingService.php` المعدل مسبقًا أو على أي من تغييراتك غير المرتبطة، كما لا توجد migration أو تعديل Production آخر.

### التحقق
- تشغيل اختبارات classifier وpay-debt المستهدفة.
- تشغيل اختبار `ProfitLossReportTest` المتعلق بتصنيف الحركات.
- تشغيل `vendor/bin/pint --dirty`.
- مراجعة `git diff --check` والتأكد من أن التغيير محصور في ملفات الإصلاح والاختبارات.